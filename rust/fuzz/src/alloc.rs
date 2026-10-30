//! A global allocator that counts what each thread holds, so a property can bound the memory a parser takes for
//! its input. Inside `bounded`, one request for more than `REFUSED` bytes is refused, which aborts the test
//! process with Rust's "memory allocation failed" message instead of letting a box run out of memory.

use std::{
    alloc::{GlobalAlloc, Layout, System},
    cell::Cell,
};

pub const REFUSED: usize = 1 << 30;

pub struct Counting;

#[global_allocator]
static GLOBAL: Counting = Counting;

thread_local! {
    static HELD: Cell<isize> = const { Cell::new(0) };
    static PEAK: Cell<isize> = const { Cell::new(0) };
    static BOUNDED: Cell<bool> = const { Cell::new(false) };
}

fn grew(n: usize) {
    let _ = HELD.try_with(|h| {
        let now = h.get().wrapping_add(n as isize);
        h.set(now);
        let _ = PEAK.try_with(|p| p.set(p.get().max(now)));
    });
}

fn shrank(n: usize) {
    let _ = HELD.try_with(|h| h.set(h.get().wrapping_sub(n as isize)));
}

fn refused(n: usize) -> bool {
    n > REFUSED && BOUNDED.try_with(Cell::get).unwrap_or(false)
}

unsafe impl GlobalAlloc for Counting {
    unsafe fn alloc(&self, layout: Layout) -> *mut u8 {
        if refused(layout.size()) {
            return std::ptr::null_mut();
        }
        let p = System.alloc(layout);
        if !p.is_null() {
            grew(layout.size());
        }
        p
    }

    unsafe fn alloc_zeroed(&self, layout: Layout) -> *mut u8 {
        if refused(layout.size()) {
            return std::ptr::null_mut();
        }
        let p = System.alloc_zeroed(layout);
        if !p.is_null() {
            grew(layout.size());
        }
        p
    }

    unsafe fn dealloc(&self, ptr: *mut u8, layout: Layout) {
        System.dealloc(ptr, layout);
        shrank(layout.size());
    }

    unsafe fn realloc(&self, ptr: *mut u8, layout: Layout, new_size: usize) -> *mut u8 {
        if refused(new_size) {
            return std::ptr::null_mut();
        }
        let p = System.realloc(ptr, layout, new_size);
        if !p.is_null() {
            shrank(layout.size());
            grew(new_size);
        }
        p
    }
}

struct Unbound;

impl Drop for Unbound {
    fn drop(&mut self) {
        let _ = BOUNDED.try_with(|b| b.set(false));
    }
}

/// Runs `f` on this thread and returns its result with the most memory it held at once beyond what the thread held
/// before.
pub fn bounded<T>(f: impl FnOnce() -> T) -> (T, usize) {
    let start = HELD.with(Cell::get);
    PEAK.with(|p| p.set(start));
    BOUNDED.with(|b| b.set(true));
    let out = {
        let _unbound = Unbound;
        f()
    };
    let peak = PEAK.with(Cell::get);
    (out, peak.saturating_sub(start).max(0) as usize)
}

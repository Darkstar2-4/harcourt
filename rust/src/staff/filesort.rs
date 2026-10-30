//! The order MariaDB printed rows in. The PHP pages ran `ORDER BY` on MariaDB 10.6, whose filesort runs its own
//! quicksort (mysys/mf_qsort.c) over the rows in the order the table was read, so rows whose sort keys tie come
//! out where that quicksort leaves them, the same way on every request. SQLite breaks ties differently, so a page
//! that lists tied rows reads them in table order (`ORDER BY id`) and sorts here.
//!
//! Text compares as latin1_swedish_ci did: case-insensitive by its weight table, trailing spaces ignored.

use std::cmp::Ordering;

/// Below this many rows mf_qsort.c finishes a range with an insertion sort.
const INSERT_SORT_BELOW: usize = 10;

/// my_qsort2() over `rows`, which must be in the order MariaDB read them (primary key order for a table scan).
pub fn sort<T>(rows: &mut [T], cmp: impl Fn(&T, &T) -> Ordering) {
    if rows.len() <= 1 {
        return;
    }
    let less = |a: &T, b: &T| cmp(a, b) == Ordering::Less;
    let mut stack: Vec<(usize, usize)> = Vec::new();
    let (mut low, mut high) = (0usize, rows.len() - 1);
    loop {
        let count = high - low + 1;
        if count < INSERT_SORT_BELOW {
            for start in low + 1..=high {
                let mut p = start;
                while p > low && cmp(&rows[p - 1], &rows[p]) == Ordering::Greater {
                    rows.swap(p, p - 1);
                    p -= 1;
                }
            }
            match stack.pop() {
                Some(range) => (low, high) = range,
                None => return,
            }
            continue;
        }
        let mid = low + count / 2;
        let median = |rows: &mut [T], l: usize, m: usize, h: usize| {
            if less(&rows[h], &rows[l]) {
                rows.swap(h, l);
            }
            if less(&rows[m], &rows[l]) {
                rows.swap(m, l);
            } else if less(&rows[h], &rows[m]) {
                rows.swap(m, h);
            }
        };
        let (mut lp, mut hp) = if count > 40 {
            let step = count / 8;
            median(rows, low, low + step, low + step * 2);
            median(rows, mid - step, mid, mid + step);
            median(rows, high - 2 * step, high - step, high);
            median(rows, low + step, mid, high - step);
            (low as isize, high as isize)
        } else {
            median(rows, low, mid, high);
            (low as isize + 1, high as isize - 1)
        };
        // The pivot is the row that sat at `mid` when partitioning began; it moves with swaps, so follow it.
        let mut pivot = mid;
        loop {
            while less(&rows[lp as usize], &rows[pivot]) {
                lp += 1;
            }
            while less(&rows[pivot], &rows[hp as usize]) {
                hp -= 1;
            }
            if lp < hp {
                rows.swap(lp as usize, hp as usize);
                if pivot == lp as usize {
                    pivot = hp as usize;
                } else if pivot == hp as usize {
                    pivot = lp as usize;
                }
                lp += 1;
                hp -= 1;
            } else {
                if lp == hp {
                    lp += 1;
                    hp -= 1;
                }
                break;
            }
            if lp > hp {
                break;
            }
        }
        let (l, h) = (low as isize, high as isize);
        if hp - l <= 0 {
            if h - lp <= 0 {
                match stack.pop() {
                    Some(range) => (low, high) = range,
                    None => return,
                }
            } else {
                low = lp as usize;
            }
        } else if h - lp <= 0 {
            high = hp as usize;
        } else if hp - l > h - lp {
            stack.push((low, hp as usize));
            low = lp as usize;
        } else {
            stack.push((lp as usize, high));
            high = hp as usize;
        }
    }
}

/// latin1_swedish_ci weights (MariaDB strings/ctype-latin1.c, sort_order_latin1).
const WEIGHTS: [u8; 256] = [
    0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22, 23, 24, 25, 26, 27, 28, 29, 30, 31, 32,
    33, 34, 35, 36, 37, 38, 39, 40, 41, 42, 43, 44, 45, 46, 47, 48, 49, 50, 51, 52, 53, 54, 55, 56, 57, 58, 59, 60, 61, 62,
    63, 64, 65, 66, 67, 68, 69, 70, 71, 72, 73, 74, 75, 76, 77, 78, 79, 80, 81, 82, 83, 84, 85, 86, 87, 88, 89, 90, 91, 92,
    93, 94, 95, 96, 65, 66, 67, 68, 69, 70, 71, 72, 73, 74, 75, 76, 77, 78, 79, 80, 81, 82, 83, 84, 85, 86, 87, 88, 89, 90,
    123, 124, 125, 126, 127, 128, 129, 130, 131, 132, 133, 134, 135, 136, 137, 138, 139, 140, 141, 142, 143, 144, 145, 146,
    147, 148, 149, 150, 151, 152, 153, 154, 155, 156, 157, 158, 159, 160, 161, 162, 163, 164, 165, 166, 167, 168, 169, 170,
    171, 172, 173, 174, 175, 176, 177, 178, 179, 180, 181, 182, 183, 184, 185, 186, 187, 188, 189, 190, 191, 65, 65, 65, 65,
    92, 91, 92, 67, 69, 69, 69, 69, 73, 73, 73, 73, 68, 78, 79, 79, 79, 79, 93, 215, 216, 85, 85, 85, 89, 89, 222, 223, 65,
    65, 65, 65, 92, 91, 92, 67, 69, 69, 69, 69, 73, 73, 73, 73, 68, 78, 79, 79, 79, 79, 93, 247, 216, 85, 85, 85, 89, 89,
    222, 255,
];

/// The latin1 (cp1252) byte MariaDB stored for a character; `?` for one latin1 cannot hold.
fn latin1(c: char) -> u8 {
    const CP1252: [(char, u8); 27] = [
        ('€', 0x80), ('‚', 0x82), ('ƒ', 0x83), ('„', 0x84), ('…', 0x85), ('†', 0x86), ('‡', 0x87), ('ˆ', 0x88), ('‰', 0x89),
        ('Š', 0x8A), ('‹', 0x8B), ('Œ', 0x8C), ('Ž', 0x8E), ('‘', 0x91), ('’', 0x92), ('“', 0x93), ('”', 0x94), ('•', 0x95),
        ('–', 0x96), ('—', 0x97), ('˜', 0x98), ('™', 0x99), ('š', 0x9A), ('›', 0x9B), ('œ', 0x9C), ('ž', 0x9E), ('Ÿ', 0x9F),
    ];
    match u8::try_from(u32::from(c)) {
        Ok(b) if !(0x80..0xA0).contains(&b) => b,
        _ => CP1252.iter().find(|(k, _)| *k == c).map_or(b'?', |(_, b)| *b),
    }
}

fn weights(s: &str) -> impl Iterator<Item = u8> + '_ {
    s.chars().map(|c| WEIGHTS[usize::from(latin1(c))])
}

/// Two strings as latin1_swedish_ci ordered them (my_strnncollsp_simple: the shorter is padded with spaces).
pub fn ci(a: &str, b: &str) -> Ordering {
    let (mut x, mut y) = (weights(a), weights(b));
    loop {
        match (x.next(), y.next()) {
            (Some(p), Some(q)) if p == q => continue,
            (Some(p), Some(q)) => return p.cmp(&q),
            (Some(p), None) => return if p == b' ' { continue_pad(x, Ordering::Greater) } else { p.cmp(&b' ') },
            (None, Some(q)) => return if q == b' ' { continue_pad(y, Ordering::Less) } else { b' '.cmp(&q) },
            (None, None) => return Ordering::Equal,
        }
    }
}

/// The rest of the longer string against the padding: equal if it is all spaces, else ordered by its first other
/// character against a space (`longer` is how the longer string ranks when that character is above a space).
fn continue_pad(rest: impl Iterator<Item = u8>, longer: Ordering) -> Ordering {
    for w in rest {
        if w != b' ' {
            return if w > b' ' { longer } else { longer.reverse() };
        }
    }
    Ordering::Equal
}

/// A nullable text column: NULL sorts before every string, as MariaDB sorted it.
pub fn ci_opt(a: Option<&str>, b: Option<&str>) -> Ordering {
    match (a, b) {
        (Some(a), Some(b)) => ci(a, b),
        (a, b) => a.is_some().cmp(&b.is_some()),
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn text_compares_without_case_or_trailing_spaces() {
        assert_eq!(ci("liner", "LINER"), Ordering::Equal);
        assert_eq!(ci("Liner ", "Liner"), Ordering::Equal);
        assert_eq!(ci("A_", "AB"), Ordering::Greater);
        assert_eq!(ci("ab", "ab\t"), Ordering::Greater);
        assert_eq!(ci("Öl", "Zz"), Ordering::Greater);
        assert_eq!(ci_opt(None, Some("")), Ordering::Less);
    }

    #[test]
    fn small_ranges_insertion_sort_and_keep_ties_in_read_order() {
        let mut rows = vec![(2, 'a'), (1, 'b'), (2, 'c'), (1, 'd')];
        sort(&mut rows, |a, b| a.0.cmp(&b.0));
        assert_eq!(rows, [(1, 'b'), (1, 'd'), (2, 'a'), (2, 'c')]);
    }

    fn order(n: usize, key: impl Fn(usize) -> usize) -> Vec<usize> {
        let mut rows: Vec<(usize, usize)> = (0..n).map(|i| (key(i), i)).collect();
        sort(&mut rows, |a, b| a.0.cmp(&b.0));
        rows.into_iter().map(|r| r.1).collect()
    }

    #[test]
    fn ties_land_where_mariadb_quicksort_puts_them() {
        assert_eq!(order(25, |i| (i * 7) % 5), [0, 10, 15, 20, 5, 8, 3, 23, 13, 18, 16, 11, 1, 6, 21, 19, 14, 24, 9, 4, 17, 7, 22, 2, 12]);
        assert_eq!(
            order(60, |i| i % 3),
            [
                0, 15, 42, 18, 39, 21, 36, 30, 24, 33, 27, 45, 12, 6, 3, 57, 51, 9, 48, 54, 58, 31, 34, 55, 46, 52, 40, 43, 49, 37, 7,
                19, 16, 4, 22, 10, 25, 13, 1, 28, 50, 29, 8, 53, 5, 56, 2, 11, 47, 32, 26, 35, 23, 38, 20, 41, 17, 44, 14, 59
            ]
        );
    }
}

//! The Rust-owned part of www.harcourt.co that is not page rendering: accounts, sign-in, sessions and the routes
//! that replace PHP endpoints. The binary (main.rs) mounts `api::router` and checks `retired` before it proxies.

pub mod access_code;
pub mod api;
pub mod audit;
pub mod config;
pub mod db;
pub mod identity;
pub mod orders;
pub mod remote_grant;
pub mod retired;
pub mod routes;
pub mod session;
pub mod visitor;
pub mod token;

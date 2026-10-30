//! www.harcourt.co's routes, accounts, sign-in, sessions, content and data. The binary (main.rs) mounts
//! `routes::all` and answers what no route claims with `retired`.

pub mod access_code;
pub mod accounts;
pub mod api;
pub mod assets;
pub mod audit;
pub mod cms;
pub mod catalog;
pub mod config;
pub mod db;
pub mod identity;
pub mod orders;
pub mod pricing;
pub mod remote_grant;
pub mod retired;
pub mod routes;
pub mod session;
pub mod staff;
pub mod visitor;
pub mod token;

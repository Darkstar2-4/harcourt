//! Who is asking, for every page. `Visitor` is the one way a handler learns it. Pages never read cookies or the
//! session table themselves; they take a `Visitor` argument.
//!
//! The accounts agent grows this: sign-in by company address, bots, campaigns, language and currency. Other areas
//! only read it.

use std::{convert::Infallible, sync::Arc};

use axum::{extract::FromRequestParts, http::request::Parts};

use crate::{api::Auth, db::unix_now, identity::User, session};

pub enum Visitor {
    Anonymous,
    SignedIn { user: User, email: Option<String> },
}

impl Visitor {
    pub fn user(&self) -> Option<&User> {
        match self {
            Visitor::Anonymous => None,
            Visitor::SignedIn { user, .. } => Some(user),
        }
    }
}

impl FromRequestParts<Arc<Auth>> for Visitor {
    type Rejection = Infallible;

    async fn from_request_parts(parts: &mut Parts, core: &Arc<Auth>) -> Result<Self, Infallible> {
        let Some(token) = crate::api::session_cookie(&parts.headers) else { return Ok(Visitor::Anonymous) };
        match session::lookup(&core.pool, &token, unix_now()).await {
            Ok(Some(found)) if found.user.is_active() => Ok(Visitor::SignedIn { user: found.user, email: found.email }),
            _ => Ok(Visitor::Anonymous),
        }
    }
}

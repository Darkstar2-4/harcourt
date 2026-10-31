//! Who a request is allowed to be. A `user` row is a company account (see classes/user.php); a person is an email
//! address that proves it can read mail at that company's domain.
//!
//! Role and status come out of the database as text. They are parsed here, once; a value this code does not know
//! parses to nothing, and a row that parses to nothing grants nothing.

use sqlx::SqlitePool;

#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash)]
pub struct UserId(pub i64);

/// Lowest to highest, as in classes/user.php. The derive order is the privilege order.
#[derive(Debug, Clone, Copy, PartialEq, Eq, PartialOrd, Ord)]
pub enum Role {
    Customer,
    External,
    Internal,
    Executive,
    Administrator,
    SuperUser,
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Status {
    Pending,
    Active,
    Denied,
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub struct User {
    pub id: UserId,
    pub role: Role,
    pub status: Status,
    pub company_name: Option<String>,
}

impl Role {
    pub fn as_str(self) -> &'static str {
        match self {
            Role::Customer => "Customer",
            Role::External => "External",
            Role::Internal => "Internal",
            Role::Executive => "Executive",
            Role::Administrator => "Administrator",
            Role::SuperUser => "Super User",
        }
    }

    pub fn parse(s: &str) -> Option<Role> {
        Some(match s {
            "Customer" => Role::Customer,
            "External" => Role::External,
            "Internal" => Role::Internal,
            "Executive" => Role::Executive,
            "Administrator" => Role::Administrator,
            "Super User" => Role::SuperUser,
            _ => return None,
        })
    }
}

impl Status {
    pub fn parse(s: &str) -> Option<Status> {
        Some(match s {
            "Pending" => Status::Pending,
            "Active" => Status::Active,
            "Denied" => Status::Denied,
            _ => return None,
        })
    }
}

impl User {
    pub fn from_row(id: i64, status: &str, role: &str, company_name: Option<String>) -> Option<User> {
        Some(User { id: UserId(id), status: Status::parse(status)?, role: Role::parse(role)?, company_name })
    }

    pub fn is_active(&self) -> bool {
        self.status == Status::Active
    }
}

/// A normalised mail address: trimmed, lower-case, one `@`, a dotted domain, characters a mailbox can use.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Email(String);

impl Email {
    pub fn parse(s: &str) -> Option<Email> {
        let s = s.trim().to_ascii_lowercase();
        let (local, domain) = s.split_once('@')?;
        let local_ok = !local.is_empty() && local.len() <= 64 && local.bytes().all(|c| c.is_ascii_alphanumeric() || b"._%+-".contains(&c));
        let domain_ok = domain.len() <= 80
            && domain.contains('.')
            && !domain.starts_with('.')
            && !domain.ends_with('.')
            && !domain.contains("..")
            && domain.bytes().all(|c| c.is_ascii_alphanumeric() || c == b'.' || c == b'-');
        (local_ok && domain_ok).then_some(Email(s))
    }

    pub fn as_str(&self) -> &str {
        &self.0
    }

    pub fn domain(&self) -> &str {
        self.0.split_once('@').map_or("", |(_, d)| d)
    }
}

pub async fn load_user(pool: &SqlitePool, id: UserId) -> sqlx::Result<Option<User>> {
    let row = sqlx::query!(
        r#"SELECT id AS "id!: i64", status AS "status!", "type" AS "ty!", company_name FROM "user" WHERE id = ?1"#,
        id.0
    )
    .fetch_optional(pool)
    .await?;
    Ok(row.and_then(|r| User::from_row(r.id, &r.status, &r.ty, r.company_name)))
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn roles_order_by_privilege() {
        assert!(Role::Customer < Role::External);
        assert!(Role::Executive < Role::Administrator);
        assert!(Role::Administrator < Role::SuperUser);
    }

    #[test]
    fn unknown_role_or_status_yields_no_user() {
        assert!(User::from_row(1, "Active", "Super User", None).is_some());
        assert!(User::from_row(1, "Active", "Root", None).is_none());
        assert!(User::from_row(1, "active", "Customer", None).is_none());
        assert!(User::from_row(1, "Reject", "Customer", None).is_none());
    }

    #[test]
    fn emails_normalise_and_reject_junk() {
        assert_eq!(Email::parse("  Jane.Doe@Example.COM ").unwrap().as_str(), "jane.doe@example.com");
        assert_eq!(Email::parse("a@example.com").unwrap().domain(), "example.com");
        for bad in ["", "a", "a@", "@example.com", "a@b", "a@@example.com", "a b@example.com", "a@exa mple.com", "a@.example.com", "a@example..com", "a'@example.com", "a@exam\nple.com", "a\n@example.com"] {
            assert!(Email::parse(bad).is_none(), "{bad:?}");
        }
    }
}

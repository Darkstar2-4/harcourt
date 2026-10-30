//! The table-maintenance pattern the staff pages repeat (supplier.php, mailer_toolbox::maintain(), the portal and
//! campaign managers): a list with "Add new", an edit form, and one form posted back with `action` and `record_id`.
//!
//! A page describes its table once as a `Table`: its columns (label, widget, how posted text becomes the row), its
//! queries and its list. `route::<T>()` serves it. Only a CSRF-checked POST changes anything; a GET only lists,
//! where the PHP acted on `?action=delete` in a link as well.

use std::{future::Future, sync::Arc};

use axum::{extract::State, response::Response, routing::MethodRouter};
use sqlx::SqlitePool;

use super::{
    csrf::{Fields, Posted},
    html::{close, hidden, open, Form, Notes, Opts},
    shell, Staff,
};
use crate::api::{Auth, MinRole};

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Action {
    List,
    Cancel,
    Maintain,
    Update,
    Delete,
}

impl Action {
    pub fn parse(s: &str) -> Action {
        match s {
            "cancel" => Action::Cancel,
            "maintain" => Action::Maintain,
            "update" => Action::Update,
            "delete" => Action::Delete,
            _ => Action::List,
        }
    }
}

pub enum Widget {
    Text { size: u32, max: u32 },
    TextArea { rows: u32, cols: u32 },
    Checkbox,
}

/// One row of the edit form. `get` gives the value to show; `set` takes the posted text into the row and reports
/// what is wrong with it through `Notes::error`.
pub struct Column<T> {
    pub name: &'static str,
    pub label: &'static str,
    pub widget: Widget,
    pub get: fn(&T) -> String,
    pub set: fn(&mut T, &str, &mut Notes),
}

pub enum Saved {
    Done,
    /// A unique value already taken: the field to mark and the text to show under it.
    Conflict { field: &'static str, text: &'static str },
}

pub trait Table: Default + Send + Sync + Sized + 'static {
    type Role: MinRole + Send + Sync + 'static;
    const PATH: &'static str;
    const TITLE: &'static str;
    const VERSION: &'static str = "";
    const MULTIPART: bool = false;
    const FORM_CLASS: &'static str = "large noborder";
    const LABEL_WIDTHS: (&'static str, &'static str) = ("30%", "70%");
    const COLUMNS: &'static [Column<Self>];

    /// The page's `<script>` block with its `fn_action`.
    fn script() -> &'static str;

    /// The Update and Cancel buttons under the edit form.
    fn buttons(id: i64) -> String;

    /// The message after a delete, from the row as it was read.
    fn deleted(&self, notes: &mut Notes);

    /// Checks across fields, after every column's `set`.
    fn validate(&mut self, _notes: &mut Notes) {}

    /// The edit form. The default is one labelled row per column.
    fn edit(&self, form: &Form<'_>) -> String {
        edit_table(self, form)
    }

    fn read(pool: &SqlitePool, id: i64) -> impl Future<Output = sqlx::Result<Option<Self>>> + Send;

    /// Insert when `id` is 0, else update that row.
    fn save(&self, pool: &SqlitePool, id: i64) -> impl Future<Output = sqlx::Result<Saved>> + Send;

    fn delete(pool: &SqlitePool, id: i64) -> impl Future<Output = sqlx::Result<()>> + Send;

    fn list(pool: &SqlitePool, form: &Form<'_>) -> impl Future<Output = sqlx::Result<String>> + Send;
}

pub fn edit_table<T: Table>(row: &T, form: &Form<'_>) -> String {
    let mut out = format!("<table class='{}'>", T::FORM_CLASS);
    for (i, c) in T::COLUMNS.iter().enumerate() {
        let value = (c.get)(row);
        let widget = match c.widget {
            Widget::Text { size, max } => form.text(c.name, &value, size, max, Opts::new()),
            Widget::TextArea { rows, cols } => form.textarea(c.name, &value, rows, cols, Opts::new()),
            Widget::Checkbox => form.checkbox(c.name, "1", value == "1", Opts::new()),
        };
        let (lw, vw) = if i == 0 { (format!(" width='{}'", T::LABEL_WIDTHS.0), format!(" width='{}'", T::LABEL_WIDTHS.1)) } else { (String::new(), String::new()) };
        out.push_str(&format!("<tr><td{lw}>{}</td><td{vw}>{}</td></tr>", c.label, form.notes.with_error(c.name, widget)));
    }
    out.push_str("</table>");
    out
}

enum View {
    List,
    Edit,
}

/// The whole cancel / maintain / update / delete step, then the page.
pub async fn run<T: Table>(pool: &SqlitePool, staff: &Staff<T::Role>, fields: &Fields) -> Result<Response, sqlx::Error> {
    let action = Action::parse(fields.get("action"));
    let id = fields.int("record_id");
    let mut notes = Notes::new(T::TITLE, T::VERSION);
    let mut row = if id != 0 { T::read(pool, id).await?.unwrap_or_default() } else { T::default() };
    let view = match action {
        Action::List => View::List,
        Action::Cancel => {
            notes.say("Request cancelled");
            View::List
        }
        Action::Maintain => View::Edit,
        Action::Delete => {
            T::delete(pool, id).await?;
            row.deleted(&mut notes);
            View::List
        }
        Action::Update => {
            for c in T::COLUMNS {
                (c.set)(&mut row, fields.get(c.name), &mut notes);
            }
            row.validate(&mut notes);
            if notes.has_errors() {
                View::Edit
            } else {
                match row.save(pool, id).await? {
                    Saved::Done => {
                        notes.say("Record maintained");
                        View::List
                    }
                    Saved::Conflict { field, text } => {
                        notes.error(field, Some(text));
                        View::Edit
                    }
                }
            }
        }
    };
    let form = Form { notes: &notes };
    let mut body = notes.render();
    body.push_str(T::script());
    body.push_str(&open(T::PATH, staff.csrf.as_str(), T::MULTIPART));
    body.push_str(&hidden("action", ""));
    body.push_str(&hidden("record_id", ""));
    match view {
        View::Edit => {
            body.push_str(&row.edit(&form));
            body.push_str(&T::buttons(id));
        }
        View::List => body.push_str(&T::list(pool, &form).await?),
    }
    body.push_str(close());
    Ok(shell::page(pool, staff.view(), T::TITLE, &body).await)
}

async fn show<T: Table>(State(auth): State<Arc<Auth>>, staff: Staff<T::Role>) -> Response {
    run::<T>(&auth.pool, &staff, &Fields::default()).await.unwrap_or_else(shell::internal)
}

async fn post<T: Table>(State(auth): State<Arc<Auth>>, staff: Staff<T::Role>, posted: Posted) -> Response {
    run::<T>(&auth.pool, &staff, &posted.fields).await.unwrap_or_else(shell::internal)
}

pub fn route<T: Table>() -> MethodRouter<Arc<Auth>> {
    axum::routing::get(show::<T>).post(post::<T>)
}

/// Text as the PHP stored it: trimmed.
pub fn trimmed(s: &str) -> String {
    s.trim().to_string()
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn unknown_actions_list() {
        assert_eq!(Action::parse("update"), Action::Update);
        assert_eq!(Action::parse(""), Action::List);
        assert_eq!(Action::parse("drop"), Action::List);
    }
}

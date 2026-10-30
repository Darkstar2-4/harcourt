//! hero.php, the Hero Headline manager, as a GET shows it: the report filters and the empty list for
//! Administrators. A script's request (XMLHttpRequest) got the editor's JSON before any access check; with no
//! action it is `{"content_error":""}` for anyone. The editor itself (its posts and its JSON actions) kept its
//! working copy in the PHP session and still goes to PHP.

use std::sync::Arc;

use axum::{
    extract::State,
    http::{header, HeaderMap, StatusCode},
    response::{IntoResponse, Response},
    routing::get,
    Router,
};

use crate::{
    api::{Administrator, Auth},
    staff::{
        choices,
        html::{button, close, hidden, href, list_options, open, Blank, Form, Notes, Opts},
        shell, Staff,
    },
    visitor::PHP_CONTENT_TYPE,
};

const PATH: &str = "/hero.php";
const TITLE: &str = "Hero Headline";
const VERSION: &str = "11/25/2025";

const SCRIPT: &str = r##"<script>
jQuery(document).ready(function() {
	fn_form('initial');
});
function fn_action(action,record_id,name) {
    if (action=="delete") {
        jQuery("#delete" + record_id).prop("checked", false);
        if (!confirm("Delete: #" + record_id)) return;
    }
    jQuery("#action").val(action);
    jQuery("#record_id").val(record_id);
    jQuery("#scs_form").submit();
}

function fn_form(action) {
    data=scscpq_class_data('input');
    data['action']=action;
	jQuery.ajax({
        type: 'post',
	    dataType: 'json',
	    data: data,
	    dataType: 'json',
	    success:function(response) { scscpq_fill(response) }
    });
}
</script>
<style>
#preview_content {
    border-top: 1px solid black;
    padding-top: 1em;
    padding-bottom: 1em;
}
#preview_content:empty  {
    border: none;
    padding: 0em;
}
.scscpq_hero_preview { border: 2px solid green; }
</style>
"##;

async fn page(State(auth): State<Arc<Auth>>, headers: HeaderMap, staff: Result<Staff<Administrator>, Response>) -> Response {
    if headers.contains_key("x-requested-with") {
        return (StatusCode::OK, [(header::CONTENT_TYPE, PHP_CONTENT_TYPE)], "{\"content_error\":\"\"}").into_response();
    }
    let staff = match staff {
        Ok(s) => s,
        Err(refused) => return refused,
    };
    let pool = &auth.pool;
    let result = async {
        let notes = Notes::new(TITLE, VERSION);
        let form = Form { notes: &notes };
        let codes: Vec<String> = sqlx::query_scalar!("SELECT code FROM language WHERE active = 1 ORDER BY priority, name COLLATE NOCASE").fetch_all(pool).await?;
        let row = |label: &str, widget: String| format!("<tr><td width='20%'>{label}</td><td widht='80%'>{widget}</td></tr>");
        let mut body = notes.render();
        body.push_str(SCRIPT);
        body.push_str(&open(PATH, staff.csrf.as_str(), false));
        body.push_str(&hidden("action", ""));
        body.push_str("<input type=hidden name='record_id' class='input' id='record_id'>\n");
        body.push_str("<table class='noborder'>");
        body.push_str(&row("Portal", form.select("report_portal_id", &choices::portals(pool).await?, "", Blank::Empty, Opts::new())));
        body.push_str(&row("Industry", form.select("report_industry_id", &choices::industries(pool).await?, "", Blank::Empty, Opts::new())));
        body.push_str(&row("Language", form.select("report_language_code", &list_options(&codes), "", Blank::Empty, Opts::new())));
        body.push_str("</table>");
        body.push_str(&format!("<p>{}</p>", button("List", Opts::new().onclick("fn_action('list',0,0);"))));
        body.push_str("<table class='border tablesorter'><thead><tr><th>ID</th><th>Effective Date</th><th>Portal</th><th>Industry</th><th>Language</th><th>Access</th><th>Status</th><th>Del?</th></tr></thead>");
        body.push_str(&format!("<tr><td colspan=8>{}</td></tr></table>", href("Add New", "javascript:fn_action('maintain', 0, '');", Opts::new())));
        body.push_str(close());
        Ok::<_, sqlx::Error>(body)
    }
    .await;
    match result {
        Ok(body) => shell::page(pool, staff.view(), TITLE, &body).await,
        Err(e) => shell::internal(e),
    }
}

pub fn router() -> Router<Arc<Auth>> {
    Router::new().route(PATH, get(page))
}

//! cad_lookup.php: the Configurator Toolbox (scsps_toolbox_class "lookup"), where staff load a part into the
//! PARTsolutions configurator and generate its CAD. Internal staff and above; anyone else is sent home. The page is
//! the entry form; its buttons post back to the toolbox's JSON branch, which still runs in PHP.

use std::{collections::HashMap, sync::Arc};

use axum::{
    extract::{Query, State},
    http::{header, HeaderMap, HeaderValue},
    response::{IntoResponse, Response},
    routing::get,
    Router,
};
use sqlx::SqlitePool;

use super::{html::base64_fields, registry::{self, Registry}};
use crate::{
    accounts::profile::Profile,
    api::{Auth, Internal},
    db::unix_now,
    identity::Role,
    staff::{
        html::{button, esc, hidden, text, Blank, Form, Notes, OptGroup, Opts},
        shell, Staff,
    },
    visitor::Visitor,
};

const PATH: &str = "/cad_lookup.php";
const TITLE: &str = "Configurator Toolbox (10/27/2025)";
/// cad_constant_class: the configurator's portlets an administrator may hide, and its languages.
const HIDE_PORTLETS: [(&str, &str); 4] =
    [("briefcase", "Generate CAD"), ("preview", "3D Preview"), ("searchHeader", "Search Header"), ("navigation", "Enter Parameters")];
const LANGUAGES: [(&str, &str); 5] = [("en", "English"), ("fr", "French"), ("de", "German"), ("it", "Italian"), ("es", "Spanish")];

pub fn router() -> Router<Arc<Auth>> {
    Router::new().route(PATH, get(show))
}

/// What a link from a failed lookup (`action=ipc`) fills in.
#[derive(Debug, Default, PartialEq)]
struct Input {
    source: String,
    prj: String,
    sku: String,
}

impl Input {
    fn from_query(q: &HashMap<String, String>) -> Input {
        if q.get("action").map(String::as_str) != Some("ipc") {
            return Input::default();
        }
        let get = |k: &str| q.get(k).cloned().unwrap_or_default();
        Input { source: get("source"), prj: get("prj"), sku: get("sku") }
    }
}

/// A registry setting as PHP tested it: set, and not "0".
fn on(reg: &Registry, field: &str) -> bool {
    !matches!(reg.get("cad", field), "" | "0")
}

/// cad_class::select_picklist(): the CAD formats by type, direct formats first, then 3D, then the rest; the
/// direct ones only when the registry allows Part2CAD.
async fn formats(pool: &SqlitePool, part2cad: bool) -> sqlx::Result<Vec<OptGroup>> {
    let rows = sqlx::query!(
        r#"SELECT COALESCE(code, '') AS "code!: String", COALESCE(name, '') AS "name!: String", COALESCE("type", '') AS "ty!: String"
           FROM cad WHERE ?1 OR COALESCE(direct, 0) = 0
           ORDER BY CASE WHEN direct = 1 THEN 1 WHEN "type" = '3D' THEN 2 ELSE 3 END, id"#,
        part2cad
    )
    .fetch_all(pool)
    .await?;
    let mut groups: Vec<OptGroup> = Vec::new();
    for r in rows {
        let option = (r.code, text(&r.name));
        match groups.iter_mut().find(|(t, _)| *t == r.ty) {
            Some((_, list)) => match list.iter_mut().find(|(c, _)| *c == option.0) {
                Some(slot) => *slot = option,
                None => list.push(option),
            },
            None => groups.push((r.ty, vec![option])),
        }
    }
    Ok(groups)
}

fn options(pairs: &[(&str, &str)]) -> Vec<(String, String)> {
    pairs.iter().map(|(v, l)| (v.to_string(), l.to_string())).collect()
}

/// html_lookup() between the form's opening and closing tags.
fn form_body(reg: &Registry, role: Role, input: &Input, formats: &[OptGroup]) -> String {
    let notes = Notes::new("", "");
    let form = Form { notes: &notes };
    let input_class = || Opts::new().class("input");
    let mut o: Vec<String> = Vec::new();
    o.push(hidden("action", ""));
    o.push(hidden("command", ""));
    o.push(LOOKUP_CSS.to_string());
    o.push("<table id=lookup_entry class='noborder'>".into());
    o.push("<tr>".into());
    o.push("<td width='60%'>".into());
    o.push("<table class='border'>".into());
    o.push("<tr><th colspan=2>Configurator</th></td>".into());
    o.push("<tr class=lookup_debug>".into());
    o.push("<td>Configurator</td>".into());
    o.push(format!("<td>{}</td>", form.text("configurator_url", reg.get("cad", "configurator"), 80, 0, input_class())));
    o.push("</tr>".into());
    o.push("<tr class=lookup_debug>".into());
    o.push(format!("<td>{}</td>", button("CURL IPV4", Opts::new().onclick("cad_ajax('curl_ipv4');"))));
    o.push("<td id=curl_ipv4></td>".into());
    o.push("</tr>".into());
    o.push("<td width='20%'>Source</td>".into());
    let mut sources = Vec::new();
    if on(reg, "partserver_reversemap") {
        sources.push(("sku", "Part#"));
    }
    sources.extend([("prj", "PRJ"), ("varset", "Info/Varset"), ("partid", "Part ID")]);
    o.push(format!(
        "<td width='80%'>{}</td>",
        form.select("source", &options(&sources), &input.source, Blank::None, input_class().onchange("lookup_source();"))
    ));
    o.push("</tr>".into());
    o.push("<tr class=sku_class>".into());
    o.push("<td width='20%'>Part Number</td>".into());
    o.push(format!("<td width='80%'>{}</td>", form.textarea("sku", "", 2, 80, input_class())));
    o.push("</tr>".into());
    o.push("<tr class=prj_class>".into());
    o.push("<td>PRJ</td>".into());
    o.push(format!("<td>{}</td>", form.textarea("prj", &input.prj, 8, 60, input_class())));
    o.push("</tr>".into());
    o.push("<tr class=varset_class>".into());
    o.push("<td>Info</td>".into());
    o.push(format!("<td>{}</td>", form.textarea("info", "", 3, 60, input_class())));
    o.push("</tr>".into());
    o.push("<tr class=varset_class>".into());
    let varset = if role >= Role::SuperUser { button("Edit Varset", Opts::new().onclick("jQuery('#varset_values_tr').show();")) } else { "Varset".into() };
    o.push(format!("<td>{varset}</td>"));
    o.push(format!("<td>{}</td>", form.textarea("varset", "", 12, 60, input_class())));
    o.push("</tr>".into());
    o.push("<tr class=partid_class>".into());
    o.push("<td>PartId</td>".into());
    o.push(format!("<td>{}</td>", form.textarea("partid", "", 12, 60, input_class())));
    o.push("</tr>".into());
    o.push("<tr>".into());
    o.push(format!("<td id=lookup_button>{}</td>", button("Load Configurator", Opts::new().onclick("cad_ajax('load');"))));
    o.push("<td id=toolbox_status colspan=2></td>".into());
    o.push("</tr>".into());
    o.push("<tr><th colspan=2>Generate CAD</th></tr>".into());
    o.push("<tr class=lookup_debug>".into());
    o.push("<td>Catalog</td>".into());
    o.push(format!("<td>{}", form.text("catalog", reg.get("cad", "catalog"), 40, 40, input_class())));
    o.push("</tr>".into());
    o.push("<tr class=lookup_debug>".into());
    o.push("<td>API Key</td>".into());
    o.push(format!("<td>{}", form.text("apikey", "", 40, 40, input_class())));
    o.push("</tr>".into());
    o.push("<tr>".into());
    o.push("<tr>".into());
    o.push("<td>CAD Format</td>".into());
    let cad_format = if formats.is_empty() { String::new() } else { form.optgroup("cad_code", formats, "PDFDATASHEET", false, input_class()) };
    o.push(format!("<td>{cad_format}</td>"));
    o.push("</tr>".into());
    o.push("<tr>".into());
    o.push("<td>CAD Output</td>".into());
    o.push(format!("<td>{}</td>", form.select("generate", &options(&[("0", "Download"), ("1", "Email")]), "", Blank::None, input_class())));
    o.push("</tr>".into());
    o.push("<tr>".into());
    o.push(format!("<td>{}</td>", button("Generate CAD", Opts::new().onclick("cad_ajax('cad');"))));
    o.push("<td id=cad_working></td>".into());
    o.push("</tr>".into());
    o.push("</table>".into());
    o.push("</td>".into());
    o.push("<td width='40%'>".into());
    o.push("<table class='border'>".into());
    o.push("<tr><th colspan=2>Options</th></td>".into());
    o.push("<tr>".into());
    o.push("<td width='30%'>QA Server?</td>".into());
    let qa = reg.get("cad", "configurator").to_ascii_lowercase().contains(".qa.");
    o.push(format!("<td width='70%'>{}</td>", form.checkbox("staging", "1", qa, input_class())));
    o.push("</tr>".into());
    o.push("<tr>".into());
    o.push("<td>Language</td>".into());
    o.push(format!("<td>{} (languageIso)</td>", form.select("language", &options(&LANGUAGES), "", Blank::None, input_class())));
    o.push("</tr>".into());
    let fixed = base64_fields(&["cad_options", "partFixed"]);
    if role >= Role::Administrator {
        o.push("<tr>".into());
        o.push("<td>Options</td>".into());
        o.push(format!("<td>{} Fixed Part#? (partFixed)</td>", form.checkbox(&fixed, "1", false, input_class())));
        o.push("</tr>".into());
        o.push("<tr>".into());
        o.push("<td>Hide Portlets</td>".into());
        let boxes: Vec<String> = HIDE_PORTLETS
            .iter()
            .map(|(field, name)| format!("{} {name}? ({field})", form.checkbox(&base64_fields(&["hidePortlets", field]), "1", false, input_class())))
            .collect();
        o.push(format!("<td>{}</td>", boxes.join("<br>")));
        o.push("</tr>".into());
        for (label, field) in [("Show Variables?", "parse"), ("Debug?", "debug")] {
            o.push("<tr>".into());
            o.push(format!("<td>{label}</td>"));
            o.push(format!("<td>{}</td>", form.checkbox(field, "1", false, input_class().onclick("lookup_screen(this.checked);"))));
            o.push("</tr>".into());
        }
        o.push("<tr id=varset_values_tr>".into());
        let buttons = [
            button("Varset -> Editor", Opts::new().onclick("cad_ajax('varset_load');")),
            button("Varset <- Editor", Opts::new().onclick("cad_ajax('varset_save');")),
            button("Compare varset", Opts::new().onclick("cad_ajax('varset_compare');")),
        ];
        o.push(format!(
            "<td colspan=2><p>{}</p><div id=varset_field_error></div>{}<div id=varset_compare></div></td>",
            buttons.join(" "),
            form.textarea("varset_field", "", 20, 80, input_class())
        ));
        o.push("</tr>".into());
    } else {
        o.push(format!("<input type=hidden name='{fixed}' class='input' id='{fixed}' value='false'>\n"));
    }
    o.push("</table>".into());
    o.push("</td>".into());
    o.push("</table>".into());
    o.push("<table class='border' class=lookup_debug>".into());
    o.push("<caption>&nbsp;</caption>".into());
    o.push("<tr>".into());
    o.push("<td width='10%'>Part URL</td>".into());
    o.push("<td width='90%' id=cad_url></td>".into());
    o.push("</tr>".into());
    if on(reg, "partserver_reversemap") {
        o.push("<tr>".into());
        o.push("<td>Reversemap URL</td>".into());
        o.push("<td id=reversemap_url></td>".into());
        o.push("</tr>".into());
    }
    for (label, id) in [("Stopwatch", "stopwatch"), ("CURL", "curl")] {
        o.push("<tr>".into());
        o.push(format!("<td>{label}</td>"));
        o.push(format!("<td id={id}></td>"));
        o.push("</tr>".into());
    }
    o.push("</table>".into());
    if !input.sku.is_empty() {
        o.push(format!("<p>Lookup error detected for SKU: <b>{}</b></p>", esc(&input.sku)));
    }
    o.push("<table class='noborder'>".into());
    o.push("<tr>".into());
    o.push("<td width='60%' id=lookup_configurator></td>".into());
    o.push("<td width='40%' id=lookup_parse></td>".into());
    o.push("</tr>".into());
    o.push("</table>".into());
    o.concat()
}

/// A request from the page's script (the PHP's JSON branch) is not answered here.
fn handed_back() -> Response {
    let mut r = ().into_response();
    r.headers_mut().insert("x-hxweb", HeaderValue::from_static("legacy"));
    r
}

/// The page loads the visitor's profile first, which writes the `harcourt_profile` cookie on every answer, the
/// redirect home included.
async fn show(
    State(core): State<Arc<Auth>>,
    staff: Result<Staff<Internal>, Response>,
    visitor: Visitor,
    headers: HeaderMap,
    Query(q): Query<HashMap<String, String>>,
) -> Response {
    if headers.contains_key("x-requested-with") {
        return handed_back();
    }
    let cookie = match Profile::load(&core.pool, &headers, &visitor).await {
        Ok(p) => p.remember(&core.pool, &visitor, unix_now()).await,
        Err(e) => Err(e),
    };
    let cookie = match cookie {
        Ok(c) => c,
        Err(e) => return shell::internal(e),
    };
    let mut resp = match staff {
        Ok(staff) => page(&core, &staff, &q).await,
        Err(home) => home,
    };
    if let Some(c) = cookie {
        resp.headers_mut().append(header::SET_COOKIE, c);
    }
    resp
}

async fn page(core: &Auth, staff: &Staff<Internal>, q: &HashMap<String, String>) -> Response {
    let input = Input::from_query(q);
    let role = staff.who.user.role;
    let body = async {
        let reg = registry::load(&core.pool).await?;
        let formats = formats(&core.pool, on(&reg, "part2cad")).await?;
        let open = crate::staff::html::open(PATH, staff.csrf.as_str(), false);
        Ok::<_, sqlx::Error>(format!(
            "<p class='message left'>{TITLE}</p>{TOOLBOX_JS}{LOOKUP_JS}{open}{}{}",
            form_body(&reg, role, &input, &formats),
            crate::staff::html::close()
        ))
    }
    .await;
    match body {
        Ok(body) => shell::page(&core.pool, staff.view(), TITLE, &body).await,
        Err(e) => shell::internal(e),
    }
}

const LOOKUP_CSS: &str = "<style>\n#lookup_entry { height: 400px; }\n#lookup_configurator { height: 1050px; }\n</style>";

const TOOLBOX_JS: &str = r###"<script>
var scscpq_function="utility";
var scscpq_action;
var scscpq_data;
var scscpq_id;
function cad_command(command, action) {
    if ( (command=='xml') &&  (!confirm('All existing records will be replaced')) ) return;
    jQuery("#command").val(command);
    jQuery("#action").val(action);
    jQuery("#scs_form").submit();
}
function cad_ajax(action, configurator) {
    field="#" + action + "_working";
    if (configurator == undefined) configurator=scscpq_data;
    jQuery(field).html("<b>Working ...</b>");
    jQuery.ajax({
        dataType: 'json',
        method: 'post',
        data: { function: scscpq_function, action: action, configurator: configurator, input: scscpq_class_data('input') },
        success: function(response) { scscpq_fill(response) },
        error: function (request, status, error) {
            jQuery(field).html("<b>Error Detected</b>"),
            console.log(error)
        }
    });
}
function cad_listener(id) {
    scscpq_id=id;
    scscpq_message('PCOM_INFO', "sku");
}
function scscpq_message(message, action) {
    try {
        jQuery("#" + scscpq_id)[0].contentWindow.postMessage(message, '*');
        scscpq_action=action;
    }
    catch(error) {
        action=scscpq_action;
    }
    scscpq_action=action;
}

jQuery(window).unbind('message');
jQuery(window).on('message', function(e) {
    var response=e.originalEvent.data;
    console.log(response);
    console.log(scscpq_action);
    switch (typeof(response)) {
    case "object":
        switch (true) {
        case ( (typeof(response.width) != "undefined") && (typeof(response.height) != "undefined") ):
            if (scscpq_action == "size") {
                if (scscpq_resize) {
                    if (response.height) document.getElementById(scscpq_id).style.height = (response.height + 30) + "px";
                    scscpq_resize=false;
                }
            }
            scscpq_message('PCOM_INFO', "sku");
            break;
        case (typeof(response.standardName) != "undefined"):
            switch (scscpq_action) {
            case "sku":
                if (JSON.stringify(response) == scscpq_data) break;
                scscpq_data=JSON.stringify(response);
                cad_ajax('sku');
                scscpq_action="";
                break;
            }
            break;
        }
      break;
    case "string":
        switch (response) {
        case "PCOM_PART_CHANGED":
            scscpq_message('PCOM_INFO', "sku");
            break;
        }
    }
});

</script>"###;

const LOOKUP_JS: &str = r###"<script>
var scscpq_function="lookup";
jQuery(document).ready(function() {
    lookup_screen();
    lookup_source();
    switch (true) {
      case (typeof scscpq_class_data != "function" ):
        jQuery("#lookup_button").html("Missing function: scscpq_class_data");
        break;
      case (typeof scscpq_fill != "function" ):
        jQuery("#lookup_button").html("Missing function: scscpq_fill");
        break;
    }
});
function lookup_screen() {
    if (jQuery("#debug").prop('checked')) {
        jQuery(".lookup_debug").show();
      } else {
        jQuery(".lookup_debug").hide();
    }
    if (jQuery("#parse").prop('checked')) {
        jQuery("#lookup_parse").show();
      } else {
        jQuery("#lookup_parse").hide();
    }
}
function lookup_source() {
    show="." + jQuery("#source").val() + "_class";
    jQuery(".sku_class").hide();
    jQuery(".prj_class").hide();
    jQuery(".varset_class").hide();
    jQuery(".partid_class").hide();
    jQuery("#varset_values_tr").hide();
    jQuery("." + jQuery("#source").val() + "_class").show();
}
</script>"###;

#[cfg(test)]
mod tests {
    use super::*;

    fn q(pairs: &[(&str, &str)]) -> HashMap<String, String> {
        pairs.iter().map(|(k, v)| (k.to_string(), v.to_string())).collect()
    }

    #[test]
    fn only_a_failed_lookup_link_fills_the_form() {
        let ipc = Input::from_query(&q(&[("action", "ipc"), ("source", "prj"), ("prj", "a.prj"), ("sku", "SYN-1")]));
        assert_eq!(ipc, Input { source: "prj".into(), prj: "a.prj".into(), sku: "SYN-1".into() });
        assert_eq!(Input::from_query(&q(&[("action", "other"), ("sku", "SYN-1")])), Input::default());
    }

    #[test]
    fn the_form_escapes_what_the_link_carries_and_shows_administrators_their_options() {
        let reg = Registry::from_rows([("", "cad", "configurator", "", Some("https://cad.example/qa.x/")), ("", "cad", "partserver_reversemap", "", Some("1"))]);
        let input = Input { source: "sku".into(), prj: String::new(), sku: "<b>".into() };
        let internal = form_body(&reg, Role::Internal, &input, &[]);
        assert!(internal.contains("Lookup error detected for SKU: <b>&lt;b&gt;</b>"));
        assert!(internal.contains("<option value='sku' selected>Part#</option>"));
        assert!(internal.contains("<td>Varset</td>"));
        assert!(!internal.contains("Hide Portlets"));
        assert!(internal.contains("<input type=hidden name='Y2FkX29wdGlvbnMJcGFydEZpeGVk' class='input' id='Y2FkX29wdGlvbnMJcGFydEZpeGVk' value='false'>"));
        let admin = form_body(&reg, Role::Administrator, &Input::default(), &[]);
        assert!(admin.contains("name='aGlkZVBvcnRsZXRzCWJyaWVmY2FzZQ'"));
        assert!(admin.contains("<td>Varset</td>"));
        assert!(form_body(&reg, Role::SuperUser, &Input::default(), &[]).contains("value='Edit Varset'"));
        assert!(!form_body(&Registry::default(), Role::Internal, &Input::default(), &[]).contains("Reversemap URL"));
    }
}

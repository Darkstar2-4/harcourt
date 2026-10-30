//! address_class for the staff forms: the address block of one registry table (module `address`, field = the
//! table, item = the part, data = `required` or `allowed`). Inputs are named `<table>_<part>`; the state goes in
//! one of three inputs chosen by the country.
//!
//! Only the parts the staff pages edit exist here. A part the registry does not list is not shown and is posted
//! as blank, as verify_field() blanked it.

use sqlx::SqlitePool;

use super::{
    check::posted_text,
    choices,
    csrf::Fields,
    html::{Blank, Form, Notes, Opts},
    shell::registry,
};

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Part {
    CompanyName,
    Address,
    City,
    State,
    Zip,
}

impl Part {
    const ALL: [Part; 5] = [Part::CompanyName, Part::Address, Part::City, Part::State, Part::Zip];

    fn key(self) -> &'static str {
        match self {
            Part::CompanyName => "company_name",
            Part::Address => "address",
            Part::City => "city",
            Part::State => "state",
            Part::Zip => "zip",
        }
    }

    fn label(self) -> &'static str {
        match self {
            Part::CompanyName => "Company Name",
            Part::Address => "Address",
            Part::City => "City",
            Part::State => "State/Province",
            Part::Zip => "Zip/Postal Code",
        }
    }
}

#[derive(Debug, Clone, Default, PartialEq, Eq)]
pub struct Address {
    pub company_name: String,
    pub address: String,
    pub city: String,
    pub state: String,
    pub zip: String,
    pub country_code: String,
}

impl Address {
    fn part(&self, p: Part) -> &str {
        match p {
            Part::CompanyName => &self.company_name,
            Part::Address => &self.address,
            Part::City => &self.city,
            Part::State => &self.state,
            Part::Zip => &self.zip,
        }
    }

    fn part_mut(&mut self, p: Part) -> &mut String {
        match p {
            Part::CompanyName => &mut self.company_name,
            Part::Address => &mut self.address,
            Part::City => &mut self.city,
            Part::State => &mut self.state,
            Part::Zip => &mut self.zip,
        }
    }
}

/// Which parts a table shows, and which of them must not be blank. The country always shows.
pub struct Block {
    table: &'static str,
    required: Vec<Part>,
    allowed: Vec<Part>,
    country_required: bool,
}

/// The input the state is posted in for a country.
fn state_input(country: &str) -> &'static str {
    match country {
        "US" => "state_us",
        "CA" => "state_ca",
        _ => "state_other",
    }
}

const STAR: &str = "<font color=red><b>*</b></font>";

impl Block {
    pub async fn load(pool: &SqlitePool, table: &'static str) -> Block {
        let rows = registry(pool, "address").await;
        let need = |key: &str| rows.iter().find(|(f, i, _)| f == table && i == key).map(|(_, _, d)| d.trim() == "required");
        let mut block = Block { table, required: Vec::new(), allowed: Vec::new(), country_required: need("country_code") == Some(true) };
        for p in Part::ALL {
            match need(p.key()) {
                Some(true) => block.required.push(p),
                Some(false) => block.allowed.push(p),
                None => {}
            }
        }
        block
    }

    fn shows(&self, p: Part) -> bool {
        self.required.contains(&p) || self.allowed.contains(&p)
    }

    fn field(&self, key: &str) -> String {
        format!("{}_{key}", self.table)
    }

    /// verify(): the posted address, each required part that is blank marked in `notes`.
    pub fn verify(&self, fields: &Fields, notes: &mut Notes) -> Address {
        let mut a = Address { country_code: posted_text(fields.get(&self.field("country_code"))), ..Address::default() };
        for p in Part::ALL {
            if !self.shows(p) {
                continue;
            }
            let input = if p == Part::State { state_input(&a.country_code) } else { p.key() };
            let value = posted_text(fields.get(&self.field(input)));
            let value = if p == Part::Zip { value.to_uppercase() } else { value };
            if value.is_empty() && self.required.contains(&p) {
                notes.error(&self.field(input), Some(&format!("{} cannot be blank", p.label())));
            }
            *a.part_mut(p) = value;
        }
        a
    }

    /// input(): one row per part, the legend and the script that shows the state input of the chosen country.
    pub async fn input(&self, pool: &SqlitePool, form: &Form<'_>, a: &Address) -> sqlx::Result<String> {
        let placeholder = |text: &str| Opts::new().set("placeholder", text);
        let mut rows = String::new();
        for p in Part::ALL.into_iter().filter(|p| self.shows(*p)) {
            let value = a.part(p);
            let (title, content) = match p {
                Part::CompanyName | Part::City => (p.label().to_string(), form.text(&self.field(p.key()), value, 40, 40, placeholder(p.label()))),
                Part::Address => (p.label().to_string(), form.text(&self.field(p.key()), value, 40, 100, placeholder(p.label()))),
                Part::Zip => (format!("<span id={}></span>", self.field("zip_text")), form.text(&self.field("zip"), value, 25, 40, placeholder("Zip/Postal Code/Zip Code"))),
                Part::State => {
                    let us = form.select(&self.field("state_us"), &choices::states(pool, "US").await?, value, Blank::Label("Select State"), Opts::new());
                    let ca = form.select(&self.field("state_ca"), &choices::states(pool, "CA").await?, value, Blank::Label("Select Province"), Opts::new());
                    let other = form.text(&self.field("state_other"), value, 40, 40, placeholder("Province/State"));
                    (format!("<span id={}></span>", self.field("state_text")), [us, ca, other].join(" "))
                }
            };
            rows.push_str(&self.row(self.required.contains(&p), &title, &content));
        }
        let onchange = Opts::new().onchange(format!("fn_country_address('{}');", self.table));
        let country = form.select(&self.field("country_code"), &choices::countries(pool).await?, &a.country_code, Blank::Label("Select Country"), onchange);
        rows.push_str(&self.row(self.country_required, "Country", &country));
        rows.push_str(&format!("<tr ><td colspan=2>{STAR}Indicates mandatory fields</td></tr>"));
        rows.push_str(&format!("\n<script>\n\nfn_country_address('{}');\n{COUNTRY_SCRIPT}\n\n</script>\n", self.table));
        Ok(rows)
    }

    fn row(&self, required: bool, title: &str, content: &str) -> String {
        let mark = if required { STAR } else { "&nbsp;" };
        format!("<tr ><td width='20%'>{mark}{title}</td><td width='80%'>{content}</td></tr>")
    }
}

const COUNTRY_SCRIPT: &str = "function fn_country_address(table) {
jQuery('#' + table + '_state_us').hide();
jQuery('#' + table + '_state_ca').hide();
jQuery('#' + table + '_state_other').hide();
jQuery('#' + table + '_state_us_div').hide();
jQuery('#' + table + '_state_ca_div').hide();
jQuery('#' + table + '_state_other_div').hide();
var state;
var zip='Postal Code';
country_code=jQuery('#' + table + '_country_code').val();
switch (true) {
case (country_code == 'US'):
jQuery('#' + table + '_state_us').show();
jQuery('#' + table + '_state_us_div').show();
state='State';
zip='ZIP Code';
break;
case (country_code == 'CA'):
jQuery('#' + table + '_state_ca').show();
jQuery('#' + table + '_state_ca_div').show();
state='Province';
break;
default:
jQuery('#' + table + '_state_other').show();
jQuery('#' + table + '_state_other_div').show();
state='Province/State';
}
jQuery('#' + table + '_state_text').html(state);
jQuery('#' + table + '_zip_text').html(zip);
}";

#[cfg(test)]
mod tests {
    use super::*;

    fn profile() -> Block {
        Block {
            table: "profile",
            required: vec![Part::CompanyName],
            allowed: vec![Part::Address, Part::City, Part::State, Part::Zip],
            country_required: true,
        }
    }

    #[test]
    fn the_state_comes_from_the_input_of_the_country() {
        let mut notes = Notes::new("T", "");
        let fields = Fields::of(&[
            ("profile_company_name", " Acme  Tools "),
            ("profile_country_code", "CA"),
            ("profile_state_us", "MI"),
            ("profile_state_ca", "ON"),
            ("profile_zip", "k1a 0b1"),
        ]);
        let a = profile().verify(&fields, &mut notes);
        assert_eq!(a.company_name, "Acme  Tools");
        assert_eq!(a.state, "ON");
        assert_eq!(a.zip, "K1A 0B1");
        assert!(!notes.has_errors());
    }

    #[test]
    fn a_blank_required_part_is_marked_by_its_input_name() {
        let mut notes = Notes::new("T", "");
        let a = profile().verify(&Fields::of(&[("profile_country_code", "US")]), &mut notes);
        assert_eq!(a.country_code, "US");
        assert!(notes.is_error("profile_company_name"));
        assert_eq!(notes.error_text("profile_company_name"), Some("Company Name cannot be blank"));
        assert!(!notes.is_error("profile_country_code"));
    }

    #[test]
    fn posted_text_is_trimmed_line_by_line() {
        assert_eq!(posted_text("  a\nd "), "a\nd");
        assert_eq!(posted_text("plain"), "plain");
    }
}

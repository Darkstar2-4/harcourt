//! The smart-SKU modules (classes/configure_<subcategory>.php): a smart subcategory builds its parts from the SKU
//! and prices them from `pricing`, one row per record type and option set. Here is what each module prices, which
//! is what `hxweb export pricing` and `hxweb import pricing` read; parsing a SKU stays with the configurator.

/// One kind of pricing row: its code, its name, the options that key it and the prices it holds. No module's
/// option has a list or a number format, so an option is the text stored.
#[derive(Debug)]
pub struct RecordType {
    pub code: &'static str,
    pub name: &'static str,
    pub fields: &'static [&'static str],
    pub prices: &'static [&'static str],
}

#[derive(Debug)]
pub struct Module {
    pub subcategory_id: i64,
    pub record_types: &'static [RecordType],
}

impl Module {
    pub fn record_type(&self, code: &str) -> Option<&'static RecordType> {
        self.record_types.iter().find(|r| r.code == code)
    }
}

const DIAMETER: &[&str] = &["1.00mm to 5.00mm", "5.01mm to 10.00mm", "10.01mm to 15.00mm", "15.01mm to 20.00mm", "20.01mm to 24.99mm"];
const LENGTH: &[&str] = &["10mm to 20mm", "21mm to 30mm", "31mm to 40mm", "41mm to 50mm", "51mm to 60mm"];
const GHD: &[&str] = &["GHD-3-BLACK", "GHD-4-BLACK"];

const fn prices(code: &'static str, name: &'static str, prices: &'static [&'static str]) -> RecordType {
    RecordType { code, name, fields: &[], prices }
}

const A: RecordType = prices("a_price", "A Diameter", DIAMETER);
const B: RecordType = prices("b_price", "B Diameter", DIAMETER);
const C: RecordType = prices("c_price", "C Length", LENGTH);
const D: RecordType = prices("d_price", "D Length", LENGTH);
const G: RecordType = prices("g_price", "G Diameter", DIAMETER);
const H: RecordType = prices("h_price", "H Diameter", DIAMETER);
const K: RecordType = prices("k_price", "K Length", LENGTH);
const L: RecordType = prices("l_price", "L Length", LENGTH);
const GHD_PRICE: RecordType = prices("ghd_price", "GHD", GHD);

/// The modules in the order the PHP defined their record types.
pub const MODULES: &[Module] = &[
    Module {
        subcategory_id: 549,
        record_types: &[
            RecordType { code: "carriage_length", name: "Carriage Length", fields: &["carriage_length"], prices: &["Per Carriage"] },
            prices("rail_length", "Rail Length", &["Per MM Rail"]),
        ],
    },
    Module { subcategory_id: 606, record_types: &[A, B, C, D, GHD_PRICE] },
    Module { subcategory_id: 607, record_types: &[G, H, K, L, GHD_PRICE] },
    Module { subcategory_id: 608, record_types: &[A, B, C, D, G, H, K, L, GHD_PRICE] },
];

/// smart_module(): the module of a subcategory, if it has one.
pub fn module(subcategory_id: i64) -> Option<&'static Module> {
    MODULES.iter().find(|m| m.subcategory_id == subcategory_id)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn only_the_four_smart_subcategories_have_modules() {
        assert_eq!(MODULES.iter().map(|m| m.subcategory_id).collect::<Vec<_>>(), [549, 606, 607, 608]);
        assert!(module(1).is_none());
        let slides = module(549).unwrap();
        assert_eq!(slides.record_type("carriage_length").map(|r| r.fields.len()), Some(1));
        assert_eq!(module(608).unwrap().record_types.len(), 9);
        assert_eq!(module(606).unwrap().record_type("ghd_price").unwrap().prices, GHD);
    }
}

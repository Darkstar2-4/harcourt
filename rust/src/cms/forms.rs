//! Filling a rendered page's `{{gform:N}}` holes with the forms the orders area renders from SQLite.

use super::{FormEmbed, Lang};

pub type Markup<'a> = std::pin::Pin<Box<dyn std::future::Future<Output = Result<String, String>> + Send + 'a>>;

/// Where a page's form markup comes from (tests pass a fixed one).
pub trait FormSource: Send + Sync {
    fn markup<'a>(&'a self, form: &'a FormEmbed<'a>) -> Markup<'a>;
}

/// Gravity Forms as the orders area renders them from SQLite.
pub struct OrdersForms {
    pub orders: std::sync::Arc<crate::orders::Orders>,
}

impl FormSource for OrdersForms {
    fn markup<'a>(&'a self, form: &'a FormEmbed<'a>) -> Markup<'a> {
        Box::pin(async move {
            let lang = match form.lang {
                Lang::En => crate::orders::forms::model::Lang::En,
                Lang::Fr => crate::orders::forms::model::Lang::Fr,
            };
            let embed = crate::orders::forms::render::Embed {
                ajax: form.ajax,
                styles: form.styles.to_string(),
                texturize: form.texturize,
                in_content: form.in_content,
                position: form.position,
            };
            match crate::orders::forms::embedded(&self.orders, i64::from(form.form), lang, form.page_uri, form.user_agent, &embed).await {
                Ok(Some(html)) => Ok(html),
                Ok(None) => Err(format!("form {} is not available", form.form)),
                Err(e) => Err(format!("form {}: {e}", form.form)),
            }
        })
    }
}

/// Replaces each `{{gform:N}}` in `page`, in order, with `markup(N)`. A hole whose form has no markup is an error,
/// so a page never goes out with a form missing.
pub async fn fill<F, Fut>(page: &str, mut markup: F) -> Result<String, String>
where
    F: FnMut(u32, usize) -> Fut,
    Fut: std::future::Future<Output = Result<String, String>>,
{
    let mut out = String::with_capacity(page.len() + 32_000);
    let mut rest = page;
    let mut index = 0;
    while let Some(start) = rest.find("{{gform:") {
        let after = &rest[start + 8..];
        let Some(end) = after.find("}}") else { break };
        let Ok(form) = after[..end].parse::<u32>() else {
            out.push_str(&rest[..start + 8]);
            rest = after;
            continue;
        };
        out.push_str(&rest[..start]);
        out.push_str(&markup(form, index).await?);
        index += 1;
        rest = &after[end + 2..];
    }
    out.push_str(rest);
    Ok(out)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[tokio::test]
    async fn fills_holes_in_order_and_leaves_other_braces() {
        let out = fill("a{{gform:3}}b{{gform:x}}c{{gform:1}}", |f, i| async move { Ok(format!("[{f}#{i}]")) }).await.unwrap();
        assert_eq!(out, "a[3#0]b{{gform:x}}c[1#1]");
        assert!(fill("{{gform:2}}", |_, _| async { Err("down".to_string()) }).await.is_err());
    }
}

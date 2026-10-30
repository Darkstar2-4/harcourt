//! Times as the PHP showed and read them: `date_default_timezone_set("America/Detroit")`, so `date()` and
//! `strtotime()` work in US Eastern time with the post-2007 daylight-saving rules.

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub struct Local {
    pub year: i64,
    pub month: u32,
    pub day: u32,
    pub hour: u32,
    pub minute: u32,
    pub second: u32,
    /// 0 is Sunday, as PHP's `w`.
    pub weekday: u32,
}

const MONTHS: [&str; 12] = ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"];
const DAYS: [&str; 7] = ["Sunday", "Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday"];

fn days_from_civil(y: i64, m: u32, d: u32) -> i64 {
    let y = if m <= 2 { y - 1 } else { y };
    let era = y.div_euclid(400);
    let yoe = y - era * 400;
    let mp = (m as i64 + 9) % 12;
    let doy = (153 * mp + 2) / 5 + d as i64 - 1;
    let doe = yoe * 365 + yoe / 4 - yoe / 100 + doy;
    era * 146097 + doe - 719468
}

fn civil_from_days(z: i64) -> (i64, u32, u32) {
    let z = z + 719468;
    let era = z.div_euclid(146097);
    let doe = z - era * 146097;
    let yoe = (doe - doe / 1460 + doe / 36524 - doe / 146096) / 365;
    let doy = doe - (365 * yoe + yoe / 4 - yoe / 100);
    let mp = (5 * doy + 2) / 153;
    let d = (doy - (153 * mp + 2) / 5 + 1) as u32;
    let m = if mp < 10 { mp + 3 } else { mp - 9 } as u32;
    (if m <= 2 { yoe + era * 400 + 1 } else { yoe + era * 400 }, m, d)
}

/// The day of month of the n-th Sunday (1-based) of a month.
fn nth_sunday(year: i64, month: u32, n: u32) -> u32 {
    let first_weekday = (days_from_civil(year, month, 1) + 4).rem_euclid(7) as u32;
    1 + (7 - first_weekday) % 7 + 7 * (n - 1)
}

/// UTC instants at which daylight time starts and ends in a year.
fn dst_bounds(year: i64) -> (i64, i64) {
    let start = days_from_civil(year, 3, nth_sunday(year, 3, 2)) * 86400 + 7 * 3600;
    let end = days_from_civil(year, 11, nth_sunday(year, 11, 1)) * 86400 + 6 * 3600;
    (start, end)
}

pub fn offset_at(unix: i64) -> i64 {
    let (year, _, _) = civil_from_days(unix.div_euclid(86400));
    let (start, end) = dst_bounds(year);
    if (start..end).contains(&unix) {
        -4 * 3600
    } else {
        -5 * 3600
    }
}

pub fn detroit(unix: i64) -> Local {
    let t = unix + offset_at(unix);
    let days = t.div_euclid(86400);
    let secs = t.rem_euclid(86400);
    let (year, month, day) = civil_from_days(days);
    Local {
        year,
        month,
        day,
        hour: (secs / 3600) as u32,
        minute: (secs % 3600 / 60) as u32,
        second: (secs % 60) as u32,
        weekday: (days + 4).rem_euclid(7) as u32,
    }
}

/// The unix time of a Detroit wall-clock time (`strtotime("Y/m/d H:i:s")`). In the hour skipped in March the
/// standard-time reading is used, in the repeated hour in November the daylight one, as PHP does.
pub fn from_local(year: i64, month: u32, day: u32, hour: u32, minute: u32, second: u32) -> i64 {
    let wall = days_from_civil(year, month, day) * 86400 + hour as i64 * 3600 + minute as i64 * 60 + second as i64;
    let daylight = wall + 4 * 3600;
    if offset_at(daylight) == -4 * 3600 {
        daylight
    } else {
        wall + 5 * 3600
    }
}

/// `Y/m/d`, `Y-m-d` or `m/d/Y` (the date fields of the staff forms), as (year, month, day).
pub fn parse_date(s: &str) -> Option<(i64, u32, u32)> {
    let s = s.trim();
    let parts: Vec<&str> = s.split(['/', '-']).collect();
    if parts.len() != 3 || parts.iter().any(|p| p.is_empty() || !p.bytes().all(|b| b.is_ascii_digit())) {
        return None;
    }
    let n = |i: usize| parts[i].parse::<i64>().ok();
    let (y, m, d) = if parts[0].len() == 4 { (n(0)?, n(1)?, n(2)?) } else { (n(2)?, n(0)?, n(1)?) };
    let y = if parts[0].len() != 4 && parts[2].len() == 2 { 2000 + y } else { y };
    let (m, d) = (u32::try_from(m).ok()?, u32::try_from(d).ok()?);
    let valid = (1..=12).contains(&m) && d >= 1 && civil_from_days(days_from_civil(y, m, d)) == (y, m, d);
    valid.then_some((y, m, d))
}

/// The day of a unix time in Detroit.
pub fn today(unix: i64) -> (i64, u32, u32) {
    let l = detroit(unix);
    (l.year, l.month, l.day)
}

/// The date `days` after (or before) a calendar date.
pub fn add_days(date: (i64, u32, u32), days: i64) -> (i64, u32, u32) {
    civil_from_days(days_from_civil(date.0, date.1, date.2) + days)
}

impl Local {
    /// PHP `date()` for the format letters the staff pages use: d j m n Y y H G h g i s A a D l M F N w.
    /// Any other character is copied; `\` escapes the next one.
    pub fn format(&self, fmt: &str) -> String {
        let h12 = match self.hour % 12 {
            0 => 12,
            h => h,
        };
        let mut out = String::new();
        let mut chars = fmt.chars();
        while let Some(c) = chars.next() {
            match c {
                'd' => out.push_str(&format!("{:02}", self.day)),
                'j' => out.push_str(&self.day.to_string()),
                'm' => out.push_str(&format!("{:02}", self.month)),
                'n' => out.push_str(&self.month.to_string()),
                'Y' => out.push_str(&self.year.to_string()),
                'y' => out.push_str(&format!("{:02}", self.year.rem_euclid(100))),
                'H' => out.push_str(&format!("{:02}", self.hour)),
                'G' => out.push_str(&self.hour.to_string()),
                'h' => out.push_str(&format!("{h12:02}")),
                'g' => out.push_str(&h12.to_string()),
                'i' => out.push_str(&format!("{:02}", self.minute)),
                's' => out.push_str(&format!("{:02}", self.second)),
                'A' => out.push_str(if self.hour < 12 { "AM" } else { "PM" }),
                'a' => out.push_str(if self.hour < 12 { "am" } else { "pm" }),
                'D' => out.push_str(&DAYS[self.weekday as usize][..3]),
                'l' => out.push_str(DAYS[self.weekday as usize]),
                'M' => out.push_str(&MONTHS[self.month as usize - 1][..3]),
                'F' => out.push_str(MONTHS[self.month as usize - 1]),
                'N' => out.push_str(&(if self.weekday == 0 { 7 } else { self.weekday }).to_string()),
                'w' => out.push_str(&self.weekday.to_string()),
                '\\' => {
                    if let Some(next) = chars.next() {
                        out.push(next);
                    }
                }
                c => out.push(c),
            }
        }
        out
    }
}

/// `date($fmt, $unix)`.
pub fn date(fmt: &str, unix: i64) -> String {
    detroit(unix).format(fmt)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn eastern_time_with_daylight_saving() {
        assert_eq!(date("m/d/Y h:i:s A", 1_700_000_000), "11/14/2023 05:13:20 PM");
        assert_eq!(date("m/d/Y H:i", 1_689_000_000), "07/10/2023 10:40");
        assert_eq!(date("Y/m/d H:i", 1_678_604_399), "2023/03/12 01:59");
        assert_eq!(date("Y/m/d H:i", 1_678_604_400), "2023/03/12 03:00");
        assert_eq!(date("Y/m/d H:i", 1_699_163_999), "2023/11/05 01:59");
        assert_eq!(date("Y/m/d H:i", 1_699_164_000), "2023/11/05 01:00");
        assert_eq!(date("D l M F N w j n y g a", 0), "Wed Wednesday Dec December 3 3 31 12 69 7 pm");
    }

    #[test]
    fn local_midnight_round_trips() {
        let t = from_local(2025, 9, 25, 0, 0, 0);
        assert_eq!(date("Y/m/d H:i:s", t), "2025/09/25 00:00:00");
        let w = from_local(2025, 1, 2, 0, 0, 0);
        assert_eq!(date("Y/m/d H:i:s", w), "2025/01/02 00:00:00");
        assert_eq!(w - from_local(2025, 1, 1, 0, 0, 0), 86400);
    }

    #[test]
    fn form_dates_parse_in_either_order() {
        assert_eq!(parse_date("2025/09/25"), Some((2025, 9, 25)));
        assert_eq!(parse_date("09/25/2025"), Some((2025, 9, 25)));
        assert_eq!(parse_date("2025-02-29"), None);
        assert_eq!(parse_date("nope"), None);
        assert_eq!(add_days((2025, 3, 1), -1), (2025, 2, 28));
    }
}

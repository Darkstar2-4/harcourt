//! The site's local time. The PHP ran in America/Detroit; dates it compared and printed are Detroit dates.

#[derive(Debug, Clone, Copy, PartialEq, Eq, PartialOrd, Ord)]
pub struct Date {
    pub year: i32,
    pub month: u32,
    pub day: u32,
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub struct Local {
    pub date: Date,
    pub hour: u32,
    pub minute: u32,
}

/// Unix seconds as wall-clock time in America/Detroit: US Eastern, with the US daylight-saving rules since 2007.
pub fn detroit(unix: i64) -> Local {
    let year = civil(unix.div_euclid(86400)).year;
    let start = (days(year, 3, nth_sunday(year, 3, 2)) * 86400) + 7 * 3600;
    let end = (days(year, 11, nth_sunday(year, 11, 1)) * 86400) + 6 * 3600;
    let offset = if (start..end).contains(&unix) { -4 * 3600 } else { -5 * 3600 };
    let local = unix + offset;
    let secs = local.rem_euclid(86400);
    Local { date: civil(local.div_euclid(86400)), hour: (secs / 3600) as u32, minute: (secs % 3600 / 60) as u32 }
}

impl Date {
    /// PHP date("Y/m/d").
    pub fn slash(&self) -> String {
        format!("{:04}/{:02}/{:02}", self.year, self.month, self.day)
    }
}

impl Local {
    /// PHP date('m/d/Y h:i A'), as the staff notice mails print it.
    pub fn mdy_hm_ampm(&self) -> String {
        let h12 = if self.hour.is_multiple_of(12) { 12 } else { self.hour % 12 };
        let half = if self.hour < 12 { "AM" } else { "PM" };
        format!("{:02}/{:02}/{:04} {h12:02}:{:02} {half}", self.date.month, self.date.day, self.date.year, self.minute)
    }
}

/// Day `n` (1-based) that is the nth Sunday of the month.
fn nth_sunday(year: i32, month: u32, n: u32) -> u32 {
    let weekday = (days(year, month, 1) + 4).rem_euclid(7) as u32;
    1 + (7 - weekday) % 7 + 7 * (n - 1)
}

/// Days since 1970-01-01 (H. Hinnant's days_from_civil).
fn days(year: i32, month: u32, day: u32) -> i64 {
    let y = i64::from(year) - i64::from(month <= 2);
    let era = y.div_euclid(400);
    let yoe = y - era * 400;
    let m = i64::from(month);
    let doy = (153 * (if m > 2 { m - 3 } else { m + 9 }) + 2) / 5 + i64::from(day) - 1;
    let doe = yoe * 365 + yoe / 4 - yoe / 100 + doy;
    era * 146097 + doe - 719468
}

fn civil(z: i64) -> Date {
    let z = z + 719468;
    let era = z.div_euclid(146097);
    let doe = z - era * 146097;
    let yoe = (doe - doe / 1460 + doe / 36524 - doe / 146096) / 365;
    let doy = doe - (365 * yoe + yoe / 4 - yoe / 100);
    let mp = (5 * doy + 2) / 153;
    let day = (doy - (153 * mp + 2) / 5 + 1) as u32;
    let month = if mp < 10 { mp + 3 } else { mp - 9 } as u32;
    let year = (yoe + era * 400 + i64::from(month <= 2)) as i32;
    Date { year, month, day }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn utc(y: i32, mo: u32, d: u32, h: i64, mi: i64) -> i64 {
        days(y, mo, d) * 86400 + h * 3600 + mi * 60
    }

    #[test]
    fn civil_dates_round_trip() {
        for (y, m, d) in [(1970, 1, 1), (2000, 2, 29), (2026, 10, 29), (2100, 12, 31), (1969, 12, 31)] {
            assert_eq!(civil(days(y, m, d)), Date { year: y, month: m, day: d });
        }
        assert_eq!(days(1970, 1, 1), 0);
    }

    #[test]
    fn daylight_saving_starts_and_ends_on_the_us_dates() {
        assert_eq!(nth_sunday(2026, 3, 2), 8);
        assert_eq!(nth_sunday(2026, 11, 1), 1);
        let before = detroit(utc(2026, 3, 8, 6, 59));
        assert_eq!((before.date.day, before.hour, before.minute), (8, 1, 59));
        let after = detroit(utc(2026, 3, 8, 7, 0));
        assert_eq!((after.date.day, after.hour), (8, 3));
        let last_daylight = detroit(utc(2026, 11, 1, 5, 59));
        assert_eq!((last_daylight.hour, last_daylight.minute), (1, 59));
        let standard_again = detroit(utc(2026, 11, 1, 6, 0));
        assert_eq!((standard_again.hour, standard_again.minute), (1, 0));
    }

    #[test]
    fn local_dates_and_times_format_as_php() {
        let t = detroit(utc(2026, 10, 30, 3, 5));
        assert_eq!(t.date.slash(), "2026/10/29");
        assert_eq!(t.mdy_hm_ampm(), "10/29/2026 11:05 PM");
        assert_eq!(detroit(utc(2026, 1, 15, 5, 0)).mdy_hm_ampm(), "01/15/2026 12:00 AM");
        assert_eq!(detroit(utc(2026, 1, 15, 17, 30)).mdy_hm_ampm(), "01/15/2026 12:30 PM");
    }
}

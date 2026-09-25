<?php

/**
 * Unit tests for log_class (classes/log.php) — focused on the Blacklist log
 * type and the update() validation/routing logic that previously caused
 * blacklist log entries to be silently dropped.
 *
 * Uses the same stub-include-path strategy as the other test files.
 */

use PHPUnit\Framework\TestCase;

set_include_path(__DIR__ . '/stubs' . PATH_SEPARATOR . get_include_path());

// ── Stub: global functions ────────────────────────────────────────────────

if (!function_exists('harcourt_remote_addr')) {
    function harcourt_remote_addr(): string { return '127.0.0.1'; }
}

// ── Stub: database layer ──────────────────────────────────────────────────

if (!class_exists('database_meta_class')) {
    class database_meta_class {
        public $rows       = 0;
        public $error      = '';
        public $error_abort = TRUE;
    }
}

if (!class_exists('database_class')) {
    class database_class {
        public $meta;
        private $_queryResult = [];
        private $_cursor      = 0;
        private $_lastInsertId = 0;

        public static $insertedRows     = [];
        public static $insertCounter    = 0;
        public static $tableExists      = true;
        public static $rows             = [];
        public static $lastInsertCounter = 0;

        public function query($q) {
            $this->meta = new database_meta_class();
            if (is_array($q)) $q = implode(' ', $q);

            if (stripos($q, 'insert into log') !== false) {
                self::$insertCounter++;
                $this->_lastInsertId = self::$insertCounter;
                self::$insertedRows[] = $q;
            }
        }
        public function fetch_array() {
            if ($this->_cursor < count($this->_queryResult)) {
                return $this->_queryResult[$this->_cursor++];
            }
            return null;
        }
        public function free_result() {}
        public function insert_id()   { return $this->_lastInsertId; }
        public function where($conditions) { return ''; }

        public static function resetState() {
            self::$insertedRows     = [];
            self::$insertCounter    = 0;
            self::$tableExists      = true;
            self::$rows             = [];
            self::$lastInsertCounter = 0;
        }
    }
}

if (!function_exists('fn_escape')) {
    function fn_escape($value, $quote = TRUE) {
        if ($quote === FALSE) return (strlen($value)) ? $value : "0";
        if ($quote === "null" && !strlen($value)) return "null";
        return "'" . addslashes($value) . "'";
    }
}

if (!function_exists('import_match')) {
    function import_match($text, $data, $forced = TRUE) {
        $key = array_search(strtolower($text), array_map('strtolower', $data));
        if (strlen($key)) return $data[$key];
        if ($forced) return "";
        return $text;
    }
}

// ── Load log class ────────────────────────────────────────────────────────

$_logtest_orig_error_reporting = error_reporting(E_ALL & ~E_NOTICE);

$_SESSION = [
    'user' => (object)[
        'id'     => 1,
        'ip'     => '127.0.0.1',
        'status' => 'Active',
        'bot_id' => null,
    ],
    'harcourt_profile' => (object)['email' => 'admin@harcourt.co'],
];

$database = new stdClass();
require_once dirname(__DIR__, 2) . '/classes/log.php';

error_reporting($_logtest_orig_error_reporting);


// ── Tests ─────────────────────────────────────────────────────────────────

class LogBlacklistTest extends TestCase
{
    /** @var log_class */
    private $log;

    protected function setUp(): void
    {
        global $database;

        $_SESSION['user'] = (object)[
            'id'     => 1,
            'ip'     => '127.0.0.1',
            'status' => 'Active',
            'bot_id' => null,
        ];
        $_SESSION['harcourt_profile'] = (object)['email' => 'admin@harcourt.co'];
        unset($_SESSION['impersonate']);
        $GLOBALS['SCS_API'] = false;
        error_reporting(E_ALL & ~E_NOTICE);

        database_class::resetState();
        $database->log = new log_class();
        $this->log = $database->log;
    }

    // ── Blacklist type is registered ──────────────────────────────────────

    public function test_blacklist_type_exists_in_constants(): void
    {
        $this->assertArrayHasKey('Blacklist', $this->log->constant->type);
    }

    public function test_blacklist_subtypes_include_denied(): void
    {
        $this->assertContains('Denied', $this->log->constant->type['Blacklist']);
    }

    public function test_blacklist_subtypes_include_add(): void
    {
        $this->assertContains('Add', $this->log->constant->type['Blacklist']);
    }

    public function test_blacklist_subtypes_include_remove(): void
    {
        $this->assertContains('Remove', $this->log->constant->type['Blacklist']);
    }

    // ── Blacklist:Denied inserts a log row ────────────────────────────────

    public function test_blacklist_denied_inserts_row(): void
    {
        $options = ['email' => 'user@evil.com', 'comment' => 'Blacklisted domain denied (Microsoft OAuth)'];
        $this->log->update('Blacklist:Denied', $options);

        $this->assertCount(1, database_class::$insertedRows);
    }

    public function test_blacklist_denied_sets_type_field(): void
    {
        $this->log->update('Blacklist:Denied', ['email' => 'x@bad.com', 'comment' => 'test']);

        $this->assertSame('Blacklist', $this->log->data->type);
    }

    public function test_blacklist_denied_sets_subtype_field(): void
    {
        $this->log->update('Blacklist:Denied', ['email' => 'x@bad.com', 'comment' => 'test']);

        $this->assertSame('Denied', $this->log->data->subtype);
    }

    public function test_blacklist_denied_stores_email(): void
    {
        $this->log->update('Blacklist:Denied', ['email' => 'hacker@evil.com', 'comment' => 'test']);

        $this->assertSame('hacker@evil.com', $this->log->data->email);
    }

    public function test_blacklist_denied_stores_comment(): void
    {
        $this->log->update('Blacklist:Denied', ['comment' => 'Blacklisted domain denied (Google OAuth)']);

        $this->assertSame('Blacklisted domain denied (Google OAuth)', $this->log->data->comment);
    }

    public function test_blacklist_denied_returns_id(): void
    {
        $id = $this->log->update('Blacklist:Denied', ['email' => 'x@bad.com', 'comment' => 'test']);

        $this->assertGreaterThan(0, $id);
    }

    // ── Blacklist:Add inserts a log row ───────────────────────────────────

    public function test_blacklist_add_inserts_row(): void
    {
        $this->log->update('Blacklist:Add', ['comment' => 'Domain blacklisted: evil.com']);

        $this->assertCount(1, database_class::$insertedRows);
        $this->assertSame('Blacklist', $this->log->data->type);
        $this->assertSame('Add', $this->log->data->subtype);
    }

    // ── Blacklist:Remove inserts a log row ────────────────────────────────

    public function test_blacklist_remove_inserts_row(): void
    {
        $this->log->update('Blacklist:Remove', ['comment' => 'Domain removed from blacklist: evil.com']);

        $this->assertCount(1, database_class::$insertedRows);
        $this->assertSame('Blacklist', $this->log->data->type);
        $this->assertSame('Remove', $this->log->data->subtype);
    }

    // ── Case insensitivity ───────────────────────────────────────────────

    public function test_blacklist_type_is_case_insensitive(): void
    {
        $this->log->update('blacklist:denied', ['comment' => 'test']);

        $this->assertSame('Blacklist', $this->log->data->type);
        $this->assertCount(1, database_class::$insertedRows);
    }

    public function test_blacklist_subtype_is_case_insensitive(): void
    {
        $this->log->update('Blacklist:DENIED', ['comment' => 'test']);

        $this->assertSame('Denied', $this->log->data->subtype);
    }

    // ── Array-style type:subtype ─────────────────────────────────────────

    public function test_blacklist_accepts_array_format(): void
    {
        $this->log->update(['Blacklist', 'Denied'], ['email' => 'x@bad.com', 'comment' => 'test']);

        $this->assertSame('Blacklist', $this->log->data->type);
        $this->assertSame('Denied', $this->log->data->subtype);
        $this->assertCount(1, database_class::$insertedRows);
    }

    // ── Invalid type is silently dropped ─────────────────────────────────

    public function test_invalid_type_does_not_insert(): void
    {
        $this->log->update('FakeType:Denied', ['comment' => 'test']);

        $this->assertCount(0, database_class::$insertedRows);
    }

    // ── Invalid subtype is silently dropped ──────────────────────────────

    public function test_invalid_subtype_does_not_insert(): void
    {
        $this->log->update('Blacklist:FakeSub', ['comment' => 'test']);

        $this->assertCount(0, database_class::$insertedRows);
    }

    // ── Options mapping ──────────────────────────────────────────────────

    public function test_update_maps_ip_option(): void
    {
        $this->log->update('Blacklist:Denied', ['ip' => '10.0.0.1', 'comment' => 'test']);

        $this->assertSame('10.0.0.1', $this->log->data->ip);
    }

    public function test_update_maps_user_id_option(): void
    {
        $this->log->update('Blacklist:Denied', ['user_id' => 42, 'comment' => 'test']);

        $this->assertSame(42, $this->log->data->user_id);
    }

    public function test_update_maps_comment_array_to_newline_string(): void
    {
        $this->log->update('Blacklist:Denied', ['comment' => ['line 1', 'line 2']]);

        $this->assertSame("line 1\nline 2", $this->log->data->comment);
    }

    // ── Guard: no session → no insert ────────────────────────────────────

    public function test_update_skips_when_no_session_user(): void
    {
        unset($_SESSION['user']);

        $this->log->update('Blacklist:Denied', ['comment' => 'test']);

        $this->assertCount(0, database_class::$insertedRows);
    }

    // ── Guard: impersonate → no insert ───────────────────────────────────

    public function test_update_skips_when_impersonating(): void
    {
        $_SESSION['impersonate'] = true;

        $this->log->update('Blacklist:Denied', ['comment' => 'test']);

        $this->assertCount(0, database_class::$insertedRows);
    }

    // ── Existing types still work ────────────────────────────────────────

    public function test_user_signup_still_inserts(): void
    {
        $this->log->update('User:Signup', ['comment' => 'new user']);

        $this->assertCount(1, database_class::$insertedRows);
        $this->assertSame('User', $this->log->data->type);
        $this->assertSame('Signup', $this->log->data->subtype);
    }

    public function test_empty_subtype_array_allows_freeform_subtype(): void
    {
        $this->log->update('CRON:daily_cleanup', ['comment' => 'ran ok', 'cron' => 1]);

        $this->assertCount(1, database_class::$insertedRows);
        $this->assertSame('CRON', $this->log->data->type);
        $this->assertSame('daily_cleanup', $this->log->data->subtype);
    }

    // ── Email type special case (no subtype required) ────────────────────

    public function test_email_type_inserts_without_subtype(): void
    {
        $this->log->update('Email:', ['comment' => 'sent ok']);

        $this->assertCount(1, database_class::$insertedRows);
        $this->assertSame('Email', $this->log->data->type);
    }
}

<?php

/**
 * Unit tests for the User Manager admin script (user.php).
 *
 * user.php is a procedural script (not a class) that, on action=update, lets an
 * administrator allow ("Active") or deny ("Denied") a user's access request and
 * sync the per-user "Category Access" restrictions (user_category join table)
 * driven by the tab_category() tab added alongside it.
 *
 * Because the allow/deny + category-sync logic runs inline at include-time
 * (not inside a reusable function), each scenario needs a fresh execution of
 * the script with different $_POST/$_REQUEST state. Tests run in separate
 * processes so user.php can be required freshly every time without redeclaring
 * its top-level functions (tab_info/tab_category).
 *
 * Stub strategy mirrors NewUserTest.php / BlacklistTest.php: stubs/scs_header.php
 * is resolved first via include_path so the real dependency chain never loads;
 * classes/portal.php and classes/industry.php (required unconditionally by
 * user.php) are the real files, backed by a minimal database_class stub.
 */

use PHPUnit\Framework\TestCase;

set_include_path(__DIR__ . '/stubs' . PATH_SEPARATOR . dirname(__DIR__, 2) . PATH_SEPARATOR . get_include_path());

// ── Stub: global functions normally provided by scs_header.php ─────────────

if (!function_exists('fn_escape')) {
    function fn_escape($value, $quote = TRUE) {
        if ($quote === FALSE) return (strlen($value)) ? $value : "0";
        if ($quote === "null" && !strlen($value)) return "null";
        return "'" . addslashes($value) . "'";
    }
}
if (!function_exists('fn_number')) {
    function fn_number($v) { return is_numeric($v) ? floatval($v) : 0; }
}
if (!function_exists('fn_text')) {
    function fn_text($v) { return is_string($v) ? trim($v) : ''; }
}
if (!function_exists('fn_url_redirect')) {
    function fn_url_redirect($url) {}
}

// ── Stub: minimal database base class (classes/portal.php & classes/industry.php extend this) ──

if (!class_exists('database_meta_class')) {
    class database_meta_class {
        public $rows = 0;
        public $error = 0;
        public $found_rows = 0;
    }
}
if (!class_exists('database_class')) {
    class database_class {
        public $meta;
        function query($q) { $this->meta = new database_meta_class(); }
        function fetch_array() { return false; }
        function free_result() {}
        function where($conditions) { return ''; }
        function insert_id() { return 0; }
    }
}

// ── Stub: address / profile helpers used by the update action ──────────────

if (!class_exists('profile_data_class')) {
    class profile_data_class {}
}
if (!class_exists('address_class')) {
    class address_class {
        public $data;
        public $registry;
        function __construct($type, $data, $opts = []) {
            $this->data     = (object) ['email' => ''];
            $this->registry = new stdClass();
        }
        function verify() {}
    }
}

// ── Stub: user record (in-memory, records what would have been persisted) ──

if (!class_exists('user_data_class')) {
    class user_data_class {
        public $id                  = 0;
        public $ip                  = '';
        public $status               = 'Pending';
        public $type                 = 'Customer';
        public $currency_code        = 'USD';
        public $language_code        = 'EN';
        public $portal_id            = 0;
        public $login_document_id    = 0;
        public $landing_document_id  = 0;
        public $customer_code        = '';
        public $catalog              = 0;
        public $ecommerce            = 0;
        public $price_type           = '';
        public $price_pct            = 0;
        public $cad                  = 0;
        public $industry_id          = 0;
        public $leadscore            = 0;
        public $revenue              = '';
        public $employees            = '';
        public $comment              = '';
        public $company_name         = '';
        public $last_login           = 0;
    }
}

if (!class_exists('UserManagerStubUser')) {
    class UserManagerStubUser {
        public $data;
        public $meta;
        public $constant;
        public $updateCalled = false;
        public $updatedSnapshot;

        function __construct() {
            $this->data     = new user_data_class();
            $this->meta     = (object) ['rows' => 0, 'error' => 0];
            $this->constant = (object) ['status' => ['Pending', 'Active', 'Denied']];
        }
        function read($id) {
            // Test pre-seeds $this->data to represent the record on file; production
            // would re-fetch it here, but the stub already holds the fixture state.
        }
        function type_list() { return ['Customer', 'Administrator']; }
        function update($rows) {
            $this->updateCalled    = true;
            $this->updatedSnapshot = clone $this->data;
        }
        function free_result() {}
        function access($role, $deny = TRUE) { return TRUE; }
    }
}

// ── Stub: category catalog + user_category join table ──────────────────────

if (!class_exists('UserManagerStubCategory')) {
    class UserManagerStubCategory {
        public $rows = [];
        public $fetch;
        public $data;
        private $cursor = 0;

        function query($sql) { $this->cursor = 0; }
        function fetch_array() {
            if ($this->cursor < count($this->rows)) return $this->rows[$this->cursor++];
            return false;
        }
        function fetch($f = false) {
            $this->data = (object) ['id' => $this->fetch['id'], 'name' => $this->fetch['name']];
        }
        function free_result() {}
    }
}

if (!class_exists('UserManagerStubTemp')) {
    class UserManagerStubTemp {
        public $fetch;
        public $queryLog    = [];
        public $fixtureRows = [];
        private $cursor     = 0;
        private $selecting  = false;

        function query($sql) {
            $this->queryLog[] = $sql;
            $this->selecting  = (stripos($sql, 'select') !== false);
            $this->cursor     = 0;
        }
        function fetch_array() {
            if (!$this->selecting) return false;
            if ($this->cursor < count($this->fixtureRows)) return $this->fixtureRows[$this->cursor++];
            return false;
        }
        function free_result() {}
    }
}

// ── Stub: $forms / $menu ─────────────────────────────────────────────────

if (!class_exists('UserManagerStubForms')) {
    class UserManagerStubForms {
        public $report     = [];
        public $error       = [];
        public $error_text  = [];
        public $message     = [];
        public $constant;

        function __construct() { $this->constant = new stdClass(); }
        function title($t) {}
        function error($field) {
            $args = func_get_args();
            $text = '';
            for ($i = count($args) - 1; $i >= 1; $i--) {
                if (is_string($args[$i]) && strlen($args[$i])) { $text = $args[$i]; break; }
            }
            $this->error[$field]      = true;
            $this->error_text[$field] = $text;
        }
        function message() { return implode('', $this->message); }
        function open($a = null, $b = null)  { return '<form>'; }
        function hidden($name, $val = null)  { return ''; }
        function close()                     { return '</form>'; }
        function select($name, $opts = [], $val = null, $blank = true, $o = []) { return ''; }
        function text($name, $val = '', $w = null, $m = null, $t = null, $o = []) { return ''; }
        function button($label, $o = [])     { return ''; }
        function checkbox($name, $val, $checked, $o = []) {
            return $checked ? "<input type=checkbox name='{$name}' checked>" : "<input type=checkbox name='{$name}'>";
        }
        function radio($name, $val, $checked, $o = []) { return ''; }
    }
}

if (!class_exists('UserManagerStubMenu')) {
    class UserManagerStubMenu {
        function head() {}
        function copyright() {}
    }
}

// ── Stub: $database->log / $database->captcha_bypass ───────────────────────
// Backs the "auto-add signup email to captcha_bypass on Pending→Active
// approval" behavior: user.php looks up the user:signup log row for the
// user_id being approved (that's where the registrant's email lives, since
// user_data_class itself has no email field) and, if found, inserts it into
// captcha_bypass unless it's already there.

if (!class_exists('captcha_bypass_data_class')) {
    class captcha_bypass_data_class {
        public $id = 0;
        public $email;
        public $comment;
        public $created = 0;
    }
}

if (!class_exists('UserManagerStubLog')) {
    class UserManagerStubLog {
        public $meta;
        public $fixtureRows = [];
        public $queryLog    = [];
        private $cursor     = 0;

        function __construct() { $this->meta = (object) ['rows' => 0]; }
        function query($sql) {
            $this->queryLog[] = is_array($sql) ? implode(' ', $sql) : $sql;
            $this->cursor     = 0;
            $this->meta->rows = count($this->fixtureRows);
        }
        function fetch_array() {
            if ($this->cursor < count($this->fixtureRows)) return $this->fixtureRows[$this->cursor++];
            return false;
        }
        function free_result() {}
    }
}

if (!class_exists('UserManagerStubCaptchaBypass')) {
    class UserManagerStubCaptchaBypass {
        public $meta;
        public $data;
        public $store = [];

        function __construct() {
            $this->meta = (object) ['rows' => 0, 'error' => 0];
            $this->data = new captcha_bypass_data_class();
        }
        function read($value, $field = 'id') {
            $found = null;
            foreach ($this->store as $row) {
                if ($field === 'email' && strtolower($row->email) === strtolower($value)) { $found = $row; break; }
                if ($field === 'id' && (int) $row->id === (int) $value) { $found = $row; break; }
            }
            if ($found) {
                $this->data       = $found;
                $this->meta->rows = 1;
            } else {
                $this->data       = new captcha_bypass_data_class();
                $this->meta->rows = 0;
            }
        }
        function update($update = false) {
            if (!$update) {
                $this->data->id = count($this->store) + 1;
                $this->store[]  = $this->data;
            }
        }
    }
}


// ── Tests ─────────────────────────────────────────────────────────────────

class UserManagerTest extends TestCase
{
    private function defaultRequest(array $overrides = []): array {
        return array_merge([
            'action'     => 'update',
            'record_id'  => 42,
            'return_url' => '',
        ], $overrides);
    }

    private function defaultPost(array $overrides = []): array {
        return array_merge([
            'page'                 => '',
            'report_status'        => '',
            'report_type'          => '',
            'report_option_field'  => '',
            'report_option_value'  => '',
            'ajax_user_id'         => 0,
            'ip'                   => '203.0.113.5',
            'status'               => 'Active',
            'type'                 => 'Customer',
            'currency_code'        => 'USD',
            'language_code'        => 'EN',
            'portal_id'            => 0,
            'login_document_id'    => 0,
            'landing_document_id'  => 0,
            'customer_code'        => '',
            'catalog'              => 0,
            'ecommerce'            => 0,
            'price_type'           => '',
            'price_pct'            => 0,
            'cad'                  => 0,
            'industry_id'          => 0,
            'leadscore'            => 0,
            'revenue'              => '',
            'employees'            => '',
            'ipstack'              => 0,
            'user_category_1'      => 0,
            'user_category_2'      => 0,
            'user_category_3'      => 0,
        ], $overrides);
    }

    /**
     * Requires user.php with the given globals/superglobals and returns its
     * captured HTML output. Must be called from a @runInSeparateProcess test.
     */
    private function runUserManager(array $requestOverrides, array $postOverrides, UserManagerStubUser $user, UserManagerStubCategory $category, UserManagerStubTemp $temp, array $signupLogRows = [], array $captchaBypassSeed = []): string {
        global $database, $forms, $menu;

        $database                = new stdClass();
        $database->user          = $user;
        $database->category      = $category;
        $database->temp          = $temp;
        $database->profile       = new stdClass();
        $database->log           = new UserManagerStubLog();
        $database->log->fixtureRows = $signupLogRows;
        $database->captcha_bypass = new UserManagerStubCaptchaBypass();
        $database->captcha_bypass->store = $captchaBypassSeed;

        $forms = new UserManagerStubForms();
        $menu  = new UserManagerStubMenu();

        $_REQUEST = $this->defaultRequest($requestOverrides);
        $_POST    = $this->defaultPost($postOverrides);

        ob_start();
        require dirname(__DIR__, 2) . '/user.php';
        return ob_get_clean();
    }

    private function categoryFixture(): array {
        return [
            ['id' => 1, 'name' => 'Books'],
            ['id' => 2, 'name' => 'Software'],
            ['id' => 3, 'name' => 'Hardware'],
        ];
    }

    private function pendingUser(): UserManagerStubUser {
        $user = new UserManagerStubUser();
        $user->data->id     = 42;
        $user->data->ip     = '198.51.100.7';
        $user->data->status = 'Pending';
        return $user;
    }

    // ── Admin allow/deny ─────────────────────────────────────────────────

    /** @runInSeparateProcess @preserveGlobalState disabled */
    public function test_admin_approves_pending_user_activates_and_persists(): void {
        $user     = $this->pendingUser();
        $category = new UserManagerStubCategory();
        $category->rows = $this->categoryFixture();
        $temp = new UserManagerStubTemp();

        $this->runUserManager([], ['status' => 'Active'], $user, $category, $temp);

        $this->assertSame('Active', $user->data->status);
        $this->assertTrue($user->updateCalled);
        $this->assertSame('Active', $user->updatedSnapshot->status);
    }

    /** @runInSeparateProcess @preserveGlobalState disabled */
    public function test_admin_denies_pending_user_and_persists(): void {
        $user     = $this->pendingUser();
        $category = new UserManagerStubCategory();
        $category->rows = $this->categoryFixture();
        $temp = new UserManagerStubTemp();

        $this->runUserManager([], ['status' => 'Denied'], $user, $category, $temp);

        $this->assertSame('Denied', $user->data->status);
        $this->assertTrue($user->updateCalled);
        $this->assertSame('Denied', $user->updatedSnapshot->status);
    }

    // ── Approval → captcha_bypass (looked up via the user:signup log row) ──

    /** @runInSeparateProcess @preserveGlobalState disabled */
    public function test_approving_pending_user_adds_signup_email_to_captcha_bypass(): void {
        global $database;
        $user     = $this->pendingUser();
        $category = new UserManagerStubCategory();
        $category->rows = $this->categoryFixture();
        $temp = new UserManagerStubTemp();

        $this->runUserManager([], ['status' => 'Active'], $user, $category, $temp, [
            ['email' => 'new.customer@example.com'],
        ]);

        $this->assertCount(1, $database->captcha_bypass->store);
        $this->assertSame('new.customer@example.com', $database->captcha_bypass->store[0]->email);
    }

    /** @runInSeparateProcess @preserveGlobalState disabled */
    public function test_approving_pending_user_without_signup_log_leaves_captcha_bypass_untouched(): void {
        global $database;
        $user     = $this->pendingUser();
        $category = new UserManagerStubCategory();
        $category->rows = $this->categoryFixture();
        $temp = new UserManagerStubTemp();

        $this->runUserManager([], ['status' => 'Active'], $user, $category, $temp);

        $this->assertCount(0, $database->captcha_bypass->store);
    }

    /** @runInSeparateProcess @preserveGlobalState disabled */
    public function test_denying_user_does_not_add_to_captcha_bypass(): void {
        global $database;
        $user     = $this->pendingUser();
        $category = new UserManagerStubCategory();
        $category->rows = $this->categoryFixture();
        $temp = new UserManagerStubTemp();

        $this->runUserManager([], ['status' => 'Denied'], $user, $category, $temp, [
            ['email' => 'new.customer@example.com'],
        ]);

        $this->assertCount(0, $database->captcha_bypass->store);
    }

    /** @runInSeparateProcess @preserveGlobalState disabled */
    public function test_resaving_already_active_user_does_not_requery_signup_log(): void {
        global $database;
        $user = $this->pendingUser();
        $user->data->status = 'Active';
        $category = new UserManagerStubCategory();
        $category->rows = $this->categoryFixture();
        $temp = new UserManagerStubTemp();

        $this->runUserManager([], ['status' => 'Active'], $user, $category, $temp, [
            ['email' => 'new.customer@example.com'],
        ]);

        $this->assertCount(0, $database->log->queryLog, 'Should not look up the signup log when the user was already Active.');
        $this->assertCount(0, $database->captcha_bypass->store);
    }

    /** @runInSeparateProcess @preserveGlobalState disabled */
    public function test_approving_pending_user_already_in_captcha_bypass_is_not_duplicated(): void {
        global $database;
        $user     = $this->pendingUser();
        $category = new UserManagerStubCategory();
        $category->rows = $this->categoryFixture();
        $temp = new UserManagerStubTemp();

        $existing = new captcha_bypass_data_class();
        $existing->id    = 1;
        $existing->email = 'new.customer@example.com';

        $this->runUserManager([], ['status' => 'Active'], $user, $category, $temp, [
            ['email' => 'new.customer@example.com'],
        ], [$existing]);

        $this->assertCount(1, $database->captcha_bypass->store, 'Duplicate email should not create a second row.');
    }

    /** @runInSeparateProcess @preserveGlobalState disabled */
    public function test_update_shows_confirmation_message_on_success(): void {
        $user     = $this->pendingUser();
        $category = new UserManagerStubCategory();
        $category->rows = $this->categoryFixture();
        $temp = new UserManagerStubTemp();

        $output = $this->runUserManager([], ['status' => 'Active'], $user, $category, $temp);

        $this->assertStringContainsString('Record maintained', $output);
    }

    // ── Category Access tab: persistence (admin restricting catalog access) ─

    /** @runInSeparateProcess @preserveGlobalState disabled */
    public function test_category_sync_deletes_previous_blocks_before_reapplying(): void {
        $user     = $this->pendingUser();
        $category = new UserManagerStubCategory();
        $category->rows = $this->categoryFixture();
        $temp = new UserManagerStubTemp();

        $this->runUserManager([], ['status' => 'Active'], $user, $category, $temp);

        $this->assertStringContainsString('DELETE FROM user_category', $temp->queryLog[0]);
        $this->assertStringContainsString('user_id=42', $temp->queryLog[0]);
    }

    /** @runInSeparateProcess @preserveGlobalState disabled */
    public function test_category_sync_inserts_rows_only_for_checked_categories(): void {
        $user     = $this->pendingUser();
        $category = new UserManagerStubCategory();
        $category->rows = $this->categoryFixture();
        $temp = new UserManagerStubTemp();

        $this->runUserManager([], [
            'status'           => 'Active',
            'user_category_2'  => 1,
            'user_category_3'  => 1,
        ], $user, $category, $temp);

        $inserts = implode("\n", $temp->queryLog);
        $this->assertStringContainsString('VALUES (42,2)', $inserts);
        $this->assertStringContainsString('VALUES (42,3)', $inserts);
        $this->assertStringNotContainsString('VALUES (42,1)', $inserts);
    }

    /** @runInSeparateProcess @preserveGlobalState disabled */
    public function test_category_sync_removes_all_blocks_when_no_categories_checked(): void {
        $user     = $this->pendingUser();
        $category = new UserManagerStubCategory();
        $category->rows = $this->categoryFixture();
        $temp = new UserManagerStubTemp();

        $this->runUserManager([], ['status' => 'Active'], $user, $category, $temp);

        $this->assertCount(1, $temp->queryLog);
        $this->assertStringContainsString('DELETE FROM user_category', $temp->queryLog[0]);
    }

    // ── Category Access tab: rendering ──────────────────────────────────

    /** @runInSeparateProcess @preserveGlobalState disabled */
    public function test_tab_category_prechecks_boxes_for_currently_blocked_categories(): void {
        $user     = $this->pendingUser();
        $category = new UserManagerStubCategory();
        $category->rows = $this->categoryFixture();
        $temp = new UserManagerStubTemp();
        $temp->fixtureRows = [['category_id' => 2]];

        $this->runUserManager(['action' => ''], [], $user, $category, $temp);
        $html = implode('', tab_category());

        $this->assertStringContainsString("name='user_category_2' checked", $html);
    }

    /** @runInSeparateProcess @preserveGlobalState disabled */
    public function test_tab_category_leaves_unblocked_categories_unchecked(): void {
        $user     = $this->pendingUser();
        $category = new UserManagerStubCategory();
        $category->rows = $this->categoryFixture();
        $temp = new UserManagerStubTemp();
        $temp->fixtureRows = [['category_id' => 2]];

        $this->runUserManager(['action' => ''], [], $user, $category, $temp);
        $html = implode('', tab_category());

        $this->assertStringContainsString("name='user_category_1'>", $html);
        $this->assertStringContainsString("name='user_category_3'>", $html);
        $this->assertStringNotContainsString("name='user_category_1' checked", $html);
        $this->assertStringNotContainsString("name='user_category_3' checked", $html);
    }

    /** @runInSeparateProcess @preserveGlobalState disabled */
    public function test_tab_category_lists_every_active_category_by_name(): void {
        $user     = $this->pendingUser();
        $category = new UserManagerStubCategory();
        $category->rows = $this->categoryFixture();
        $temp = new UserManagerStubTemp();

        $this->runUserManager(['action' => ''], [], $user, $category, $temp);
        $html = implode('', tab_category());

        $this->assertStringContainsString('Books', $html);
        $this->assertStringContainsString('Software', $html);
        $this->assertStringContainsString('Hardware', $html);
    }

    /** @runInSeparateProcess @preserveGlobalState disabled */
    public function test_tab_category_shows_no_blocks_for_new_user_without_saved_id(): void {
        $user = new UserManagerStubUser(); // fresh, unsaved: id defaults to 0
        $category = new UserManagerStubCategory();
        $category->rows = $this->categoryFixture();
        $temp = new UserManagerStubTemp();
        $temp->fixtureRows = [['category_id' => 1]]; // would be blocked if id were looked up

        $this->runUserManager(['action' => '', 'record_id' => 0], [], $user, $category, $temp);
        $html = implode('', tab_category());

        $this->assertStringNotContainsString('checked', $html);
    }
}

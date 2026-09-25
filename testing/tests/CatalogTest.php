<?php

/**
 * Unit tests for the /catalog page (catalog.php's catalog_output_class).
 *
 * Covers:
 *  - page-level access control (constructor gating on session status/catalog flag)
 *  - blocked_categories() — the per-user category restriction lookup (user_category)
 *  - output_summary() — the category overlay + subcategory link suppression it drives
 *    on the catalog summary page
 *  - inventory() — the per-SKU link suppression it drives on the subcategory table
 *
 * catalog.php auto-instantiates catalog_output_class at file scope, and its
 * constructor does substantial DB/session work, so:
 *  - Tests that only need to exercise a single method (blocked_categories,
 *    output_summary, inventory) require the file once, then build a bare instance
 *    via ReflectionClass::newInstanceWithoutConstructor() and set just the
 *    properties that method reads.
 *  - Tests of the constructor's own access-control logic must run in separate
 *    processes (like UserManagerTest.php's admin-action tests), since the
 *    auto-instantiation reruns on every require and can't be redeclared.
 *
 * Stub strategy mirrors UserManagerTest.php: stubs/scs_header.php,
 * stubs/document_viewer.php and stubs/classes/{portaloutput,configure}.php
 * are resolved first via include_path so the real (heavy) dependency chains
 * never load; classes/search.php is the real, self-contained file.
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
if (!function_exists('fn_base64')) {
    function fn_base64($v, $encode = TRUE) { return is_array($v) ? implode('~', $v) : $v; }
}
if (!function_exists('fn_href')) {
    function fn_href($text, $url, $query = [], $opts = []) { return "<a href='#'>{$text}</a>"; }
}

// ── Stub: minimal database base class (classes/search.php extends this) ────

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

// ── Stub: configure_class (real classes/configure.php is stubbed out) ──────

if (!class_exists('configure_class')) {
    class configure_class {
        function __construct($a = '') {}
        function currency_name() { return ''; }
        function inventory_msrp() {}
        function output_simple() { return ''; }
        function output_verbose() { return ''; }
    }
}

// ── Stub: generic query/fetch table (category, subcategory, content, temp) ─

if (!class_exists('CatalogStubTable')) {
    class CatalogStubTable {
        public $rows = [];
        // Rows served for queries against company_category_block, kept separate
        // from $rows (user_category) since blocked_categories() issues both
        // queries against the same $database->temp stub instance.
        public $companyRows = [];
        public $fetch;
        public $data;
        public $meta;
        public $constant;
        private $cursor = 0;
        private $activeRows = [];

        function __construct() { $this->meta = (object) ['rows' => 0, 'error' => 0, 'found_rows' => 0]; }
        function query($sql) {
            $this->cursor = 0;
            $sql_text = is_array($sql) ? implode(" ", $sql) : $sql;
            $this->activeRows = (stripos($sql_text, 'company_category_block') !== false) ? $this->companyRows : $this->rows;
            $this->meta->rows = count($this->activeRows);
        }
        function fetch_array() {
            if ($this->cursor < count($this->activeRows)) return $this->activeRows[$this->cursor++];
            return false;
        }
        function fetch($f = false) { $this->data = (object) $this->fetch; }
        function free_result() {}
        function read($id) {}
    }
}

if (!class_exists('CatalogStubInventory')) {
    class CatalogStubInventory {
        public $data;
        public $meta;
        function new_date() { return 0; }
        function leadtime() { return ''; }
    }
}

if (!class_exists('CatalogStubProfile')) {
    class CatalogStubProfile {
        public $data;
        public $forcedResult = true;
        function __construct() { $this->data = (object) ['cad_name' => '']; }
        function load() {}
        function forced() { return $this->forcedResult; }
        function enter($opts = []) { return ''; }
        function quick_cad_message() { return ''; }
    }
}

if (!class_exists('CatalogStubNoop')) {
    class CatalogStubNoop {
        public $meta;
        public $data;
        function __call($name, $args) { return null; }
    }
}

if (!class_exists('query_where')) {
    class query_where {
        public $field, $operator, $value, $and_or, $type;
        function __construct($field, $operator, $value, $and_or = "", $type = "") {
            $this->field    = $field;
            $this->operator = strtolower($operator);
            $this->value    = $value;
            $this->and_or   = strtolower($and_or);
            $this->type     = strtolower($type);
        }
    }
}

if (!class_exists('CatalogStubDatabase')) {
    class CatalogStubDatabase {
        function where($conditions) { return ''; }
        function item_limit($item) { return ''; }
        function page_limit($page) { return ''; }
        function query_limit() { return 0; }
    }
}

if (!class_exists('CatalogStubMenu')) {
    class CatalogStubMenu {
        public $page;
        public $configure;
        function __construct() { $this->page = (object) ['products' => (object) ['url' => '/catalog.php']]; }
        function head($options = []) {}
        function copyright() {}
        function language($key) { return $key; }
    }
}

// ── Stub: jquery_tab_class (real tabs class lives outside the stubbed chain) ─

if (!class_exists('CatalogStubTabs')) {
    class CatalogStubTabs {
        public $items = [];
        function item($name, $content, $opts = []) {
            $id = $opts['id'] ?? $name;
            $this->items[$id] = is_array($content) ? implode('', $content) : $content;
        }
        function landing_tab() {}
        function options($a, $b = []) {}
        function output_order($a = null, $b = null) {}
        function output() {}
    }
}

if (!class_exists('CatalogStubForms')) {
    class CatalogStubForms {
        public $message = [];
        public $title_constant = '';
        public $html;
        function __construct() { $this->html = new CatalogStubFormsHtml(); }
        function title($t) {}
        function message() { return implode('', $this->message); }
        function open($a = null, $b = null) { return '<form>'; }
        function hidden($name, $val = null) { return ''; }
        function close() { return '</form>'; }
        function font($color, $text, $suffix = '') { return $text; }
        function checkbox($name, $val, $checked, $o = []) { return ''; }
        function text($name, $val = '', $w = null, $m = null, $t = null, $o = []) { return ''; }
        function img($src, $o = []) { return ''; }
    }
}
if (!class_exists('CatalogStubFormsHtml')) {
    class CatalogStubFormsHtml {
        function script($type, $src) {}
    }
}


// ── Tests ─────────────────────────────────────────────────────────────────

class CatalogTest extends TestCase
{
    private function defaultGet(array $overrides = []): array {
        return array_merge([
            'action'          => '',
            'scs_page'        => 0,
            'item'            => 0,
            'document'        => 0,
            'new_date'        => 0,
            'search_source'   => '',
            'portal_category' => '',
            'search_code'     => '|',
            'search_complete' => '',
            'sku'             => '',
            'category_id'     => 0,
            'subcategory_id'  => 0,
            'debug'           => 0,
        ], $overrides);
    }

    private function defaultSessionUser(array $overrides = []): stdClass {
        $user = (object) array_merge([
            'id'                 => 42,
            'status'             => 'Active',
            'catalog'            => 1,
            'portal_id'          => 0,
            'language_code'      => 'EN',
            'landing_document_id'=> 0,
            'price_type'         => '',
            'ecommerce'          => 0,
            'bot_code'           => null, // isset() is still false for null; direct reads stay notice-free
        ], $overrides);
        return $user;
    }

    /**
     * Requires catalog.php with the given session/GET state. Sets up every
     * stub sub-object catalog.php's constructor touches, captures the
     * auto-instantiated $obj (a local to this method's scope, since catalog.php's
     * top-level code runs within it via PHP's include-scope sharing) and returns
     * it along with the captured HTML output. Must be called from a
     * @runInSeparateProcess test.
     *
     * @return array{0: string, 1: catalog_output_class}
     */
    private function requireCatalog(array $getOverrides, stdClass $sessionUser, bool $profileForced = true): array {
        global $database, $forms, $menu;

        $database              = new CatalogStubDatabase();
        $database->user         = new CatalogStubNoop();
        $database->category    = new CatalogStubTable();
        $database->subcategory = new CatalogStubTable();
        $database->subcategory->constant = (object) ['parameters' => 0];
        $database->content     = new CatalogStubTable();
        $database->temp        = new CatalogStubTable();
        $database->inventory    = new CatalogStubInventory();
        $database->profile      = new CatalogStubProfile();
        $database->profile->forcedResult = $profileForced;
        $database->document     = new CatalogStubNoop();
        $database->log          = new CatalogStubNoop();
        $database->registry     = (object) ['local' => (object) ['filmstrip_timestamp' => 0]];

        $forms = new CatalogStubForms();
        $menu  = new CatalogStubMenu();

        $_SESSION['user']       = $sessionUser;
        $_GET                   = $this->defaultGet($getOverrides);
        $_REQUEST['debug']      = 0;
        $_SERVER['HTTP_REFERER'] = '';
        $_SERVER['REQUEST_URI']  = '/catalog.php';

        ob_start();
        require dirname(__DIR__, 2) . '/catalog.php';
        $output = ob_get_clean();

        return [$output, $obj];
    }

    /**
     * Loads catalog.php's class definition (via a minimal, access-denied
     * construction) and returns a bare instance for direct method testing,
     * bypassing the heavy constructor via Reflection.
     */
    private function bareCatalogInstance(): catalog_output_class {
        $this->requireCatalog([], $this->defaultSessionUser(['catalog' => 0]));
        $ref = new ReflectionClass('catalog_output_class');
        return $ref->newInstanceWithoutConstructor();
    }

    private function subcategoryFixture(array $overrides = []): stdClass {
        return (object) array_merge([
            'id'               => 2,
            'name'             => 'Software',
            'category_id'      => 2,
            'new_date'         => 0,
            'status'           => 'Active',
            'options'          => [],
            'parameters'       => [],
            'parameter_search' => [],
            'page_break'       => 0,
            'output'           => 0,
            'description'      => '',
            'prj'              => 0,
        ], $overrides);
    }

    // ── /catalog page access control ────────────────────────────────────

    /** @runInSeparateProcess @preserveGlobalState disabled */
    public function test_active_user_with_catalog_flag_is_granted_access(): void {
        // profile "not forced" gives a light exit that still computes $this->access first.
        [, $obj] = $this->requireCatalog([], $this->defaultSessionUser(), false);

        $this->assertTrue($obj->access);
    }

    /** @runInSeparateProcess @preserveGlobalState disabled */
    public function test_user_without_catalog_flag_is_denied_access(): void {
        [, $obj] = $this->requireCatalog([], $this->defaultSessionUser(['catalog' => 0]));

        $this->assertFalse($obj->access);
    }

    /** @runInSeparateProcess @preserveGlobalState disabled */
    public function test_logged_out_user_is_denied_access(): void {
        [, $obj] = $this->requireCatalog([], $this->defaultSessionUser(['id' => 0]));

        $this->assertFalse($obj->access);
    }

    // ── blocked_categories() ─────────────────────────────────────────────

    /** @runInSeparateProcess @preserveGlobalState disabled */
    public function test_blocked_categories_returns_ids_from_user_category_table(): void {
        global $database;
        $obj = $this->bareCatalogInstance();
        $_SESSION['user'] = $this->defaultSessionUser();
        $database->temp = new CatalogStubTable();
        $database->temp->rows = [['category_id' => 3], ['category_id' => 5]];

        $this->assertSame([3, 5], $obj->blocked_categories());
    }

    /** @runInSeparateProcess @preserveGlobalState disabled */
    public function test_blocked_categories_ignores_bot_sessions(): void {
        global $database;
        $obj = $this->bareCatalogInstance();
        $_SESSION['user'] = $this->defaultSessionUser();
        $_SESSION['user']->bot_code = 'GOOGLEBOT';
        $database->temp = new CatalogStubTable();
        $database->temp->rows = [['category_id' => 3]];

        $this->assertSame([], $obj->blocked_categories());
    }

    /** @runInSeparateProcess @preserveGlobalState disabled */
    public function test_blocked_categories_empty_for_logged_out_user(): void {
        global $database;
        $obj = $this->bareCatalogInstance();
        $_SESSION['user'] = $this->defaultSessionUser(['id' => 0]);
        $database->temp = new CatalogStubTable();
        $database->temp->rows = [['category_id' => 3]];

        $this->assertSame([], $obj->blocked_categories());
    }

    /** @runInSeparateProcess @preserveGlobalState disabled */
    public function test_blocked_categories_merges_company_level_blocks(): void {
        global $database;
        $obj = $this->bareCatalogInstance();
        $_SESSION['user'] = $this->defaultSessionUser(['company_name' => 'Acme Corp']);
        $database->temp = new CatalogStubTable();
        $database->temp->rows = [['category_id' => 3]];
        $database->temp->companyRows = [['category_id' => 7]];

        $this->assertSame([3, 7], $obj->blocked_categories());
    }

    /** @runInSeparateProcess @preserveGlobalState disabled */
    public function test_blocked_categories_dedupes_when_both_mechanisms_block_same_category(): void {
        global $database;
        $obj = $this->bareCatalogInstance();
        $_SESSION['user'] = $this->defaultSessionUser(['company_name' => 'Acme Corp']);
        $database->temp = new CatalogStubTable();
        $database->temp->rows = [['category_id' => 3]];
        $database->temp->companyRows = [['category_id' => 3]];

        $this->assertSame([3], $obj->blocked_categories());
    }

    /** @runInSeparateProcess @preserveGlobalState disabled */
    public function test_blocked_categories_ignores_company_block_when_session_has_no_company_name(): void {
        global $database;
        $obj = $this->bareCatalogInstance();
        $_SESSION['user'] = $this->defaultSessionUser(); // no company_name set
        $database->temp = new CatalogStubTable();
        $database->temp->rows = [['category_id' => 3]];
        $database->temp->companyRows = [['category_id' => 7]];

        $this->assertSame([3], $obj->blocked_categories());
    }

    /** @runInSeparateProcess @preserveGlobalState disabled */
    public function test_blocked_categories_ignores_company_block_for_bot_sessions(): void {
        global $database;
        $obj = $this->bareCatalogInstance();
        $_SESSION['user'] = $this->defaultSessionUser(['company_name' => 'Acme Corp']);
        $_SESSION['user']->bot_code = 'GOOGLEBOT';
        $database->temp = new CatalogStubTable();
        $database->temp->companyRows = [['category_id' => 7]];

        $this->assertSame([], $obj->blocked_categories());
    }

    // ── output_summary(): category overlay + link suppression ──────────

    /** @runInSeparateProcess @preserveGlobalState disabled */
    public function test_output_summary_overlays_blocked_category(): void {
        global $database;
        $obj = $this->bareCatalogInstance();
        $_SESSION['user'] = $this->defaultSessionUser();
        $database->temp = new CatalogStubTable();
        $database->temp->rows = [['category_id' => 2]];

        $obj->category    = [2 => (object) ['id' => 2, 'name' => 'Software']];
        $obj->subcategory  = [20 => $this->subcategoryFixture(['id' => 20, 'name' => 'Antivirus', 'category_id' => 2])];
        $obj->php_self     = '/catalog.php';
        $obj->request      = ['debug' => 0];
        $obj->search_code  = '';
        $obj->category_id  = 0;
        $obj->new_date     = 0;

        ob_start();
        $obj->output_summary([2 => [20]]);
        $html = ob_get_clean();

        $this->assertStringContainsString('catalog_category_overlay', $html);
        $this->assertStringContainsString('Integrator Agreement Required', $html);
        // The subcategory name is plain text, not wrapped in the fn_href link the
        // stub produces (the overlay's own "Contact Us" link is unrelated and expected).
        $this->assertStringNotContainsString("<a href='#'><span class='subcat20'>", $html);
        // catalog_blocked_link carries cursor: not-allowed so the block still reads
        // as inaccessible even if the overlay itself is removed via devtools.
        $this->assertStringContainsString("<span class='catalog_blocked_link'>", $html);
        $this->assertStringContainsString('Antivirus', $html);
    }

    /** @runInSeparateProcess @preserveGlobalState disabled */
    public function test_output_summary_shows_design_partner_agreement_message_for_company_blocked_category(): void {
        global $database;
        $obj = $this->bareCatalogInstance();
        $_SESSION['user'] = $this->defaultSessionUser(['company_name' => 'Acme Corp']);
        $database->temp = new CatalogStubTable();
        $database->temp->companyRows = [['category_id' => 2]];

        $obj->category    = [2 => (object) ['id' => 2, 'name' => 'Software']];
        $obj->subcategory  = [20 => $this->subcategoryFixture(['id' => 20, 'name' => 'Antivirus', 'category_id' => 2])];
        $obj->php_self     = '/catalog.php';
        $obj->request      = ['debug' => 0];
        $obj->search_code  = '';
        $obj->category_id  = 0;
        $obj->new_date     = 0;

        ob_start();
        $obj->output_summary([2 => [20]]);
        $html = ob_get_clean();

        $this->assertStringContainsString('Design Partner Agreement Required', $html);
        $this->assertStringNotContainsString('Integrator Agreement Required', $html);
    }

    /** @runInSeparateProcess @preserveGlobalState disabled */
    public function test_output_summary_shows_both_messages_when_both_mechanisms_block_the_category(): void {
        global $database;
        $obj = $this->bareCatalogInstance();
        $_SESSION['user'] = $this->defaultSessionUser(['company_name' => 'Acme Corp']);
        $database->temp = new CatalogStubTable();
        $database->temp->rows = [['category_id' => 2]];
        $database->temp->companyRows = [['category_id' => 2]];

        $obj->category    = [2 => (object) ['id' => 2, 'name' => 'Software']];
        $obj->subcategory  = [20 => $this->subcategoryFixture(['id' => 20, 'name' => 'Antivirus', 'category_id' => 2])];
        $obj->php_self     = '/catalog.php';
        $obj->request      = ['debug' => 0];
        $obj->search_code  = '';
        $obj->category_id  = 0;
        $obj->new_date     = 0;

        ob_start();
        $obj->output_summary([2 => [20]]);
        $html = ob_get_clean();

        $this->assertStringContainsString('Integrator Agreement and Design Partner Agreement are required for Access', $html);
        $this->assertSame(1, substr_count($html, 'catalog_category_overlay_message'));
    }

    /** @runInSeparateProcess @preserveGlobalState disabled */
    public function test_output_summary_links_unblocked_category(): void {
        global $database;
        $obj = $this->bareCatalogInstance();
        $_SESSION['user'] = $this->defaultSessionUser();
        $database->temp = new CatalogStubTable();
        $database->temp->rows = []; // nothing blocked

        $obj->category    = [2 => (object) ['id' => 2, 'name' => 'Software']];
        $obj->subcategory  = [20 => $this->subcategoryFixture(['id' => 20, 'name' => 'Antivirus', 'category_id' => 2])];
        $obj->php_self     = '/catalog.php';
        $obj->request      = ['debug' => 0];
        $obj->search_code  = '';
        $obj->category_id  = 0;
        $obj->new_date     = 0;

        ob_start();
        $obj->output_summary([2 => [20]]);
        $html = ob_get_clean();

        $this->assertStringNotContainsString('catalog_category_overlay', $html);
        $this->assertStringContainsString("<a href='#'><span class='subcat20'>", $html);
        $this->assertStringNotContainsString('catalog_blocked_link', $html);
        $this->assertStringContainsString('Antivirus', $html);
    }

    /** @runInSeparateProcess @preserveGlobalState disabled */
    public function test_output_summary_blocks_only_the_restricted_category_among_several(): void {
        global $database;
        $obj = $this->bareCatalogInstance();
        $_SESSION['user'] = $this->defaultSessionUser();
        $database->temp = new CatalogStubTable();
        $database->temp->rows = [['category_id' => 2]]; // only "Software" is blocked

        $obj->category = [
            1 => (object) ['id' => 1, 'name' => 'Books'],
            2 => (object) ['id' => 2, 'name' => 'Software'],
        ];
        $obj->subcategory = [
            10 => $this->subcategoryFixture(['id' => 10, 'name' => 'Fiction', 'category_id' => 1]),
            20 => $this->subcategoryFixture(['id' => 20, 'name' => 'Antivirus', 'category_id' => 2]),
        ];
        $obj->php_self     = '/catalog.php';
        $obj->request      = ['debug' => 0];
        $obj->search_code  = '';
        $obj->category_id  = 0;
        $obj->new_date     = 0;

        ob_start();
        $obj->output_summary([1 => [10], 2 => [20]]);
        $html = ob_get_clean();

        $overlayPos = strpos($html, 'catalog_category_overlay');
        $softwarePos = strpos($html, 'Software');
        $booksPos = strpos($html, 'Books');
        $this->assertNotFalse($overlayPos);
        // The overlay must appear after "Software" (its section) and there is
        // exactly one blocked category, so only one overlay block is emitted.
        $this->assertSame(1, substr_count($html, 'catalog_category_overlay_message'));
        $this->assertTrue($softwarePos < $overlayPos);
        $this->assertNotFalse($booksPos);
    }

    // ── inventory(): per-SKU link suppression for blocked categories ───

    /** @runInSeparateProcess @preserveGlobalState disabled */
    public function test_inventory_hides_sku_link_for_blocked_category(): void {
        global $database;
        $obj = $this->bareCatalogInstance();
        $_SESSION['user'] = $this->defaultSessionUser();
        $database->temp = new CatalogStubTable();
        $database->temp->rows = [['category_id' => 2]];

        $subcategory = $this->subcategoryFixture(['id' => 20, 'category_id' => 2]);
        $obj->subcategory     = [20 => $subcategory];
        $obj->subcategory_id  = 20;
        $obj->php_self        = '/catalog.php';
        $obj->request         = ['debug' => 0];
        $obj->search_code     = '';
        $obj->new_date        = 0;
        $obj->page             = 0;
        $obj->action            = '';
        $obj->parameter          = [];
        $obj->related_sku       = '';
        $obj->search_complete  = '';

        $data = ['SKU-100' => (object) ['sku' => 'SKU-100', 'new_date' => 0, 'parameter' => []]];

        $html = implode('', (array) $obj->inventory($data));

        $this->assertStringContainsString('SKU-100', $html);
        $this->assertStringNotContainsString("<a href='#'>SKU-100</a>", $html);
        $this->assertStringContainsString("<span class='catalog_blocked_link'>SKU-100</span>", $html);
    }

    /** @runInSeparateProcess @preserveGlobalState disabled */
    public function test_inventory_shows_sku_link_for_unblocked_category(): void {
        global $database;
        $obj = $this->bareCatalogInstance();
        $_SESSION['user'] = $this->defaultSessionUser();
        $database->temp = new CatalogStubTable();
        $database->temp->rows = []; // nothing blocked

        $subcategory = $this->subcategoryFixture(['id' => 20, 'category_id' => 2]);
        $obj->subcategory     = [20 => $subcategory];
        $obj->subcategory_id  = 20;
        $obj->php_self        = '/catalog.php';
        $obj->request         = ['debug' => 0];
        $obj->search_code     = '';
        $obj->new_date        = 0;
        $obj->page             = 0;
        $obj->action            = '';
        $obj->parameter          = [];
        $obj->related_sku       = '';
        $obj->search_complete  = '';

        $data = ['SKU-100' => (object) ['sku' => 'SKU-100', 'new_date' => 0, 'parameter' => []]];

        $html = implode('', (array) $obj->inventory($data));

        $this->assertStringContainsString("<a href='#'>SKU-100</a>", $html);
        $this->assertStringNotContainsString('catalog_blocked_link', $html);
    }

    // ── output_subcategory(): whole-page gate for blocked categories ───────
    // Reached via /catalog?search_source=search&search_id=... when the
    // search hit's table_name is "subcategory" (or a bookmarked/typed
    // subcategory URL). Previously rendered the full landing page — title,
    // image, overview, product table, downloads, CAD — regardless of
    // blocked_categories(); inventory() only suppressed the SKU links
    // *inside* the products table, leaving everything else exposed.

    /** @runInSeparateProcess @preserveGlobalState disabled */
    public function test_output_subcategory_overlays_blocked_category_instead_of_product_content(): void {
        global $database;
        $obj = $this->bareCatalogInstance();
        $_SESSION['user'] = $this->defaultSessionUser(['company_name' => 'Acme Corp']);
        $database->temp = new CatalogStubTable();
        $database->temp->companyRows = [['category_id' => 2]];

        $obj->tabs           = new CatalogStubTabs();
        $obj->category        = [2 => (object) ['id' => 2, 'name' => 'Software']];
        $obj->subcategory     = [20 => $this->subcategoryFixture(['id' => 20, 'name' => 'Antivirus', 'category_id' => 2])];
        $obj->subcategory_id  = 20;
        $obj->sku             = '';
        $obj->page            = 0;

        $data = ['SKU-100' => (object) ['sku' => 'SKU-100', 'new_date' => 0, 'parameter' => []]];

        ob_start();
        $obj->output_subcategory($data);
        $html = ob_get_clean();

        $this->assertStringContainsString('catalog_category_overlay', $html);
        $this->assertStringContainsString('Design Partner Agreement Required', $html);
        $this->assertStringContainsString('Antivirus', $html); // subcategory name still shown, matching output_summary()'s treatment
        $this->assertStringNotContainsString('SKU-100', $html);
        // No products/overview/customs/download tab was even built for a blocked category.
        $this->assertSame([], $obj->tabs->items);
    }

    /** @runInSeparateProcess @preserveGlobalState disabled */
    public function test_output_subcategory_renders_product_content_for_unblocked_category(): void {
        global $database;
        $obj = $this->bareCatalogInstance();
        $_SESSION['user'] = $this->defaultSessionUser();
        $database->temp = new CatalogStubTable();
        $database->temp->rows = []; // nothing blocked

        $obj->tabs             = new CatalogStubTabs();
        $obj->category          = [2 => (object) ['id' => 2, 'name' => 'Software']];
        $obj->subcategory       = [20 => $this->subcategoryFixture(['id' => 20, 'name' => 'Antivirus', 'category_id' => 2])];
        $obj->subcategory_id    = 20;
        $obj->sku                = '';
        $obj->page               = 0;
        $obj->php_self           = '/catalog.php';
        $obj->request            = ['debug' => 0];
        $obj->search_code        = '';
        $obj->new_date           = 0;
        $obj->action             = '';
        $obj->parameter          = [];
        $obj->related_sku        = '';
        $obj->search_complete    = '';
        $database->subcategory->constant = (object) ['parameters' => 0, 'tabs' => []];

        $data = ['SKU-100' => (object) ['sku' => 'SKU-100', 'new_date' => 0, 'parameter' => []]];

        ob_start();
        $obj->output_subcategory($data);
        $html = ob_get_clean();

        $this->assertStringNotContainsString('catalog_category_overlay', $html);
        $this->assertStringContainsString('SKU-100', $obj->tabs->items['catalog_products']);
    }

    // ── output_sku_tab(): direct/bookmarked/search-navigated SKU access ────
    // gated by blocked_categories(), same as inventory()'s link suppression.

    /** @runInSeparateProcess @preserveGlobalState disabled */
    public function test_output_sku_tab_overlays_blocked_category_instead_of_product_details(): void {
        global $database;
        $obj = $this->bareCatalogInstance();
        $_SESSION['user'] = $this->defaultSessionUser(['company_name' => 'Acme Corp']);
        $database->temp = new CatalogStubTable();
        $database->temp->companyRows = [['category_id' => 2]];

        $obj->tabs            = new CatalogStubTabs();
        $obj->category         = [2 => (object) ['id' => 2, 'name' => 'Software']];
        $obj->subcategory      = [20 => $this->subcategoryFixture(['id' => 20, 'name' => 'Antivirus', 'category_id' => 2])];
        $obj->category_id      = 2;
        $obj->subcategory_id   = 20;
        $obj->related_sku      = '';
        $database->inventory->data = (object) ['sku' => 'SKU-100', 'new_date' => 0, 'parameter' => []];

        $obj->output_sku_tab();
        $html = $obj->tabs->items['catalog_sku'];

        $this->assertStringContainsString('catalog_category_overlay', $html);
        $this->assertStringContainsString('Design Partner Agreement Required', $html);
        // The product's part number and add-to-cart controls must not leak into
        // the response for a search hit / bookmarked URL on a blocked category.
        $this->assertStringNotContainsString('SKU-100', $html);
    }

    /** @runInSeparateProcess @preserveGlobalState disabled */
    public function test_output_sku_tab_renders_product_details_for_unblocked_category(): void {
        global $database;
        $obj = $this->bareCatalogInstance();
        $_SESSION['user'] = $this->defaultSessionUser();
        $database->temp = new CatalogStubTable();
        $database->temp->rows = []; // nothing blocked

        $obj->tabs            = new CatalogStubTabs();
        $obj->category         = [2 => (object) ['id' => 2, 'name' => 'Software']];
        $obj->subcategory      = [20 => $this->subcategoryFixture(['id' => 20, 'name' => 'Antivirus', 'category_id' => 2])];
        $obj->category_id      = 2;
        $obj->subcategory_id   = 20;
        $obj->related_sku      = '';
        $database->inventory->data = (object) ['sku' => 'SKU-100', 'new_date' => 0, 'parameter' => []];

        $obj->output_sku_tab();
        $html = $obj->tabs->items['catalog_sku'];

        $this->assertStringNotContainsString('catalog_category_overlay', $html);
        $this->assertStringContainsString('SKU-100', $html);
    }
}

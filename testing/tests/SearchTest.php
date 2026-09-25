<?php

/**
 * Unit tests for classes/search.php's search_class — specifically the
 * blocked_categories() gate added to ajax() so the top-nav search-bar
 * autocomplete stops surfacing products from categories the current user
 * (via user_category, "Integrator Agreement") or their company (via
 * company_category_block, "Design Partner Agreement") is blocked from.
 *
 * Before this fix, ajax() queried the `search` table with no reference to
 * either block table, so blocked products were fully searchable even though
 * /catalog suppressed their links. blocked_categories() here mirrors
 * catalog.php's method of the same name (see CatalogTest.php) but is
 * duplicated locally since search_class has no shared base with
 * catalog_output_class.
 *
 * search_class extends database_class, so — per BlacklistTest.php/
 * CaptchaBypassTest.php's convention — this uses the shared
 * stubs/database_stub.php mock rather than declaring a competing
 * database_class, since PHPUnit loads every test file's top-level code
 * into one process and only the first declaration of a given class name
 * wins. database_stub.php now tracks queryLog and implements where() (for
 * query_where's "in"/"not in" case), added alongside this fix so the
 * generated "category_id not in (...)" clause can be asserted directly.
 */

use PHPUnit\Framework\TestCase;

set_include_path(__DIR__ . '/stubs' . PATH_SEPARATOR . dirname(__DIR__, 2) . PATH_SEPARATOR . get_include_path());

require_once __DIR__ . '/stubs/database_stub.php';

// ── Stub: global functions normally provided by scs_header.php ─────────────

if (!function_exists('fn_escape')) {
    function fn_escape($value, $quote = TRUE) {
        if ($quote === FALSE) return (strlen($value)) ? $value : "0";
        if (is_array($value)) return $value;
        return "'" . addslashes($value) . "'";
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

// ── Stub: $database->user (Administrator access check) ─────────────────────

if (!class_exists('SearchStubUser')) {
    class SearchStubUser {
        function access($role, $deny = TRUE) { return FALSE; }
    }
}

// ── Stub: $database->temp (user_category / company_category_block lookups) ─
// Mirrors CatalogStubTable: distinguishes the two lookup queries by table
// name appearing in the SQL text, since both go through the same stub.

if (!class_exists('SearchStubTemp')) {
    class SearchStubTemp {
        public $userCategoryRows = [];
        public $companyCategoryRows = [];
        public $fetch;
        private $activeRows = [];
        private $cursor = 0;

        function query($sql) {
            $this->cursor = 0;
            $this->activeRows = (stripos($sql, 'company_category_block') !== false) ? $this->companyCategoryRows : $this->userCategoryRows;
        }
        function fetch_array() {
            if ($this->cursor < count($this->activeRows)) return $this->activeRows[$this->cursor++];
            return false;
        }
        function free_result() {}
    }
}

// ── Tests ─────────────────────────────────────────────────────────────────

class SearchTest extends TestCase
{
    private function defaultSessionUser(array $overrides = []): stdClass {
        return (object) array_merge([
            'id'            => 42,
            'language_code' => 'EN',
            'bot_code'      => null,
        ], $overrides);
    }

    /**
     * Requires classes/search.php with the given session/database fixtures
     * and returns the auto-instantiated $database->search object.
     * Must be called from a @runInSeparateProcess test.
     */
    private function requireSearch(stdClass $sessionUser, SearchStubTemp $temp): search_class {
        global $database;

        $database        = new stdClass();
        $database->user  = new SearchStubUser();
        $database->temp  = $temp;

        $_SESSION['user'] = $sessionUser;

        require dirname(__DIR__, 2) . '/classes/search.php';

        return $database->search;
    }

    // ── blocked_categories() ─────────────────────────────────────────────

    /** @runInSeparateProcess @preserveGlobalState disabled */
    public function test_blocked_categories_merges_user_and_company_blocks_and_dedupes(): void {
        $temp = new SearchStubTemp();
        $temp->userCategoryRows    = [['category_id' => 3]];
        $temp->companyCategoryRows = [['category_id' => 3], ['category_id' => 7]];
        $search = $this->requireSearch($this->defaultSessionUser(['company_name' => 'Acme Corp']), $temp);

        $this->assertSame([3, 7], $search->blocked_categories());
    }

    /** @runInSeparateProcess @preserveGlobalState disabled */
    public function test_blocked_categories_empty_for_logged_out_session(): void {
        $temp = new SearchStubTemp();
        $temp->userCategoryRows = [['category_id' => 3]];
        $search = $this->requireSearch($this->defaultSessionUser(['id' => 0]), $temp);

        $this->assertSame([], $search->blocked_categories());
    }

    // ── ajax(): search-bar autocomplete excludes blocked categories ────────

    /** @runInSeparateProcess @preserveGlobalState disabled */
    public function test_ajax_excludes_blocked_categories_from_search_query(): void {
        $temp = new SearchStubTemp();
        $temp->userCategoryRows    = [['category_id' => 3]];
        $temp->companyCategoryRows = [['category_id' => 7]];
        $search = $this->requireSearch($this->defaultSessionUser(['company_name' => 'Acme Corp']), $temp);

        $search->ajax(['widget'], 20);

        $sql = implode("\n", $search->queryLog);
        $this->assertStringContainsString('category_id not in (3, 7)', $sql);
    }

    /** @runInSeparateProcess @preserveGlobalState disabled */
    public function test_ajax_omits_category_filter_when_nothing_blocked(): void {
        $temp = new SearchStubTemp();
        $search = $this->requireSearch($this->defaultSessionUser(), $temp);

        $search->ajax(['widget'], 20);

        $sql = implode("\n", $search->queryLog);
        $this->assertStringNotContainsString('category_id', $sql);
    }
}

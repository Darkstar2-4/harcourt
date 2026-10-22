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
        public $categoryNameRows = [];
        public $subcategoryNameRows = [];
        public $inventorySkuRows = [];
        public $fetch;
        private $activeRows = [];
        private $cursor = 0;

        function query($sql) {
            if (is_array($sql)) $sql = implode(' ', $sql);
            $this->cursor = 0;
            switch (TRUE) {
              case (stripos($sql, 'company_category_block') !== false):
                $this->activeRows = $this->companyCategoryRows;
                break;
              case (stripos($sql, 'from content') !== false):
                $this->activeRows = $this->categoryNameRows;
                break;
              case (stripos($sql, 'from subcategory') !== false):
                $this->activeRows = $this->subcategoryNameRows;
                break;
              case (stripos($sql, 'from inventory') !== false):
                $this->activeRows = $this->inventorySkuRows;
                break;
              default:
                $this->activeRows = $this->userCategoryRows;
            }
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

    // ── Separator-insensitive part number matching ──────────────────────────

    /** @runInSeparateProcess @preserveGlobalState disabled */
    public function test_normalize_code_strips_separators_and_uppercases(): void {
        $search = $this->requireSearch($this->defaultSessionUser(), new SearchStubTemp());

        $this->assertSame('HRSH81', $search->normalize_code('HRSH-8-1'));
        $this->assertSame('HRSH81', $search->normalize_code('hrsh 8 1'));
        $this->assertSame('HPSR4', $search->normalize_code('HPSR_4'));
        $this->assertSame('M35X20', $search->normalize_code('M3.5x20'));
        $this->assertSame('AB12', $search->normalize_code('A/B-1 2'));
        $this->assertSame('', $search->normalize_code(' - '));
    }

    /** @runInSeparateProcess @preserveGlobalState disabled */
    public function test_ajax_matches_part_number_typed_without_separators(): void {
        $search = $this->requireSearch($this->defaultSessionUser(), new SearchStubTemp());

        $search->ajax(['hrsh-8-1'], 20);

        $sql = implode("\n", $search->queryLog);
        $this->assertStringContainsString("code_normalized like '%HRSH81%'", $sql);
        $this->assertStringContainsString("order by (code_normalized='HRSH81' and table_name <> 'search_words') desc, code_normalized like 'HRSH81%' desc, (code_normalized like '%HRSH81%') desc, ranking, code", $sql);
    }

    // ── Typo-tolerant fallback ───────────────────────────────────────────

    /** @runInSeparateProcess @preserveGlobalState disabled */
    public function test_typo_variants_cover_each_single_typo(): void {
        $search = $this->requireSearch($this->defaultSessionUser(), new SearchStubTemp());

        $variants = $search->typo_variants('HRSG81');

        $this->assertContains('HRSH81', $variants, 'wrong character');
        $this->assertContains('HRS81', $variants, 'extra character');
        $this->assertContains('HRSGG81', $variants, 'missing character');
        $this->assertContains('HRGS81', $variants, 'swapped characters');
        $this->assertNotContains('HRSG81', $variants);
        $this->assertSame(count($variants), count(array_unique($variants)));
    }

    /** @runInSeparateProcess @preserveGlobalState disabled */
    public function test_ajax_falls_back_to_typo_prefix_search_when_nothing_matches(): void {
        $temp = new SearchStubTemp();
        $temp->userCategoryRows = [['category_id' => 3]];
        $search = $this->requireSearch($this->defaultSessionUser(), $temp);

        $response = $search->ajax(['HRSG-8-1'], 20);

        $this->assertCount(2, $search->queryLog);
        $typo = $search->queryLog[1];
        $this->assertStringContainsString('force index (code_normalized_index)', $typo);
        $this->assertStringContainsString("code_normalized like 'HRSH81%' or ", $typo);
        $this->assertStringContainsString('category_id not in (3)', $typo);
        $this->assertStringContainsString("order by code_normalized in ('RSG81', ", $typo);
        $this->assertSame('Lookup', end($response->items)->category);
    }

    /** @runInSeparateProcess @preserveGlobalState disabled */
    public function test_ajax_skips_typo_search_for_short_codes(): void {
        $search = $this->requireSearch($this->defaultSessionUser(), new SearchStubTemp());

        $search->ajax(['HPS4'], 20);

        $this->assertCount(1, $search->queryLog);
    }

    /** @runInSeparateProcess @preserveGlobalState disabled */
    public function test_ajax_typo_search_escapes_like_wildcard(): void {
        $search = $this->requireSearch($this->defaultSessionUser(), new SearchStubTemp());

        $search->ajax(['HRS%G81'], 20);

        $this->assertStringContainsString("code_normalized like 'ARS\\%G81%'", $search->queryLog[1]);
    }

    // ── Keyword search: plurals and misspelled words ──────────────────────

    /** @runInSeparateProcess @preserveGlobalState disabled */
    public function test_keyword_stem_matches_singular_and_plural(): void {
        $search = $this->requireSearch($this->defaultSessionUser(), new SearchStubTemp());

        $this->assertSame('hinge', $search->keyword_stem('hinges'));
        $this->assertSame('hinge', $search->keyword_stem('Hinge'));
        $this->assertSame('bushing', $search->keyword_stem('bushings'));
        $this->assertSame('assembl', $search->keyword_stem('assemblies'));
        $this->assertSame('assembl', $search->keyword_stem('assembly'));
        $this->assertSame('box', $search->keyword_stem('boxes'));
        $this->assertSame('stainless', $search->keyword_stem('stainless'));
        $this->assertSame('pin', $search->keyword_stem('pins'));
        $this->assertSame('nus', $search->keyword_stem('nus'), 'too short to stem');
        $this->assertSame('HRSH-8-1S', $search->keyword_stem('HRSH-8-1S'), 'part numbers are left alone');
    }

    /** @runInSeparateProcess @preserveGlobalState disabled */
    public function test_ajax_searches_plural_as_stem(): void {
        $search = $this->requireSearch($this->defaultSessionUser(), new SearchStubTemp());

        $search->ajax(['fixture', 'hinges'], 20);

        $sql = $search->queryLog[0];
        $this->assertStringContainsString("code_normalized like '%FIXTURE%'", $sql);
        $this->assertStringContainsString("code_normalized like '%HINGE%'", $sql);
    }

    /** @runInSeparateProcess @preserveGlobalState disabled */
    public function test_keyword_correct_fixes_unknown_word_to_most_common_neighbour(): void {
        $search = $this->requireSearch($this->defaultSessionUser(), new SearchStubTemp());
        $search->keywords = ['drill' => 9, 'bushing' => 2, 'bushings' => 7, 'pushing' => 1, 'hoist' => 4, 'ring' => 5];

        $this->assertSame(['drill', 'bushing'], $search->keyword_correct(['drill', 'bushng']));
        $this->assertSame(['hoist', 'ring'], $search->keyword_correct(['hosit', 'rnig']));
        $this->assertSame(['drill', 'bush'], $search->keyword_correct(['drill', 'bush']), 'part of a known word is left alone');
        $this->assertSame(['HRSG81', 'zzzzzz'], $search->keyword_correct(['HRSG81', 'zzzzzz']));
    }

    /** @runInSeparateProcess @preserveGlobalState disabled */
    public function test_ajax_retries_with_corrected_words_before_part_number_fallback(): void {
        $search = $this->requireSearch($this->defaultSessionUser(), new SearchStubTemp());
        $search->keywords = ['drill' => 9, 'bushing' => 2];

        $response = $search->ajax(['drill', 'bushng'], 20);

        $this->assertCount(3, $search->queryLog);
        $this->assertStringContainsString("code_normalized like '%BUSHNG%'", $search->queryLog[0]);
        $this->assertStringContainsString("code_normalized like '%BUSHING%'", $search->queryLog[1]);
        $this->assertStringContainsString('force index (code_normalized_index)', $search->queryLog[2]);
        $this->assertSame([], $response->items, 'no Lookup Part# entry for multi-word searches');
    }

    // ── Category keywords, labels and exact-first ranking ─────────────────

    private function categoryTemp(): SearchStubTemp {
        $temp = new SearchStubTemp();
        $temp->categoryNameRows = [
            ['record_id' => 14, 'name' => 'Clamping'],
            ['record_id' => 7,  'name' => 'Hoisting & Rigging'],
            ['record_id' => 8,  'name' => 'Tool Storage & Shipping'],
            ['record_id' => 18, 'name' => 'HBOX &trade;'],
        ];
        return $temp;
    }

    /** @runInSeparateProcess @preserveGlobalState disabled */
    public function test_category_matches_word_starts_only(): void {
        $search = $this->requireSearch($this->defaultSessionUser(), $this->categoryTemp());

        $this->assertSame([14], $search->category_matches('clamps'));
        $this->assertSame([7], $search->category_matches('rig'));
        $this->assertSame([18], $search->category_matches('hbox'));
        $this->assertSame([], $search->category_matches('pin'), 'not inside "Shipping"');
        $this->assertSame([], $search->category_matches('ring'), 'not inside "Rigging"');
        $this->assertSame([], $search->category_matches('to'), 'too short');
    }

    /** @runInSeparateProcess @preserveGlobalState disabled */
    public function test_ajax_keyword_also_matches_products_in_matching_category(): void {
        $search = $this->requireSearch($this->defaultSessionUser(), $this->categoryTemp());

        $search->ajax(['clamp'], 20);

        $sql = $search->queryLog[0];
        $this->assertStringContainsString("(table_name <> 'category' and (code_normalized like '%CLAMP%' or category_id in (14)))", $sql);
        $this->assertStringContainsString("(table_name = 'category' and category_id in (14))", $sql);
        $this->assertStringContainsString("(code_normalized like '%CLAMP%') desc, ranking", $sql, 'name matches rank above category-only matches');
        $this->assertStringContainsString('limit 60', $sql, 'headroom for skipped repeats');
    }

    /** @runInSeparateProcess @preserveGlobalState disabled */
    public function test_ajax_category_entry_needs_every_term_in_its_name(): void {
        $search = $this->requireSearch($this->defaultSessionUser(), $this->categoryTemp());

        $search->ajax(['swing', 'clamp'], 20);

        $this->assertStringNotContainsString("table_name = 'category'", $search->queryLog[0]);
    }

    /** @runInSeparateProcess @preserveGlobalState disabled */
    public function test_ajax_labels_shared_codes_and_skips_repeated_families(): void {
        $temp = new SearchStubTemp();
        $temp->subcategoryNameRows = [['id' => 209, 'name' => 'Swivel Hoist Rings (Metric)']];
        $temp->inventorySkuRows    = [['id' => 8166, 'sku' => 'HBLB-3-0.50']];
        $search = $this->requireSearch($this->defaultSessionUser(), $temp);
        $row = ['language_code' => 'EN', 'active' => 1, 'category_id' => 7, 'inventory_id' => 0, 'ranking' => 0];
        database_class::$rows = [
            ['id' => 1, 'table_name' => 'subcategory', 'code' => 'Swivel Hoist Rings', 'subcategory_id' => 208] + $row,
            ['id' => 2, 'table_name' => 'search_words', 'code' => 'envirolox', 'subcategory_id' => 208] + $row,
            ['id' => 3, 'table_name' => 'search_words', 'code' => 'envirolox', 'subcategory_id' => 209] + $row,
            ['id' => 4, 'table_name' => 'competitor_sku', 'code' => 'BLPB-3-0.50', 'subcategory_id' => 390, 'inventory_id' => 8166] + $row,
        ];

        $response = $search->ajax(['envirolox'], 20);

        $this->assertSame(
            ['Swivel Hoist Rings', 'envirolox - Swivel Hoist Rings (Metric)', 'BLPB-3-0.50 (Harcourt HBLB-3-0.50)'],
            array_map(function ($item) { return $item->label; }, $response->items)
        );
    }

    /** @runInSeparateProcess @preserveGlobalState disabled */
    public function test_ajax_caps_items_at_limit(): void {
        $search = $this->requireSearch($this->defaultSessionUser(), new SearchStubTemp());
        database_class::$rows = [];
        for ($id = 1; $id <= 5; $id++) {
            database_class::$rows[] = ['id' => $id, 'table_name' => 'inventory', 'code' => "HPSR-{$id}", 'subcategory_id' => 1, 'inventory_id' => $id];
        }

        $response = $search->ajax(['HPSR'], 2);

        $this->assertCount(2, $response->items);
    }

    /** @runInSeparateProcess @preserveGlobalState disabled */
    public function test_ajax_keeps_raw_match_for_separator_only_term(): void {
        $search = $this->requireSearch($this->defaultSessionUser(), new SearchStubTemp());

        $search->ajax(['-'], 20);

        $sql = implode("\n", $search->queryLog);
        $this->assertStringContainsString("code like '%-%'", $sql);
    }
}

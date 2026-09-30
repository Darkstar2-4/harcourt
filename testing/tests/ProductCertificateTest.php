<?php

/**
 * Unit tests for the customer-facing Product Certificate lookup page
 * (product_certificate.php).
 *
 * The page is procedural (not a class): it handles a PDF download branch
 * (GET ?download=id, streams via scs_pdf_class and dies), an AJAX search
 * branch (POST action=search, die(json_encode(...))), and a default page
 * shell render. Tests exercise the underlying functions directly
 * (product_certificate_search_output(), product_certificate_pdf_html())
 * after a non-AJAX, non-download require — same technique
 * DesignPartnerAgreementTest.php uses.
 *
 * Stub strategy mirrors DesignPartnerAgreementTest.php: stubs/scs_header.php
 * is resolved first via include_path so the real dependency chain never loads.
 */

use PHPUnit\Framework\TestCase;

set_include_path(__DIR__ . '/stubs' . PATH_SEPARATOR . dirname(__DIR__, 2) . PATH_SEPARATOR . get_include_path());

// ── Stub: global functions normally provided by scs_header.php's dependency chain ─

if (!function_exists('fn_escape')) {
    function fn_escape($value, $quote = TRUE) {
        if ($quote === FALSE) return (strlen($value)) ? $value : "0";
        if ($quote === "null" && !strlen($value)) return "null";
        return "'" . addslashes($value) . "'";
    }
}

if (!function_exists('fn_date')) {
    function fn_date($text, $action) {
        $data = preg_split("/[ \/\.-]/", (string) $text);
        switch (TRUE) {
          case ($text == ""):
          case ($text == "0"):
          case ($text == "0000-00-00"):
          case ($text == "0000/00/00"):
            return "";
          case ($action == "ymd"):
            return $data[1] . "/" . $data[2] . "/" . $data[0];
        }
        return "";
    }
}

if (!function_exists('role_access')) {
    function role_access($role, $role_minimum, $role_match = FALSE) {
        $roles = array('Customer', 'External', 'Internal', 'Executive', 'Administrator', 'Super User');
        $role_index = array_search($role, $roles);
        $min_index  = array_search($role_minimum, $roles);
        if ($role_index === FALSE || $min_index === FALSE) return FALSE;
        return $role_index >= $min_index;
    }
}

// ── Stub: $database->user (access gate only) ────────────────────────────────

if (!class_exists('ProductCertificateStubUser')) {
    class ProductCertificateStubUser {
        function access($role, $deny = TRUE) { return TRUE; }
    }
}

// ── Stub: $database->product_certificate (search query + fetch loop) ───────

if (!class_exists('ProductCertificateStubTable')) {
    class ProductCertificateStubTable {
        public $fetch;
        public $data;
        public $fixtureRows = [];
        public $queryLog = [];
        public $allowed = true;
        private $cursor = 0;

        function access($sku, $user) {
            return $this->allowed;
        }
        function query($sql) {
            $this->queryLog[] = is_array($sql) ? implode(" ", $sql) : $sql;
            $this->cursor = 0;
        }
        function fetch_array() {
            if ($this->cursor < count($this->fixtureRows)) return $this->fixtureRows[$this->cursor++];
            return false;
        }
        function fetch($f = false) {
            $row = $this->fetch;
            $this->data = (object) array(
                'id' => $row['id'],
                'sku' => $row['sku'],
                'manufacturing_date' => $row['manufacturing_date'] ?? '',
                'testing_date' => $row['testing_date'] ?? '',
                'product_family' => $row['product_family'] ?? '',
                'product_description' => $row['product_description'] ?? '',
                'product_weight' => $row['product_weight'] ?? '',
                'rated_load' => $row['rated_load'] ?? '',
                'conversion' => $row['conversion'] ?? '',
                'test_machine' => $row['test_machine'] ?? '',
                'test_machine_calibration_date' => $row['test_machine_calibration_date'] ?? '',
            );
        }
        function free_result() {}
    }
}

// ── Stub: $forms / $menu ─────────────────────────────────────────────────

if (!class_exists('ProductCertificateStubHtml')) {
    class ProductCertificateStubHtml {
        function meta($a, $b) {}
    }
}

if (!class_exists('ProductCertificateStubForms')) {
    class ProductCertificateStubForms {
        public $html;
        function __construct() { $this->html = new ProductCertificateStubHtml(); }
        function title($t) {}
    }
}

if (!class_exists('ProductCertificateStubMenu')) {
    class ProductCertificateStubMenu {
        function head() {}
        function copyright() {}
    }
}

// ── Tests ─────────────────────────────────────────────────────────────────

class ProductCertificateTest extends TestCase
{
    private function fixtureRow(array $overrides = []): array {
        return array_merge([
            'id' => 1,
            'sku' => 'H0000001',
            'manufacturing_date' => '2025-12-31',
            'testing_date' => '2026-01-05',
            'product_family' => 'HLIFT',
            'product_description' => 'HLIFT-8-48',
            'product_weight' => '5.6 lbs',
            'rated_load' => '10,000 lbs',
            'conversion' => '4,536 KG',
            'test_machine' => 'ADB Calibrated Equipment per ASTM E4-20',
            'test_machine_calibration_date' => '2025-10-23',
        ], $overrides);
    }

    /**
     * Requires product_certificate.php in default (non-AJAX, non-download) GET
     * mode so its top-level code renders the page shell rather than hitting a
     * die() branch, then returns the captured HTML. The functions under test
     * are defined unconditionally at the bottom of the file, so they're
     * available afterward regardless of which shell content was rendered.
     */
    private function requirePage(ProductCertificateStubTable $table, string $sessionRole = 'Customer', int $sessionId = 1, string $sessionStatus = 'Active'): string {
        global $database, $forms, $menu;

        $database = new stdClass();
        $database->user = new ProductCertificateStubUser();
        $database->product_certificate = $table;

        $forms = new ProductCertificateStubForms();
        $menu  = new ProductCertificateStubMenu();

        $_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__, 2);
        unset($_SERVER['HTTP_X_REQUESTED_WITH']);
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET = [];
        $_POST = [];
        $_SESSION['user'] = (object) ['type' => $sessionRole, 'id' => $sessionId, 'status' => $sessionStatus];

        ob_start();
        require dirname(__DIR__, 2) . '/product_certificate.php';
        return ob_get_clean();
    }

    // ── product_certificate_search_output() ─────────────────────────────

    /** @runInSeparateProcess @preserveGlobalState disabled */
    public function test_search_reports_no_certificate_found(): void {
        $table = new ProductCertificateStubTable();
        $this->requirePage($table);

        $html = product_certificate_search_output('UNKNOWNSKU');

        $this->assertStringContainsString('No certificate found', $html);
        $this->assertStringContainsString('UNKNOWNSKU', $html);
    }

    /** @runInSeparateProcess @preserveGlobalState disabled */
    public function test_search_prompts_when_sku_blank(): void {
        $table = new ProductCertificateStubTable();
        $this->requirePage($table);

        $html = product_certificate_search_output('');

        $this->assertStringContainsString('Please enter a product number', $html);
    }

    /** @runInSeparateProcess @preserveGlobalState disabled */
    public function test_search_renders_matching_certificate_with_download_link(): void {
        $table = new ProductCertificateStubTable();
        $table->fixtureRows = [$this->fixtureRow()];
        $this->requirePage($table);

        $html = product_certificate_search_output('H0000001');

        $this->assertStringContainsString('H0000001', $html);
        $this->assertStringContainsString('HLIFT-8-48', $html);
        $this->assertStringContainsString('Tested 01/05/2026', $html);
        $this->assertStringContainsString("href='/product_certificate.php?download=1'", $html);
    }

    /** @runInSeparateProcess @preserveGlobalState disabled */
    public function test_search_renders_every_matching_row_for_repeated_sku(): void {
        $table = new ProductCertificateStubTable();
        $table->fixtureRows = [
            $this->fixtureRow(['id' => 1, 'testing_date' => '2026-01-05']),
            $this->fixtureRow(['id' => 2, 'testing_date' => '2025-06-01']),
        ];
        $this->requirePage($table);

        $html = product_certificate_search_output('H0000001');

        $this->assertStringContainsString("download=1'", $html);
        $this->assertStringContainsString("download=2'", $html);
    }

    /** @runInSeparateProcess @preserveGlobalState disabled */
    public function test_search_escapes_html_in_product_description(): void {
        $table = new ProductCertificateStubTable();
        $table->fixtureRows = [$this->fixtureRow(['product_description' => '<script>alert(1)</script>'])];
        $this->requirePage($table);

        $html = product_certificate_search_output('H0000001');

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    /** @runInSeparateProcess @preserveGlobalState disabled */
    public function test_search_queries_by_exact_uppercase_sku(): void {
        $table = new ProductCertificateStubTable();
        $this->requirePage($table);

        product_certificate_search_output('H0000001');

        $query = implode(" ", $table->queryLog);
        $this->assertStringContainsString("sku='H0000001'", $query);
        $this->assertStringContainsString('order by testing_date desc', $query);
    }

    /** @runInSeparateProcess @preserveGlobalState disabled */
    public function test_search_denied_sku_reports_not_found_without_querying(): void {
        $table = new ProductCertificateStubTable();
        $table->fixtureRows = [$this->fixtureRow()];
        $table->allowed = false;
        $this->requirePage($table);

        $html = product_certificate_search_output('H0000001');

        // Identical to an unknown SKU so restricted part numbers can't be discovered
        $this->assertStringContainsString('No certificate found', $html);
        $this->assertStringNotContainsString('download=', $html);
        $this->assertSame([], $table->queryLog);
    }

    // ── Dashboard link visibility (page shell) ──────────────────────────

    /** @runInSeparateProcess @preserveGlobalState disabled */
    public function test_certificate_dashboard_link_hidden_for_customer(): void {
        $table = new ProductCertificateStubTable();
        $html = $this->requirePage($table, 'Customer');

        $this->assertStringNotContainsString('Certificate Dashboard', $html);
    }

    /** @runInSeparateProcess @preserveGlobalState disabled */
    public function test_certificate_dashboard_link_shown_for_administrator(): void {
        $table = new ProductCertificateStubTable();
        $html = $this->requirePage($table, 'Administrator');

        $this->assertStringContainsString('Certificate Dashboard', $html);
        $this->assertStringContainsString('/hcert_data.php', $html);
    }

    // ── product_certificate_pdf_html() ───────────────────────────────────

    /** @runInSeparateProcess @preserveGlobalState disabled */
    public function test_pdf_html_includes_certificate_fields_and_formatted_dates(): void {
        $table = new ProductCertificateStubTable();
        $this->requirePage($table);

        $data = (object) $this->fixtureRow();
        $html = product_certificate_pdf_html($data);

        $this->assertStringContainsString('H0000001', $html);
        $this->assertStringContainsString('HLIFT-8-48', $html);
        $this->assertStringContainsString('10,000 lbs', $html);
        $this->assertStringContainsString('4,536 KG', $html);
        $this->assertStringContainsString('01/05/2026', $html); // Test Date
        $this->assertStringContainsString('12/31/2025', $html); // Manufactured Date
        $this->assertStringContainsString('10/23/2025', $html); // Calibration Date
    }

    /** @runInSeparateProcess @preserveGlobalState disabled */
    public function test_pdf_html_escapes_description(): void {
        $table = new ProductCertificateStubTable();
        $this->requirePage($table);

        $data = (object) $this->fixtureRow(['product_description' => '<b>Injected</b>']);
        $html = product_certificate_pdf_html($data);

        $this->assertStringNotContainsString('<b>Injected</b>', $html);
        $this->assertStringContainsString('&lt;b&gt;Injected&lt;/b&gt;', $html);
    }
}

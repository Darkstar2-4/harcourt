<?php

/**
 * Unit tests for product_certificate_class's certificate import and customer
 * domain handling (classes/product_certificate.php).
 *
 * The certificate row must still import when its "Customer Domain" cell holds
 * an invalid or public email domain: the bad domain is skipped and reported in
 * $domain_warning, valid domains in the same cell are still assigned.
 *
 * ProductCertificateImportSpy extends the real class and records SQL instead of
 * running it, so import_update() and the domain helpers are exercised as-is.
 * database_class/database_meta_class/fn_escape come from the shared
 * stubs/database_stub.php; table_map_class and role_access are stubbed here.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/stubs/database_stub.php';

if (!class_exists('table_map_class')) {
    class table_map_class {
        public $error = array();
        public $update = FALSE;
        public $update_timestamp = 0;
        function __construct($options) {
            if (($options['function'] ?? '') == "upload") $this->update = TRUE;
        }
        function item($field, $options = array()) {}
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

if (!class_exists('product_certificate_class')) {
    $database = new stdClass();
    require_once dirname(__DIR__, 2) . '/classes/product_certificate.php';
}

if (!class_exists('ProductCertificateImportSpy')) {
    class ProductCertificateImportSpy extends product_certificate_class {
        public $sql = array();
        public $matchRows = 0;
        function query($query) {
            $this->sql[] = is_array($query) ? implode(" ", $query) : $query;
            $this->meta = new database_meta_class();
            $this->meta->rows = $this->matchRows;
        }
        function fetch_array() { return FALSE; }
        function free_result() {}
        function certificateInserts() {
            return array_values(array_filter($this->sql, function ($q) { return stripos($q, "insert into product_certificate set") === 0; }));
        }
        function domainInserts() {
            return array_values(array_filter($this->sql, function ($q) { return stripos($q, "insert ignore into product_certificate_domain") === 0; }));
        }
    }
}

class ProductCertificateImportTest extends TestCase
{
    private function spy(string $function = "upload"): ProductCertificateImportSpy {
        $table = new ProductCertificateImportSpy();
        $table->map(array("function" => $function));
        return $table;
    }

    private function row(array $overrides = []): array {
        return array_merge([
            'sku' => 'H0000003',
            'manufacturing_date' => '2025-12-31',
            'testing_date' => '2026-01-07',
            'product_family' => 'HLIFT',
            'product_description' => 'HLIFT-6-50',
            'product_weight' => '6.2 lbs',
            'rated_load' => '10,000 lbs',
            'conversion' => '4,536 KG',
            'test_machine' => 'ADB Calibrated Equipment per ASTM E4-20',
            'test_machine_calibration_date' => '2025-10-23',
            'customer_domain' => '',
        ], $overrides);
    }

    // ── import_update(): invalid domains are skipped, the row still imports ──

    public function test_public_domain_is_skipped_but_certificate_still_imports(): void {
        $table = $this->spy();
        $table->import_update($this->row(['customer_domain' => 'gmail.com']), 4);

        $this->assertSame([], $table->map->error);
        $this->assertCount(1, $table->certificateInserts());
        $this->assertStringContainsString("sku='H0000003'", $table->certificateInserts()[0]);
        $this->assertSame([], $table->domainInserts());
        $this->assertSame(["Line 4 (H0000003): Public email domain not allowed: gmail.com (domain skipped)"], $table->domain_warning);
    }

    public function test_invalid_domain_is_skipped_but_certificate_still_imports(): void {
        $table = $this->spy();
        $table->import_update($this->row(['customer_domain' => 'acme']), 7);

        $this->assertSame([], $table->map->error);
        $this->assertCount(1, $table->certificateInserts());
        $this->assertSame([], $table->domainInserts());
        $this->assertSame(["Line 7 (H0000003): Invalid domain: acme (domain skipped)"], $table->domain_warning);
    }

    public function test_valid_domains_in_same_cell_are_kept_when_another_is_skipped(): void {
        $table = $this->spy();
        $table->import_update($this->row(['customer_domain' => 'toolbuilder.com; yahoo.com; client.com']), 5);

        $this->assertSame([], $table->map->error);
        $this->assertCount(1, $table->certificateInserts());
        $inserts = $table->domainInserts();
        $this->assertCount(2, $inserts);
        $this->assertStringContainsString("domain='toolbuilder.com'", $inserts[0]);
        $this->assertStringContainsString("domain='client.com'", $inserts[1]);
        $this->assertSame(["Line 5 (H0000003): Public email domain not allowed: yahoo.com (domain skipped)"], $table->domain_warning);
    }

    public function test_warnings_accumulate_across_rows(): void {
        $table = $this->spy();
        $table->import_update($this->row(['sku' => 'H0000001', 'customer_domain' => 'hotmail.com']), 2);
        $table->import_update($this->row(['sku' => 'H0000002', 'customer_domain' => 'customer.com']), 3);
        $table->import_update($this->row(['sku' => 'H0000003', 'customer_domain' => 'outlook.com']), 4);

        $this->assertCount(3, $table->certificateInserts());
        $this->assertCount(1, $table->domainInserts());
        $this->assertSame([
            "Line 2 (H0000001): Public email domain not allowed: hotmail.com (domain skipped)",
            "Line 4 (H0000003): Public email domain not allowed: outlook.com (domain skipped)",
        ], $table->domain_warning);
    }

    public function test_review_mode_reports_skipped_domain_without_writing(): void {
        $table = $this->spy("review");
        $table->import_update($this->row(['customer_domain' => 'gmail.com']), 4);

        $this->assertSame([], $table->map->error);
        $this->assertSame([], $table->sql);
        $this->assertSame(["Line 4 (H0000003): Public email domain not allowed: gmail.com (domain skipped)"], $table->domain_warning);
    }

    public function test_valid_domains_are_normalized_and_assigned_without_warnings(): void {
        $table = $this->spy();
        $table->import_update($this->row(['customer_domain' => 'bob@Acme.com, https://www.Client.com/about']), 2);

        $inserts = $table->domainInserts();
        $this->assertCount(2, $inserts);
        $this->assertStringContainsString("sku='H0000003'", $inserts[0]);
        $this->assertStringContainsString("domain='acme.com'", $inserts[0]);
        $this->assertStringContainsString("domain='client.com'", $inserts[1]);
        $this->assertSame([], $table->domain_warning);
    }

    public function test_blank_domain_imports_certificate_with_no_domain_or_warning(): void {
        $table = $this->spy();
        $table->import_update($this->row(['customer_domain' => '']), 2);

        $this->assertCount(1, $table->certificateInserts());
        $this->assertSame([], $table->domainInserts());
        $this->assertSame([], $table->domain_warning);
    }

    // ── import_update(): required fields still reject the row ──────────────

    public function test_missing_part_number_still_rejects_row(): void {
        $table = $this->spy();
        $table->import_update($this->row(['sku' => '', 'customer_domain' => 'acme.com']), 2);

        $this->assertSame("Cannot be blank", $table->map->error['sku']);
        $this->assertSame([], $table->sql);
    }

    public function test_missing_testing_date_still_rejects_row(): void {
        $table = $this->spy();
        $table->import_update($this->row(['testing_date' => '', 'customer_domain' => 'acme.com']), 2);

        $this->assertSame("Cannot be blank", $table->map->error['testing_date']);
        $this->assertSame([], $table->sql);
    }

    // ── access(): who can see a part number's certificates ─────────────────

    public function test_access_allows_internal_users_without_domain_lookup(): void {
        $table = $this->spy();
        $this->assertTrue($table->access('H0000001', (object) ['type' => 'Internal', 'domain' => 'harcourt.co']));
        $this->assertSame([], $table->sql);
    }

    public function test_access_denies_public_domain_customer_without_lookup(): void {
        $table = $this->spy();
        $table->matchRows = 1;
        $this->assertFalse($table->access('H0000001', (object) ['type' => 'Customer', 'domain' => 'gmail.com']));
        $this->assertSame([], $table->sql);
    }

    public function test_access_follows_domain_assignment_for_customers(): void {
        $table = $this->spy();
        $customer = (object) ['type' => 'Customer', 'domain' => 'Acme.com'];

        $table->matchRows = 1;
        $this->assertTrue($table->access('H0000001', $customer));
        $this->assertStringContainsString("domain='acme.com'", $table->sql[0]);

        $table->matchRows = 0;
        $this->assertFalse($table->access('H0000001', $customer));
    }
}

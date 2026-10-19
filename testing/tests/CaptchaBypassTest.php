<?php

/**
 * Unit tests for captcha_bypass_class (classes/captcha_bypass.php).
 *
 * Uses the same stub strategy as BlacklistTest.php — stubs/scs_header.php is
 * resolved first via include_path to avoid loading the real dependency chain.
 * The captcha_bypass_class is loaded directly from the real file; its parent
 * database_class comes from the shared stubs/database_stub.php mock, which
 * every simple CRUD *_class model's test file uses so they don't collide
 * when PHPUnit loads all test files into one process.
 */

use PHPUnit\Framework\TestCase;

set_include_path(__DIR__ . '/stubs' . PATH_SEPARATOR . get_include_path());

require_once __DIR__ . '/stubs/database_stub.php';

// ── Load captcha_bypass class ───────────────────────────────────────────────

$database = new stdClass();
require_once dirname(__DIR__, 2) . '/classes/captcha_bypass.php';


// ── Tests ─────────────────────────────────────────────────────────────────

class CaptchaBypassTest extends TestCase
{
    /** @var captcha_bypass_class */
    private $cb;

    protected function setUp(): void
    {
        global $database;
        database_class::resetState();
        $database->captcha_bypass = new captcha_bypass_class();
        $this->cb = $database->captcha_bypass;
    }

    // ── check() ───────────────────────────────────────────────────────────

    public function test_check_returns_false_for_empty_email(): void
    {
        $this->assertFalse($this->cb->check(''));
    }

    public function test_check_returns_false_when_email_not_bypassed(): void
    {
        $this->assertFalse($this->cb->check('user@safe.com'));
    }

    public function test_check_returns_true_when_email_is_bypassed(): void
    {
        database_class::$rows = [
            ['id' => 1, 'email' => 'trusted@example.com', 'comment' => 'known partner', 'created' => time()],
        ];

        $this->assertTrue($this->cb->check('trusted@example.com'));
    }

    public function test_check_is_case_insensitive(): void
    {
        database_class::$rows = [
            ['id' => 1, 'email' => 'trusted@example.com', 'comment' => '', 'created' => time()],
        ];

        $this->assertTrue($this->cb->check('Trusted@EXAMPLE.com'));
    }

    public function test_check_does_not_match_other_addresses_at_same_domain(): void
    {
        database_class::$rows = [
            ['id' => 1, 'email' => 'trusted@example.com', 'comment' => '', 'created' => time()],
        ];

        $this->assertFalse($this->cb->check('other@example.com'));
    }

    public function test_check_matches_any_address_at_a_bypassed_domain(): void
    {
        database_class::$rows = [
            ['id' => 1, 'email' => '@boeing.com', 'comment' => 'remote access company', 'created' => time()],
        ];

        $this->assertTrue($this->cb->check('New.Engineer@Boeing.com'));
    }

    public function test_check_domain_entry_does_not_match_subdomains_or_lookalikes(): void
    {
        database_class::$rows = [
            ['id' => 1, 'email' => '@boeing.com', 'comment' => '', 'created' => time()],
        ];

        $this->assertFalse($this->cb->check('user@notboeing.com'));
        $this->assertFalse($this->cb->check('user@mail.boeing.com'));
    }

    // ── migration: boeing / leidos / johndeere domain bypass ─────────────

    private function domainMigration(): array
    {
        return include dirname(__DIR__, 2) . '/migrations/2026_10_12_151657_add_domain_captcha_bypass_boeing_leidos_johndeere.php';
    }

    public function test_domain_migration_up_inserts_the_three_domains(): void
    {
        $up = implode(' ', $this->domainMigration()['up']);

        $this->assertStringContainsString('INSERT IGNORE INTO `captcha_bypass`', $up);
        foreach (['@boeing.com', '@leidos.com', '@johndeere.com'] as $domain) {
            $this->assertStringContainsString("'{$domain}'", $up);
        }
    }

    public function test_domain_migration_down_removes_only_the_three_domains(): void
    {
        $down = implode(' ', $this->domainMigration()['down']);

        $this->assertStringContainsString("DELETE FROM `captcha_bypass` WHERE `email` IN ('@boeing.com', '@leidos.com', '@johndeere.com')", $down);
    }

    public function test_migrated_domains_bypass_their_employees(): void
    {
        database_class::$rows = [
            ['id' => 1, 'email' => '@boeing.com', 'comment' => '', 'created' => time()],
            ['id' => 2, 'email' => '@leidos.com', 'comment' => '', 'created' => time()],
            ['id' => 3, 'email' => '@johndeere.com', 'comment' => '', 'created' => time()],
        ];

        $this->assertTrue($this->cb->check('new.engineer@boeing.com'));
        $this->assertTrue($this->cb->check('analyst@leidos.com'));
        $this->assertTrue($this->cb->check('tech@johndeere.com'));
        $this->assertFalse($this->cb->check('someone@deere.com'));
    }

    public function test_check_returns_false_for_email_without_domain(): void
    {
        $this->assertFalse($this->cb->check('not-an-email'));
    }

    public function test_check_returns_false_when_table_does_not_exist(): void
    {
        database_class::$tableExists = false;

        $this->assertFalse($this->cb->check('trusted@example.com'));
    }

    // ── CRUD ──────────────────────────────────────────────────────────────

    public function test_insert_creates_new_record(): void
    {
        $this->cb->data = new captcha_bypass_data_class();
        $this->cb->data->email   = 'new@example.com';
        $this->cb->data->comment = 'Load testing partner';
        $this->cb->update(FALSE);

        $this->assertSame(1, count(database_class::$rows));
        $this->assertSame('new@example.com', database_class::$rows[0]['email']);
    }

    public function test_insert_assigns_auto_increment_id(): void
    {
        $this->cb->data = new captcha_bypass_data_class();
        $this->cb->data->email = 'new@example.com';
        $this->cb->update(FALSE);

        $this->assertGreaterThan(0, $this->cb->data->id);
    }

    public function test_read_by_email(): void
    {
        database_class::$rows = [
            ['id' => 5, 'email' => 'someone@example.com', 'comment' => 'test', 'created' => 1000],
        ];

        $this->cb->read('someone@example.com', 'email');

        $this->assertSame(5, $this->cb->data->id);
        $this->assertSame('someone@example.com', $this->cb->data->email);
        $this->assertSame('test', $this->cb->data->comment);
    }

    public function test_read_by_id(): void
    {
        database_class::$rows = [
            ['id' => 3, 'email' => 'id-lookup@example.com', 'comment' => '', 'created' => 2000],
        ];

        $this->cb->read(3);

        $this->assertSame('id-lookup@example.com', $this->cb->data->email);
    }

    public function test_read_resets_data_when_not_found(): void
    {
        $this->cb->read(999);

        $this->assertSame(0, $this->cb->data->id);
        $this->assertNull($this->cb->data->email);
    }

    public function test_delete_removes_record(): void
    {
        database_class::$rows = [
            ['id' => 1, 'email' => 'a@example.com', 'comment' => '', 'created' => 0],
            ['id' => 2, 'email' => 'b@example.com', 'comment' => '', 'created' => 0],
        ];

        $this->cb->delete(1);

        $this->assertSame(1, count(database_class::$rows));
        $this->assertSame('b@example.com', database_class::$rows[0]['email']);
    }

    // ── table_exists ─────────────────────────────────────────────────────

    public function test_table_exists_returns_true_when_present(): void
    {
        database_class::$tableExists = true;
        $this->assertTrue($this->cb->table_exists());
    }

    public function test_table_exists_returns_false_when_missing(): void
    {
        database_class::$tableExists = false;
        $this->assertFalse($this->cb->table_exists());
    }

    // ── data class ────────────────────────────────────────────────────────

    public function test_data_class_sets_created_timestamp(): void
    {
        $before = strtotime("now");
        $data   = new captcha_bypass_data_class();
        $after  = strtotime("now");

        $this->assertGreaterThanOrEqual($before, $data->created);
        $this->assertLessThanOrEqual($after, $data->created);
    }

    public function test_data_class_defaults_id_to_zero(): void
    {
        $data = new captcha_bypass_data_class();
        $this->assertSame(0, $data->id);
    }
}

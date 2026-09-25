<?php

/**
 * Unit tests for checkout_class (checkout.php), focused on the order-confirmation
 * email behavior: on a successful "complete" checkout, one email goes to sales
 * (the mailform's default recipient) and a second, separate email with the same
 * content is sent directly to the customer.
 *
 * Strategy: stub files in tests/stubs/ are prepended to the include_path so PHP finds
 * them before the real scs_header.php / classes/scsmail_rev1.php, preventing the heavy
 * production dependency chain from loading. All production classes/functions those
 * files would normally provide are defined inline below, following the same pattern
 * as NewUserTest.php.
 *
 * checkout.php has no separate "action" method to call via reflection — all of the
 * logic under test lives directly in checkout_class::__construct(). So each test
 * constructs a fresh checkout_class() (running the real constructor, output-buffered)
 * against freshly-reset stub globals/session/post, then inspects the mail instances
 * scsmail_class recorded.
 */

use PHPUnit\Framework\TestCase;

// ── 1. Redirect requires to stubs so real includes are never executed ──────
set_include_path(__DIR__ . '/stubs' . PATH_SEPARATOR . get_include_path());

// ── 2. Stub: global functions ──────────────────────────────────────────────
if (!function_exists('fn_text')) {
    function fn_text($text, $uppercase = FALSE) {
        return is_string($text) ? trim($text) : $text;
    }
}

// ── 3. Stub: production classes used by checkout_class ────────────────────

// Real, unmodified data holder from classes/orderhd.php — plain property bag,
// safe to reuse as-is since it carries no DB coupling.
if (!class_exists('orderhd_data_class')) {
    class orderhd_data_class {
        var $id=0;
        var $user_id=0;
        var $punchout_id=0;
        var $order_date=0;
        var $email;
        var $name_first;
        var $name_last;
        var $name;
        var $company_name;
        var $address;
        var $city;
        var $state;
        var $zip;
        var $country_code;
        var $phone;
        var $po;
        var $comment;
        var $type="PO";
        var $status="Pending";
        var $status_comment;
        var $status_user_id=0;
        var $status_date=0;
        var $carrier_name;
        var $carrier_account;
        var $currency_code="USD";
        var $cc;
    }
}

// Matches the address_class stub in NewUserTest.php/UserManagerTest.php exactly
// (guarded by class_exists, so whichever test file's require runs first "wins" —
// keeping the shape identical avoids the loser silently getting a mismatched stub).
if (!class_exists('address_class')) {
    class address_class {
        public $data;
        public $input    = [];
        public $registry;
        public function __construct($type, $data, $opts = []) {
            $this->data     = (object)['email' => ''];
            $this->registry = new stdClass();
        }
        public function verify()          {}
        public function output($format)   { return []; }
    }
}

// Records every instance and every call made against it, so tests can assert
// on how many emails were sent, to whom, and with what content.
if (!class_exists('scsmail_class')) {
    class scsmail_class {
        public static $instances = [];
        public $form;
        public $data;
        public $options;
        public $html;
        public $recipients = [];
        public $sent = FALSE;
        public function __construct($form, $data = [], $options = []) {
            $this->form = $form;
            $this->data = $data;
            $this->options = $options;
            self::$instances[] = $this;
        }
        public function html($html) {
            $this->html = $html;
        }
        public function recipient($email, $name, $field = "") {
            $this->recipients[] = ['field' => $field ?: 'to', 'email' => $email, 'name' => $name];
        }
        public function attachment($file) {}
        public function send($success_message = "") {
            $this->sent = TRUE;
            return "Email successfully sent";
        }
    }
}

// ── 4. Stub: $database ────────────────────────────────────────────────────

class CheckoutStubOrderhd {
    public $data;
    public function __construct() { $this->data = new orderhd_data_class(); }
    public function update($update = FALSE) {
        if (!$this->data->id) $this->data->id = 5001;
    }
    public function output_header($options = array()) {
        if (isset($options['data'])) $this->data = $options['data'];
        return "<div class='order-header'>Order #" . $this->data->id . "</div>";
    }
}

class CheckoutStubOrderln {
    public $data;
    public $updated = [];
    public function __construct() { $this->data = new stdClass(); }
    public function update($update = FALSE) {
        $this->updated[] = clone $this->data;
    }
    public function cart($cart = null) {
        return "<div class='order-lines'>" . sizeof($this->updated) . " line(s)</div>";
    }
}

class CheckoutStubLog {
    public $lastType    = '';
    public $lastOptions = [];
    public function update($type, $opts = []) {
        $this->lastType    = $type;
        $this->lastOptions = $opts;
    }
}

class CheckoutStubDatabase {
    public $orderhd;
    public $orderln;
    public $log;
    public $registry;
    public function __construct() {
        $this->orderhd  = new CheckoutStubOrderhd();
        $this->orderln  = new CheckoutStubOrderln();
        $this->log      = new CheckoutStubLog();
        $this->registry = (object)['local' => (object)['cc_email' => '']];
    }
}

// ── 5. Stub: $forms ───────────────────────────────────────────────────────

class CheckoutStubForms {
    public $error      = [];
    public $error_text = [];
    public $message    = [0 => ''];
    public $constant;
    public function __construct() { $this->constant = new stdClass(); }
    public function title($title, $version = "") {}
    public function error($field, $text = "", $message = "", $registry_module = "") {
        $this->error[$field] = ($text !== "") ? $text : TRUE;
    }
    public function message($debug = array()) { return implode("<br>", $this->message); }
    public function open($a = array(), $b = array())      { return "<form>"; }
    public function close()                               { return "</form>"; }
    public function hidden($field, $text = "", $type = "", $class = "") { return "<input type=hidden name='{$field}'>"; }
    public function button($text, $options = array())     { return "<button>{$text}</button>"; }
    public function text($name, $val, $a = 0, $b = 0, $c = "", $o = array()) { return "<input name='{$name}'>"; }
}

// ── 6. Stub: $menu ────────────────────────────────────────────────────────

class CheckoutStubMenu {
    public $cart = [];
    public $page;
    public function __construct() { $this->page = (object)['home' => '/']; }
    public function head()      {}
    public function copyright() {}
}

// ── 7. Prime globals & server state, then load checkout.php once ──────────
// checkout.php redirects (and exits) at file scope unless the cart guard
// passes, so the very first require must satisfy it.

global $database, $forms, $menu;
$database = new CheckoutStubDatabase();
$forms    = new CheckoutStubForms();
$menu     = new CheckoutStubMenu();
$menu->cart = [(object)['sku' => 'PLACEHOLDER']];

$_SESSION = ['user' => (object)['id' => 1, 'ecommerce' => TRUE, 'currency_code' => 'USD']];
$_COOKIE['harcourt_ecommerce'] = '';
$_POST = [];

ob_start();
require_once dirname(__DIR__, 2) . '/checkout.php';
ob_end_clean();

// ── 8. Tests ──────────────────────────────────────────────────────────────

class CheckoutTest extends TestCase
{
    protected function setUp(): void
    {
        global $database, $forms, $menu;

        $database = new CheckoutStubDatabase();
        $forms    = new CheckoutStubForms();
        $menu     = new CheckoutStubMenu();
        $menu->cart = [(object)['sku' => 'PLACEHOLDER']];

        scsmail_class::$instances = [];

        $_SESSION = [
            'user' => (object)['id' => 1, 'ecommerce' => TRUE, 'currency_code' => 'USD'],
            'cart' => [(object)['sku' => 'ABC123', 'qty' => 2]],
        ];
        $_COOKIE['harcourt_ecommerce'] = json_encode([
            'name_first'      => 'Jane',
            'name_last'       => 'Doe',
            'name'            => 'Jane Doe',
            'email'           => 'jane.doe@example.com',
            'phone'           => '555-1234',
            'company_name'    => 'Acme Co',
            'address'         => '123 Main St',
            'city'            => 'Springfield',
            'state'           => 'IL',
            'zip'             => '62701',
            'country_code'    => 'US',
            'carrier_name'    => 'UPS',
            'carrier_account' => '',
        ]);
        $_POST = [
            'action'          => 'complete',
            'carrier_name'    => 'UPS',
            'carrier_account' => '',
            'po'              => 'PO-100',
            'comment'         => '',
            'cc'              => '',
        ];
    }

    private function runCheckout(): void {
        // PHPUnit's own progress output means headers_sent() is already TRUE by the
        // time ecommerce_class::cookie() calls setcookie() below — harmless in
        // production (nothing is printed before that point in a real request), but
        // it raises a PHP warning here that must be suppressed rather than treated
        // as a test failure.
        set_error_handler(function ($errno, $errstr) {
            return (strpos($errstr, 'headers already sent') !== false);
        }, E_WARNING);
        ob_start();
        new checkout_class();
        ob_end_clean();
        restore_error_handler();
    }

    public function test_complete_checkout_sends_two_separate_emails(): void
    {
        $this->runCheckout();

        $this->assertCount(2, scsmail_class::$instances);
    }

    public function test_first_email_is_left_for_default_sales_recipient(): void
    {
        $this->runCheckout();

        $salesMail = scsmail_class::$instances[0];
        $this->assertSame('checkout', $salesMail->form);
        $this->assertSame([], $salesMail->recipients, 'Sales copy should rely on the mailform default recipient, not an explicit override.');
    }

    public function test_second_email_is_addressed_to_the_customer(): void
    {
        $this->runCheckout();

        $customerMail = scsmail_class::$instances[1];
        $this->assertSame('checkout', $customerMail->form);
        $this->assertCount(1, $customerMail->recipients);
        $this->assertSame('jane.doe@example.com', $customerMail->recipients[0]['email']);
        $this->assertSame('Jane Doe', $customerMail->recipients[0]['name']);
    }

    public function test_customer_email_is_not_cced_but_sent_as_a_direct_recipient(): void
    {
        $this->runCheckout();

        $customerMail = scsmail_class::$instances[1];
        $this->assertSame('to', $customerMail->recipients[0]['field'], 'Customer must be an explicit "to" on their own email, not a CC on the sales email.');
    }

    public function test_customer_email_wraps_the_shared_order_content_with_a_confirmation_intro(): void
    {
        $this->runCheckout();

        [$salesMail, $customerMail] = scsmail_class::$instances;
        $this->assertNotEmpty($salesMail->html);
        $this->assertStringEndsWith($salesMail->html, $customerMail->html, 'Customer email should carry the same order content as the sales copy, plus a prepended intro.');
        $this->assertStringContainsString('sales@harcourt.co', $customerMail->html);
        $this->assertStringNotContainsString('sales@harcourt.co', $salesMail->html, 'Sales copy should not carry the customer-facing confirmation intro.');
    }

    public function test_no_ship_without_credit_card_notice_is_customer_only(): void
    {
        global $database;
        $database->registry->local->cc_email = 'Please call our offices to provide payment information.';

        $this->runCheckout();

        [$salesMail, $customerMail] = scsmail_class::$instances;
        $this->assertStringContainsString('No orders will ship without a credit card', $customerMail->html);
        $this->assertStringNotContainsString('No orders will ship without a credit card', $salesMail->html, 'Sales copy should not carry the customer-only shipping notice.');
    }

    public function test_customer_email_subject_is_overridden_to_order_confirmation(): void
    {
        $this->runCheckout();

        [$salesMail, $customerMail] = scsmail_class::$instances;
        $this->assertSame('Order Confirmation', $customerMail->options['subject']);
        $this->assertArrayNotHasKey('subject', $salesMail->options, 'Sales copy should keep the mailform default subject.');
    }

    public function test_both_emails_are_sent(): void
    {
        $this->runCheckout();

        [$salesMail, $customerMail] = scsmail_class::$instances;
        $this->assertTrue($salesMail->sent);
        $this->assertTrue($customerMail->sent);
    }

    public function test_billing_same_as_shipping_copies_the_shipping_address(): void
    {
        global $database;
        $_POST['billing_same_as_shipping'] = '1';

        $this->runCheckout();

        $this->assertSame('123 Main St', $database->orderhd->data->billing_address);
        $this->assertSame('Springfield', $database->orderhd->data->billing_city);
        $this->assertSame('IL', $database->orderhd->data->billing_state);
        $this->assertSame('62701', $database->orderhd->data->billing_zip);
        $this->assertSame('US', $database->orderhd->data->billing_country_code);
    }

    public function test_billing_not_same_as_shipping_does_not_copy_the_shipping_address(): void
    {
        global $database;
        // billing_same_as_shipping intentionally left unset.

        $this->runCheckout();

        $this->assertNotSame('123 Main St', $database->orderhd->data->billing_address);
    }

    public function test_billing_country_defaults_to_blank_when_checkbox_unchecked(): void
    {
        // The billing country starts blank on a fresh checkout session so the user
        // isn't defaulted into the shipping country. fn_country_address() shows the
        // generic Province/State field for a blank country, so the field is never
        // hidden even though no country is selected yet.
        global $database;
        // billing_same_as_shipping intentionally left unset.

        $this->runCheckout();

        $this->assertEmpty($database->orderhd->data->billing_country_code);
    }

    public function test_no_emails_sent_when_validation_fails(): void
    {
        $_POST['carrier_name'] = '';

        $this->runCheckout();

        $this->assertCount(0, scsmail_class::$instances);
    }
}

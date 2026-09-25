<?php
return [
    'description' => 'Register the orderhd_billing address table so address_class("orderhd_billing", ...) in checkout.php stops dying with "Table orderhd_billing not defined". Only registers the fields the checkout billing form actually collects (address, city, state, zip, country_code) - company_name/name/phone/etc are intentionally left unset since the billing form does not submit them.',
    'up' => [
        "DELETE FROM `registry` WHERE `module`='address' AND `field`='orderhd_billing'",
        "INSERT INTO `registry` (`domain`,`module`,`field`,`item`,`data`) VALUES
            ('','address','orderhd_billing','address','required'),
            ('','address','orderhd_billing','city','required'),
            ('','address','orderhd_billing','state','required'),
            ('','address','orderhd_billing','zip','required'),
            ('','address','orderhd_billing','country_code','required')",
    ],
    'down' => [
        "DELETE FROM `registry` WHERE `module`='address' AND `field`='orderhd_billing'",
    ],
];

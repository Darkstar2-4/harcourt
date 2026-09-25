<?php
return [
    'description' => 'Remove the billing company name column added by the checkout billing address feature',
    'up' => [
        "ALTER TABLE `orderhd`
            DROP COLUMN `billing_company_name`",
    ],
    'down' => [
        "ALTER TABLE `orderhd`
            ADD COLUMN `billing_company_name` VARCHAR(45) NULL DEFAULT '' AFTER `cc`",
    ],
];

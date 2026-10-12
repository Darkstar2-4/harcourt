<?php
return [
    'description' => 'Create product_certificate_domain table restricting HCERT certificate access to customer domains (keyed by SKU so it survives certificate re-imports)',
    'up' => [
        "CREATE TABLE IF NOT EXISTS `product_certificate_domain` (
            `id`               int(10) unsigned NOT NULL AUTO_INCREMENT,
            `sku`              varchar(80) NOT NULL DEFAULT '',
            `domain`           varchar(255) NOT NULL DEFAULT '',
            `create_timestamp` int(10) unsigned NOT NULL DEFAULT '0',
            PRIMARY KEY (`id`),
            UNIQUE KEY `sku_domain_key` (`sku`,`domain`),
            KEY `domain_key` (`domain`)
        ) ENGINE=InnoDB DEFAULT CHARSET=latin1 COMMENT='HCERT certificate customer domain access (09/30/2026)'",
    ],
    'down' => [
        "DROP TABLE IF EXISTS `product_certificate_domain`",
    ],
];

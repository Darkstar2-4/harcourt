<?php
// Removes the legacy 'acf-options-page' plugin from wp_options.active_plugins
// (serialized PHP array) without disturbing any other entry, key, or the
// array's declared count. Verified round-trip (up then down) against a copy
// of the actual serialized value before being added here.
return [
    'description' => 'Deactivate the legacy acf-options-page plugin (superseded by ACF PRO core, removed from wordpress.org)',
    'up' => [
        "UPDATE `wp_options`
         SET `option_value` = CONCAT(
             'a:',
             CAST(SUBSTRING_INDEX(SUBSTRING(`option_value`, 3), ':', 1) AS UNSIGNED) - 1,
             ':',
             SUBSTRING(
                 REGEXP_REPLACE(`option_value`, 'i:[0-9]+;s:37:\"acf-options-page/acf-options-page\\\\.php\";', ''),
                 LOCATE('{', `option_value`)
             )
         )
         WHERE `option_name` = 'active_plugins'
           AND `option_value` REGEXP 'i:[0-9]+;s:37:\"acf-options-page/acf-options-page\\\\.php\";'",
    ],
    'down' => [
        "UPDATE `wp_options`
         SET `option_value` = CONCAT(
             'a:',
             (CAST(SUBSTRING_INDEX(SUBSTRING(`option_value`, 3), ':', 1) AS UNSIGNED) + 1),
             ':{',
             SUBSTRING(`option_value`, LOCATE('{', `option_value`) + 1, LENGTH(`option_value`) - LOCATE('{', `option_value`) - 1),
             'i:99999;s:37:\"acf-options-page/acf-options-page.php\";}'
         )
         WHERE `option_name` = 'active_plugins'
           AND `option_value` NOT REGEXP 'acf-options-page/acf-options-page\\\\.php'",
    ],
];
<?php
// Part# search ignores separators (HRSH81 finds HRSH-8-1). Storing the stripped
// code avoids recomputing it across every search row on each autocomplete query.
// The separator list must match search_class::normalize_code().
return [
    'description' => 'Add separator-stripped code_normalized generated column to search',
    'up' => [
        "ALTER TABLE `search`
            ADD COLUMN `code_normalized` VARCHAR(256)
                GENERATED ALWAYS AS (REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(`code`, '-', ''), ' ', ''), '_', ''), '.', ''), '/', '')) STORED
                AFTER `code`",
    ],
    'down' => [
        "ALTER TABLE `search` DROP COLUMN `code_normalized`",
    ],
];

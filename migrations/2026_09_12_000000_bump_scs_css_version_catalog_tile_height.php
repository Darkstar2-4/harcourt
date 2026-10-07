<?php
return [
    'description' => 'Bump scs.css cache-busting version so the catalog tile height fix (long product names getting clipped) goes live without a hard refresh',
    'up' => [
        "UPDATE `registry` SET `data` = '/scs_scripts/scs.css?v=20260912'
         WHERE `module` = 'www' AND `field` = 'css' AND `item` = '2'
           AND `data` = '/scs_scripts/scs.css?v=20260911'",
    ],
    'down' => [
        "UPDATE `registry` SET `data` = '/scs_scripts/scs.css?v=20260911'
         WHERE `module` = 'www' AND `field` = 'css' AND `item` = '2'
           AND `data` = '/scs_scripts/scs.css?v=20260912'",
    ],
];

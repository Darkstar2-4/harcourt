<?php
return [
    'description' => 'Bump scs.css cache-busting version so production picks up changes without a hard refresh',
    'up' => [
        "UPDATE `registry` SET `data` = '/scs_scripts/scs.css?v=20260911'
         WHERE `module` = 'www' AND `field` = 'css' AND `item` = '2'
           AND `data` = '/scs_scripts/scs.css?v=20231130'",
    ],
    'down' => [
        "UPDATE `registry` SET `data` = '/scs_scripts/scs.css?v=20231130'
         WHERE `module` = 'www' AND `field` = 'css' AND `item` = '2'
           AND `data` = '/scs_scripts/scs.css?v=20260911'",
    ],
];
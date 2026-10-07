<?php
/*
 * Four subcategories in category_id=5 (Jig Rest Button Kits (Metric), Jig Rest
 * Button Shim Packs (Metric), Heavy Duty Round Rest Pad Shim Packs (Metric),
 * Heavy Duty Round Rest Pads (Metric) - Nylon) are correctly classified as
 * metric by catalog.php's is_metric check, which reads the raw English
 * subcategory.name. But their FR content.name translations never got the
 * "(Metrique)" suffix that every sibling metric subcategory has, so on the
 * French catalog page filtered to Metric these four tiles look like imperial
 * products slipped into the metric list even though they're filtered
 * correctly. Appending the missing suffix to match sibling naming (e.g.
 * record_id 527 "Support de chambrage en kit (Metrique)").
 */
return [
    'description' => 'Append missing "(Metrique)" suffix to FR subcategory names for 4 metric subcategories in category_id=5',
    'up' => [
        "UPDATE `content` SET `name` = 'Support de chambrage en kit (Metrique)' WHERE `record_type` = 'subcategory' AND `record_id` = 523 AND `record_item` = '' AND `language_code` = 'FR' AND `name` = 'Support de chambrage en kit'",
        "UPDATE `content` SET `name` = 'Cale pour bouton d''arret (Metrique)' WHERE `record_type` = 'subcategory' AND `record_id` = 524 AND `record_item` = '' AND `language_code` = 'FR' AND `name` = 'Cale pour bouton d''arret'",
        "UPDATE `content` SET `name` = 'Cale ultra résistante pour bouton d’arrêt (Metrique)' WHERE `record_type` = 'subcategory' AND `record_id` = 528 AND `record_item` = '' AND `language_code` = 'FR' AND `name` = 'Cale ultra résistante pour bouton d’arrêt'",
        "UPDATE `content` SET `name` = 'Cale cylindrique ultra résistante en delrin (Metrique)' WHERE `record_type` = 'subcategory' AND `record_id` = 464 AND `record_item` = '' AND `language_code` = 'FR' AND `name` = 'Cale cylindrique ultra résistante en delrin'",
    ],
    'down' => [
        "UPDATE `content` SET `name` = 'Support de chambrage en kit' WHERE `record_type` = 'subcategory' AND `record_id` = 523 AND `record_item` = '' AND `language_code` = 'FR' AND `name` = 'Support de chambrage en kit (Metrique)'",
        "UPDATE `content` SET `name` = 'Cale pour bouton d''arret' WHERE `record_type` = 'subcategory' AND `record_id` = 524 AND `record_item` = '' AND `language_code` = 'FR' AND `name` = 'Cale pour bouton d''arret (Metrique)'",
        "UPDATE `content` SET `name` = 'Cale ultra résistante pour bouton d’arrêt' WHERE `record_type` = 'subcategory' AND `record_id` = 528 AND `record_item` = '' AND `language_code` = 'FR' AND `name` = 'Cale ultra résistante pour bouton d’arrêt (Metrique)'",
        "UPDATE `content` SET `name` = 'Cale cylindrique ultra résistante en delrin' WHERE `record_type` = 'subcategory' AND `record_id` = 464 AND `record_item` = '' AND `language_code` = 'FR' AND `name` = 'Cale cylindrique ultra résistante en delrin (Metrique)'",
    ],
];
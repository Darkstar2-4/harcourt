<?php
// Remote-access companies whose new employees score 0 on reCAPTCHA and get
// blocked at registration. "@domain" entries bypass every address at that domain.
$domains = ['@boeing.com', '@leidos.com', '@johndeere.com'];
$comment = 'Domain bypass: remote access company blocked by low reCAPTCHA scores';
$created = strtotime('now');

$values = array_map(function ($domain) use ($comment, $created) {
    return "('{$domain}', '{$comment}', {$created})";
}, $domains);

return [
    'description' => 'Add @boeing.com, @leidos.com and @johndeere.com to captcha_bypass',
    'up' => [
        "INSERT IGNORE INTO `captcha_bypass` (`email`, `comment`, `created`) VALUES " . implode(', ', $values),
    ],
    'down' => [
        "DELETE FROM `captcha_bypass` WHERE `email` IN ('" . implode("', '", $domains) . "')",
    ],
];

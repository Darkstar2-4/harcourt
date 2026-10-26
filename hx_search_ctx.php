<?php
// hxweb answers the catalog search box itself (search/SPEC.md) and asks here, once per session every few minutes,
// what this visitor may search: their language, whether they are an administrator (live shows administrators only
// active rows), and the categories blocked for them or their company (classes/search.php blocked_categories()).
require_once "scs_header.php";
header("Content-Type: application/json");
header("Cache-Control: no-store");
$language = strtoupper($_SESSION['user']->language_code ?? "");
echo json_encode(array(
    "lang" => ($language == "FR") ? "FR" : "EN",
    "admin" => (bool)$database->user->access("Administrator", FALSE),
    "blocked" => $database->search->blocked_categories(),
));

<?php
// Writes the data hxweb's catalog search loads (search/SPEC.md): the search table that search_utility rebuilds every
// night, the product families and categories with their English and French names, and the part numbers. hxweb
// reloads within a minute of a change. Run after search_utility (cron) and once on deploy, as harcou6:
//   php search_export.php /home/harcou6/search_index   (hxweb: HXWEB_SEARCH)
// With --log <file> it writes the last two years of the search log instead (the input of search_learn.sh), readable by
// harcou6 only: it holds visitor IPs, which search_learn.sh drops.
if (PHP_SAPI !== "cli") exit;
$out = $argv[1] ?? exit("usage: php search_export.php <dir> | --log <file>\n");
$env = parse_ini_file(__DIR__ . "/.env");
$db = new mysqli($env["DB_HOST"], $env["DB_USER"], $env["DB_PASS"], $env["DB_NAME"]);
$db->set_charset("utf8mb4");
if ($out === "--log") {
    $file = $argv[2] ?? exit("usage: php search_export.php --log <file>\n");
    umask(077);
    $f = fopen($file, "w");
    fwrite($f, "id\tdate_time\tuser_id\tip\tsubtype\tsku\tsubcategory_id\tc\n");
    // bench test customers (comment 'bench-test') are not customers: their scripted searches would teach the ranking
    $result = $db->query("select id, date_time, user_id, ip, subtype, sku, subcategory_id, comment from log where type = 'Search' "
        . "and bot_id = 0 and date_time > unix_timestamp(now() - interval 2 year) "
        . "and user_id not in (select id from user where comment = 'bench-test') order by id", MYSQLI_USE_RESULT) or exit("log: {$db->error}\n");
    while ($row = $result->fetch_row()) {
        $row[7] = str_replace("\n", " | ", (string)$row[7]);
        fwrite($f, implode("\t", array_map(function ($v) { return str_replace(array("\t", "\r", "\n"), " ", (string)$v); }, $row)) . "\n");
    }
    fclose($f);
    exit;
}
@mkdir($out, 0755, TRUE);

function name_sql($type, $id_column, $language) {
    return "ifnull((select name from content c where c.record_type='{$type}' and c.record_item='' and c.record_id={$id_column} "
        . "and c.language_code='{$language}' limit 1),'')";
}
$files = array(
    "rows.tsv" => "select id, table_name, ifnull(language_code,''), active, code, category_id, subcategory_id, inventory_id, ranking from search",
    "families.tsv" => "select s.id, s.category_id, s.status, " . name_sql("subcategory", "s.id", "EN") . ", " . name_sql("subcategory", "s.id", "FR") . " from subcategory s",
    "categories.tsv" => "select k.id, " . name_sql("category", "k.id", "EN") . ", " . name_sql("category", "k.id", "FR") . " from category k",
    "parts.tsv" => "select id, sku, subcategory_id from inventory",
);
// Each file is written whole under a temporary name and renamed into place, so hxweb never reads a half-written file.
foreach ($files as $file => $sql) {
    $result = $db->query($sql, MYSQLI_USE_RESULT) or exit("{$file}: {$db->error}\n");
    $f = fopen("{$out}/{$file}.tmp", "w");
    $n = 0;
    while ($row = $result->fetch_row()) {
        fwrite($f, implode("\t", array_map(function ($v) { return str_replace(array("\t", "\r", "\n"), " ", (string)$v); }, $row)) . "\n");
        $n++;
    }
    fclose($f);
    $result->free();
    echo "{$file}: {$n} rows\n";
}
foreach (array_keys($files) as $file) rename("{$out}/{$file}.tmp", "{$out}/{$file}");

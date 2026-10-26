<?php
/**
 * Plugin Name: HX form island
 * Description: Renders one Gravity Form exactly as a static page would have it, with fresh state, nonces and
 *   honeypot: GET /?hx_form=<id>&hx_lang=<en|fr>&hx_page=<path+query>. Serves only (page, language, form)
 *   triples listed under "islands" in the static export manifest. Deployed by the static export; source in
 *   ~/work-dev/harcourt-web-bench/export/hx-form-island.php on the MCP host.
 */
if (!defined('ABSPATH')) exit;

// WPML fixes the request language (ICL_LANGUAGE_CODE) while plugins load, before wp_loaded, and theme code reads that
// constant: functions/gf-fr.php (live since 2026-10-22) translates the form labels only when it is 'fr'. Must-use
// plugins load before WPML, so hand WPML the island's language through its own lang parameter (it reads the URI).
if (isset($_GET['hx_form']) && ($_GET['hx_lang'] ?? '') === 'fr') {
    $_GET['lang'] = $_REQUEST['lang'] = 'fr';
    $_SERVER['QUERY_STRING'] = ($_SERVER['QUERY_STRING'] ?? '') . '&lang=fr';
    $_SERVER['REQUEST_URI'] .= (str_contains($_SERVER['REQUEST_URI'], '?') ? '&' : '?') . 'lang=fr';
}

const HX_FORM_ISLAND_MANIFEST = '/home/harcou6/static_site/manifest.json';

add_action('wp_loaded', function () {
    if (!isset($_GET['hx_form'])) return;
    $form_id = (string) $_GET['hx_form'];
    $lang = (string) ($_GET['hx_lang'] ?? '');
    $page = (string) ($_GET['hx_page'] ?? '');

    $islands = hx_form_island_list($lang, $page);
    $at = $islands === null ? false : array_search($form_id, array_map(fn($i) => (string) $i['form'], $islands), true);
    $form = ($at !== false && class_exists('GFAPI')) ? GFAPI::get_form((int) $form_id) : false;
    if (!$form || empty($form['is_active']) || !empty($form['is_trash'])) {
        hx_form_island_send(404, 'text/plain', "no such form island\n");
    }

    do_action('wpml_switch_language', $lang);
    // Gravity Forms derives the form action and the state's URL hash from the request URI.
    $_SERVER['REQUEST_URI'] = $page;
    // A page prints the gform bootstrap script in <head>; without this the first form would carry its own copy.
    GFFormDisplay::$hooks_js_printed = true;
    // Akismet numbers its fields and Gravity Forms numbers repeat renders per request, so the forms that precede
    // this one on the page are rendered first and discarded, without counting them as form views.
    add_filter('gform_disable_view_counter', '__return_true');
    foreach (array_slice($islands, 0, $at + 1) as $n => $island) {
        if ($n === $at) remove_filter('gform_disable_view_counter', '__return_true');
        $html = GFForms::get_form((int) $island['form'], false, false, false, null, (bool) $island['ajax'], 0,
            $island['theme'], $island['styles'] === '' ? null : $island['styles']);
    }
    // Forms inside post content pass through the_content(): a block's render is texturized (curly apostrophes in the
    // French form text; the export records which), then "]]>" is rewritten as "]]&gt;".
    if (!empty($islands[$at]['texturize'])) $html = wptexturize($html);
    if (!empty($islands[$at]['in_content'])) $html = str_replace(']]>', ']]&gt;', $html);
    hx_form_island_send(200, 'text/html', $html);
});

// The page is found by path and language; its query (catalog parameters, utm tags) only shapes the form action.
function hx_form_island_list($lang, $page) {
    if (!in_array($lang, array('en', 'fr'), true) || !str_starts_with($page, '/')) return null;
    $path = parse_url($page, PHP_URL_PATH);
    $manifest = json_decode((string) @file_get_contents(HX_FORM_ISLAND_MANIFEST), true);
    foreach ($manifest['pages'] ?? array() as $entry) {
        $url = $entry[$lang]['url'] ?? null;
        if ($url !== null && parse_url($url, PHP_URL_PATH) === $path) {
            return $entry[$lang]['islands'] ?? array();
        }
    }
    return null;
}

function hx_form_island_send($status, $type, $body) {
    status_header($status);
    header('Cache-Control: no-store');
    header("Content-Type: $type; charset=UTF-8");
    echo $body;
    exit;
}

<?php
require_once "scs_header.php";
$database->user->access("Customer");
if ( (!$_SESSION['user']->id) || ($_SESSION['user']->status != "Active") ) exit;

if (isset($_GET['download'])) {
    $database->product_certificate->read(intval($_GET['download']));
    if (!$database->product_certificate->data->id) {
        http_response_code(404);
        die("Certificate not found");
    }
    $html = product_certificate_pdf_html($database->product_certificate->data);
    $filename = "Certificate_" . preg_replace("/[^A-Za-z0-9_-]/", "", $database->product_certificate->data->sku) . ".pdf";
    require_once "scs_pdf.php";
    new scs_pdf_class($html, array("file" => $filename));
}

if ((isset($_SERVER['HTTP_X_REQUESTED_WITH'])) && ($_SERVER['REQUEST_METHOD'] == "POST") && ($_POST['action'] == "search")) {
    $results = array();
    $results['content'] = product_certificate_search_output(strtoupper(trim($_POST['sku'])));
    die(json_encode($results));
}

$forms->title("Harcourt | Harcourt Certified™");
$forms->html->meta("description", "Look up and download Harcourt Industrial pull test certificates by product number.");
$menu->head();
?>
<style>
.pc-page { background:#f5f6f8; padding:32px 16px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif; }
.pc-card { max-width:820px; margin:0 auto; background:#fff; border-radius:12px; box-shadow:0 1px 3px rgba(0,0,0,0.08); padding:32px; }
.pc-card h2 { margin:0 0 4px; font-size:22px; }
.pc-card p.pc-subtitle { margin:0 0 20px; color:#6b7280; }
.pc-search-row { display:flex; gap:0; }
.pc-search-row input { flex:1; border:1px solid #e5e7eb; background:#f3f4f6; border-radius:8px 0 0 8px; padding:12px 16px; font-size:15px; outline:none; }
.pc-search-row button { border:none; background:#6b7280; color:#fff; padding:0 22px; border-radius:0 8px 8px 0; cursor:pointer; font-size:15px; font-weight:600; }
.pc-search-row button:hover { background:#4b5563; }
.pc-dashboard-link { text-align:center; margin:28px auto 0; }
.pc-dashboard-link a { display:inline-flex; align-items:center; gap:8px; border:1px solid #e5e7eb; border-radius:10px; padding:10px 20px; color:#111827; text-decoration:none; font-weight:600; }
.pc-empty { text-align:center; color:#6b7280; padding:32px 0 8px; }
.pc-results { margin-top:24px; border-top:1px solid #e5e7eb; }
.pc-result-row { display:flex; justify-content:space-between; align-items:center; padding:16px 0; border-bottom:1px solid #e5e7eb; gap:16px; }
.pc-result-sku { font-weight:700; }
.pc-result-desc, .pc-result-date { color:#6b7280; font-size:14px; }
.pc-download-btn { background:#111827; color:#fff; text-decoration:none; padding:10px 18px; border-radius:8px; font-weight:600; font-size:14px; white-space:nowrap; }
.pc-download-btn:hover { background:#000; }
</style>
<script>
function pc_search() {
    var sku = jQuery("#pc_sku").val().trim();
    jQuery.ajax({
        type: "post",
        dataType: "json",
        data: { action: "search", sku: sku },
        success: function(response) {
            jQuery("#pc_results").html(response.content);
            scscpq_ipc("scscpq_wp_iframe", "resize", jQuery("#scs_content").height());
        },
        error: function(xhr) {
            console.log(xhr);
        }
    });
}
jQuery(function() {
    scscpq_ipc("scscpq_wp_iframe", "resize", jQuery("#scs_content").height());
    jQuery("#pc_sku").on("keydown", function(e) {
        if (e.which == 13) {
            e.preventDefault();
            pc_search();
        }
    });
});
</script>
<?php
print "<div class='pc-page'>";
print "<div class='pc-card'>";
print "<h2>Harcourt Certified&trade;</h2>";
print "<p class='pc-subtitle'>Enter your product number to view and download the pull test certificate</p>";
print "<div class='pc-search-row'>";
print "<input type='text' id='pc_sku' placeholder='Enter product number (e.g., H0000001)' autocomplete='off'>";
print "<button type='button' onclick='pc_search();'>&#128269; Search</button>";
print "</div>";
if (role_access($_SESSION['user']->type, "Administrator")) {
    print "<div class='pc-dashboard-link'><a href='/product-certificates/' target='_blank'>&#128737; Certificate Dashboard</a></div>";
}
print "<div id='pc_results'><p class='pc-empty'>Enter a product number to view its certificate.</p></div>";
print "</div>";
print "</div>";
$menu->copyright();

function product_certificate_search_output($sku) {
    global $database;
    if (!strlen($sku)) return "<p class='pc-empty'>Please enter a product number.</p>";
    $query = array("select * from product_certificate");
    $query[] = "where sku=" . fn_escape($sku);
    $query[] = "order by testing_date desc";
    $database->product_certificate->query($query);
    $certificates = array();
    while ($database->product_certificate->fetch = $database->product_certificate->fetch_array()) {
        $database->product_certificate->fetch();
        $certificates[] = clone $database->product_certificate->data;
    }
    $database->product_certificate->free_result();
    if (!sizeof($certificates)) {
        return "<p class='pc-empty'>No certificate found for product number &ldquo;" . htmlspecialchars($sku) . "&rdquo;.</p>";
    }
    $results = array();
    $results[] = "<div class='pc-results'>";
    foreach ($certificates as $cert) {
        $results[] = "<div class='pc-result-row'>";
        $results[] = "<div class='pc-result-info'>";
        $results[] = "<div class='pc-result-sku'>" . htmlspecialchars($cert->sku) . "</div>";
        if (strlen($cert->product_description)) $results[] = "<div class='pc-result-desc'>" . htmlspecialchars($cert->product_description) . "</div>";
        $date = fn_date($cert->testing_date, "ymd");
        if (strlen($date)) $results[] = "<div class='pc-result-date'>Tested " . htmlspecialchars($date) . "</div>";
        $results[] = "</div>";
        $results[] = "<a class='pc-download-btn' href='/product_certificate.php?download=" . intval($cert->id) . "' target='_blank'>Download PDF</a>";
        $results[] = "</div>";
    }
    $results[] = "</div>";
    return implode("", $results);
}

function product_certificate_pdf_html($data) {
    $logo = $_SERVER['DOCUMENT_ROOT'] . "/scs_images/logo.jpg";
    $signature = $_SERVER['DOCUMENT_ROOT'] . "/scs_images/certificate_signature.png";
    $rows = array();
    $rows[] = "<html><head><style>";
    $rows[] = "body { font-family: sans-serif; color:#111; font-size:12px; }";
    $rows[] = "h1 { font-size:20px; margin:0; }";
    $rows[] = "table { width:100%; border-collapse:collapse; }";
    $rows[] = "td { padding:6px 4px; vertical-align:top; }";
    $rows[] = ".label { font-weight:bold; }";
    $rows[] = "hr { border:none; border-top:2px solid #000; margin:14px 0; }";
    $rows[] = "</style></head><body>";
    $rows[] = "<table><tr>";
    $rows[] = "<td width='50%'><img src='" . $logo . "' height='55'></td>";
    $rows[] = "<td width='50%' style='text-align:right;'><h1>Certificate of Proof Load Test</h1></td>";
    $rows[] = "</tr></table>";
    $rows[] = "<p>Harcourt Industrial Inc.<br>1100 East Whitcomb Ave<br>Madison Heights, MI 48071</p>";
    $rows[] = "<hr>";
    $rows[] = "<table>";
    $rows[] = "<tr><td width='50%'><span class='label'>Part Number:</span> " . htmlspecialchars($data->sku) . "</td><td width='50%'><span class='label'>Product Weight:</span> " . htmlspecialchars($data->product_weight) . "</td></tr>";
    $rows[] = "<tr><td colspan='2'><span class='label'>Product Description:</span> " . htmlspecialchars($data->product_description) . "</td></tr>";
    $rows[] = "<tr><td colspan='2'><span class='label'>Product Family:</span> " . htmlspecialchars($data->product_family) . "</td></tr>";
    $rows[] = "<tr><td><span class='label'>Rated Load:</span> " . htmlspecialchars($data->rated_load) . "</td><td><span class='label'>Converted Rate:</span> " . htmlspecialchars($data->conversion) . "</td></tr>";
    $rows[] = "<tr><td colspan='2'><span class='label'>Manufactured Date:</span> " . htmlspecialchars(fn_date($data->manufacturing_date, "ymd")) . "</td></tr>";
    $rows[] = "<tr><td colspan='2'><span class='label'>Test Date:</span> " . htmlspecialchars(fn_date($data->testing_date, "ymd")) . "</td></tr>";
    $rows[] = "</table>";
    $rows[] = "<hr>";
    $rows[] = "<table>";
    $rows[] = "<tr><td width='50%'><span class='label'>Test Machine:</span> " . htmlspecialchars($data->test_machine) . "</td><td width='50%'><span class='label'>Calibration Date:</span> " . htmlspecialchars(fn_date($data->test_machine_calibration_date, "ymd")) . "</td></tr>";
    $rows[] = "</table>";
    $rows[] = "<p><span class='label'>ADB Calibrated Equipment Per ASTM E4-20</span></p>";
    $rows[] = "<p>A Non-Destructive Test was gradually loaded to 20,000 Lbs, or 200% and was held for 3 Seconds. Then the Hoist Ring(s) were examined showing NO damage to the product.</p>";
    $rows[] = "<hr>";
    $rows[] = "<p>Harcourt Industrial Inc certifies that the following parts were 100% load tested and passed all safety checks.</p>";
    $rows[] = "<table><tr><td width='60%'>&nbsp;</td><td width='40%' style='text-align:right;'>";
    $rows[] = "<img src='" . $signature . "' width='87' height='45'><br>";
    $rows[] = "<div style='width:87px; margin-left:auto; border-top:1px solid #000; padding-top:4px; text-align:right;'>Rob Beardmore<br>President</div>";
    $rows[] = "</td></tr></table>";
    $rows[] = "</body></html>";
    return implode("", $rows);
}
?>

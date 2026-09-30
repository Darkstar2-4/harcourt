<?php
require_once "scs_header.php";
require_once "map_rev3.php";
require_once "classes/google_sheets.php";
$database->user->access("Administrator");
$forms->title("HCERT Data");

$forms->report['action']=$_POST['action'];
$forms->report['function']=$_POST['function'];
$forms->report['file_type']=$_POST['file_type'];
$forms->report['sku']=strtoupper(trim($_POST['sku']));

// Google Sheet functions reuse the review/upload import path
$sheets=google_sheets_class::from_env($env);
$sheet_configured=( ($sheets->configured()) && (strlen($env['HCERT_SHEET_ID'])) );
$sheet_function=( ($sheet_configured) && (preg_match("/^sheet_(review|upload)$/",$forms->report['function'],$match)) ) ? $match[1] : "";
$map_report=$forms->report;
if ($sheet_function) $map_report['function']=$sheet_function;

$map=new map_class("product_certificate",$map_report);
if ($sheet_configured) {
	$map->function_list("sheet_review","Google Sheet => WWW (Review only)");
	$map->function_list("sheet_upload","Google Sheet => WWW (Update)");
}
$map->function_list("domains","Customer Domains");
$domain_panel=FALSE;

switch (TRUE) {
// Cancel
  case ($forms->report['action']=="cancel"):
	$forms->message[]="Request Cancelled";
	break;
// Google Sheet functions begin
  case (($sheet_function) && ($forms->report['action']=="prelist")):
	$rows=$sheets->rows($env['HCERT_SHEET_ID'],$env['HCERT_SHEET_TAB']);
	if ($rows === FALSE) {
		$forms->error('upload');
		$forms->message[]=htmlspecialchars($sheets->error);
		break;
	}
	$forms->message[]="Google Sheet: <b>" . htmlspecialchars($sheets->title) . "</b> / " . htmlspecialchars($sheets->tab);
	$temp=tempnam(sys_get_temp_dir(),"hcert");
	$csv=$temp . ".csv";
	google_sheets_class::csv($rows,$csv);
	if (!$map->upload("upload",array("external_file"=>$csv))) $forms->error('upload');
	@unlink($csv);
	@unlink($temp);
	$map->external_file="Google Sheet: {$sheets->title} / {$sheets->tab}";
	if ($map->message) $forms->message[]=$map->message;
	break;
  case (($sheet_function) && ($forms->report['action']=="update")):
	$forms->message[]=$map->import();
	break;
// Google Sheet functions end
// Customer domain functions begin
  case (($forms->report['function']=="domains") && (in_array($forms->report['action'],array("prelist","domain_add","domain_remove"))) && (!strlen($forms->report['sku']))):
	$forms->message[]="Enter a part number";
	break;
  case (($forms->report['function']=="domains") && ($forms->report['action']=="domain_add")):
	$domain_panel=TRUE;
	$domains=$database->product_certificate->domain_list($_POST['domain']);
	if (!sizeof($domains)) $forms->message[]="Enter a domain to add";
	foreach ($domains as $domain) {
		$error=$database->product_certificate->domain_error($domain);
		if ($error) {
			$forms->message[]=htmlspecialchars($error);
			continue;
		}
		$database->product_certificate->domain_add($forms->report['sku'],$domain);
		$database->log->ipc(array("Product Certificate","Domain Add"),"Part Number {$forms->report['sku']}: {$domain}");
		$forms->message[]="Added " . htmlspecialchars($domain);
	}
	break;
  case (($forms->report['function']=="domains") && ($forms->report['action']=="domain_remove")):
	$domain_panel=TRUE;
	$domain=$database->product_certificate->domain_normalize($_POST['remove_domain']);
	$database->product_certificate->domain_delete($forms->report['sku'],$domain);
	$database->log->ipc(array("Product Certificate","Domain Remove"),"Part Number {$forms->report['sku']}: {$domain}");
	$forms->message[]="Removed " . htmlspecialchars($domain);
	break;
  case (($forms->report['function']=="domains") && ($forms->report['action']=="prelist")):
	$domain_panel=TRUE;
	break;
// Customer domain functions end
// Template begins
  case ($forms->report['function']=="template"):
	$map->template();
	break;
// Template ends

// Download functions begin
  case (($forms->report['function']=="download") && ($forms->report['action']=="prelist")):
	$query=$database->product_certificate->export_query();
	$database->product_certificate->query($query);
	$forms->report['count']=$database->product_certificate->meta->rows;
    switch (TRUE) {
      case (!$forms->report['count']):
        $forms->error('type');
        $forms->message[]="No records found";
      	break;
    }
    break;
  case (($forms->report['function']=="download") && ($forms->report['action']=="update")):
    $map->export();
 	break;
// Download functions end

// Upload/review functions begin
  case (($forms->report['function']=="review") && ($forms->report['action']=="prelist")):
  case (($forms->report['function']=="upload") && ($forms->report['action']=="prelist")):
	if (!$map->upload("upload")) $forms->error('upload');
    if ($map->message) $forms->message[]=$map->message;
    break;
   case (($forms->report['function']=="review") && ($forms->report['action']=="update")):
   case (($forms->report['function']=="upload") && ($forms->report['action']=="update")):
	$forms->message[]=$map->import();
	break;
// Upload/review functions end
}
$domain_warning=$database->product_certificate->domain_warning;
if (sizeof($domain_warning)) {
	$forms->message[]="Customer domains skipped: " . sizeof($domain_warning) . "<br>" . implode("<br>",array_map("htmlspecialchars",$domain_warning));
	if ($database->product_certificate->map->update) $database->log->ipc(array("Product Certificate","Domain Skipped"),implode("\n",$domain_warning));
}
$menu->head();
print $forms->message();
print $forms->open(array("name"=>"template_download_form"));
print "<input type='hidden' name='function' value='template' id='template_function'>\n";
print "<input type='hidden' name='file_type' value='.csv' id='template_file_type'>\n";
print $forms->close();
print "<p><a href='javascript:void(0);' onclick=\"document.template_download_form.submit();\">Download CSV Template</a>";
if ($sheet_configured) print " | <a href='https://docs.google.com/spreadsheets/d/" . rawurlencode($env['HCERT_SHEET_ID']) . "/edit' target='_blank' rel='noopener'>Open Google Sheet</a>";
print "</p>\n";
?>
<script type='text/javascript'>
jQuery(document).ready(function() {
    import_screen();
});
function import_screen() {
	file_type_row=0;
    upload_row=0;
    sku_row=0;
	switch ( jQuery("#function").val() ) {
      case "":
      	break;
	  case "download":
		file_type_row=1;
		break;
	  case "domains":
		sku_row=1;
		break;
	  case "sheet_review":
	  case "sheet_upload":
		break;
      default:
		upload_row=1;
	}
    if (sku_row) {
		jQuery("#sku_row").show();
	  } else {
		jQuery("#sku_row").hide();
	}
    if (file_type_row) {
		jQuery("#file_type_row").show();
	  } else {
		jQuery("#file_type_row").hide();
	}
    if (upload_row) {
		jQuery("#upload_row").show();
	  } else {
		jQuery("#upload_row").hide();
	}
}
function review_overwrite() {
     if (confirm('Replace all records with import file?')) {
     	fn_action('update');
     }
}
function fn_action(action) {
	document.scs_form.action.value=action;
	document.scs_form.submit();
}
function domain_remove(domain) {
	if (confirm('Remove ' + domain + ' from this part number?')) {
		document.scs_form.remove_domain.value=domain;
		fn_action('domain_remove');
	}
}
function domain_enter(e, action) {
	if (e.which == 13) {
		e.preventDefault();
		fn_action(action);
	}
}
</script>
<?php
print $forms->open(array("data"=>TRUE));
print $forms->hidden("action");
switch (TRUE) {
  case (($forms->report['action']=="prelist") && ($forms->report['function']=="download") && (!sizeof($forms->error))):
	$map->export_html($forms->report['title'], $forms->report['count']);
	break;
  case (($forms->report['action']=="prelist") && ($forms->report['function']=="review") && (!sizeof($forms->error))):
  case (($forms->report['action']=="prelist") && ($forms->report['function']=="upload") && (!sizeof($forms->error))):
  case (($forms->report['action']=="prelist") && ($sheet_function) && (!sizeof($forms->error))):
	$map->upload_html();
	break;
  case (($forms->report['action']=="update") && ($forms->report['function']=="review")):
  case (($forms->report['action']=="update") && ($forms->report['function']=="upload")):
  case (($forms->report['action']=="update") && ($sheet_function)):
	$map->import_html();
	break;
  case ($domain_panel):
	$sku=$forms->report['sku'];
	$database->product_certificate->query(array("select id from product_certificate where sku=" . fn_escape($sku)));
	$certificate_count=$database->product_certificate->meta->rows;
	$database->product_certificate->free_result();
	$domains=$database->product_certificate->domains($sku);
	print "<input type='hidden' name='sku' value='" . htmlspecialchars($sku, ENT_QUOTES) . "'>";
	print "<input type='hidden' name='remove_domain' value=''>";
	print "<p class='standard'>Part Number: <b>" . htmlspecialchars($sku) . "</b> (" . number_format($certificate_count) . " certificate" . (($certificate_count == 1) ? "" : "s") . " on file)</p>";
	print "<table class='standard border'>";
	print "<tr><th>Customer Domain</th><th>&nbsp;</th></tr>";
	if (!sizeof($domains)) print "<tr><td colspan=2>No domains assigned. Certificates for this part number are private to Harcourt.</td></tr>";
	foreach ($domains as $domain) {
		print "<tr>";
		print "<td>" . htmlspecialchars($domain) . "</td>";
		print "<td>" . $forms->button("Remove",array("onclick"=>"domain_remove(" . htmlspecialchars(json_encode($domain), ENT_QUOTES) . ");")) . "</td>";
		print "</tr>";
	}
	print "</table>";
	print "<p class='standard'>Add domain: <input type='text' name='domain' size='40' placeholder='customer.com' onkeydown=\"domain_enter(event,'domain_add');\"> ";
	print $forms->button("Add",array("onclick"=>"fn_action('domain_add');")) . " ";
	print $forms->button("Return",array("onclick"=>"fn_action('');")) . "</p>";
	break;
  default:
	print "<table class='standard noborder'>";
	print "<tr>";
	print "<td width=30%>Function</td>";
	print "<td width=70%>" . $forms->select("function",$map->function_list,$forms->report['function'],TRUE,array("onchange"=>"import_screen();")) . "</td>";
    print "</tr>";
	print "<tr id='file_type_row'>";
	print "<td>File type</td>";
	print "<td>" . $forms->select("file_type",$map->file_type_list,$forms->report['file_type'],FALSE) . "</td>";
	print "</tr>";
	print "<tr id='upload_row'>";
	print "<td>Upload File</td>";
	print "<td>" . $forms->file("upload") . "</td>";
	print "</tr>";
	print "<tr id='sku_row'>";
	print "<td>Part Number</td>";
	print "<td><input type='text' name='sku' size='30' value='" . htmlspecialchars($forms->report['sku'], ENT_QUOTES) . "' onkeydown=\"domain_enter(event,'prelist');\"></td>";
	print "</tr>";
	print "<tr>";
    print "<td>&nbsp;</td>";
	print "<td>" . $forms->button("Go!",array("onclick"=>"fn_action('prelist');")) . "</td>";
    print "</tr>";
	print "</table>";
}
$fields=array();
$fields["function"]=$forms->report['function'];
$fields["file_type"]=$forms->report['file_type'];
$forms->hidden_fields($fields);
print $forms->close();
$menu->copyright();
?>

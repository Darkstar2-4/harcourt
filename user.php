<?php
require_once "scs_header.php";
require_once "classes/portal.php";
require_once "classes/industry.php";
$database->user->access("Administrator");
$forms->title("User Manager");
$forms->report['action']=$_REQUEST['action'];
$forms->report['record_id']=$_REQUEST['record_id'];
$forms->report['return_url']=$_REQUEST['return_url'];
$forms->report['page']=$_POST['page'];
$forms->report['report_status']=$_POST['report_status'];
$forms->report['report_type']=$_POST['report_type'];
$forms->report['report_option_field']=$_POST['report_option_field'];
$forms->report['report_option_value']=$_POST['report_option_value'];
switch (TRUE) {
  case ($forms->report['record_id']):
	$database->user->read($forms->report['record_id']);
    break;
  case ($_POST['ajax_user_id']):
	$database->user->read($_POST['ajax_user_id']);
	break;
  default:
	$database->user->data=new user_data_class();
}
switch ($forms->report['action']) {
  case "":
    break;
  case "cancel":
    $forms->message[]="Request cancelled";
    break;
  case "delete":
    $database->user->delete($forms->report['record_id']);
    $forms->message[]="IP address " . $database->user->data->ip . " deleted";
    break;
  case "maintain":
    break;
  case "update":
	$previous_status=$database->user->data->status;
	$database->user->data->ip=$_POST['ip'];
    switch (TRUE) {
      case (!intval(ip2long($database->user->data->ip))):
      case (long2ip(ip2long($database->user->data->ip)) != $_POST['ip']):
      	$forms->error('ip');
    }
    $database->profile->data=new profile_data_class();
    $address=new address_class("profile", $database->user->data);
    unset($address->registry->name, $address->registry->title, $address->registry->phone, $address->registry->fax, $address->registry->email);
    $address->verify();
	$database->user->data->status=$_POST['status'];
	$database->user->data->type=$_POST['type'];
	$database->user->data->currency_code=$_POST['currency_code'];
    if (!strlen($database->user->data->currency_code)) $forms->error('currency_code');
	$database->user->data->language_code=$_POST['language_code'];
	$database->user->data->portal_id=intval($_POST['portal_id']);
	$database->user->data->login_document_id=intval($_POST['login_document_id']);
	$database->user->data->landing_document_id=intval($_POST['landing_document_id']);
	$database->user->data->customer_code=strtoupper(trim($_POST['customer_code']));
	$database->user->data->catalog=$_POST['catalog'];
	$database->user->data->ecommerce=$_POST['ecommerce'];
	$database->user->data->price_type=$_POST['price_type'];
	$database->user->data->price_pct=(fn_number($_POST['price_pct']) / 100);
	$database->user->data->cad=$_POST['cad'];
	$database->user->data->industry_id=$_POST['industry_id'];
	$database->user->data->leadscore=$_POST['leadscore'];
	$database->user->data->revenue=fn_text($_POST['revenue']);
	$database->user->data->employees=fn_text($_POST['employees']);
    if ($forms->report['return_url']) {
		$database->user->data->sf_rating=$_POST['sf_rating'];
		$database->user->data->sf_campaign_code=$_POST['campaign_code'];
		$database->user->data->sf_call=intval($_POST['sf_call']);
		$database->user->data->sf_email=intval($_POST['sf_email']);
		$database->user->data->sf_fax=intval($_POST['sf_fax']);
    }
    switch (TRUE) {
	  case ( (!$database->user->data->catalog) && ($database->user->data->price_type) ):
		$forms->error('price_type','Pricing requires catalog access');
        break;
	}
    switch (TRUE) {
	  case ( (!$database->user->data->catalog) && ($database->user->data->cad) ):
		$forms->error('cad','CAD requires catalog access');
        break;
	}
    switch ($database->user->data->price_type) {
	  case "":
		$database->user->data->price_pct=0;
		break;
      case "base":
		if ($database->user->data->price_pct) $forms->error("price_pct","Must be 0% for base price");
        break;
      case "discount":
		if ( ($database->user->data->price_pct <= 0) || ($database->user->data->price_pct >= 1) ) $forms->error("price_pct","Discount must be > 0% and < 100%");
        break;
      case "plus":
		if ( ($database->user->data->price_pct <= 0) || ($database->user->data->price_pct >= 5) ) $forms->error("price_pct","Price plus must be > 0% and < 500%");
        break;
	}
	switch (TRUE) {
      case (!$database->user->data->portal_id):
      	break;
       case ($database->user->data->login_document_id):
       case ($database->user->data->landing_document_id):
		$forms->error("portal_id","","Landing documents not allowed when portal is selected");
	}
	if (sizeof($forms->error)) break;

    $database->user->update($database->user->meta->rows);
    switch (TRUE) {
	  case (!$database->user->meta->error):
    	break;
	  default:
		$forms->error('ip','',$database->user->meta->error);
    }
	if (sizeof($forms->error)) break;
    if ($_POST['ipstack']) $database->user->ipstack();
    $forms->message[]="Record maintained";
    if ( ($previous_status != "Active") && ($database->user->data->status == "Active") ) {
        $query=array("select email from log");
        $query[]="where user_id=" . fn_escape($database->user->data->id,FALSE);
        $query[]="and type='User' and subtype='Signup'";
        $query[]="and email <> ''";
        $query[]="order by date_time desc";
        $query[]="limit 1";
        $database->log->query($query);
        if ($database->log->meta->rows) {
            $signup=$database->log->fetch_array();
            $database->log->free_result();
            $database->captcha_bypass->read($signup['email'],"email");
            if (!$database->captcha_bypass->meta->rows) {
                $database->captcha_bypass->data=new captcha_bypass_data_class();
                $database->captcha_bypass->data->email=$signup['email'];
                $database->captcha_bypass->data->comment="Auto-added on user approval";
                $database->captcha_bypass->update(FALSE);
            }
        }
    }
    $database->temp->query("DELETE FROM user_category WHERE user_id=" . fn_escape($database->user->data->id,FALSE));
    $database->category->query("SELECT id FROM category WHERE active=1");
    while ($database->category->fetch=$database->category->fetch_array()) {
        $database->category->fetch();
        if ($_POST["user_category_" . $database->category->data->id]) {
            $database->temp->query("INSERT INTO user_category (user_id, category_id) VALUES (" . fn_escape($database->user->data->id,FALSE) . "," . fn_escape($database->category->data->id,FALSE) . ")");
        }
    }
    $database->category->free_result();
    if ($forms->report['return_url']) {
    	if ($database->user->data->status == "Active") {
        	$data=array();
            $data['old']="00D4T000000F1jA";
            $data['retURL']="http://";
            $data['first_name']=$database->user->data->first_name;
            $data['last_name']=$database->user->data->last_name;
            $data['email']=$database->user->data->email;
            $data['company']=$database->user->data->company_name;
            $data['city']=$database->user->data->city;
            $data['state']=$database->user->data->city;
            $database->industry->read($database->user->data->industry_id);
            $data['industry']=$database->industry->data->name;
            $data['rating']=$database->user->data->sf_rating;
            $data['revenue']=$database->user->data->revenue;
            $data['employees']=$database->user->data->employees;
            $database->campaign->read($database->user->data->sf_campaign_code,"code");
            $data['Campaign_ID']=$database->campaign->data->sf_campaign_id;
            $data['member_status']="";
            $data['emailOptOut']=intval($database->user->data->sf_email);
            $data['faxOptOut']=intval($database->user->data->sf_fax);
            $data['doNotCall']=intval($database->user->data->sf_fax);
			$fh=fopen("sf.txt","w");
	        $ch=curl_init();
	        curl_setopt($ch,CURLOPT_URL, "https://webto.salesforce.com/servlet/servlet.WebToLead?encoding=UTF-8");
	        curl_setopt($ch, CURLOPT_HEADER, 0);
	        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	        curl_setopt($ch, CURLOPT_POST, 1);
	        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
		    curl_setopt($ch, CURLOPT_VERBOSE, true);
		    curl_setopt($ch, CURLOPT_STDERR, $fh);
	        $curl_response=curl_exec($ch);
	        $curl_error_no=intval(curl_errno($ch));
	        $curl_info=curl_getinfo($ch);
	        curl_close($ch);
            fclose($fh);
		}
    	fn_url_redirect($forms->report['return_url']);
	}
	break;
}
$menu->head();
print $forms->message();
?>
<script>
function fn_action(obj,action,id,name) {
console.log(action);
	if ( (action == 'delete') && (!confirm('Delete: #' + id + ' ' + name)) ) {
        obj.checked=false;
        return;
    }
	document.scs_form.action.value=action;
	document.scs_form.record_id.value=id;
	document.scs_form.submit();
}
</script>
<?php
print $forms->open();
print $forms->hidden("action");
print $forms->hidden("record_id");
print $forms->hidden("page");
print $forms->hidden("return_url",$forms->report['return_url']);
switch (TRUE) {
  case ($forms->report['action'] == "maintain"):
  case (($forms->report['action'] == "update") && (sizeof($forms->error))):
	print $forms->hidden("report_status",$forms->report['report_status']);
	print $forms->hidden("report_type",$forms->report['report_type']);
	print $forms->hidden("report_option_field",$forms->report['report_option_field']);
	print $forms->hidden("report_option_value",$forms->report['report_option_value']);
	$menu->tabs=new jquery_tab_class();
	$forms->constant->parse=parse_object($database->user->data->ipstack_json);
    $menu->tabs->item("Info",tab_info());
    $menu->tabs->item("Category Access",tab_category());
    $menu->tabs->item("IPStack",$forms->constant->parse);
    $menu->tabs->output();
    $buttons=array();
    $buttons[]=$forms->button("Update",array("onclick"=>"fn_action(this,'update',"  . fn_escape($forms->report['record_id']) . ",'');"));
    $buttons[]=$forms->button("Cancel",array("onclick"=>"fn_action(this,'cancel','0','');"));
	print "<p>" . implode(" ",$buttons) . "</p>";
  	break;
  default:
	print "<table class='standard noborder'>";
    print "<tr>";
    print "<td width='20%'>Type</td>";
    print "<td width='80%'>" . $forms->select("report_type",$database->user->type_list(),$forms->report['report_type']) . "</td>";
    print "</tr>";
    print "<tr>";
    print "<td width='20%'>Status</td>";
    print "<td width='80%'>" . $forms->select("report_status",$database->user->constant->status,$forms->report['report_status']) . "</td>";
    print "</tr>";
    print "<tr>";
    print "<td width='20%'>" . $forms->select("report_option_field",array("company_name"=>"Company Name","ip"=>"IP Address","domain"=>"Domain"),$forms->report['report_option_field'],FALSE) . "</td>";
    print "<td width='80%'>" . $forms->text("report_option_value",$forms->report['report_option_value'],30) . "</td>";
    print "</tr>";
    print "</table>";
	print "<p>" . $forms->button("List",array("onclick"=>"fn_action(this,'list','','');")) . "</p>";
	print "<table class='small border tablesorter'>";
    print "<caption>&nbsp;</caption>";
    print "<thead>";
    print "<tr>";
    print "<th>IP</th>";
    print "<th>Company Name</th>";
    print "<th>City</th>";
    print "<th>State</th>";
    print "<th>Zip</th>";
    print "<th>Country</th>";
    print "<th>Status</th>";
    print "<th>Del?</th>";
    print "</tr>";
    print "</thead>";
    print "<tr>";
    print
    	"<td class=standard colspan=5>Copy from: " . $forms->text('ajax_user_id','',8) . " " .
        $forms->button("Add New",array("onclick"=>"fn_action(this,'maintain','0','');")) .
        "<br><span id='ajax_user_label'></span></td>";
    print "</tr>";
    print "<tbody>";
    if ($forms->report['action'] == "list") {
 		$query_where=array();
        if ($forms->report['report_type']) {
			$query_where[]=new query_where("type","=",$forms->report['report_type']);
		  } else {
			$query_where[]=new query_where("type","in",$database->user->type_list());
		}
        if ($forms->report['report_status']) $query_where[]=new query_where("status","=",$forms->report['report_status']);
        if ($forms->report['report_option_value']) $query_where[]=new query_where($forms->report['report_option_field'],"LIKE",$forms->report['report_option_value'] . "%");
        $query=array("select * from user");
	    $query[]=$database->where($query_where);
	    $query[]="order by company_name,ip";
        $query[]=$database->page_limit($forms->report['page']);
	    $database->user->query($query,TRUE);
	    while ($database->user->fetch = $database->user->fetch_array() ) {
	        $database->user->fetch();
	        print "<tr>";
	        print "<td>" . fn_href($database->user->data->ip,"javascript:fn_action(this,'maintain'," . fn_escape($database->user->data->id) . ",'');") . "</td>";
	        print "<td>" . $database->user->data->company_name . "</td>";
            print "<td>" . $database->user->data->city . "</td>";
            print "<td>" . $database->user->data->state . "</td>";
            print "<td>" . $database->user->data->zip . "</td>";
            print "<td>" . $database->user->data->country_code . "</td>";
	        print "<td>" . $database->user->data->status . "</td>";
			if ($database->user->data->status == "Active") {
	            $text="&nbsp;";
			  } else {
				$text=$forms->radio("delete",$database->user->data->id,0,array("onclick"=>"fn_action(this,'delete'," . fn_escape($database->user->data->id) . "," . fn_escape($database->user->data->ip) . ");"));
	        }
	        print "<td class=center>" . $text . "</td>";
	        print "</td>";
	        print "</tr>";
	    }
	}
    print "</tbody>";
    print "</table>";
	$database->user->free_result();
    if ($forms->report['action']=="list") {
		$page_break=new page_item_break($database->user->meta->found_rows,"fn_page_item('list',%page%,00);");
        print $page_break->page($forms->report['page']);
	}
}
print $forms->close();
$menu->copyright();
function tab_info() {
global $database, $forms, $menu;
	$results=array();
	$results[]="<table class='standard noborder'>";
    $results[]="<tr>";
    $results[]="<td width=30%>IP Address</td>";
    $results[]="<td width=70%>" . $forms->text("ip",$database->user->data->ip,50,50) . "</td>";
    $results[]="</tr>";
    if ($database->user->data->comment) {
    	$results[]="<tr>";
	    $results[]="<td>Request comment</td>";
	    $results[]="<td><b>" . $database->user->data->comment . "</b></td>";
	    $results[]="</tr>";
    }
    $database->profile->data=new profile_data_class();
    $address=new address_class("profile", $database->user->data);
    unset($address->registry->name, $address->registry->title, $address->registry->phone, $address->registry->fax, $address->registry->email);
    $results[]=$address->input();

    $results[]="<tr>";
    $results[]="<td>Annual revenue</td>";
    $results[]="<td>" . $forms->text("revenue",$database->user->data->revenue,20,20) . "</td>";
    $results[]="</tr>";
    $results[]="<tr>";
    $results[]="<td>Employees</td>";
    $results[]="<td>" . $forms->text("employees",$database->user->data->employees,20,20) . "</td>";
    $results[]="</tr>";
    $results[]="<tr>";
    $results[]="<td>Language</td>";
    $results[]="<td>" . $database->language->select($database->user->data->language_code,array("blank"=>FALSE,"local"=>FALSE)) . "</td>";
    $results[]="</tr>";
    $results[]="<tr>";
    $results[]="<td>Currency</td>";
    $results[]="<td>" . $database->currency->select($database->user->data->currency_code) . "</td>";
    $results[]="</tr>";
    $results[]="<tr>";
    $results[]="<td>Industry</td>";
    $results[]="<td>" . $database->industry->select($database->user->data->industry_id) . "</td>";
    $results[]="</tr>";
    $results[]="<tr>";
    $results[]="<td>Status</td>";
    $results[]="<td>" . $forms->select("status",$database->user->constant->status,$database->user->data->status,FALSE) . "</td>";
    $results[]="</tr>";
    $results[]="<tr>";
    $results[]="<td>Type</td>";
    $results[]="<td>" . $forms->select("type",$database->user->type_list(),$database->user->data->type,FALSE) . "</td>";
    $results[]="</tr>";
    $results[]="<tr>";
    $results[]="<td>Customer #</td>";
    $results[]="<td>" . $forms->text("customer_code",$database->user->data->customer_code,12,12) . "</td>";
    $results[]="</tr>";
    $results[]="<tr>";
    $results[]="<td>Lead Score?</td>";
    $results[]="<td>" . $forms->checkbox("leadscore",1,$database->user->data->leadscore) .  "</td>";
    $results[]="</tr>";
    $results[]="<tr>";
    $results[]="<td>Catalog?</td>";
    $text=array( $forms->checkbox("catalog",1,$database->user->data->catalog) );
    if ($forms->error_text['catalog']) $text[]=$forms->error_text['catalog'];
    $results[]="<td>" . implode(" ",$text) . "</td>";
    $results[]="</tr>";
    $results[]="<tr>";
    $results[]="<td>CAD?</td>";
    $text=array( $forms->checkbox("cad",1,$database->user->data->cad) );
    if ($forms->error_text['cad']) $text[]=$forms->error_text['cad'];
    $results[]="<td>" . implode(" ",$text) . "</td>";
    $results[]="</tr>";
    $results[]="<tr>";
    $results[]="<td>ECommerce?</td>";
    $text=array( $forms->checkbox("ecommerce",1,$database->user->data->ecommerce) );
    if ($forms->error_text['ecommerce']) $text[]=$forms->error_text['ecommerce'];
    $results[]="<td>" . implode(" ",$text) . "</td>";
    $results[]="</tr>";
    $results[]="<tr>";
    $text=array( $forms->select("price_type",$database->user->constant->price_type,$database->user->data->price_type,FALSE,array("onchange"=>"$('#price_pct').val('');")) );
    if ($forms->error_text['price_type']) $text[]=$forms->error_text['price_type'];
    $results[]="<td>" . implode("<br>",$text) . "</td>";
    $text=array($forms->text("price_pct",($database->user->data->price_pct * 100),6,6,"decimal:2") . "%");
    if ($forms->error_text['price_pct']) $text[]="<b>" . $forms->error_text['price_pct'] . "</b>";
    $results[]="<td>" . implode(" ",$text) . "</td>";
    $results[]="</tr>";
    $results[]="<tr>";
    $results[]="<td>Portal</td>";
    $results[]="<td>" . $database->portal->select($database->user->data->portal_id) . "</td>";
    $results[]="</tr>";
    $results[]="<tr>";
    $results[]="<td>Login page</td>";
    $results[]="<td>" . $database->document->select($database->user->data->login_document_id,array("field"=>"login_document_id")) . "</td>";
    $results[]="</tr>";
    $results[]="<tr>";
    $results[]="<td>Landing page</td>";
    $results[]="<td>" . $database->document->select($database->user->data->landing_document_id,array("field"=>"landing_document_id")) . "</td>";
    $results[]="</tr>";
    if (!strlen($forms->constant->parse)) {
    	$results[]="<tr>";
      	$results[]="<td>Load IP Stack?</td>";
        $results[]="<td>" . $forms->checkbox("ipstack",1,$forms->report['ipstack']) . "</td>";
    	$results[]="<tr>";
    }

    if ($forms->report['return_url']) {
    	$results[]="<tr><td colspan=2><br><b>Salesforce Integration</b></td></tr>";
        $results[]="<tr>";
        $results[]="<td>Rating</td>";
        $results[]="<td>" . $forms->select("sf_rating",array("Hot","Warm","Cold"),$database->user->data->sf_rating) . "</td>";
        $results[]="</tr>";
        $results[]="<tr>";
        $results[]="<td>Campaign Code</td>";
        $results[]="<td>" . $database->campaign->select($database->user->data->sf_campaign_code,array("active"=>FALSE,"sf"=>TRUE)) . "</td>";
        $results[]="</tr>";
        $results[]="<tr>";
        $results[]="<td>Do not call</td>";
        $results[]="<td>" . $forms->checkbox("sf_call",1,$database->user->data->sf_call) . "</td>";
        $results[]="</tr>";
        $results[]="<tr>";
        $results[]="<td>Do not email</td>";
        $results[]="<td>" . $forms->checkbox("sf_email",1,$database->user->data->sf_email) . "</td>";
        $results[]="</tr>";
        $results[]="<tr>";
        $results[]="<td>Do not fax</td>";
        $results[]="<td>" . $forms->checkbox("sf_fax",1,$database->user->data->sf_fax) . "</td>";
        $results[]="</tr>";
    }
    if ($database->user->data->last_login) {
        $results[]="<tr>";
        $results[]="<td>reCAPTCHA Score</td>";
        $results[]="<td>" . $database->user->recaptcha_score($database->user->data->recaptcha_score) . "</td>";
        $results[]="</tr>";
    }
    if ($database->user->data->last_login) {
        $results[]="<tr>";
        $results[]="<td>Last Login</td>";
        $results[]="<td>" . date("m/d/Y h:i A",$database->user->data->last_login) . "</td>";
        $results[]="</tr>";
    }
    $results[]="</table>";
    return $results;
}
function tab_category() {
    global $database, $forms;
    $blocked_categories=array();
    if ($database->user->data->id) {
        $database->temp->query("SELECT category_id FROM user_category WHERE user_id=" . fn_escape($database->user->data->id,FALSE));
        while ($database->temp->fetch=$database->temp->fetch_array()) {
            $blocked_categories[]=$database->temp->fetch['category_id'];
        }
        $database->temp->free_result();
    }
    $results=array();
    $results[]="<table class='standard noborder'>";
    $results[]="<tr><td colspan=2>Check to restrict access to a category (Integrator Agreement required)</td></tr>";
    $database->category->query("SELECT * FROM category WHERE active=1 ORDER BY sort_order,name");
    while ($database->category->fetch=$database->category->fetch_array()) {
        $database->category->fetch();
        $checked=in_array($database->category->data->id,$blocked_categories) ? 1 : 0;
        $results[]="<tr>";
        $results[]="<td><label style='display:flex;align-items:center;gap:8px;cursor:pointer;'>" . $forms->checkbox("user_category_" . $database->category->data->id,1,$checked) . $database->category->data->name . "</label></td>";
        $results[]="</tr>";
    }
    $database->category->free_result();
    $results[]="</table>";
    return $results;
}
?>
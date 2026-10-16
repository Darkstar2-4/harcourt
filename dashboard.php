<?php
require_once "scs_header.php";
require_once "classes/subcategory.php";
require_once "classes/portal.php";
require_once "classes/portalcategory.php";
$database->user->access("Executive");
$dashboard=new dashboard_class();
class dashboard_class {
	var $tabs;
    function scs_table_version() {
        $results=array();
        $results['07/23/2024']="Add PARTSolutions:Reverse Lookup Error";
        return $results;
    }
    function __construct() {
	    global $database, $forms, $menu;
		$this->stopwatch=new stopwatch_class("Dashboard",2);
	    $forms->title($_SESSION['user']->type . " Dashboard");
	    $menu->head();
	    print $forms->message();

	    print $forms->open(array("url"=>"/user.php"));
	    print $forms->hidden("action","maintain");
	    print $forms->hidden("return_url",$_SERVER['PHP_SELF']);
	    print $forms->hidden("record_id");
		print $forms->close();
		$this->tabs=new jquery_tab_class();
        if (isset($_SESSION['scscpq_wp'])) $this->tabs->meta->options->lineheight="auto";
		parse_str($_SERVER['QUERY_STRING'],$url_query);
        if (array_key_exists("tab",$url_query)) $this->tabs->landing_tab($url_query['tab'],TRUE);
		$this->menu();
	    $this->pending();
		$this->orderhd();
	    $this->leadscore();
	    $this->access();
	    $this->campaign();
        $this->sku_error();
        $this->related_error();
		if ($database->user->access("Super User",FALSE)) $this->tabs->item("Stopwatch",$this->stopwatch->results());
	    $this->tabs->output();
		$menu->copyright();
    }
    function menu() {
	    global $database, $forms, $menu;
		$this->stopwatch->split("Main Menu");
	    $menu_group=new menu_group_class("standard");

	    $menu_group->group("User & BOT Manager");
	    $menu_group->item("Executive","Maintain Users","/user.php");
	    $menu_group->item("Administrator","User Utility","/user_utility.php");
        $menu_group->item("Executive","Email Export","/user_email.php");
	    $menu_group->item("Administrator","User Impersonate","/user_impersonate.php");
	    $menu_group->item("Administrator","Currency Codes","/currency.php");
	    $menu_group->item("Administrator","Maintain Industry","/industry.php");
	    $menu_group->item("Administrator","Maintain BOTS","/bot.php");
	    $menu_group->item("Administrator","BOT Impersonate","/bot_impersonate.php");
	    $menu_group->item("Administrator","Apache Log BOTS","/bot_apache.php");

	    $menu_group->group("Remote Access");
        $menu_group->item("Administrator","Company","/remote_access.php");
        $menu_group->item("Administrator","Access Code","/remote_code.php");
	    $menu_group->item("Administrator","Tokens","/remote_token.php");
	    $menu_group->item("Administrator","Domain Blacklist","/blacklist.php");
	    $menu_group->item("Administrator","reCAPTCHA Bypass","/captcha_bypass.php");

	    $menu_group->group("Document Manager");
	    $menu_group->item("Administrator","Languages","/language.php");
	    $menu_group->item("Executive","Documents","/document.php");
	    $menu_group->item("Executive","Pages","/pages.php");
	    $menu_group->item("Administrator","Campaigns","/campaign_manager.php");

	    $menu_group->group("Portal / Hero Pages");
	    $menu_group->item("Executive","Maintain Customer Portal","/portal.php");
	    $menu_group->item("Executive","Portal Content","/portalcontent.php");
	    $menu_group->item("Executive","Portal Category","/portalcategory.php");
	    $menu_group->item("Executive","Portal Items","/portalitem.php");
//        $menu_group->item("Administrator","Portal Preview","/portal_preview.php");
        $menu_group->item("Administrator","Hero Headline","/hero.php");
        $menu_group->item("Administrator","Hero Preview","/hero_preview.php");

	    $menu_group->group("Inventory");
	    $menu_group->item("Internal","Pricing & CAD","/configure.php");
	    $menu_group->item("Internal","Price Book","/inventory_pricebook.php");
	    $menu_group->item("Executive","Category Codes","/category.php");
	    $menu_group->item("Executive","Subcategory Codes","/subcategory.php");
	    $menu_group->item("Executive","Subcategory Utility","/subcategory_utility.php");
	    $menu_group->item("Executive","Subcategory Order","/subcategory_order.php");
	    $menu_group->item("Executive","Inventory Manager","/inventory.php");
	    $menu_group->item("Administrator","Price Increase","/inventory_increase.php");
	    $menu_group->item("Administrator","Inventory Import/Export","/inventory_utility.php");
	    $menu_group->item("Administrator","Inventory w/o Prices","/inventory_no_price.php");
	    $menu_group->item("Administrator","ERP Pricing Import","/inventory_price.php");
	    $menu_group->item("Administrator","Smart SKU Pricing Utility","/pricing_utility.php");
	    $menu_group->item("Administrator","Product Certificates","/hcert_data.php");

        $menu_group->group("Catalog");
	    $menu_group->item("Administrator","Search Utility","/search_utility.php");
	    $menu_group->item("Administrator","Image Filmstrip","/image_filmstrip.php");
	    $menu_group->item("Administrator","Design Partner Agreement","/design_partner_agreement.php");
	    $menu_group->item("Administrator","Metric Filter","/catalog_metric_filter.php");

	    $menu_group->group("PARTsolutions Integration");
	    $menu_group->item("Administrator","Inventory Import PRJ","/inventory_prj.php");
	    $menu_group->item("Administrator","Inventory Missing PRJ","/inventory_missing_prj.php");
	    $menu_group->item("Administrator","Subcategory PRJ Review","/subcategory_prj.php");
	    $menu_group->item("Administrator","CAD Toolbox","/cad_toolbox.php");
	    $menu_group->item("Administrator","Configurator Toolbox","/configurator_toolbox.php");

	    $menu_group->group("Orders");
	    $menu_group->item("Executive","PO","/orderhd.php");
	    $menu_group->item("Executive","Carrier Codes","/carrier.php");

	    $menu_group->group($_SESSION['user']->type);
	    $menu_group->item("Administrator","Registry","/scs_registry.php");
	    $menu_group->item("Executive","Event Log","/scs_log.php");
	    $menu_group->item("Administrator","Tracking Log","/scs_track_log.php");
	    $menu_group->item("Executive","Reset Cookie","/cookie_reset.php");
	    $menu_group->item("Administrator","Email Documents","/mailer_maintain.php");
	    $menu_group->item("Administrator","Email Output Tester","/mailer_output.php");
	    $menu_group->item("Super User","File Manager","/scs_filemanager.php");
	    $menu_group->item("Super User","jQuery Toolbox","/jquery_toolbox.php");
	    $menu_group->item("Super User","MySQL Toolbox","/scs_mysql.php");
	    $menu_group->item("Super User","SCS Toolbox","/scs_toolbox.php");
	    $menu_group->item("Administrator","PHP Toolbox","/php_toolbox.php");
	    $menu_group->item("","Cookie Test","/cookie.php",array(),array("target"=>"_blank"));
        if (isset($_SESSION['scscpq_wp'])) $menu_group->item("","Reset WP",$_SERVER['PHP_SELF'],array("reset"=>"scscpq"),array("target"=>"top"));

		$this->tabs->item($_SESSION['user']->type,$menu_group->output());
    }
    function pending() {
	    global $database, $forms, $menu;
		if (!$database->user->access("Administrator",FALSE)) return;
		$this->stopwatch->split("Pending Users");
	    $query=array("select * from user");
	    $query[]="where status='Pending'";
	    $query[]="order by recaptcha_score desc, company_name";
	    $database->user->query($query);
        $this->stopwatch->split_comment(array_merge($query,array("",number_format($database->user->meta->rows) . " records found")));
	    if (!$database->user->meta->rows) return;

        $items=array();
        while ($database->user->fetch = $database->user->fetch_array() ) {
	        $database->user->fetch();
            $items[$database->user->data->id]=$database->user->data;
        }

        $query_where=array();
        $query_where[]=new query_where("user_id", "in", array_keys($items));
        $query_where[]=new query_where("type", "=", "user");
        $query_where[]=new query_where("subtype", "=", "signup");
        $query=array("select * from log");
        $query[]=$database->where($query_where);
        $query[]="order by id desc";
        $database->log->query($query);
        while ($database->log->fetch = $database->log->fetch_array()) {
            $database->log->fetch();
            $items[$database->log->data->user_id]->log=$database->log->data->comment;
        }
		$tab_name="Pending Access (" . number_format($database->user->meta->rows) . ")";
		$results=array();
	    $results[]="<table class='standard border tablesorter'>";
        $results[]="<thead>";
	    $results[]="<tr>";
	    $results[]="<th>IP</th>";
	    $results[]="<th>Company</th>";
	    $results[]="<th>Name</th>";
	    $results[]="<th>Score</th>";
	    $results[]="<th>Date/Time</th>";
	    $results[]="</tr>";
        $results[]="</thead>";
        $results[]="<tbody>";
        $url_query=array();
        $url_query['action']='maintain';
        $url_query['record_id']=0;
        $url_query['return_url']=fn_url($_SERVER['PHP_SELF'],array("tab"=>$tab_name));
	    foreach ($items as $id => $database->user->data) {
    		$url_query['record_id']=$id;
	        $results[]="<tr>";
	        $results[]="<td>" . fn_href($database->user->data->ip,fn_url("/user.php",$url_query)) . "</td>";
	        $results[]="<td>" . $database->user->data->company_name . "</td>";
	        $results[]="<td>" . $database->user->data->log . "</td>";
	        $results[]="<td>" . $database->user->recaptcha_score($database->user->data->recaptcha_score) . "</td>";
	        $results[]="<td class=center>" . date("m/d/Y h:i A",$database->user->data->last_login) . "</td>";
	        $results[]="</tr>";
	    }
        $results[]="</tbody>";
	    $results[]="</table>";
        $this->tabs->landing_tab($tab_name,TRUE);
		$this->tabs->item($tab_name,$results);
    }
    function sku_error() {
        global $database, $forms, $menu;
		if (!$database->user->access("Administrator",FALSE)) return;
		$this->stopwatch->split("Reverse Part# Lookup Error");
        $query_where=array();
        $query_where[]=new query_where("log.type","=","PARTSolutions");
        $query_where[]=new query_where("log.subtype","=","Reverse Lookup Error");
        $query_where[]=new query_where("log.date_time",">=",strtotime("-7 days"));
        $query_where[]=new query_where("subcategory.prj","<>","");
        $query=array("select");
        $query[]="log.sku as sku,";
        $query[]="category.name as 'category.name',";
        $query[]="subcategory.name as 'subcategory.name',";
        $query[]="subcategory.prj as prj,";
        $query[]="min(log.date_time) as min,";
        $query[]="max(log.date_time) as max,";
        $query[]="count(*) as count";
        $query[]="from log";
        $query[]="left join inventory on inventory.sku=log.sku";
        $query[]="left join category on category.id=inventory.category_id";
        $query[]="left join subcategory on subcategory.id=inventory.subcategory_id";
        $query[]=$database->where($query_where);
        $query[]="group by inventory.sku";
        $query[]="order by log.date_time";
        $database->temp->query($query);
        $this->stopwatch->split_comment(array_merge($query,array("",number_format($database->temp->meta->rows) . " records found")));
        if (!$database->temp->meta->rows) return;
        $results=array();
        $results[]="<table class='standard border tablesorter'>";
        $results[]="<thead>";
        $results[]="<tr>";
        $results[]="<th>SKU</th>";
        $results[]="<th>Category</th>";
        $results[]="<th>Subcategory</th>";
        $results[]="<th>First Date</th>";
        $results[]="<th>Last Date</th>";
        $results[]="<th>Count</th>";
        $results[]="</tr>";
        $results[]="</thead>";
        $results[]="<tbody>";
        while ($database->temp->fetch = $database->temp->fetch_array()) {
            $results[]="<tr>";
            $query=array();
            $query['action']="ipc";
            $query['source']="prj";
            $query['prj']=$database->temp->fetch['prj'];
            $query['sku']=$database->temp->fetch['sku'];
            $results[]="<td>" . fn_href($database->temp->fetch['sku'], "/cad_lookup.php", $query) . "</td>";
            $results[]="<td>" .  $database->temp->fetch['category.name'] . "</td>";
            $results[]="<td>" .  $database->temp->fetch['subcategory.name'] . "</td>";
            $results[]="<td class=center>" . date("m/d/Y h:i A", $database->temp->fetch['min'])  . "</td>";
            $results[]="<td class=center>" . date("m/d/Y h:i A", $database->temp->fetch['max']) . "</td>";
            $results[]="<td class=right>" . number_format($database->temp->fetch['count']) . "</td>";
            $results[]="</tr>";
        }
        $results[]="</tbody>";
        $results[]="</table>";
        $results[]="<p class=center>" . number_format($database->temp->meta->rows) . " errors detected.</p>";
        $this->tabs->item("Reverse Part# Missing (7 Days)",$results);
    }
    function leadscore() {
	    global $database, $forms, $menu;
		if (!$database->user->access("Administrator",FALSE)) return;
		$this->stopwatch->split("Lead Score");
		$query_where=array();
        $type=array();
        foreach ($database->log->constant->type as $key => $value) {
        	if (sizeof($value)) $type[]=$key;
        }
        if (sizeof($type)) $query_where[]=new query_where("log.type","in",$type);
        $query_where[]=new query_where("log.date_time",">=",strtotime("now -" . $database->registry->leadscore->days . " days"));
        $query_where[]=new query_where("log.email","<>","");
        $query_where[]=new query_where("log.user_id","<>",0);
        $query_where[]=new query_where("user.leadscore","=",1);
        $query_where[]=new query_where("user.status","=","active");
        $query=array();
        $query[]="select";
        $query[]="user.company_name as 'company_name',";
        $query[]="log.* from log";
        $query[]="left join user on user.id=log.user_id";
        $query[]=trim($database->where($query_where));
        $query[]="group by log.id";
        $query[]="order by email, log.id desc";
		$database->log->query($query);
		$data=array();
        while ($database->log->fetch = $database->log->fetch_array() ) {
			$database->log->fetch();
            if (!array_key_exists($database->log->data->email,$data)) $data[$database->log->data->email]=new dashboard_leadscore_class($database->log);
            $data[$database->log->data->email]->process($database->log->data);
        }
		$output=array();
        foreach ($data as $email => $item) {
			if ($item->score > 0) $output[$email]=$item->score;
        }
        $this->stopwatch->split_comment(array_merge($query,array("",number_format(sizeof($output)) . " records found")));
        if (!sizeof($output)) return;
        arsort($output);
        $results=array();
        $results[]="<table class='small border tablesorter'>";
        $results[]="<thead>";
        $results[]="<tr>";
        $results[]="<th>Email</th>";
        $results[]="<th>Company Name</th>";
        $results[]="<th class=nobr>Lead Score</th>";
        $results[]="<th>Viewed</th>";
        $results[]="<th>New?</th>";
        $results[]="<th>Campaign?</th>";
        $results[]="<th>Referer?</th>";
        $results[]="</tr>";
        $results[]="</thead>";
        $results[]="<tbody>";
        foreach ($output as $email => $item) {
        	$results[]="<tr>";
        	$results[]="<td>{$email}</td>";
            $results[]="<td class nobr>" . $data[$email]->company_name . "</td>";
        	$results[]="<td class=center>" . number_format($data[$email]->score) . "</td>";
            $text=array();
            if (sizeof($data[$email]->subcategory)) {
                $img_query=array("width"=>$database->registry->local->thumbnail,"height"=>$database->registry->local->thumbnail);
				$query_where=array();
				$query_where[]=new query_where("subcategory.id","in",$data[$email]->subcategory);
                $query=array();
                $query[]="select";
                $query[]="category.name as 'category_name',";
                $query[]="subcategory.* from subcategory";
                $query[]="left join category on category.id=subcategory.category_id";
                $query[]=$database->where($query_where);
                $query[]="order by category.sort_order,category.name,subcategory.sort_order,subcategory.name";
				$database->subcategory->query($query);
                while ($database->subcategory->fetch = $database->subcategory->fetch_array() ) {
                	$database->subcategory->fetch();
                    $text[]="<li>" . $forms->img($database->subcategory->data->thumbnail_url,$img_query) . "<br>" . $database->subcategory->data->name . "</li>";
                }
			}
            if (sizeof($data[$email]->portal)) {
            	$img_query=array("width"=>$database->registry->local->thumbnail,"height"=>$database->registry->local->thumbnail,"resize"=>TRUE);
                $portal_image=array();
				$query_where=array();
				$query_where[]=new query_where("portalcategory.id","in",$data[$email]->portal);
                $query=array();
                $query[]="select";
                $query[]="portalcategory.id as 'portalcategory_id',";
                $query[]="portalcategory.image_portalcontent_id as 'portalcategory_portalcontent_id',";
                $query[]="portalcategory.name as 'portalcategory_name',";
                $query[]="content.* from portalcategory";
                $query[]="left join content on content.record_type='portalcontent' and content.language_code='EN' and content.record_id=portalcategory.image_portalcontent_id";
                $query[]=$database->where($query_where);
                $query[]="order by content.name";
				$database->content->query($query);
                while ($database->content->fetch = $database->content->fetch_array() ) {
                	$database->content->fetch();
                    $img_query['content_id']=$database->content->encode($database->content->data->id);
					$text[]="<li>" . $forms->img("",array(),$img_query) . "<br>" . $database->content->fetch['portalcategory_name'] . "</li>";
				}
            }
            $results[]="<td class=catalog_subcategory_list>" . (sizeof($text) ? "<ul>" . implode("\n",$text) . "</ul>" : "&nbsp;" ) . "</td>";
			$results[]="<td>" . (($data[$email]->new) ? $forms->font("red","NEW!") : "&nbsp;") . "</td>";
            $results[]="<td>" . implode("<br>",$data[$email]->campaign) . "</td>";
            $results[]="<td>" . implode("<br>",$data[$email]->referer) . "</td>";
        	$results[]="</tr>";
        }
        $results[]="</tbody>";
        $results[]="</table>";
		$this->tabs->item("Lead Score (" . $database->registry->leadscore->days . " Days)",$results);
    }
    function access() {
	    global $database, $forms, $menu;
		$this->stopwatch->split("7 day access");
	    $query=array();
        $query[]="select * from user";
        $query[]="where last_login >= " . strtotime("-7 days");
        $query[]="order by last_login desc";
	    $database->user->query($query);
        $this->stopwatch->split_comment(array_merge($query,array("",number_format($database->user->meta->rows) . " records found")));
	    if (!$database->user->meta->rows) return;
		$tab_name="7 Day Access (" . number_format($database->user->meta->rows) . ")";
        $results=array();
	    $results[]="<table class='small border tablesorter'>";
        $results[]="<thead>";
	    $results[]="<tr>";
	    $results[]="<th>IP</th>";
	    $results[]="<th>Company</th>";
	    $results[]="<th>City</th>";
        $results[]="<th>State</th>";
        $results[]="<th>Zip</th>";
	    $results[]="<th>Country</th>";
	    $results[]="<th>Date/Time</th>";
	    $results[]="</tr>";
        $results[]="</thead>";
        $results[]="<tbody>";
	    while ($database->user->fetch = $database->user->fetch_array() ) {
	        $database->user->fetch();
	        $url_query=array();
	        $url_query['action']="login";
	        $url_query['user_id']=$database->user->data->id;
	        $results[]="<tr>";
	        $results[]="<td>" . fn_href($database->user->data->ip,"/scs_log.php",$url_query) . "</td>";
	        $results[]="<td>" . $database->user->data->company_name . "</td>";
	        $results[]="<td>" . $database->user->data->city . "</td>";
            $results[]="<td>" . $database->user->data->state . "</td>";
            $results[]="<td>" . $database->user->data->zip . "</td>";
	        $results[]="<td>" . $database->user->data->country_code . "</td>";
	        $results[]="<td class=center>";
	        if ($database->user->data->last_login) {
	            $results[]=date("m/d/Y h:i A",$database->user->data->last_login);
	          } else {
	            $results[]="&nbsp;";
	        }
	        $results[]="</td>";
	        $results[]="</tr>";
	    }
        $results[]="</tbody>";
	    $results[]="</table>";
	    $database->user->free_result();
		$this->tabs->item($tab_name,$results);
    }
    function orderhd() {
	    global $database, $forms, $menu;
		if (!$database->user->access("Executive",FALSE)) return;
		$this->stopwatch->split("Pending Orders");
        $query_where=array();
        $query_where[]=new query_where("status","=","Pending");
		$query=array("select * from orderhd");
        $query[]=$database->where($query_where);
        $query[]="order by id";
	    $database->orderhd->query($query);
        $this->stopwatch->split_comment(array_merge($query,array("",number_format($database->orderhd->meta->rows) . " records found")));
	    if (!$database->orderhd->meta->rows) return;
		$tab_name="Pending PO" . " (" . $database->orderhd->meta->rows . ")";
        $results=array();
	    $results[]="<table class='small border tablesorter'>";
        $results[]="<thead>";
	    $results[]="<tr>";
	    $results[]="<th>PO#</th>";
	    $results[]="<th>Company</th>";
	    $results[]="<th>Name</th>";
	    $results[]="<th>Email</th>";
	    $results[]="<th>Date/Time</th>";
	    $results[]="</tr>";
        $results[]="</thead>";
        $results[]="<tbody>";
        $url_query=array();
        $url_query['action']='maintain';
        $url_query['record_id']=0;
        $url_query['return_url']=fn_url($_SERVER['PHP_SELF'],array("tab"=>$tab_name));
	    while ($database->orderhd->fetch = $database->orderhd->fetch_array() ) {
	        $database->orderhd->fetch();
            $url_query['record_id']=$database->orderhd->data->id;
            $results[]="<tr>";
	    	$results[]="<td class=center>" . fn_href($database->orderhd->data->id,fn_url("/orderhd.php",$url_query)) . "</td>";
	    	$results[]="<td>" . $database->orderhd->data->company_name . "</td>";
	    	$results[]="<td>" . $database->orderhd->data->name . "</td>";
	    	$results[]="<td>" . $database->orderhd->data->email . "</td>";
	    	$results[]="<td class=center>" . date("m/d/Y h:i A",$database->orderhd->data->order_date) . "</td>";
            $results[]="</tr>";
		}
        $results[]="</tbody>";
		$results[]="</table>";
	    $database->orderhd->free_result();
		$this->tabs->item($tab_name,$results);
        $this->tabs->landing_tab($tab_name,TRUE);
    }
    function bot($subtype) {
	    global $database, $forms, $menu;
		if (!$database->user->access("Administrator",FALSE)) return;
		$this->stopwatch->split("Bots: {$subtype}");
        $query_where=array();
        $query_where[]=new query_where("log.type","=","BOT");
        $query_where[]=new query_where("log.subtype","=",$subtype);
        $query_where[]=new query_where("log.date_time",">=",strtotime("-7 days"));
        $query=array();
        $query[]="select";
        $query[]="ifnull(bot.name,'Unknown/Missing') as 'bot_name',";
        $query[]="bot.code as 'bot_code',";
        $query[]="bot.comment as 'bot_comment',";
        $query[]="max(log.date_time) as 'last_time',";
        $query[]="log.* from log";
        $query[]="left join bot on bot.id=log.bot_id";
        $query[]=$database->where($query_where);
        $query[]="group by log.bot_id";
        $query[]="order by bot_name, log.bot_id";
		$database->log->query($query);
        $this->stopwatch->split_comment(array_merge($query,array("",number_format($database->log->meta->rows) . " records found")));
		if (!$database->log->meta->rows) return;
        $tab_name="BOT: $subtype";
        $results=array();
	    $results[]="<table class='standard border tablesorter'>";
        $results[]="<thead>";
	    $results[]="<tr>";
	    $results[]="<th>Name</th>";
	    $results[]="<th>Code</th>";
	    $results[]="<th>Comment</th>";
	    $results[]="<th>Last Access</th>";
	    $results[]="</tr>";
        $results[]="</thead>";
        $results[]="<tbody>";
        while ($database->log->fetch = $database->log->fetch_array()) {
			$database->log->fetch();
	    	$results[]="<tr>";
			$results[]="<td>" . $database->log->fetch['bot_name'] . "</td>";
			$results[]="<td>" . $database->log->fetch['bot_code'] . "</td>";
			$results[]="<td>" . str_replace("\n","<br>",$database->log->fetch['bot_comment']) . "</td>";
			$results[]="<td class=center>" . date("m/d/Y g:i A",$database->log->fetch['last_time']) . "</td>";
	    	$results[]="</tr>";
        }
        $results[]="</tbody>";
		$results[]="</table>";
	    $database->log->free_result();
		$this->tabs->item($tab_name,$results);
    }
    function referer() {
	    global $database, $forms, $menu;
		$this->stopwatch->split("Referrer");
	    $query=array();
	    $query[]="select comment,count(*) as 'count',max(date_time) as 'date' from log";
	    $query[]="where date_time >= " . strtotime("-7 days");
	    $query[]="and type='initial'";
	    $query[]="and subtype='referer'";
	    $query[]="group by comment";
	    $query[]="order by comment";
	    $database->temp->query($query);
        $this->stopwatch->split_comment(array_merge($query,array("",number_format($database->temp->meta->rows) . " records found")));
	    if (!$database->temp->meta->rows) return;
	    $results[]="<table class='large border tablesorter'>";
        $results[]="<thead>";
	    $results[]="<tr>";
	    $results[]="<th>Referer</th>";
	    $results[]="<th>Last Date/Time</th>";
	    $results[]="<th>Count</th>";
	    $results[]="</tr>";
        $results[]="</thead>";
        $results[]="<tbody>";
        $referer=array();
	    while ($database->temp->fetch = $database->temp->fetch_array() ) {
        	$referer[ $database->temp->fetch['comment'] ]=$database->temp->fetch['count'];
	        $results[]="<tr>";
	        $results[]="<td>" . $database->temp->fetch['comment'] . "</td>";
	        $results[]="<td class=center>" . date("m/d/Y h:i A",$database->temp->fetch['date']) . "</td>";
	        $results[]="<td class=right>" . number_format($database->temp->fetch['count']) . "</td>";
	        $results[]="</td>";
	        $results[]="</tr>";
	    }
        $results[]="</tbody>";
        $results[]="<tfooter>";
	    $results[]="<tr>";
	    $results[]="<td colspan=2><b>7 day totals</b></td>";
	    $results[]="<td class=right><b>" . number_format(array_sum($referer)) . "</b></td>";
	    $results[]="</tr>";
        $results[]="</tfooter>";
	    $results[]="</table>";
	    $database->user->free_result();
		$this->tabs->item("Referer",$results);
    }
    function campaign() {
	    global $database, $forms, $menu;
		$this->stopwatch->split("Campaign");
	    $query=array();
        $query[]="select comment,count(*) as 'count',max(date_time) as 'date' from log";
        $query[]="where date_time >= " . strtotime("-7 days");
        $query[]="and type='initial'";
        $query[]="and subtype='campaign'";
        $query[]="group by comment";
        $query[]="order by comment";
	    $database->temp->query($query);
        $this->stopwatch->split_comment(array_merge($query,array("",number_format($database->temp->meta->rows) . " records found")));
	    if (!$database->temp->meta->rows) return;
	    $database->temp->query($query);
	    if (!$database->temp->meta->rows) return;
	    $results[]="<table class='large border tablesorter'>";
        $results[]="<thead>";
	    $results[]="<tr>";
	    $results[]="<th>Campaign</th>";
	    $results[]="<th>Last Date/Time</th>";
	    $results[]="<th>Count</th>";
	    $results[]="</tr>";
        $results[]="</thead>";
        $results[]="<tbody>";
        $items=array();
	    while ($database->temp->fetch = $database->temp->fetch_array() ) {
        	$items[ $database->temp->fetch['comment'] ]=$database->temp->fetch['count'];
	        $results[]="<tr>";
	        $results[]="<td>" . $database->temp->fetch['comment'] . "</td>";
	        $results[]="<td class=center>" . date("m/d/Y h:i A",$database->temp->fetch['date']) . "</td>";
	        $results[]="<td class=right>" . number_format($database->temp->fetch['count']) . "</td>";
	        $results[]="</td>";
	        $results[]="</tr>";
	    }
        $results[]="</tbody>";
        if (sizeof($items) > 1) {
	        $results[]="<tfooter>";
	        $results[]="<tr>";
	        $results[]="<td colspan=2><b>7 day totals</b></td>";
	        $results[]="<td class=right><b>" . number_format(array_sum($items)) . "</b></td>";
	        $results[]="</tr>";
	        $results[]="</tfooter>";
		}
	    $results[]="</table>";
	    $database->user->free_result();
		$this->tabs->item("Campaign",$results);
    }
    function related_error() {
        global $database;
		$this->stopwatch->split("Related SKU Error");

        $query_where=array();
        $query_where[]=new query_where("type", "=", "error");
        $query_where[]=new query_where("subtype", "=", "related sku");
        $query_where[]=new query_where("date_time",">=",strtotime("-7 days"));
        $query=array("select * from log");
        $query[]=$database->where($query_where);
        $query[]="group by sku";
        $query[]="order by sku, id desc";
        $database->log->query($query);
        $this->stopwatch->split_comment(array_merge($query,array("",number_format($database->log->meta->rows) . " records found")));
        if (!$database->log->meta->rows) return;
        $results=array();
        $results[]="<table class='border'>";
        $results[]="<tr>";
        $results[]="<th>Part#</th>";
        $results[]="<th>Comment</th>";
        $results[]="<th>Last Date</th>";
        $results[]="</tr>";
        while ($database->log->fetch = $database->log->fetch_array()) {
            $database->log->fetch();
            $results[]="<tr>";
            $results[]="<td>{$database->log->data->sku}</td>";
            $results[]="<td>" . str_replace("\n", "<br>", $database->log->data->comment) . "</td>";
            $results[]="<td class=center>" . date("m/d/Y h:i", $database->log->data->date_time) . "</td>";
            $results[]="</tr>";
        }
        $results[]="</table>";
		$this->tabs->item("Related SKU",$results);
    }
}
class dashboard_leadscore_class {
	var $score=0;
    var $company_name;
    var $name;
    var $new=0;
    var $subcategory=array();
    var $portal=array();
    var $campaign=array();
    var $referer=array();
    function __construct($log) {
    	$this->company_name=$log->fetch['company_name'];
    	$this->name=$log->fetch['name'];
    }
    function process($data) {
    global $database;
		switch (TRUE) {
          case (!$data->subcategory_id):
			break;
          case ( ($data->type=="Page") && ($data->subtype=="Portal") ):
			if (!in_array($data->subcategory_id, $this->portal)) $this->portal[]=$data->subcategory_id;
			break;
          default:
			if (!in_array($data->subcategory_id, $this->subcategory)) $this->subcategory[]=$data->subcategory_id;
		}
        $field=strtolower($data->type . ":" . $data->subtype);
        if ($field == "user:email") $this->new=TRUE;
        if (array_key_exists($field,$database->registry->leadscore->score)) $this->score += $database->registry->leadscore->score[$field];
        switch (TRUE) {
          case (!strlen($data->comment)):
          	break;
          case ( (!strcasecmp($data->subtype,"campaign")) && (!in_array($data->comment,$this->campaign)) ):
          	$this->campaign[]=$data->comment;
          	break;
          case ( (!strcasecmp($data->subtype,"referer")) && (!in_array($data->comment,$this->referer)) ):
          	$this->referer[]=$data->comment;
          	break;
		}
    }
}
?>
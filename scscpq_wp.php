<?php
/*
http://localhost:8105/scscpq_wp.php?action=initial
*/
$GLOBALS['SCS_API']=TRUE;
print header("Access-Control-Allow-Origin: *");
require_once "scs_header.php";
session_start();
$menu=new menu_class();
switch (TRUE) {
  case (!isset($_SESSION['user'])):
	$menu->initial();
	break;
  case ($_SESSION['user']->status == "Pending"):
	$database->user->read($_SESSION['user']->id);
	$_SESSION['user']=$database->user->data;
    break;
}
$database->log->track($database->registry->local->track);
$obj=new scscpq_wp_class();
class scscpq_wp_class {
	var $error;
    var $fatal;
    var $action;
    var $token;
    var $page;
    var $document;
    var $link=FALSE;
    var $url;
    var $catalog=0;
    var $url_query=array();
	var $response;
    var $trace=array();
    var $account_language="";
	function scs_table_version() {
		$results=array();
        $results['10/01/2025']="Add harcourt_remote_addr()";
        $results['08/15/2024']="Improve security";
        $results['11/21/2023']="Add search";
        $results['01/16/2023']="Add portal, language";
        $results['10/29/2022']="Cart function bug";
        $results['06/15/2021']="Add meta";
        $results['08/22/2014']="Initial release";
        return $results;
    }
	function __construct() {
        $this->trace[]=__FUNCTION__;
        $this->response=new stdClass();
    	$this->action=$_REQUEST['action'];
    	$this->token=$_REQUEST['token'];
    	$this->page=trim(strtolower(strip_tags($_REQUEST['page'])));
    	$this->document=trim(strtolower(strip_tags($_REQUEST['document'])));
        $url=parse_url($_REQUEST['url']);
		if (array_key_exists("query",$url)) parse_str($url['query'],$this->url_query);
		switch (TRUE) {
          case ($_SERVER['REQUEST_METHOD'] != "POST"):
          	$this->fatal="102";
            break;
		  case (!strlen($this->action)):
          	$this->fatal="103";
            break;
		  case (!method_exists($this,$this->action)):
          	$this->fatal="104";
            break;
          default:
          	$this->fatal=$this->{$this->action}();
        }
		if ( (!$this->fatal) && (strlen($this->page)) ) {
        	$this->page();
        	switch (TRUE) {
              case (!$this->url):
              	$this->response->scscpq_page="";
                break;
              case ($this->link):
        		$this->response->scscpq_link=$this->url;
                break;
              default:
        		$this->response->scscpq_iframe=$this->url;
			}
		}
        $meta=new stdClass();
        $meta->script=$_SERVER['SCRIPT_FILENAME'];
        $meta->referrer=$_SERVER['HTTP_REFERER'];
        $meta->version=key($this->scs_table_version());
        $this->response->meta=$meta;
        $this->response->scscpq_document=$this->document();
		if (strlen($this->fatal)) {
          	header("HTTP/1.1 401 Unauthorized");
            $this->response->error=$this->action . ": " . $this->fatal;
		  } else {
      		$this->response->token=$this->token;
            $this->response->error=( (strlen($this->error)) ? $this->error : "");
		}
        $this->response->trace=$this->trace;
        die( json_encode($this->response) );
    }

    function initial() {
    global $database;
        $this->trace[]=__FUNCTION__;
		if (!strlen($this->token)) $this->token();
		if (!isset($_SESSION['user'])) $this->user();
		$this->language();
		$this->response->scscpq_newuser=$this->newuser();
		$this->response->scscpq_impersonate=$this->impersonate();
        $this->response->scscpq_catalog=$this->catalog();
        $this->response->scscpq_search=$this->search();
        $this->response->scscpq_cart=$this->cart();
        $this->response->scscpq_portal=$this->portal();
        $this->response->scscpq_dashboard=$this->dashboard();
        $this->response->scscpq_products=$this->response->scscpq_catalog;
        $this->response->account_language=($_SESSION['user']->id) ? $this->account_language : "";
    }
    // The page's language decides the catalog's and the menu's, so it is applied before anything is built.
    // A flag click sends remember=1, which also saves the choice to the signed-in account (as language_change.php does).
    function language() {
        global $database, $menu;
        $this->trace[]=__FUNCTION__;
        if ($_SESSION['user']->id) {
            $database->user->read($_SESSION['user']->id);
            if ($database->user->meta->rows) $this->account_language=$database->user->data->language_code;
        }
        $code=strtoupper(substr(trim($_REQUEST['language']), 0, 2));
        if (!array_key_exists($code, $menu->language)) {
            $database->language->read($_REQUEST['language'], "local_name");
            $code=($database->language->meta->rows) ? $database->language->data->code : "";
        }
        if (!$code) return;
        $_SESSION['user']->language_code=$code;
        if ( (!$_REQUEST['remember']) || (!$this->account_language) || ($this->account_language == $code) ) return;
        $database->user->data->language_code=$code;
        $database->user->update(TRUE);
        $this->account_language=$code;
    }
    function token() {
        $this->trace[]=__FUNCTION__;
      	$this->token=bin2hex(openssl_random_pseudo_bytes(40));
    }
    function user() {
        global $database;
        $this->trace[]=__FUNCTION__;
        $database->user->read(harcourt_remote_addr());
		$database->user->data->last_login=strtotime("now");
	    if (!$database->user->meta->rows) return FALSE;
        $database->user->update(TRUE);
		$database->user->data->token=$this->token;
        $database->user->session();
    }
    function impersonate() {
        global $database, $menu, $forms;
        $this->trace[]=__FUNCTION__;
    	return ( ($_SESSION['impersonate']) ? 1 : 0);
    }
    function dashboard() {
        global $database, $forms, $menu;
        $this->trace[]=__FUNCTION__;
		return ( ( ($database->user->access("Executive",FALSE)) && ($_SESSION['user']->status == "Active") ) ? 1 : 0);
    }
    function newuser() {
        global $database, $menu, $forms;
        $this->trace[]=__FUNCTION__;
    	switch (TRUE) {
          case ( ($_SESSION['user']->id) && ($_SESSION['user']->status != "Pending") ):
          case (isset($_SESSION['user']->bot_id)):
          	return 0;
		  default:
          	return 1;
		}
    }
    function catalog() {
        global $database, $forms, $menu;
        $this->trace[]=__FUNCTION__;
        switch (TRUE) {
          case (!$_SESSION['user']->id):
          case (!$database->user->access("Customer",FALSE)):
          case ($_SESSION['user']->status != "Active"):
          case (!$_SESSION['user']->catalog):
            return 0;
          default:
            return 1;
        }
    }
    function search() {
        global $database, $forms, $menu;
        $this->trace[]=__FUNCTION__;
        switch (TRUE) {
          case (!$this->catalog()):
          case (isset($_SESSION['user']->bot_id)):
            return;
        }
        $results=array();
        $results[]=$this->search_css();
        $results[]=$this->search_js();
        $results[]="<form id=scscpq_search_form  method=GET action=\"javascript:search_content(0, jQuery('#scscpq_search_text').val());\">";
        $results[]=$forms->text("scscpq_search_text","",20,0,"",array("placeholder"=>$menu->language('menu_search_placeholder')));
        $results[]="</form>";
        return implode("",$results);
    }
    function cart() {
        global $database, $forms, $menu;
        $this->trace[]=__FUNCTION__;
        switch (TRUE) {
          case (!$this->catalog()):
          case (!$_SESSION['user']->ecommerce):
          case (isset($_SESSION['user']->bot_id)):
            return;
        }
        $text=array();
        $text[]="<img src='/scs_images/cart.png' id=menu_cart_img title='View Cart'>";
		$text[]="<span id=menu_cart_size>" . ( (isset($_SESSION['cart'])) ? sizeof($_SESSION['cart']) : 0) . "</span> " . $menu->language('menu_cart');
        $options=array();
        $options["title"]=$menu->language('menu_cart_title');
        $options['class']="nav-link";
        return fn_href(implode(" ",$text),"/cart/",array(),$options);
    }
    function portal() {
        global $menu;
        $this->trace[]=__FUNCTION__;
        $text=array();
        $text[]="<img src='/scs_images/portal.png' id=menu_portal_img>";
        $text[]=$menu->language('menu_portal');
        $options=array();
		$options["title"]=$menu->language('menu_portal');
		$options['class']="nav-link";
        $url_options=array();
        if ($_SESSION['user']->language_code == "FR") {
        	$url="/portail-clients/";
            $url_options['lang']="fr";
		  } else {
          	$url="/customer_portal/";
		}
        return fn_href(implode(" ",$text), $url, $url_options, $options);
	}
    function search_css() {
        return <<<EOT

<style>
.ui-autocomplete {
    z-index: 1000;
    position: absolute;
    overflow: visible;
}
</style>
EOT;
    }
    function search_js() {
        return <<<EOT

<script>
jQuery(function() {
    var box=jQuery("#scscpq_search_text");
    box.autocomplete({
        // hxweb answers in about a millisecond, so ask on every keystroke instead of after jQuery UI's 300 ms pause
        delay: 0,
        source: function(request, response) {
            var term=this.term.trim();
            if (term.length < 2) return;
            var query={ table: "search", limit: 20, term: term };
            jQuery.ajax({
                url: "/scs_ajax.php?" + jQuery.param(query),
                dataType: 'json',
                success: function (data) {
                    // Enter opens the first suggestion when it is the part number or name typed, or starts with it
                    // (hxweb's tiers exact and prefix); otherwise Enter still opens the search results page
                    var tier=(data.items.length > 0) ? data.items[0].tier : "";
                    box.autocomplete("option", "autoFocus", tier == "exact" || tier == "prefix");
                    if (data.items.length > 0) {
                        response(data.items);
                        } else {
                        response([{ label: 'No results found.', val: '', id: 0 }]);
                    }
                }
            })
        },
        select: function(event, ui) {
            switch (true) {
              case (ui.item['category'] == "Lookup"):
                search_content(0, ui.item['value'], "lookup");
                break;
              case (ui.item['id'] != 0):
                search_content(ui.item['id'], '');
                break;
            }
        }
    });
});
/*
jQuery(function() {
    jQuery("#scscpq_search_text").autocomplete({
        source: function(request, response) {
            var term=this.term.trim();
            if (term.length < 2) return;
            var query={ table: "search", limit: 20, term: term };
            jQuery.ajax({
                url: "/scs_ajax.php?" + jQuery.param(query),
                dataType: 'json',
                success: function (data) {
                    if (data.items.length > 0) {
                        response(data.items);
                        } else {
                        response([{ label: 'No results found.', val: '', id: 0 }]);
                    }
                }
            })
        },
        select: function(event, ui) {
            if (ui.item['id']) search_content(ui.item['id'], '')
        }
    });
});
*/
function search_content(id, code, search_source="search") {
    code=code.trim();
    if ( (!id) && (code.length < 2) ) {
        alert("Your search term must include at least 2 characters");
        return;
    }
    // the French catalogue keeps its own address, so a pick there can swap in place too
    var french=/^\/catalogue\//.test(location.pathname);
    var url=(french ? "/catalogue/?lang=fr&" : "/catalog/?") + jQuery.param( { search_source: search_source, search_id: id, search_code: code } );
    // on a catalog page hx-nav swaps in just the catalog, as it does for catalog links
    if (!(window.hxGo && window.hxGo(url))) window.location.href=url;
}
</script>
EOT;
    }
    function page() {
        global $database, $menu, $forms;
    	if (!strlen($this->page)) return;
        $url_query=array();
        switch (TRUE) {
          case ( ($this->page == "dashboard") && ($database->user->access("Executive",FALSE)) ):
          	$page="/dashboard.php";
          	break;
          case ( ($this->page == "newuser")  && (!isset($_SESSION['user']->bot_id)) ):
	        $this->link=TRUE;
	        $page="/newuser.php";
          	break;
          case ( ($this->page == "products") && ($database->user->access("Customer",FALSE)) ):
          case ( ($this->page == "catalog") && ($database->user->access("Customer",FALSE)) ):
          	$this->link=TRUE;
            $this->catalog=TRUE;
            $page=(preg_match("/beta/i", $_REQUEST['url'])) ? "/beta_catalog.php" : "/catalog.php";
            $url_query=$this->url_query;
          	break;
          case ( ($this->page == "impersonate") && ($_SESSION['impersonate']) ):
          	$page="/user_impersonate.php";
          	break;
          case ($this->page == "campaign"):
          	$page="/campaign.php";
            $url_query=$this->url_query;
          	break;
          case ($this->page == "customer_portal"):
          	$this->link=TRUE;
          	$page="/customer_portal.php";
          	break;
          case ( ($this->page == "cart") && ($_SESSION['user']->ecommerce) ):
          	$page="/cart.php";
          	break;
          case ( ($this->page == "checkout") && ($_SESSION['user']->ecommerce) && (sizeof($menu->cart)) ):
          	$page="/checkout.php";
          	break;
          default:
          	return;
        }
        $url_query['scscpq_wp']=(intval(!fn_development_server()));
		$_SESSION['scscpq_page']=$this->page;
        if ($this->link) {
            $this->url=fn_url($this->url($page), $url_query);
		  } else {
        	$this->url="<iframe id=scscpq_wp_iframe src=" . fn_escape(fn_url($this->url($page), $url_query)) . " width='100%' frameBorder='0' style='height: 100px;'>";
		}
    }
    function document() {
        $this->trace[]=__FUNCTION__;
    	if (!strlen($this->document)) return;
        $document_id=0;
        switch (TRUE) {
          case ( ($this->document == "document_email") && ($_SESSION['user']->id) && (!$_COOKIE['email']) ):
            $document_id=( ($_SESSION['user']->login_document_id) ? $_SESSION['user']->login_document_id : 156);
            break;
		}
        if (!$document_id) return;
        $options=array("document"=>fn_base64(array($document_id,strtotime("now"))));
        return fn_url($this->url("/document_viewer.php"),$options);
    }
    function url($page) {
        $this->trace[]=__FUNCTION__;
        $url=array( ($_SERVER['HTTPS']) ? "https://" : "http://");
        $url[]=$_SERVER['HTTP_HOST'];
        $url[]=$page;
        return implode("",$url);
    }
}
?>
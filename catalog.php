<?php
require_once "scs_header.php";
require_once "classes/portaloutput.php";
require_once "classes/search.php";
$database->user->access("Customer");
print header("Access-Control-Allow-Origin: *");
$obj=new catalog_output_class();
class catalog_output_class {
	var $action;
    var $document=0;
    var $page=0;
    var $item=0;
    var $access=0;
    var $sku;
    var $related="";
    var $title=array("Products");
    var $search_source;
    var $search_code;
    var $search_term;
    var $search_count=array("total"=>0,"category"=>0,"subcategory"=>0,"inventory"=>0);
    var $category_id=0;
    var $subcategory_id=0;
    var $new_date=0;
    var $tabs;
    var $inventory;
    var $portal_category=0;
	var $category=array();
    var $subcategory=array();
    var $parameter=array();
    var $php_self;
    var $email;
    var $request=array();
    var $trace=array();
    var $blocked_categories;
	function scs_table_version() {
		$results=array();
        $results['11/25/2025']="New hero, portal format, related";
        $results['08/13/2025']="Improve lookup algorithm";
        $results['07/08/2025']="Security issues";
        $results['11/06/2024']="search:enter, search table first before reverse #";
        $results['10/01/2024']="search:Subcategory show wrong subcategory name";
        $results['09/11/2024']="Add search_source: lookup";
        $results['08/25/2024']="Add profile:generate";
        $results['08/16/2024']="Add profile";
        $results['07/26/2024']="Add subcategory:lookup, base64 encode SKU";
        $results['06/06/2024']="Reverse part# lookup";
        $results['05/13/2024']="Add special, custom tabs";
        $results['02/21/2024']="Add subcategory tabs to SKU search";
        $results['01/14/2024']="Add product details to SKU search";
        $results['11/21/2023']="Add search";
        $results['09/10/2022']="Partcommunity 12.0 interface";
        $results['07/27/2022']="Add currency_code";
        $results['05/05/2021']="Land on search tab if parameters selected";
        $results['10/28/2020']="Alter page_item_break";
        $results['08/29/2020']="WordPress integration";
        $results['02/18/2020']="Drop sonic, prj_new";
        $results['08/23/2019']="Integrate smart SKU";
        $results['08/12/2019']="Show categories w/o SKU's";
        $results['04/20/2019']="Update Product Range titles";
        $results['03/11/2019']="Update pricing";
        $results['06/22/2017']="Update pricing";
        $results['02/12/2017']="New layout";
        $results['08/10/2016']="Add sonic";
        $results['07/22/2016']="Add portal subcategory";
        $results['07/07/2016']="Add pending subcategory";
        $results['03/23/2016']="Add industry, portal";
        $results['11/24/2015']="Rewrite cart functions";
        $results['10/08/2015']="Add link name (PO 60302)";
        $results['09/23/2015']="Add page_breaks";
        $results['08/06/2015']="Revise sortable tabs";
        $results['07/08/2015']="Add sortable tabs";
        $results['06/03/2015']="Add download tab";
        $results['04/15/2015']="Log activity";
        $results['04/06/2015']="Automatic PRJ landing page";
        $results['03/12/2015']="Parameteric search";
        $results['02/12/2015']="Integrate new skins";
        $results['01/06/2015']="Include category tab on SKU drilldown";
        $results['08/25/2014']="Initial release";
        return $results;
    }
    function __construct() {
        global $database, $forms, $menu;
    	$this->trace[]=__FUNCTION__;
        require_once "classes/configure.php";
        $database->profile->load();
        if (isset($_SESSION['scscpq_wp'])) {
	        $referer_url=parse_url($_SERVER['HTTP_REFERER']);
	        parse_str($referer_url['query'],$this->request);
		   } else {
           	$this->request=$_GET;
		}
        $this->request=$_GET;
		$this->action=$this->request['action'];
		$this->page=intval($this->request['scs_page']);
		$this->item=intval($this->request['item']);
		$this->document=intval($this->request['document']);
		$this->new_date=intval($this->request['new_date']);
        $this->search_source=$this->request['search_source'];
        $this->portal_category=$this->request['portal_category'];
    	switch (TRUE) {
          case (isset($_SESSION['user']->bot_code)):
            $this->access=TRUE;
            break;
          case (!$_SESSION['user']->id):
          case (!$_SESSION['user']->catalog):
            $this->access=FALSE;
			break;
          default:
            $this->access=TRUE;
        }
        $this->php_self=$menu->page->products->url;
		if ($_SESSION['user']->portal_id) {
            $this->portal=new portaloutput_class();
            if ($this->portal->error) $_SESSION['user']->portal_id=0;
        }
		list($this->search_code,$this->search_term)=preg_split("/\|/",$this->request['search_code'],2);
        for ($parameter_id=0; $parameter_id < $database->subcategory->constant->parameters; $parameter_id ++) {
			$this->parameter[$parameter_id]=trim($this->request["parameter{$parameter_id}"]);
        }
        $this->search_complete=$this->request['search_complete'];
		switch ($this->search_source) {
          case "search":
			if ($this->request['search_id']) {
            	$database->search->read($this->request['search_id']);
                $this->category_id=$database->search->data->category_id;
                $this->subcategory_id=$database->search->data->subcategory_id;
                if ($database->search->data->table_name != "subcategory") $database->inventory->read($database->search->data->inventory_id);
              } else {
                $this->lookup_error=$this->lookup();
              }
          	break;
          case "lookup":
            $this->lookup_error=$this->lookup();
            break;
		  case "sku":
			$this->sku=$this->search_code;
            break;
		  case "name":
			$this->subcategory_id=$this->search_code;
            break;
		  default:
            $sku=fn_base64($this->request['sku'],FALSE);
			$this->sku=($this->request['sku'] == fn_base64(array($sku))) ? $sku : $this->request['sku'];
            if ($this->action == "related") {
                $this->related_sku();
              } else {
                $this->category_id=intval($this->request['category_id']);
                $this->subcategory_id=intval($this->request['subcategory_id']);
            }
		}
		$database->category->query("select * from category");
        while ($database->category->fetch = $database->category->fetch_array() ) {
			$database->category->fetch();
            $this->category[$database->category->data->id]=$database->category->data;
        }
        $database->category->free_result();
		$database->subcategory->query("select * from subcategory");
        while ($database->subcategory->fetch = $database->subcategory->fetch_array() ) {
			$database->subcategory->fetch();
            $this->subcategory[$database->subcategory->data->id]=$database->subcategory->data;
        }
        $database->subcategory->free_result();
		$query=array("select subcategory_id as 'subcategory_id' from inventory");
        $query[]="where new_date >= " . fn_escape($database->inventory->new_date());
        $query[]="and inventory.active = '1'";
        $query[]="group by subcategory_id";
		$database->temp->query($query);
        while ($database->temp->fetch = $database->temp->fetch_array() ) {
			if (array_key_exists($database->temp->fetch['subcategory_id'],$this->subcategory)) $this->subcategory[$database->temp->fetch['subcategory_id']]->new_inventory=TRUE;
        }
		$database->temp->free_result();
		switch (TRUE) {
          case ($this->action != "search"):
          	break;
		  case ($this->search_source == "sku"):
			$database->inventory->read($this->sku,"sku");
            if (!$database->inventory->meta->rows) {
            	$this->action="";
			  } else {
                $this->subcategory_id=$database->inventory->data->subcategory_id;
                $this->category_id=$this->subcategory[$this->subcategory_id]->category_id;
                $this->inventory=$database->inventory->data;
			}
            break;
		  case ($this->search_source == "name"):
            $this->category_id=$this->subcategory[$this->search_code]->category_id;
            $this->subcategory_id=$this->search_code;
            break;
		}
	    $query_where=array();
	    $query_where[]=new query_where("record_type","in",array("category","subcategory"));
	    $query_where[]=new query_where("record_item","=","");
	    $query_where[]=new query_where("language_code","in",array("EN",$_SESSION['user']->language_code));
	    $query=array("select");
        $query[]="if (language_code='EN',2,1) as sort_key,";
        $query[]="content.* from content";
	    $query[]=$database->where($query_where);
	    $query[]="order by record_type,sort_key";
	    $database->content->query($query);
        while ($database->content->fetch = $database->content->fetch_array() ) {
			$database->content->fetch();
            switch ($database->content->data->record_type) {
              case "category":
				if ( (array_key_exists($database->content->data->record_id,$this->category)) && (!isset($this->category[$database->content->data->record_id]->language_code)) ) {
					$this->category[$database->content->data->record_id]->language_code=$database->content->data->language_code;
					$this->category[$database->content->data->record_id]->name=$database->content->data->name;
					$this->category[$database->content->data->record_id]->description=$database->content->data->description;
                }
			  	break;
              case "subcategory":
				if ( (array_key_exists($database->content->data->record_id,$this->subcategory)) && (!isset($this->subcategory[$database->content->data->record_id]->language_code)) ) {
					$this->subcategory[$database->content->data->record_id]->language_code=$database->content->data->language_code;
					$this->subcategory[$database->content->data->record_id]->name=$database->content->data->name;
					$this->subcategory[$database->content->data->record_id]->description=$database->content->data->description;
                }
			  	break;
			}
		}
        $database->content->free_result();
        if ($this->subcategory_id) $this->category_id=$this->subcategory[$this->subcategory_id]->category_id;
        $referer=parse_url($_SERVER['HTTP_REFERER']);
		$options=array();
        $options['subcategory_id']=$this->subcategory_id;
        $options['comment']=array();
	    switch (TRUE) {
          case ($_SESSION['user']->bot_code):
          case ($this->search_source == "search"):
          	break;
          case ($this->subcategory_id):
          	if ( (!$this->sku) && (!$this->page) ) {
	            $options['comment'][]="Subcategory: " . $this->subcategory[$this->subcategory_id]->name;
	            $database->log->update("page:products",$options);
			}
            break;
          case ($this->category_id):
			$options['comment'][]="Category: " . $this->category[$this->category_id]->name;
			$database->log->update("page:products",$options);
          	break;
		  case ($referer['path'] != $this->php_self):
			$options['comment'][]="Landing Page";
			$database->log->update("page:products",$options);
            break;
	    }
	    if ($this->document) {
	        $database->document->read($this->document);
	        $database->content->page("document",$this->document);
	        if ( (!$database->document->meta->rows) || (!$database->content->meta->rows) ) $this->document=0;
	    }
	    switch (TRUE) {
	      case ($this->document):
	        $forms->title($database->document->data->name);
	        unset($forms->message[0]);
	        break;
	      case ($_SESSION['user']->portal_id):
	      case ($_SESSION['user']->landing_document_id):
	        break;
	    }
        $options=array("products_div"=>"products_div");
	    $forms->title(implode(" | ",$this->title));
	    unset($forms->message[0]);
        $forms->html->script("css", "/scs_scripts/catalog.css?v=" . $database->registry->local->filmstrip_timestamp);
	    $menu->head($options);
        if ( ($forms->title_constant) && (!sizeof($forms->message)) )  {
			$forms->title_constant="";
            $forms->message[]=fn_href("Products: Home",$menu->page->products->url . ( ($this->category_id) ? "#category" . $this->category_id : ""));
		}
	    print $forms->message();
        print $this->js();
        print $this->css();
	    print $forms->open();
        print $forms->hidden("action",$this->action);
        print $forms->hidden("category_id",$this->category_id);
        print $forms->hidden("subcategory_id",$this->subcategory_id);
        print $forms->hidden("document",$this->document);
        print $forms->hidden("scs_page",$this->page);
        print $forms->hidden("item",$this->item);
		print $forms->hidden("sku");
		print $forms->hidden("search_complete");
        print $forms->hidden("cad_name", $database->profile->data->cad_name);
	    switch (TRUE) {
	      case (!$_SESSION['user']->id):
	      case ($_SESSION['user']->status != "Active"):
	      case (!$_SESSION['user']->catalog):
            $this->trace[]="Document: 157";
	        $_SESSION['document_viewer']=157;
	        $_SESSION['document_viewer_override']=0;
            require_once "document_viewer.php";
			break;
          case (!$database->profile->forced()):
            $this->trace[]="Profile";
            print $database->profile->enter(array("success_url"=>$_SERVER['REQUEST_URI'],"notice"=>1));
            break;
	      case (!$this->access):
            $this->trace[]="Access";
	        break;
	      case ((!$this->document) && ($_SESSION['user']->landing_document_id)):
	        $_SESSION['document_viewer']=$_SESSION['user']->landing_document_id;
	        $_SESSION['document_viewer_override']=0;
            $this->trace[]="Landing page: {$_SESSION['user']->landing_document_id}";
            require_once "document_viewer.php";
	        break;
		  default:
			$this->route();
	    }
        print $forms->close();
        if ($_REQUEST['debug']) {
            fn_pre($this->request);
            fn_pre($this->trace);
        }
	    $menu->copyright();
	}
    function route() {
	    global $database, $forms, $menu;
    	$this->trace[]=__FUNCTION__;
		$this->tabs=new jquery_tab_class("products_jquery");
        $this->tabs->options("content",array("class"=>"products_content"));
        foreach ($database->language->constant->product_sortable as $key => $value) {
            if (!array_key_exists($key,$this->tabs->output_order)) $this->tabs->output_order($key,$value);
        }
        switch (TRUE) {
          case ($this->search_source == "search"):
          	switch ($database->search->data->table_name) {
              case "inventory":
              case "competitor_sku":
                $code=array("search");
                $options=array("sku"=>$database->inventory->data->sku);
                $options['subcategory_id']=$database->inventory->data->subcategory_id;
                if (($database->search->data->table_name) == "inventory") {
                    $code[]="sku";
                  } else {
                    $code[]="Competitor SKU";
                    $options['comment']=$database->search->data->code;
                }
                $database->log->update($code, $options);
				$this->action="sku";
                $this->sku=$database->inventory->data->sku;
                $this->subcategory();
	            break;
              case "subcategory":
              case "search_words":
                $options=array();
                $options['subcategory_id']=$database->search->data->subcategory_id;
                $options['comment']="Subcategory: " . $this->subcategory[$this->subcategory_id]->name;
                $database->log->update("search:Subcategory",$options);
	            $this->subcategory();
	            break;
              default:
				$this->search_code();
          	}
          	break;
          case ( ($this->search_source == "lookup") && (strlen($this->lookup_error)) ):
            print "<p class=center>{$this->lookup_error}</p>";
            break;
          case ( ($this->search_source == "lookup") && ($this->subcategory_id) ):
            $this->subcategory();
            break;
          case ($this->search_source == "lookup"):
            $this->output_cad();
            $this->tabs_output();
            break;
		  case ($this->search_source == "sku"):
			$this->search_sku();
			break;
		  case ($this->search_source == "name"):
          case ( (in_array($this->action,array("subcategory","sku"))) && (array_key_exists($this->subcategory_id,$this->subcategory)) ):
			$this->subcategory();
           break;
		  case ($this->action=="portal"):
			$this->portal();
			break;
		  default:
			$this->summary();
		}
    }
    function lookup() {
        global $database, $forms;
        $this->trace[]=__FUNCTION__;
        $this->sku_request=fn_text($_REQUEST['search_code'],TRUE);
        if (!strlen($this->sku_request)) return "Blank SKU";
        $skus=array($this->sku_request);
        foreach (array("", "_") as $pattern) {
            $sku=str_replace(" ", $pattern, $this->sku_request);
            if (!in_array($sku, $skus)) $skus[]=$sku;
        }
        $query_where=array();
        $query_where[]=new query_where("table_name", "in", array("inventory", "competitor_sku"));
        $query_where[]=new query_where("code", "in", $skus);
        $query=array("select");
        $query[]="if(table_name='inventory',0,1) as ranking,";
        $query[]="search.* from search";
        $query[]=trim($database->where($query_where));
        $query[]="order by ranking";
        $query[]="limit 1";
        $database->search->query($query);
        if ($database->search->meta->rows) {
            $database->search->fetch(TRUE);
            $this->category_id=$database->search->data->category_id;
            $this->subcategory_id=$database->search->data->subcategory_id;
            $database->inventory->read($database->search->data->inventory_id);
            return;
        }
        foreach ($skus as $sku) {
            $lookup=new cad_lookup_class($sku, array("log"=>FALSE));
            if (!strlen($lookup->error)) break;
        }
        if (strlen($lookup->error)) return "Part# <b>{$this->sku_request}</b> not found";
        $mident=preg_split("/[{}]/", $lookup->json['mident'], 2, PREG_SPLIT_NO_EMPTY);
        $prj=explode("/", $mident[0]);
        $query=array("select * from subcategory");
        $query[]="where prj like " . fn_escape("%/" . end($prj) . "%");
        $query[]="limit 1";
        $database->subcategory->query($query);
        $database->subcategory->fetch(TRUE);

        $this->trace[]="PRJ: "  . end($prj) . (($database->subcategory->meta->rows) ? " Found" : " Not found");
        $status=array("Active");
        if ($database->user->access("Administrator",FALSE)) $status[]="Pending";
        if (!in_array($database->subcategory->data->status, $status)) return "Part# <b>{$this->sku_request}</b> not available";

        $this->lookup_sku=$lookup->sku;
        $prj_query=array();
        $prj_query['catalog']=$database->registry->cad->catalog;
        $prj_query['part']=$lookup->sku;
        $this->lookup_prj=http_build_query($prj_query);
        $this->search_source="lookup";
        $database->category->read($database->category->data->category_id);
        $this->category_id=$database->category->data->category_id;
        $this->subcategory_id=$database->subcategory->data->id;
    }
    function search_code() {
        global $database, $forms;
    	$this->trace[]=__FUNCTION__;
        $codes=preg_split("/\s+/",trim($this->request['search_code']));
        if (!sizeof($codes)) {
			print "<p class=center>Search term cannot be blank</p>";
            return;
        }
		$query_where=array();
        $query_where[]=new query_where("language_code", "in", array("", $_SESSION['user']->language_code));
        foreach ($codes as $code) {
        	$query_where[]=new query_where("code","like","%{$code}%");
		}
	    if ($this->subcategory_id) $query_where[]=new query_where("subcategory_id","=",$this->subcategory_id);

		$query=array("select");
        $query[]="table_name,";
        $query[]="code,";
        $query[]="category_id,";
        $query[]="subcategory_id,";
        $query[]="count(*) as 'count'";
        $query[]="from search";
        $query[]=$database->where($query_where);
        $query[]="group by category_id, subcategory_id";
		$summary_list=array();
        $table_subcategory=FALSE;
        $database->temp->query($query);
        $options=array();
        $comment=array("Search: " . $this->request['search_code']);
        $summary=array();
        while ($database->temp->fetch = $database->temp->fetch_array()) {
            if ( ($database->temp->fetch['table_name'] == "inventory") && ($database->temp->fetch['count'] == 1) ) $summary['sku']=$database->temp->fetch['code'];
        	$summary['category_id']=$database->temp->fetch['category_id'];
        	$summary['subcategory_id']=$database->temp->fetch['subcategory_id'];
        	if ($database->temp->fetch['table_name'] == "subcategory") $table_subcategory=TRUE;
        	if (!array_key_exists($database->temp->fetch['category_id'], $summary_list)) $summary_list[  $database->temp->fetch['category_id'] ]=array();
        	$summary_list[ $database->temp->fetch['category_id'] ][]=$summary['subcategory_id'];
        }
        switch ($database->temp->meta->rows) {
          case 0:
            $comment[]="No records found";
            break;
          case 1:
            $options['subcategory_id']=$summary['subcategory_id'];
            if (strlen($summary['sku'])) $options['sku']=$summary['sku'];
            break;
          default:
            $comment[]="Categories: " . number_format(sizeof($summary_list));
            $count=0;
            foreach ($summary_list as $items) {
                $count += sizeof($items);
            }
            $comment[]="Subcategories: " . number_format($count);
        }
        $options['comment']=$comment;
        $database->log->update("search:general", $options);
        switch (TRUE) {
          case (!$database->temp->meta->rows):
			print "<p class=center>No results for search term: <b>" . $this->search_code . "</b></p>";
            return;
          case ($table_subcategory):
          case ($database->temp->meta->rows > 1):
			$this->output_summary($summary_list);
            return;
		}
        $this->category_id=$summary['category_id'];
        $this->subcategory_id=$summary['subcategory_id'];
        $detail_list=array();
        $query=array("select * from inventory");
        $query[]="where inventory.id in (";
        $query[]="select search.inventory_id from search";
        $query[]=$database->where($query_where);
        $query[]=")";
        $query[]="order by sort_order, sku";
        $database->inventory->query($query);
        while ($database->inventory->fetch = $database->inventory->fetch_array() ) {
        	$database->inventory->fetch();
            $detail_list[]=$database->inventory->data;
        }
        if (sizeof($detail_list) == 1) {
			$this->action="sku";
        	$this->search_sku();
		  } else {
        	$this->output_subcategory($detail_list);
		}
	}
    function related_sku() {
        global $database;
    	$this->trace[]=__FUNCTION__;
		$this->action="sku";
        $database->inventory->read($this->sku, "sku");
        if ($database->inventory->meta->rows) {
            $this->category_id=$database->inventory->data->category_id;
            $this->subcategory_id=$database->inventory->data->subcategory_id;
            return;
        }
        $this->related_sku=$this->sku;
        $this->sku=fn_base64($this->request['related'],FALSE);
        $database->inventory->read($this->sku, "sku");
        $this->category_id=$database->inventory->data->category_id;
        $this->subcategory_id=$database->inventory->data->subcategory_id;
        $database->subcategory->read($this->subcategory_id);

        $options=array();
        $options['sku']=$this->sku;
        $options['subcategory_id']=$this->subcategory_id;
        $options['comment']=array();
        $options['comment'][]="Subcategory: {$database->subcategory->data->name}";
        $options['comment'][]="Related SKU: {$this->related_sku}";
        $database->log->update("Error:Related SKU",$options);
    }
    function related_sku_message() {
        global $database;
    	$this->trace[]=__FUNCTION__;
        if (strlen($database->registry->local->related_message)) return "<div>" . str_replace("%sku%", $this->related_sku, $database->registry->local->related_message) . "</div>";
    }
    function query_subcategory() {
        global $database, $forms, $menu;
    	$this->trace[]=__FUNCTION__;
	    $query_where=array();
	    $query_where[]=new query_where("inventory.active","=","1");
	    $query_where[]=new query_where("category.active","=","1");
        if ($database->user->access("Administrator",FALSE)) {
			$query_where[]=new query_where("subcategory.status","in",array("Active","Pending"));
		  } else {
			$query_where[]=new query_where("subcategory.status","=","Active");
		}
	    if ($this->subcategory_id) {
        	$query_where[]=new query_where("inventory.subcategory_id","=",$this->subcategory_id);
	        foreach ($this->subcategory[$this->subcategory_id]->parameters as $parameter) {
	            switch (TRUE) {
	              case (!in_array($parameter->id,$this->subcategory[$this->subcategory_id]->parameter_search)):
	              case (!strlen($this->parameter[$parameter->id])):
	                break;
	              case ($parameter->numeric):
	                $query_where["parameter{$parameter->id}"]=new query_where("inventory.nparameter{$parameter->id}","=",$this->parameter[$parameter->id]);
	                break;
	              default:
	                $query_where["parameter{$parameter->id}"]=new query_where("inventory.parameter{$parameter->id}","=",$this->parameter[$parameter->id]);
	            }
	        }
		}
        if ($this->new_date) {
        	$query_where['new_date'][]=new query_where("inventory.new_date",">=",$database->inventory->new_date());
        	$query_where['new_date'][]=new query_where("subcategory.new_date",">=",$database->inventory->new_date(),"or");
		}
		$query=
        	"select \n" .
            "inventory.* from inventory \n" .
	        "left join subcategory on subcategory.id=inventory.subcategory_id \n" .
	        "left join category on category.id=subcategory.category_id \n" .
            $database->where($query_where) .
			"order by inventory.sort_order, inventory.sku";
		return $query;
    }
    function portal() {
        global $database, $forms, $menu;
    	$this->trace[]=__FUNCTION__ . " {$this->portal_category}";
        print "<div class=products_content>";
        print $this->portal->image();
        print $this->portal->headline();
		print $this->portal->detail($this->portal_category);
        print "</div>";
		$database->log->update("page:portal",array("subcategory_id"=>$this->portal_category,"comment"=>"Portal: {$database->portalcategory->data->name}"));
    }
    function summary() {
	    global $database, $forms, $menu;
    	$this->trace[]=__FUNCTION__;
        if (!sizeof($menu->subcategory)) return;
		print "<div class=products_summary>";
        print $database->hero->output();
		if ($_SESSION['user']->portal_id) {
            print $this->portal->image(array("class"=>"left"));
            print $this->portal->headline();
            print $this->portal->summary();
            print "<h1>Standard Products</h1>";
        }
	    $query_where=array();
	    $query_where[]=new query_where("category.active","=",1);
        if ($database->user->access("Administrator",FALSE)) {
			$query_where[]=new query_where("subcategory.status","in",array("Active","Pending"));
		  } else {
			$query_where[]=new query_where("subcategory.status","=","Active");
		}
        switch (TRUE) {
          case ($this->new_date):
	        $query_where[]=new query_where("inventory.active","=",1);
	        $query_where[]=new query_where("inventory.subcategory_id","in",$menu->subcategory);
	        if ($this->new_date) {
	            $query_where['new_date'][]=new query_where("inventory.new_date",">=",$database->inventory->new_date());
	            $query_where['new_date'][]=new query_where("subcategory.new_date",">=",$database->inventory->new_date(),"or");
	        }
	        $query=array("select");
	        $query[]="category.id as 'category.id',";
            $query[]="inventory.subcategory_id as 'subcategory.id'";
            $query[]="from inventory";
            $query[]="left join subcategory on subcategory.id=inventory.subcategory_id";
	    	$query[]="left join category on category.id=subcategory.category_id";
			$query[]=$database->where($query_where);
			$query[]="group by subcategory.id";
			$query[]="order by category.sort_order,category.name,subcategory.sort_order,subcategory.name";
            break;
          default:
	        $query_where[]=new query_where("subcategory.id","in",$menu->subcategory);
	        $query=array("select");
	        $query[]="category.id as 'category.id',";
            $query[]="subcategory.id as 'subcategory.id'";
            $query[]="from subcategory";
	    	$query[]="left join category on category.id=subcategory.category_id";
			$query[]=$database->where($query_where);
			$query[]="order by category.sort_order,category.name,subcategory.sort_order,subcategory.name";
        }
	    $database->temp->query($query);
        $results=array();
		$summary_list=array();
        while ($database->temp->fetch = $database->temp->fetch_array() ) {
			$subcategory_id=$database->temp->fetch['subcategory.id'];
			$category_id=$this->subcategory[$subcategory_id]->category_id;
			if (!array_key_exists($category_id,$summary_list)) $summary_list[$category_id]=array();
			$summary_list[$category_id][]=$subcategory_id;
        }
		$database->temp->free_result();
        $this->output_summary($summary_list);
		print "</div> <!-- products_summary -->";
    }
    function blocked_categories() {
        global $database;
        if (is_array($this->blocked_categories)) return $this->blocked_categories;
        $this->blocked_categories=array();
        $this->blocked_categories_user=array();
        $this->blocked_categories_company=array();
        if ($_SESSION['user']->id && !isset($_SESSION['user']->bot_code)) {
            $query="SELECT category_id FROM user_category WHERE user_id=" . fn_escape($_SESSION['user']->id,FALSE);
            $database->temp->query($query);
            while ($database->temp->fetch=$database->temp->fetch_array()) {
                $this->blocked_categories_user[]=intval($database->temp->fetch['category_id']);
            }
            $database->temp->free_result();
            if (isset($_SESSION['user']->company_name) && strlen($_SESSION['user']->company_name)) {
                $query="SELECT category_id FROM company_category_block WHERE company_name=" . fn_escape($_SESSION['user']->company_name);
                $database->temp->query($query);
                while ($database->temp->fetch=$database->temp->fetch_array()) {
                    $this->blocked_categories_company[]=intval($database->temp->fetch['category_id']);
                }
                $database->temp->free_result();
            }
            $this->blocked_categories=array_values(array_unique(array_merge($this->blocked_categories_user,$this->blocked_categories_company)));
        }
        return $this->blocked_categories;
    }
    function blocked_agreement_message($category_id) {
        $agreement_types=array();
        if (in_array($category_id,(array)$this->blocked_categories_user)) $agreement_types[]="Integrator Agreement";
        if (in_array($category_id,(array)$this->blocked_categories_company)) $agreement_types[]="Design Partner Agreement";
        return (sizeof($agreement_types) > 1) ?
            implode(" and ",$agreement_types) . " are required for Access." :
            $agreement_types[0] . " Required for Access.";
    }
    function output_summary($summary_list) {
	    global $database, $forms, $menu;
    	$this->trace[]=__FUNCTION__;
        $blocked_categories=$this->blocked_categories();
        $url_query=array();
        $url_query['lang']=strtolower($_SESSION['user']->language_code);
        if ($this->request['debug']) $url_query['debug']=1;
        $url_query['action']="subcategory";
        if (strlen($this->search_code)) $url_query['search_code']=$this->search_code;
		if ($this->category_id) $url_query['category_id']=$this->category_id;
        $url_query['subcategory_id']=0;
        if ($this->new_date) $url_query['new_date']=1;
		foreach ($summary_list as $category_id => $subcategory_list) {
			$blocked=in_array($category_id,$blocked_categories);
			$results[]="<a class=anchor id=category{$category_id}></a><h2>" . $this->category[$category_id]->name . "</h2>";
			$results[]="<div class='catalog_category_wrapper'>";
        	$results[]="<div class=catalog_subcategory_list>";
            $results[]="<ul>";

            foreach ($subcategory_list as $subcategory_id) {
                $subcategory=$this->subcategory[$subcategory_id];
				$url_query['subcategory_id']=$subcategory->id;
                $href_query=array();
                $watermark="";
                switch (TRUE) {
				  case ($subcategory->new_date >= $database->inventory->new_date() ):
					$watermark="new_subcategory";
                    $href_query['title']="New Item!";
                    break;
				  case (isset($subcategory->new_inventory)):
					$watermark="new_inventory";
                    $href_query['title']="New Sizes!";
                    break;
                }
                $li_style="";
                switch (TRUE) {
                  case ($subcategory->status == "Pending"):
                    $li_style="style='border: 1px solid #00b0f0;'";
                    break;
                  case (strlen($watermark)):
                    $li_style="style='border: 1px solid #f00;'";
                    break;
                }
                $text=array();
                $text[]="<span class='subcat" . $subcategory->id . "'></span>";
                $text[]=$subcategory->name;
                $link=$blocked ? "<span class='catalog_blocked_link'>" . implode("", $text) . "</span>" : fn_href(implode("", $text), $this->php_self, $url_query, $href_query);
                $results[]="<li {$li_style}>" . $link . "</li>";
            }
            $results[]="</ul>";
			$results[]="</div>";
            if ($blocked) {
                $results[]="<div class='catalog_category_overlay'>";
                $results[]="<div class='catalog_category_overlay_message'>" . $this->blocked_agreement_message($category_id) . " " . fn_href("Contact Us","/contact") . " for more information.</div>";
                $results[]="</div>";
            }
            $results[]="</div>";
        }
		$results[]="</div>";
		print implode("\n",$results);
    }
    function sku() {
        global $database, $forms, $menu;
    	$this->trace[]=__FUNCTION__;
		$query=
			$this->query_subcategory() .
            $database->item_limit($this->item);
		$database->inventory->query($query,TRUE);
		$database->inventory->fetch(TRUE);
		$database->inventory->free_result();
        $this->output_sku();
	}
    function search_sku() {
        global $database, $forms, $menu;
    	$this->trace[]=__FUNCTION__;
        $this->output_sku();
    }
    function subcategory() {
        global $database, $forms, $menu;
    	$this->trace[]=__FUNCTION__;
		$data=array();
        $query=$this->query_subcategory();
        if ( ($this->subcategory[$this->subcategory_id]->page_break) && (!$_SESSION['user']->bot_code) ) $query .= $database->page_limit($this->page);
		$database->inventory->query($query,TRUE);
        while ($database->inventory->fetch = $database->inventory->fetch_array() ) {
			$database->inventory->fetch();
            $data[$database->inventory->data->sku]=$database->inventory->data;
		}
        $database->inventory->free_result();
        $this->output_subcategory($data);
    }
    function search() {
        global $database, $forms, $menu;
    	$this->trace[]=__FUNCTION__;
		switch (TRUE) {
		  case (!$this->subcategory_id):
          case (!sizeof($this->subcategory[$this->subcategory_id]->parameter_search)):
			return;
		}
		if (sizeof(array_filter($this->parameter))) $this->tabs->landing_tab();
    	$results=array();
        $results[]="<script type='text/javascript'>";
        $results[]="function fn_search_select(obj,search_complete) {";
		$url_query=array();
        $url_query['lang']=strtolower($_SESSION['user']->language_code);
        $url_query['action']="subcategory";
        $url_query['subcategory_id']=$this->subcategory_id;
        if (strlen($this->search_code)) $url_query['search_code']=$this->search_code;
        if ($this->new_date) $url_query['new_date']=1;
        foreach ($this->parameter as $key => $value) {
			if (strlen($value)) $url_query["parameter{$key}"]=$value;
        }
        $url_query["page"]=1;
        $url_query["search_complete"]=1;
        $text=array();
        foreach ($url_query as $key => $value) {
			$text[]=fn_escape($key) . ": " . fn_escape($value);
        }
        $results[]="var url_query={" . implode(", ",$text) . "};";
        $results[]="if (search_complete=='0') {";
        $results[]="  url_query['search_complete']='0';";
		$results[]="  url_query[obj.id]=obj.value;";
        $results[]="}";
	    $results[]="window.location.href = '" . $this->php_self . "?' + $.param(url_query);";
        $results[]="}";
        $results[]="</script>";
		$query_where=array();
	    $query_where[]=new query_where("category.active","=",1);
	    $query_where[]=new query_where("subcategory.id","=",$this->subcategory_id);
        if ($database->user->access("Administrator",FALSE)) {
			$query_where[]=new query_where("subcategory.status","in",array("Active","Pending"));
		  } else {
			$query_where[]=new query_where("subcategory.status","=","Active");
		}
	    $query_where[]=new query_where("inventory.active","=",1);
        foreach ($this->subcategory[$this->subcategory_id]->parameters as $parameter) {
			switch (TRUE) {
			  case (!in_array($parameter->id,$this->subcategory[$this->subcategory_id]->parameter_search)):
              case (!strlen($this->parameter[$parameter->id])):
				break;
			  case ($parameter->numeric):
				$query_where["parameter{$parameter->id}"]=new query_where("inventory.nparameter{$parameter->id}","=",$this->parameter[$parameter->id]);
                break;
              default:
				$query_where["parameter{$parameter->id}"]=new query_where("inventory.parameter{$parameter->id}","=",$this->parameter[$parameter->id]);
			}
        }
        $parameter_results=array();
        foreach ($this->subcategory[$this->subcategory_id]->parameters as $parameter) {
			if (!in_array($parameter->id,$this->subcategory[$this->subcategory_id]->parameter_search)) continue;
            $parameter_results[$parameter->id]=array();
			$parameter_query_where=$query_where;
            if ($parameter->numeric) {
            	$parametric_query_field="inventory.nparameter{$parameter->id}";
				$parameter_query_where["parameter{$parameter->id}"]=new query_where("inventory.nparameter{$parameter->id}","<>",0);
              } else {
            	$parametric_query_field="inventory.parameter{$parameter->id}";
				$parameter_query_where["parameter{$parameter->id}"]=new query_where("inventory.parameter{$parameter->id}","<>","");
            }
            $query=
				"select \n" .
				$parametric_query_field . " as 'parameter_results'\n" .
                "from inventory \n" .
                "left join subcategory on subcategory.id=inventory.subcategory_id \n" .
                "left join category on category.id=subcategory.category_id \n" .
                $database->where($parameter_query_where) .
                "group by parameter_results \n" .
                "order by parameter_results";
			$database->temp->query($query);
            while ($database->temp->fetch = $database->temp->fetch_array() ) {
				$parameter_results[$parameter->id][]=$parameter->output($database->temp->fetch['parameter_results']);
            }
			$database->temp->free_result();
		}
        $results[]="<p class='standard'>";
        foreach ($this->subcategory[$this->subcategory_id]->parameter_search as $parameter_id) {
			switch (TRUE) {
			  case (!array_key_exists($parameter_id,$this->subcategory[$this->subcategory_id]->parameters)):
              case (sizeof($parameter_results[$parameter_id]) < 2):
              	break;
			  default:
				$parameter=$this->subcategory[$this->subcategory_id]->parameters[$parameter_id];
				$results[]="<span class=nobr>{$parameter->name} : ";
                $results[]=$forms->select("parameter{$parameter_id}",$parameter_results[$parameter_id],$this->parameter[$parameter_id],TRUE,array("onchange"=>"fn_search_select(this,'0');"));
                $results[]="</span>";
			}
		}
		$results[]="</p>";
		return implode("",$results);
    }
    function output_subcategory($data) {
        global $database, $forms, $menu;
    	$this->trace[]=__FUNCTION__;
        $category_id=$this->subcategory[$this->subcategory_id]->category_id;
        if (in_array($category_id,$this->blocked_categories())) {
            $results=array();
            $results[]="<div id=products_header>";
            $results[]="<h1>" . $this->subcategory[$this->subcategory_id]->name . "</h1>";
            $results[]="</div>";
            $results[]="<div class='catalog_category_overlay'>";
            $results[]="<div class='catalog_category_overlay_message'>" . $this->blocked_agreement_message($category_id) . " " . fn_href("Contact Us","/contact") . " for more information.</div>";
            $results[]="</div>";
            print implode("\n",$results);
            return;
        }
		print $this->subcategory_title();
	    print "<div id='cart_dialog'>";
        print "<div id='cart_contents'></div>";
	    print "</div>\n";
		$this->output_overview();
		if (!$this->subcategory[$this->subcategory_id]->prj_landing_page) {
			if ($this->page) $this->tabs->landing_tab();
        	$this->tabs->item($menu->language('catalog_products'),$this->inventory($data),array("id"=>"catalog_products"));
		}
        $this->output_customs();
        if (strlen($this->sku)) {
            $database->inventory->read($this->sku, "sku");
            $this->output_sku_tab();
        }
		$this->output_cad();
        $this->output_download();
		$this->tabs_output();
    }
    function output_customs() {
        global $database, $forms, $menu;
        $query_where=array();
        $query_where[]=new query_where("record_type","=","subcategory");
        $query_where[]=new query_where("record_id","=",$this->subcategory_id);
        $query_where[]=new query_where("record_item", "in", array_keys($database->subcategory->constant->tabs));
        $query=array("select");
        $query[]="if(language_code = " . fn_escape($_SESSION['user']->language_code) . ", 0, 1) as language_match,";
        $query[]="content.* from content";
        $query[]=$database->where($query_where);
        $query[]="order by record_item, language_match";
        $database->content->query($query);
        $found=array();
        while ($database->content->fetch = $database->content->fetch_array()) {
            $database->content->fetch();
            if (array_key_exists($database->content->data->record_item, $found)) continue;
            $found[$database->content->data->record_item]=$database->content->data->language_code;
            $field="catalog_{$database->content->data->record_item}";
            $results=array();
            switch ($database->content->data->filetype) {
              case "image":
                $results[]="<img src='" . fn_url("/scs_file.php",array("content_id"=>$database->content->encode($database->content->data->id))) . "'>";
                break;
              case "html":
                $results[]="<div>" . $database->content->data->content . "</div>";
                break;
            }
            $this->tabs->item($menu->language($field), $results, array("id"=>$field));

        }
    }
    function output_download() {
        global $database, $forms, $menu;
    	$this->trace[]=__FUNCTION__;
		$query_where=array();
        $query_where['subcategory']=array();
        $query_where['subcategory'][]=new query_where("content.record_type","=","subcategory");
        $query_where['subcategory'][]=new query_where("content.record_id","=",$this->subcategory_id);
        $query_where['subcategory'][]=new query_where("content.record_item","like","download:%");
        if ($this->sku) {
	        $query_where['inventory']=array();
	        $query_where['inventory'][]=new query_where("content.record_type","=","inventory","or");
	        $query_where['inventory'][]=new query_where("content.record_id","=",$database->inventory->data->id);
	        $query_where['inventory'][]=new query_where("content.record_item","like","download:%");
        }
		$query=
			"select \n" .
            "if (content.record_type = 'subcategory',1,0) as sort_key, \n" .
            "content.* from content \n" .
			$database->where($query_where) .
            "order by sort_key, content.record_item";
		$database->content->query($query);
        if (!$database->content->meta->rows) return;
	    $results=array();
	    $results[]="<table class='standard noborder'>";
	    while ($database->content->fetch = $database->content->fetch_array() ) {
			$database->content->fetch();
            list($content_download,$content_id)=preg_split("/\:/",$database->content->data->record_item);
			switch (TRUE) {
			  case ($database->content->data->record_type != "inventory"):
				break;
			  case (!array_key_exists($this->subcategory_id,$this->subcategory)):
			  case (!array_key_exists($content_id,$this->subcategory[$this->subcategory_id]->download)):
				$database->content->data->name="";
				break;
			  default:
				$database->content->data->name=$this->subcategory[$this->subcategory_id]->download[$content_id];
			}
            if ($database->content->data->name) {
				$local_log=array();
                $local_log[]="download";
                $local_log[]=$database->content->data->meta->name;
	            $local_log[]=$this->sku;
	            $local_log[]=$this->subcategory_id;
	            $results[]="<tr>";
	            $url_options=array();
	            $url_options['content_id']=$database->content->encode($database->content->data->id);
	            $url_options['local_log']=base64_encode(implode("\t",$local_log));
	            $href_options=array();
	            $href_options['target']="_blank";
	            $results[]="<td>" . fn_href($database->content->data->name,"/scs_file.php",$url_options,$href_options) . "</td>";
	            $results[]="</tr>";
			}
	    }
		$database->content->free_result();
		$results[]="</table>";
		$this->tabs->item($menu->language('catalog_download'),$results,array("id"=>"catalog_download"));
    }
    function subcategory_title($sku=FALSE) {
        global $database, $forms, $menu;
    	$this->trace[]=__FUNCTION__;
		$results=array();
        $text=array();
        $text[]=$this->subcategory[$this->subcategory_id]->name;
        switch (TRUE) {
		  case ($this->subcategory[$this->subcategory_id]->new_date >= $database->inventory->new_date() ):
			$text[]="(New Item!)";
            break;
		  case (isset($this->subcategory[$this->subcategory_id]->new_inventory)):
			$text[]="(New Sizes!)";
            break;
		}
		$results[]="<div id=products_header>";
		$results[]="<h1>" . implode(" ",$text) . "</h1>";
		$results[]="</div>";
        $results[]="<div class=products_content>";
        if ($this->subcategory[$this->subcategory_id]->thumbnail_url)$results[]="<div style='display:none;'><img src='" . $this->subcategory[$this->subcategory_id]->thumbnail_url . "' id=cart_thumbnail></div>";
		$results[]="<div id=products_image>";
        if ($this->subcategory[$this->subcategory_id]->image_url) $results[]="<span>" . $forms->img($this->subcategory[$this->subcategory_id]->image_url,array("align"=>"top","width"=>450)) . "</span>";
        if ($this->subcategory[$this->subcategory_id]->sketch_url) $results[]="<span>" . $forms->img($this->subcategory[$this->subcategory_id]->sketch_url,array("align"=>"top","width"=>450)) . "</span>";
		$results[]="</div>";
        $results[]="</div>";

        return implode("\n",$results);
    }
    function output_sku_tab() {
        global $database, $forms, $menu;
    	$this->trace[]=__FUNCTION__;
        if (in_array($this->category_id,$this->blocked_categories())) {
            $results=array();
            $results[]="<div class='catalog_category_overlay'>";
            $results[]="<div class='catalog_category_overlay_message'>" . $this->blocked_agreement_message($this->category_id) . " " . fn_href("Contact Us","/contact") . " for more information.</div>";
            $results[]="</div>";
            $this->tabs->item($menu->language('catalog_sku'),$results,array("id"=>"catalog_sku"));
            return;
        }
        $database->subcategory->data=$this->subcategory[$this->subcategory_id];
        $menu->configure=new configure_class("");
        $menu->configure->inventory_msrp();
        $results=array();
        if (strlen($this->related_sku)) $results[]=$this->related_sku_message();
		$results[]="<table class='noborder large'>";
        $results[]="<tr>";
        $results[]="<td width='50%'>";
        $data=array();
        $data[]="Part number: " . $database->inventory->data->sku;
        $data[]="Category: " . $this->category[$this->category_id]->name;
        $data[]="Subcategory: " . $this->subcategory[$this->subcategory_id]->name;
		switch (TRUE) {
		  case ($database->inventory->data->new_date >= $database->inventory->new_date()):
			$data[]="New Item!";
            break;
		  case ($this->subcategory[$this->subcategory_id]->new_date >= $database->inventory->new_date()):
			$data[]="New Series!";
            break;
        }
        $results[]="<p>" . implode("<br>\n",$data) . "</p>";
        $results[]=$menu->configure->output_verbose();
        if ($_SESSION['user']->ecommerce) {
        	$text=array();
			$field_sku=fn_base64(array("sku",$database->inventory->data->sku));
			$field_list=fn_base64(array("list",$database->inventory->data->sku));
            $text[]=$forms->text($field_sku,"",6,6,"",array("class"=>"products_sku_quantity"));
            $text[]=$forms->button("Add to Cart",array("class"=>"cart_button","onclick"=>"cart_update(this," . fn_escape($field_sku) . "," . fn_escape($field_list) . ");"));
            $results[]="<p>" . implode(" ",$text) . "</p>";

            if ($this->subcategory[$this->subcategory_id]->prj) {
                $field=array();
                $field[]="inventory";
                $field[]=$database->inventory->data->sku;
                $results[]="<p>" . $forms->button("Download CAD",array("title"=>"Download CAD", "class"=>"quick_cad_button", "onclick"=>"quick_cad('', " . fn_escape($database->inventory->data->sku) . ", 0);")) . "</p>";
            }
        }
        $results[]="</td>";
        $items=array();
        foreach ($this->subcategory[$this->subcategory_id]->parameters as $parameter) {
            switch (TRUE) {
                case (!strlen($database->inventory->data->parameter[$parameter->id])):
                $text="";
                break;
              case ($parameter->hyperlink):
                $hyperlink_query=array();
                $hyperlink_query['action']="related";
                $hyperlink_query['sku']=fn_base64(array($database->inventory->data->parameter[$parameter->id]));
                $hyperlink_query['related']=fn_base64(array($database->inventory->data->sku));
                if ($this->request['debug']) $hyperlink_query['debug']=1;
                $text=fn_href($database->inventory->data->parameter[$parameter->id],$this->php_self,$hyperlink_query,array("title"=>"Related Part#"));
                break;
              default:
                $text="{$database->inventory->data->parameter[$parameter->id]}";
            }
            if (strlen($text)) $items[]="{$parameter->name}: <b>{$text}</b>";
        }
        if (sizeof($items)) array_unshift($items, "<b>Specifications:</b>");
        $results[]="<td width='50%'>" . implode("<br>", $items) . "</td>";
		$results[]="</tr>";
		$results[]="</table>";
        $this->tabs->item($menu->language('catalog_sku'),$results,array("id"=>"catalog_sku"));
		if ($database->user->access("Internal",FALSE)) $this->xref($database->inventory->data->sku);
    }
    function output_sku() {
        global $database, $forms, $menu;
    	$this->trace[]=__FUNCTION__;
        $this->subcategory_id=$database->inventory->data->subcategory_id;
		$this->category_id=$this->subcategory[$this->subcategory_id]->category_id;
		print $this->subcategory_title(TRUE);
		$this->output_sku_tab();
		$this->output_overview();
		$this->output_cad();
        $this->output_download();
		$this->tabs_output();
    }
    function output_cad() {
        global $database, $forms, $menu;
    	$this->trace[]=__FUNCTION__;
        switch (TRUE) {
          case (!$_SESSION['user']->id);
          case (!$_SESSION['user']->cad);
          case ($_SESSION['user']->status != "Active");
          case (!strlen($database->profile->data->email)):
          case ( (!strlen($this->subcategory[$this->subcategory_id]->prj)) && (!strlen($this->lookup_prj)) ):
            return;
        }
        switch (TRUE) {
          case (strlen($this->lookup_prj)):
            $prj=$this->lookup_prj;
            break;
          default:
            $prj=$this->subcategory[$this->subcategory_id]->prj;
            if ( ($this->subcategory[$this->subcategory_id]->lookup) && (strlen($this->sku)) && ($database->inventory->lookup()) ) $prj=$database->inventory->data->prj;
        }
        switch (TRUE) {
          case (!strlen($prj)):
            return;
          case ($this->search_source == "lookup"):
            $this->tabs->landing_tab();
            $database->log->update("search:lookup",array("sku"=>$this->lookup_sku,"subcategory_id"=>$this->subcategory_id));
            break;
          case ($this->action == "sku"):
            $this->tabs->landing_tab();
            $database->log->update("PARTSolutions:configurator",array("sku"=>$database->inventory->data->sku,"subcategory_id"=>$database->inventory->data->subcategory_id));
            break;
        }
        $options=array();
        $options['prj']=$prj;
        $options['partFixed']="false";
        $options['languageIso']=strtolower($_SESSION['user']->language_code);
        $options['hidePortlets']=array("briefcase", "searchHeader");
        $url=$database->cad->url($options);
        $results=array();
        $results[]="<div id=products_cad_configurator>";
        $results[]="<iframe id=scscpq_configurator_id src=$url onload='javascript:scscpq_initial(this.id, false);'>IFrame support required to view content</iframe>";
        $results[]="</div>";
        $results[]="<div id=products_cad_price>";
        $results[]="<span id=products_cad_html></span>";
        $results[]="</div>";
        $this->tabs->item($menu->language('catalog_cad'),$results,array("id"=>"catalog_cad","class"=>"products_cad"));
    }
    function output_overview() {
        global $database, $forms, $menu;
    	$this->trace[]=__FUNCTION__;
		$query_where=array();
        $query_where[]=new query_where("record_type","=","subcategory");
        $query_where[]=new query_where("record_id","=",$this->subcategory_id);
        $query_where[]=new query_where("record_item","like","overview:%");
	    $query_where[]=new query_where("language_code","in",array("EN",$_SESSION['user']->language_code));
        $query=array("select");
        $query[]="if (language_code='EN',2,1) as sort_key,";
        $query[]="content.* from content";
		$query[]=$database->where($query_where);
		$query[]="order by sort_key, record_item";
		$database->content->query($query);
        if (!$database->content->meta->rows) return;
        $results=array();
        $items=array();
        while ($database->content->fetch = $database->content->fetch_array() ) {
			$database->content->fetch();
            if (in_array($database->content->data->record_item, $items)) continue;
            $items[]=$database->content->data->record_item;
	        switch ($database->content->data->filetype) {
	          case "image":
        		$results[]="<p class=products_overview>" . $database->content->data->name . "</p>";
	            $results[]="<img src='" . fn_url("/scs_file.php",array("content_id"=>$database->content->encode($database->content->data->id))) . "'>";
	            break;
	          case "html":
            	$results[]="<p class=products_overview>" . $database->content->data->name . "</p>";
            	$results[]="<div>" . $database->content->data->content . "</div>";
				break;
              default:
	            $url_options=array();
	            $url_options['content_id']=$database->content->encode($database->content->data->id);
	            $href_options=array();
	            $href_options['target']="_blank";
            	$results[]="<p class=products_overview>" . fn_href($database->content->data->name,"/scs_file.php",$url_options,$href_options) . "</p>";
			}
            $results[]="<hr>";
        }
        $this->tabs->item("Overview",$results,array("id"=>"catalog_overview"));
    }
    function inventory($data) {
        global $database, $forms, $menu;
    	$this->trace[]=__FUNCTION__;
		if ( ($this->subcategory[$this->subcategory_id]->output) || (!sizeof($data)) ) return;
		if ( ($this->action=="subcategory") && ($this->search_code) ) $this->tabs->landing_tab();
		$category_id=$this->subcategory[$this->subcategory_id]->category_id;
		$blocked=in_array($category_id,$this->blocked_categories());
        $database->subcategory->data=$this->subcategory[$this->subcategory_id];
        $menu->configure=new configure_class("");
		$results=array();
        $results[]=$forms->hidden("cart_quantity");
		$results[]=$this->search();
        if ($this->subcategory[$this->subcategory_id]->description) $results[]="<div class='products_content' style='margin-bottom: 10px;'>" . $this->subcategory[$this->subcategory_id]->description . "</div>";
        if ($_SESSION['user']->ecommerce) $results[]=$menu->configure->currency_name();
        if (strlen($this->related_sku)) $results[]=$this->related_sku_message();
        $results[]="<table class='products_table'>";
        $results[]="<thead>";
        $results[]="<tr>";
        $results[]="<th>" . ( (array_key_exists("sku",$this->subcategory[$this->subcategory_id]->options)) ? $this->subcategory[$this->subcategory_id]->options['sku'] : "Part#" ) . "</th>";
        foreach ($this->subcategory[$this->subcategory_id]->parameters as $parameter) {
            $results[]="<th>" . wordwrap($parameter->name,12,"<br>") . "</th>";
        }
        if ($_SESSION['user']->price_type) $results[]="<th>" . ( (array_key_exists("price",$this->subcategory[$this->subcategory_id]->options)) ? $this->subcategory[$this->subcategory_id]->options['price'] : "Price" ) . "</th>";
        if ($_SESSION['user']->ecommerce) {
        	$results[]="<th class=nobr>" . $forms->font("red","*", " Typical<br>Lead Time") . "</th>";
        	$results[]="<th>Quantity</th>";
        	$results[]="<th class=nobr>Add to<br>Cart</th>";
            if ($this->subcategory[$this->subcategory_id]->prj) $results[]="<th class=nobr>Quick<br>CAD</th>";
        }
        $results[]="</tr>";
        $results[]="</thead>";
        $url_query=array();
        $url_query['lang']=strtolower($_SESSION['user']->language_code);
        $url_query['action']="sku";
        $url_query['subcategory_id']=$this->subcategory_id;
        if ($this->request['debug']) $url_query['debug']=1;
        if (strlen($this->search_code)) $url_query['search_code']=$this->search_code;
        if ($this->new_date) $url_query['new_date']=1;
        if (sizeof($this->subcategory[$this->subcategory_id]->parameter_search)) {
            $url_query["search_complete"]=$this->search_complete;
            foreach ($this->parameter as $key => $value) {
                if (strlen($value)) $url_query["parameter{$key}"]=$value;
            }
        }
        $url_query['search_complete']=$this->search_complete;
        $url_query['sku']="";
        $url_query['scs_page']=$this->page;
        $results[]="<tbody>";
        foreach ($data as  $database->inventory->data) {
        	$results[]="<tr>";
            $url_query['sku']=fn_base64(array($database->inventory->data->sku));
            $text=array();
            switch (TRUE) {
              case ($this->subcategory[$this->subcategory_id]->new_date >= $database->inventory->new_date() ):
              case ($database->inventory->data->new_date >= $database->inventory->new_date() ):
                $text[]=$forms->font("red","*");
            }
            $text[]=$blocked ? "<span class='catalog_blocked_link'>" . $database->inventory->data->sku . "</span>" : fn_href($database->inventory->data->sku,$this->php_self,$url_query);
            $results[]="<td class='nobr'>" . implode("",$text) . "</td>";
            foreach ($this->subcategory[$this->subcategory_id]->parameters as $parameter) {
                switch (TRUE) {
                  case (!strlen($database->inventory->data->parameter[$parameter->id])):
                    $text="&nbsp;";
                    break;
                  case ($parameter->hyperlink):
                    $hyperlink_query=array();
                    $hyperlink_query['action']="related";
                    $hyperlink_query['sku']=fn_base64(array($database->inventory->data->parameter[$parameter->id]));
                    $hyperlink_query['related']=fn_base64(array($database->inventory->data->sku));
                    if ($this->request['debug']) $hyperlink_query['debug']=1;
                    $text=fn_href($database->inventory->data->parameter[$parameter->id],$this->php_self,$hyperlink_query,array("title"=>"Related Part#"));
                    break;
                  default:
                    $text=$database->inventory->data->parameter[$parameter->id];
                }
                $results[]="<td>{$text}</td>";
            }
            if ($_SESSION['user']->price_type) {
            	$menu->configure->inventory_msrp();
                $results[]="<td class='right nobr'>" . $menu->configure->output_simple() . "</td>";
            }
	        if ($_SESSION['user']->ecommerce) {
	            $results[]="<td class=center>" . $database->inventory->leadtime() . "</td>";
	            $field_sku=fn_base64(array("sku",$database->inventory->data->sku));
	            $field_list=fn_base64(array("list",$database->inventory->data->sku));
	            $results[]="<td class=center>" . $forms->text($field_list,"",6,6,"",array("class"=>"products_quantity")) . "</td>";
	            $results[]="<td class=center>" . fn_href($forms->img("/scs_images/addtocart.png",array("id"=>$field_icon,"class"=>"products_cart","title"=>"Add to Cart")),"javascript:cart_update(this," . fn_escape($field_list) . "," . fn_escape($field_sku) . ")") . "</td>";
                $field=array();
                $field[]="inventory";
                $field[]=$database->inventory->data->sku;
                if ($this->subcategory[$this->subcategory_id]->prj) $results[]="<td class=center>" . $forms->checkbox(fn_base64(array("inventory", $database->inventory->data->sku)), 1, 0, array("onclick"=>"quick_cad(this.id, " . fn_escape($database->inventory->data->sku) . ", 0);")) . "</td>";
	        }
            $results[]="</tr>";
        }
        $results[]="</tbody>";
        $results[]="</table>";
		switch (TRUE) {
          case (!$this->subcategory[$this->subcategory_id]->page_break):
          case ($_SESSION['user']->bot_code):
          case ($database->inventory->meta->found_rows <= $database->query_limit):
        	break;
          default:
        	$url_query['lang']=strtolower($_SESSION['user']->language_code);
            $url_query['action']="subcategory";
            $url_query["scs_page"]="%page%";
            unset($url_query['sku']);
            $url=fn_url($this->php_self,$url_query);
            $page_break=new page_item_break($database->inventory->meta->found_rows,"window.location.href=" . fn_escape($url) . ";");
            $results[]=$page_break->page($this->page);
        }
		return $results;
    }
    function xref($sku) {
        global $database, $forms, $menu;
        $this->trace[]=__FUNCTION__;
        if (!sizeof($database->inventory->data->competitor_sku)) return;
        $results=array();
        $results[]="<p class='standard catalog'>";
        $results[]="&bull; " . implode("<br>\n&bull; ", $database->inventory->data->competitor_sku);
        $results[]="</p>";
		$this->tabs->item($menu->language('catalog_competitors'),$results,array("id"=>"catalog_competitors"));
    }
    function tabs_output() {
        global $database, $forms, $menu;
    	$this->trace[]=__FUNCTION__;
		$this->tabs->landing_tab("Product Range",TRUE);
        print "<div class=center id=quick_cad_status>" . $database->profile->quick_cad_message() . "</div>";
        if (!isset($_SESSION['user']->bot_code)) $this->tabs->item($menu->language('catalog_profile'), $database->profile->enter(), array("id"=>"catalog_profile"));
		$this->tabs->output();
    }
    function js() {
        return <<< EOT
<script>
jQuery(document).ready(function() {
    jQuery('.products_cart').on('mouseover', function(){
        jQuery(this).attr('src', '/scs_images/addtocart-hover.png');
    });
    jQuery('.products_cart').on('mouseout', function(){
        jQuery(this).attr('src', '/scs_images/addtocart.png');
    });
});
function quick_cad(id, sku, configurator) {
    if (id.length) jQuery("#" + id).prop("checked", false);
    cad_name=jQuery("#cad_name").val();
    if (!cad_name.length) {
        jQuery("#profile_tab").val(jQuery("#products_jquery_div").tabs('option', 'active'));
        jQuery('#products_jquery_div').tabs({ active: jQuery("#tab_catalog_profile_no").val() });
        return;
    }
    spinner_message("Generating CAD ...", "quick_cad_status");

    data=new Object();
    data['action']="quick_cad";
    data['sku']=sku;
    if (configurator == 1) data['configurator']=scscpq_data;
	jQuery.ajax({
        url: "/scscpq_api.php",
        type: "post",
	    dataType: "json",
		data,
	    success: function(response) {
            scscpq_fill(response)
            if ("url" in response) window.open(response['url'], "_blank")
        },
        error: function (request, status, error) {
            jQuery("#quick_cad_status").html("Internal Error Detected")
            console.log(request)
        }
    });
}
</script>
EOT;
    }
    function css() {
        return <<< EOT
<style>
.scscpq_spinner {
  display: inline-block;
  border: 4px solid #f3f3f3;
  border-radius: 50%;
  border-top: 4px solid #e53247;
  width: 20px;
  height: 20px;
  -webkit-animation: spin 2s linear infinite; /* Safari */
  animation: spin 2s linear infinite;
}
.scscpq_spinner_text {
  display: inline-block;
  vertical-align: top;
  margin-left: 10px;
  margin-top: 5px;
}
#quick_cad_status {
   margin-bottom: 10px;
}
.quick_cad_button {
    color: #fff;
    background: #6b1f1f;
    width: 160px !important;
    height: 80px !important;
    line-height: 33px !important;
    white-space: normal !important;
    text-align: center !important;
    cursor: pointer;
    border-radius: 4px;
    padding: 0 1em;
    font-family: 'Rajdhani' !important;
	font-weight: 600 !important;
	font-size: 26px !important;
}
.cad_download_button {
    color: #fff;
    background: #6b1f1f;
    cursor: pointer;
    border-radius: 4px;
    padding: 0 1em;
    margin-bottom: 10px;
    font-family: 'Rajdhani' !important;
	font-weight: 600 !important;
	font-size: 26px !important;
    text-decoration: none !important;
}

/* Safari */
@-webkit-keyframes spin {
  0% { -webkit-transform: rotate(0deg); }
  100% { -webkit-transform: rotate(360deg); }
}

@keyframes spin {
  0% { transform: rotate(0deg); }
  100% { transform: rotate(360deg); }
}
.catalog_category_wrapper {
    position: relative;
    display: inline-block;
    width: 100%;
}
.catalog_category_overlay {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background-color: rgba(0, 0, 0, 0.75);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 10;
    cursor: not-allowed;
}
.catalog_category_overlay_message {
    text-align: center;
    padding: 20px 30px;
    font-weight: 600;
    font-size: 1.1rem;
    color: #fff;
    background: rgba(0, 0, 0, 0.85);
    border-radius: 6px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.15);
}
.catalog_category_overlay_message a {
    color: #e51c38;
    cursor: pointer;
}
.catalog_blocked_link {
    cursor: not-allowed;
}
</style>
EOT;
    }
}
class catalog_legacy_class {

    function legacy_menu() {
	    global $database, $forms, $menu;
    	$this->trace[]=__FUNCTION__;
		if (!$database->portalcontent->portal->header->id) return;
        print "<div class=products_content>";
	    print "<table class='large noborder'>\n";
	    print "<tr>\n";
	    $standard_options=array();
	    $standard_options["onmouseover"]="image_rollover(this,'scs_images/portal_standard2.jpg');";
	    $standard_options["onmouseout"]="image_rollover(this,'scs_images/portal_standard1.jpg');";
	    $standard_options["title"]="Harcourt Standard Products!";
	    $custom_options=array();
	    $custom_options["onmouseover"]="image_rollover(this,'scs_images/portal_custom2.jpg');";
	    $custom_options["onmouseout"]="image_rollover(this,'scs_images/portal_custom1.jpg');";
	    $custom_options["title"]="Custom Designs for " . $_SESSION['user']->company_name;
	    if ($_SESSION['user']->catalog) print "<td width=50% class=center>" . fn_href($forms->img('scs_images/portal_standard1.jpg',$standard_options),$this->php_self) . "</td>\n";
	    if (sizeof($database->portalcontent->portal->item)) print "<td width=50% class=center>" . fn_href($forms->img("scs_images/portal_custom1.jpg",$custom_options),$this->php_self,array("action"=>"legacy_products")) . "</td>\n";
	    print "</tr>\n";
	    print "</table>\n";
        print "</div>";
    }
    function legacy_products() {
	    global $database, $forms, $menu;
    	$this->trace[]=__FUNCTION__;
		print $database->portalcontent->portal_custom();
    }
}
?>
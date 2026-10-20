<?php
require_once "scs_header.php";
$obj=new local_class($forms->cron);
class local_class {
	var $subcategory=array();
    var $count=array();
    var $message=array();
    var $timestamp;
    var $cron;
    var $trace=array();
	function scs_table_version() {
		$results=array();
        $results['10/20/2026']="Add categories; name insert columns (search.code_normalized)";
        $results['09/29/2025']="Add cron output";
        $results['10/06/2024']="Add purge";
        $results['08/22/2024']="Add search_words, competitor_sku";
        $results['01/12/2024']="Only active";
        $results['11/28/2023']="Add CRON";
        $results['10/26/2023']="Initial release";
        $results['08/01/2023']="Proof of concept (beta)";
        $results['file']=__FILE__;
        return $results;
    }
	function __construct($cron) {
        global $database;
        $this->cron=$cron;
        $this->timestamp=strtotime("now");
		$this->input=new stdClass();
        switch (TRUE) {
          case ($this->cron):
            die( $this->process() );
          case (!$database->user->access("Administrator",FALSE)):
            die("Access denied");
          case (preg_match("/^xmlhttprequest$/i",$_SERVER['HTTP_X_REQUESTED_WITH'])):
        	$this->json();
            break;
          default:
          	$this->html();
		}
    }
    function json() {
    global $database, $forms;
    	$this->response=new stdClass();

        die(json_encode($this->response));
    }
    function html() {
        global $database, $forms, $menu;
        switch (TRUE) {
          case ($_POST['action']):
            $this->process();
            break;
        }
	    $forms->title("Search Utility (" . key($this->scs_table_version()) . ")");
        $forms->message[]="Last Update: " . ( ($database->registry->local->search_timestamp) ? date("r", $database->registry->local->search_timestamp) : "Never");
	    $menu->head();
	    print $forms->message();
        print $this->js();
        print $forms->open();
        print $forms->hidden("action","update");
        if ($_POST['action']) {
            print "<p>Processing Complete<br>" . implode("<br>", $this->message);
            print implode("", $this->stopwatch->results(FALSE));
		  } else {
        	print "<p id=submit_button>" . $forms->button("Go",array("onclick"=>"fn_action();") ) . "</p>";
        }
        print $forms->close();
        $menu->copyright();
    }
    function process() {
        global $database;
        $this->trace[]=__FUNCTION__;
    	set_time_limit(120);
        $this->stopwatch=new stopwatch_class("Search Utility");
        $query="truncate table search";
        $this->stopwatch->split_comment($query);
        $database->temp->query($query);
        $query=trim($this->table_create());
        $this->stopwatch->split_comment($query);
		$database->temp->query($query);

        $query_where=array();
        $query_where[]=new query_where("status","=","active");
        $query=array("select id from subcategory");
        $query[]=$database->where($query_where);
        $this->stopwatch->split("Subcategory", $query);
        $database->temp->query($query);
		while ($database->temp->fetch = $database->temp->fetch_array()) {
			$this->subcategory[]=$database->temp->fetch['id'];
        }
        $this->stopwatch->split_comment("Records: " . number_format($database->temp->meta->rows));
        $this->count['subcategory']=$database->temp->affected_rows();
        // Categories first, so they survive the duplicate purge against same-named search words
        $this->count['category']=$this->category();
        $this->count['content']=$this->content();
        $this->count['inventory']=$this->inventory();
        $this->count['search_words']=$this->search_words();
        $this->count['competitor_sku']=$this->competitor_sku();
        $this->count['duplicates']=$this->duplicates();
        $this->count['count']=$this->count();
        $this->stopwatch->stop();
        $this->message['elapsed']="Elapsed Time: " . number_format($this->stopwatch->elapsed, 2) . " seconds";
        $this->message['subcategory']="Subcategory: " . number_format($this->count['subcategory']);
        $this->message['category']="Category Languages: " . number_format($this->count['category']);
        $this->message['languages']="Subcategory Languages: " . number_format($this->count['content']);
        $this->message['search words']="Search Words: " . number_format($this->count['search_words']);
        $this->message['inventory']="Inventory: " . number_format($this->count['inventory']);
        $this->message['competitor']="Competitor SKU: " . number_format($this->count['competitor_sku']);
        $this->message['purge']="Purge Duplicates: " . number_format($this->count['duplicates']);
        $this->message['unique']="Unique search terms: " . number_format($this->count['count']);
        $options=array();
        $options['comment']=$this->message;
        if ($this->cron) $options['cron']=$this->cron;
        $database->log->update("CRON:Search Utility", $options);
        $database->registry->update("local", "search_timestamp", $this->timestamp);
        $database->registry->load("local");
        return $this->message['unique'];
    }
    function table_create() {
        $this->trace[]=__FUNCTION__;
        return <<< EOT
    CREATE TEMPORARY TABLE `search_temp` (
    `code` varchar(120) DEFAULT NULL,
    `category_id` bigint(10) DEFAULT NULL,
    `subcategory_id` bigint(10) DEFAULT NULL,
    `inventory_id` bigint(10) DEFAULT NULL,
    `ranking` bigint(10) DEFAULT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=latin1 COMMENT='Temporary Search (08/20/2024)'
 EOT;
    }
    function category() {
        global $database;
        $this->trace[]=__FUNCTION__;
        $query_where=array();
        $query_where[]=new query_where("content.record_type", "=", "category");
        $query_where[]=new query_where("content.record_item", "=", "");
        $query_where[]=new query_where("category.active", "=", 1);
        $query=array("insert into search (id, language_code, active, table_name, code, category_id, subcategory_id, inventory_id, ranking)");
		$query[]="select 0, content.language_code, 1, 'category', regexp_replace(content.name, ' *&[a-zA-Z0-9#]+;', ''), category.id, 0, 0, cast(category.sort_order as signed) - 1000000 from content";
        $query[]="left join category on category.id=content.record_id";
        $query[]=$database->where($query_where);
        $this->stopwatch->split("Category Languages", $query);
        $database->temp->query($query);
        $this->stopwatch->split_comment("Records: " . number_format($database->temp->meta->rows));
		return $database->temp->affected_rows();
    }
    function content() {
        global $database;
        $this->trace[]=__FUNCTION__;
        $query_where=array();
        $query_where[]=new query_where("content.record_type", "=", "subcategory");
        $query_where[]=new query_where("content.record_item", "=", "");
        $query_where[]=new query_where("content.record_id", "in", $this->subcategory);
        $query=array("insert into search (id, language_code, active, table_name, code, category_id, subcategory_id, inventory_id, ranking)");
		$query[]="select 0, content.language_code, if(subcategory.status = 'Pending',0,1), 'subcategory', content.name, subcategory.category_id, subcategory.id, 0, subcategory.sort_order from content";
        $query[]="left join subcategory on subcategory.id=content.record_id";
        $query[]=$database->where($query_where);
        $this->stopwatch->split("Subcategory Languages", $query);
        $database->temp->query($query);
        $this->stopwatch->split_comment("Records: " . number_format($database->temp->meta->rows));
		return $database->temp->affected_rows();
    }
    function inventory() {
        global $database;
        $this->trace[]=__FUNCTION__;
        $query_where=array();
        $query_where[]=new query_where("inventory.active", "=", 1);
        $query_where[]=new query_where("inventory.subcategory_id", "in", $this->subcategory);
        $query=array("insert into search (id, language_code, active, table_name, code, category_id, subcategory_id, inventory_id, ranking)");
        $query[]="select 0, '', if(subcategory.status = 'Pending',0,1), 'inventory', inventory.sku, subcategory.category_id, subcategory.id, inventory.id, inventory.sort_order + 2000000 from inventory";
        $query[]="left join subcategory on subcategory.id=inventory.subcategory_id";
        $query[]=$database->where($query_where);
        $this->stopwatch->split("Inventory", $query);
        $database->temp->query($query);
        $this->stopwatch->split_comment("Records: " . number_format($database->temp->meta->rows));
		return $database->temp->affected_rows();
    }
    function search_words() {
        global $database;
        $this->trace[]=__FUNCTION__;
        $this->stopwatch->split("Search Words");
        $database->temp->query("truncate table search_temp");
        $this->stopwatch->split_comment($database->temp->meta->query);
        $query_where=array();
        $query_where[]=new query_where("id", "in", $this->subcategory);
        $query=array("select * from subcategory");
        $query[]=$database->where($query_where);
        $this->stopwatch->split_comment($query);
        $database->subcategory->query($query);
        $this->stopwatch->split_comment("Found: " . number_format($database->subcategory->meta->rows));
        while ($database->subcategory->fetch = $database->subcategory->fetch_array()) {
            $database->subcategory->fetch();
            $names=array();
            foreach ($database->subcategory->data->search_words as $name) {
                $name=trim($name);
                if ( (strlen($name) > 2) && (!in_array($name, $names)) ) $names[]=$name;
            }
            if (!sizeof($names)) continue;
            $text=array();
            $text[0]="";
            $text[1]=fn_escape($database->subcategory->data->category_id);
            $text[2]=fn_escape($database->subcategory->data->id);
            $text[3]=0;
            $text[4]=fn_escape($database->subcategory->data->sort_order + 1000000);
            $items=array();
            foreach ($names as $name) {
                $name=trim($name);
                if (!strlen($name)) continue;
                $text[0]=fn_escape($name);
                $items[]="(" . implode(", ", $text) . ")";
            }
            $query=array("insert into search_temp values");
            $query[]=implode(", ", $items);
            $database->temp->query($query);
        }
        $query=array("insert into search (id, language_code, active, table_name, code, category_id, subcategory_id, inventory_id, ranking)");
        $query[]="select 0, 'EN', 1, 'search_words', code, category_id, subcategory_id, inventory_id, ranking from search_temp";
        $query[]="group by code";
        $query[]="order by code, ranking";
        $database->temp->query($query);
        $this->stopwatch->split_comment("Records: " . number_format($database->temp->meta->rows));
        return $database->temp->affected_rows();
    }
    function competitor_sku() {
        global $database;
        $this->trace[]=__FUNCTION__;
        $this->stopwatch->split("Competitor SKU");
        $database->temp->query("truncate table search_temp");
        $this->stopwatch->split_comment($database->temp->meta->query);

        $query_where=array();
        $query_where[]=new query_where("active", "=", 1);
        $query_where[]=new query_where("subcategory_id", "in", $this->subcategory);
        $query_where[]=new query_where("competitor_sku", "<>", "");
        $query=array("select * from inventory");
        $query[]=$database->where($query_where);
        $this->stopwatch->split_comment($query);
        $database->inventory->query($query);
        $this->stopwatch->split_comment("Found: " . number_format($database->inventory->meta->rows));
        while ($database->inventory->fetch = $database->inventory->fetch_array()) {
            $database->inventory->fetch();
            $items=array();
            $text=array();
            $text[0]="";
            $text[1]=fn_escape($database->inventory->data->category_id);
            $text[2]=fn_escape($database->inventory->data->subcategory_id);
            $text[3]=fn_escape($database->inventory->data->id);
            $text[4]=fn_escape($database->inventory->data->sort_order + 3000000);
            foreach ($database->inventory->data->competitor_sku as $sku) {
                $text[0]=fn_escape($sku);
                $items[]="(" . implode(",", $text) . ")";
            }
            $query=array("insert into search_temp values");
            $query[]=implode(", ", $items);
            $database->temp->query($query);
        }
        $query=array("insert into search (id, language_code, active, table_name, code, category_id, subcategory_id, inventory_id, ranking)");
        $query[]="select 0, '', 1, 'competitor_sku', code, category_id, subcategory_id, inventory_id, ranking from search_temp";
        $query[]="group by code";
        $query[]="order by code, ranking";
        $database->temp->query($query);
        $this->stopwatch->split_comment("Records: " . number_format($database->temp->meta->rows));
        return $database->temp->affected_rows();
    }
    function duplicates() {
        global $database;
        $this->trace[]=__FUNCTION__;
        $query=array("select code, id, count(*) as count from search");
        $query[]="group by code";
        $query[]="having count > 1";
        $query[]="order by ranking, id";
        $database->temp->query($query);
        $this->stopwatch->split("Duplicate Records", $query);
        $this->stopwatch->split_comment("Records: " . number_format($database->temp->meta->rows));
        $items=array();
        while ($database->temp->fetch = $database->temp->fetch_array()) {
            $items[ $database->temp->fetch['id'] ]=$database->temp->fetch['code'];
        }
        foreach ($items as $id => $code) {
            $query=array("delete from search");
            $query[]="where code=" . fn_escape($code);
            $query[]="and id <> {$id}";
            $database->temp->query($query);
        }
        return sizeof($items);
    }
    function count() {
        global $database;
        $this->trace[]=__FUNCTION__;
        $query="select count(*) as count from search";
        $database->temp->query($query);
        $this->stopwatch->split("Unique search terms", $query);
        $database->temp->fetch();
        $count=$database->temp->fetch['count'];
        $this->stopwatch->split_comment("Records: " . number_format($count));
        return $count;
    }
    function js() {
        return <<<EOT

<script>
function fn_action() {
    jQuery("#submit_button").html("Working ...");
    jQuery("#scs_form").submit();
}
</script>

EOT;
    }
}
?>
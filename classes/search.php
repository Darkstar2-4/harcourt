<?php
$database->search = new search_class();
class search_class extends database_class {
	function scs_table_version() {
		$results=array();
        $results['10/20/2026']="Separator-insensitive and typo-tolerant part# matching";
        $results['09/11/2024']="Add lookup part# if null results";
        $results['07/31/2023']="Initial release";
        $results['file']=__FILE__;
        return $results;
    }
	function __construct() {
    	$this->data=new search_data_class();
    	$this->meta=new database_meta_class();
        $this->fetch=array();
        $this->constant=new search_constant_class();
    }
    function fetch($fetch=FALSE) {
        if ($fetch) $this->fetch=$this->fetch_array();
    	$this->data=new search_data_class();
	    $this->data->id=intval($this->fetch['id']);
	    $this->data->language_code=$this->fetch['language_code'];
	    $this->data->active=intval($this->fetch['active']);
	    $this->data->table_name=$this->fetch['table_name'];
	    $this->data->code=$this->fetch['code'];
	    $this->data->category_id=intval($this->fetch['category_id']);
	    $this->data->subcategory_id=intval($this->fetch['subcategory_id']);
	    $this->data->inventory_id=intval($this->fetch['inventory_id']);
	    $this->data->ranking=intval($this->fetch['ranking']);
    }
    function read($id, $field="id") {
		$query=array("select * from search");
        $query[]="where {$field}=" . fn_escape($id);
        $this->query($query);
        if ($this->meta->rows) {
        	$this->fetch(TRUE);
		  } else {
			$this->data=new search_data_class();
		}
        $this->free_result();
    }
    function update($update=FALSE) {
		$field=array();
	    $fields[]="id=" . fn_escape($this->data->id,FALSE);
	    $fields[]="language_code=" . fn_escape($this->data->language_code);
	    $fields[]="active=" . fn_escape($this->data->active,FALSE);
	    $fields[]="table_name=" . fn_escape($this->data->table_name);
	    $fields[]="code=" . fn_escape($this->data->code);
	    $fields[]="category_id=" . fn_escape($this->data->category_id,FALSE);
	    $fields[]="subcategory_id=" . fn_escape($this->data->subcategory_id,FALSE);
	    $fields[]="inventory_id=" . fn_escape($this->data->inventory_id,FALSE);
	    $fields[]="ranking=" . fn_escape($this->data->ranking,FALSE);
        $query=array();
    	if ($update) {
          	$query[]="update search set";
            $query[]=implode(",\n",$fields);
            $query[]="where id=" . fn_escape($this->data->id);
		  } else {
          	$query[]="insert into search set";
            $query[]=implode(",\n",$fields);
        }
		$this->query($query);
        $this->data->id = $this->insert_id();
    }
    function delete($id) {
	    $query=array("delete from search");
        $query[]="where id=" . fn_escape($id);
		$this->query($query);
    }
    function blocked_categories() {
    global $database;
        $blocked_categories=array();
        if ( ($_SESSION['user']->id) && (!isset($_SESSION['user']->bot_code)) ) {
            $query="SELECT category_id FROM user_category WHERE user_id=" . fn_escape($_SESSION['user']->id,FALSE);
            $database->temp->query($query);
            while ($database->temp->fetch=$database->temp->fetch_array()) {
                $blocked_categories[]=intval($database->temp->fetch['category_id']);
            }
            $database->temp->free_result();
            if (isset($_SESSION['user']->company_name) && strlen($_SESSION['user']->company_name)) {
                $query="SELECT category_id FROM company_category_block WHERE company_name=" . fn_escape($_SESSION['user']->company_name);
                $database->temp->query($query);
                while ($database->temp->fetch=$database->temp->fetch_array()) {
                    $blocked_categories[]=intval($database->temp->fetch['category_id']);
                }
                $database->temp->free_result();
            }
        }
        return array_values(array_unique($blocked_categories));
    }
    // Part numbers are matched with separators (hyphen, space, underscore, period, slash)
    // ignored so HRSH81 or HRSH 8 1 finds HRSH-8-1. Must strip the same separators as
    // the code_normalized generated column.
    function normalize_code($text) {
        return strtoupper(preg_replace("/[-\s_.\/]+/", "", $text));
    }
    // Every code one typo away (deleted, swapped, wrong or extra character) from $code.
    function typo_variants($code) {
        $characters=str_split($this->constant->typo_characters);
        $length=strlen($code);
        $results=array();
        for ($position=0; $position < $length; $position ++) {
            $results[]=substr($code,0,$position) . substr($code,$position+1);
            if ($position < $length-1) $results[]=substr($code,0,$position) . $code[$position+1] . $code[$position] . substr($code,$position+2);
            foreach ($characters as $character) {
                $results[]=substr($code,0,$position) . $character . substr($code,$position+1);
            }
        }
        for ($position=0; $position <= $length; $position ++) {
            foreach ($characters as $character) {
                $results[]=substr($code,0,$position) . $character . substr($code,$position);
            }
        }
        return array_values(array_diff(array_unique($results),array($code,"")));
    }
    // Fallback when nothing matches as typed: codes starting with a one-typo variant of the
    // search, so a mistyped (or partly typed) part# still finds the part. Exact variants first.
    function ajax_typo($query_where,$limit) {
        $code=$this->normalize_code($this->search_terms);
        if ( (strlen($code) < $this->constant->typo_min_length) || (strlen($code) > $this->constant->typo_max_length) ) return FALSE;
        $variants=$this->typo_variants($code);
        $query_where['typo']=array();
        foreach ($variants as $variant) {
            $query_where['typo'][]=new query_where("code_normalized", "like", str_replace("%","\\%",$variant) . "%", (sizeof($query_where['typo']) ? "or" : ""));
        }
        $query=array();
        $query[]="select * from search force index (code_normalized_index)";
        $query[]=$this->where($query_where);
        $query[]="order by code_normalized in (" . implode(", ",array_map("fn_escape",$variants)) . ") desc, ranking, code";
		if ($limit) $query[]="limit {$limit}";
        $this->query($query);
        return TRUE;
    }
    function ajax_items($response) {
        $response->rows=$this->meta->rows;
        $response->found_rows=number_format($this->meta->found_rows);
	    while($this->fetch = $this->fetch_array() ) {
			$this->fetch();
			$obj=new stdClass();
			$obj->value=$this->search_terms;
            $obj->label=$this->data->code;
            $obj->category=ucwords($this->data->table_name);
            $obj->id=$this->data->id;
			$response->items[]=$obj;
		}
		$this->free_result();
    }
    function ajax($search_terms,$limit,$options=array()) {
    global $database;
		$this->search_terms=implode(" ",$search_terms);
	    $query_filter=array();
        $query_filter[]=new query_where("language_code","in", array("",$_SESSION['user']->language_code));
		if ($database->user->access("Administrator",FALSE)) $query_filter[]=new query_where("active","=",1);
        $query_filter[]=new query_where("category_id","not in",$this->blocked_categories());
	    $query_where=$query_filter;
        foreach ($search_terms as $term) {
            $normalized=$this->normalize_code($term);
            if (strlen($normalized)) {
                $query_where[]=new query_where("code_normalized", "like", "%{$normalized}%");
              } else {
                $query_where[]=new query_where("code", "like", "%{$term}%");
            }
		}
        $query=array();
        $query[]="select * from search";
        $query[]=$this->where($query_where);
        $query[]="order by code_normalized=" . fn_escape($this->normalize_code($this->search_terms)) . " desc, ranking, code";
		if ($limit) $query[]="limit {$limit}";
        $this->query($query);
        $response=new stdClass();
        $response->items=array();
        $this->ajax_items($response);
        if (sizeof($response->items)) return $response;
        // Typo suggestions sit above the part# lookup, which still covers parts not in the index
        if ($this->ajax_typo($query_filter,$limit)) $this->ajax_items($response);
        if (sizeof($search_terms) == 1) {
            $obj=new stdClass();
            $obj->value=$this->search_terms;
            $obj->label="Lookup Part# {$this->search_terms}";
            $obj->category="Lookup";
            $obj->id=0;
            $response->items[]=$obj;
        }
		return $response;
	}
}
class search_data_class {
	var $id=0;
    var $language_code;
    var $active=1;
	var $table_name;
    var $code;
    var $category_id=0;
    var $subcategory_id=0;
    var $inventory_id=0;
    var $ranking=0;
}
class search_constant_class {
    // Shorter codes have too many one-typo neighbours to be useful; longer ones too many variants
    var $typo_min_length=5;
    var $typo_max_length=25;
    var $typo_characters="ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789";
}
/*
CREATE TABLE `search` (
  `id` bigint(10) NOT NULL AUTO_INCREMENT,
  `language_code` varchar(45),
  `active`  int(1),
  `table_name` varchar(45),
  `code` varchar(256),
  `code_normalized` varchar(256) GENERATED ALWAYS AS (replace(replace(replace(replace(replace(`code`,'-',''),' ',''),'_',''),'.',''),'/','')) STORED,
  `category_id` bigint(10),
  `subcategory_id` bigint(10),
  `inventory_id` bigint(10),
  `ranking` bigint(10),
  PRIMARY KEY (`id`),
  KEY `code_index` (`language_code`, `active`, `code`),
  KEY `code_normalized_index` (`code_normalized`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COMMENT='Search (07/31/2023)'
*/
?>
<?php
$database->search = new search_class();
class search_class extends database_class {
	function scs_table_version() {
		$results=array();
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
    function ajax($search_terms,$limit,$options=array()) {
    global $database;
		$this->search_terms=implode(" ",$search_terms);
	    $query_where=array();
        $query_where[]=new query_where("language_code","in", array("",$_SESSION['user']->language_code));
		if ($database->user->access("Administrator",FALSE)) $query_where[]=new query_where("active","=",1);
        foreach ($search_terms as $term) {
			$query_where[]=new query_where("code", "like", "%{$term}%");
		}
        $query_where[]=new query_where("category_id","not in",$this->blocked_categories());
        $query=array();
        $query[]="select * from search";
        $query[]=$this->where($query_where);
        $query[]="order by ranking, code";
		if ($limit) $query[]="limit {$limit}";
        $this->query($query);
        $response=new stdClass();
        $response->rows=$this->meta->rows;
        $response->found_rows=number_format($this->meta->found_rows);
        $response->items=array();
	    while($this->fetch = $this->fetch_array() ) {
			$this->fetch();
			$obj=new stdClass();
			$obj->value=$this->search_terms;
            $obj->label=$this->data->code;
            $obj->category=ucwords($this->data->table_name);
            $obj->id=$this->data->id;
			$response->items[]=$obj;
		}
        if ( (!sizeof($response->items)) && (sizeof($search_terms) == 1) ) {
            $obj=new stdClass();
            $obj->value=$this->search_terms;
            $obj->label="Lookup Part# {$this->search_terms}";
            $obj->category="Lookup";
            $obj->id=0;
            $response->items[]=$obj;
        }
		$this->free_result();
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

}
/*
CREATE TABLE `search` (
  `id` bigint(10) NOT NULL AUTO_INCREMENT,
  `language_code` varchar(45),
  `active`  int(1),
  `table_name` varchar(45),
  `code` varchar(256),
  `category_id` bigint(10),
  `subcategory_id` bigint(10),
  `inventory_id` bigint(10),
  `ranking` bigint(10),
  PRIMARY KEY (`id`),
  KEY `code_index` (`language_code`, `active`, `code`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COMMENT='Search (07/31/2023)'
*/
?>
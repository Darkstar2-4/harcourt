<?php
$database->search = new search_class();
class search_class extends database_class {
	function scs_table_version() {
		$results=array();
        $results['10/21/2026']="Exact-first ranking, category keyword matching, labelled search words/competitor SKUs";
        $results['10/20/2026']="Separator-insensitive and typo-tolerant part# and keyword matching";
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
    // Singular/plural-insensitive keyword: "hinges" and "hinge" both search as "hinge",
    // "assemblies" and "assembly" as "assembl". Part numbers (anything not all letters) pass through.
    function keyword_stem($term) {
        if (!preg_match("/^[a-z]{4,}$/i",$term)) return $term;
        $term=strtolower($term);
        switch (TRUE) {
          case (preg_match("/ies$/",$term)):
            $stem=substr($term,0,-3);
            break;
          case (preg_match("/(s|x|z|ch|sh)es$/",$term)):
            $stem=substr($term,0,-2);
            break;
          case (preg_match("/[^s]s$/",$term)):
          case ( (strlen($term) >= 5) && (preg_match("/y$/",$term)) ):
            $stem=substr($term,0,-1);
            break;
          default:
            $stem=$term;
        }
        return (strlen($stem) >= 3) ? $stem : $term;
    }
    // Category names (English and the user's language) split into lowercase words, by category id.
    function categories() {
        global $database;
        if (isset($this->categories)) return $this->categories;
        $this->categories=array();
        $query_where=array();
        $query_where[]=new query_where("record_type","=","category");
        $query_where[]=new query_where("record_item","=","");
        $query_where[]=new query_where("language_code","in",array("EN",$_SESSION['user']->language_code));
        $query=array("select record_id, name from content");
        $query[]=$this->where($query_where);
        $database->temp->query($query);
        while ($database->temp->fetch=$database->temp->fetch_array()) {
            if (!isset($database->temp->fetch['record_id'])) continue;
            $name=strtolower(preg_replace("/&[a-z0-9#]+;/i","",$database->temp->fetch['name']));
            $category_id=intval($database->temp->fetch['record_id']);
            if (!isset($this->categories[$category_id])) $this->categories[$category_id]=array();
            $this->categories[$category_id]=array_merge($this->categories[$category_id],preg_split("/[^a-z0-9]+/",$name,-1,PREG_SPLIT_NO_EMPTY));
        }
        $database->temp->free_result();
        return $this->categories;
    }
    // Categories with a name word starting with the term, so "clamp" finds Clamping but "pin" not Shipping.
    function category_matches($term,$min_length=3) {
        $stem=strtolower($this->keyword_stem($term));
        if ( (strlen($stem) < $min_length) || (!preg_match("/^[a-z0-9]+$/",$stem)) ) return array();
        $results=array();
        foreach ($this->categories() as $category_id => $words) {
            foreach ($words as $word) {
                if (strpos($word,$stem) === 0) {
                    $results[]=$category_id;
                    break;
                }
            }
        }
        return $results;
    }
    function keyword_like($term) {
        $normalized=$this->normalize_code($this->keyword_stem($term));
        if (!preg_match("/[A-Z0-9]/",$normalized)) return "code like " . fn_escape("%{$term}%");
        return "code_normalized like " . fn_escape("%{$normalized}%");
    }
    // Every term must appear in the code or name, or be the start of a word in the category it is
    // filed under, so "clamp" also finds clamping products without "clamp" in their name. Category
    // entries themselves only match when every term starts a word of the category name.
    function keyword_where($search_terms) {
        $terms=array();
        $category_ids=array();
        foreach (array_filter($search_terms,"strlen") as $term) {
            $like=$this->keyword_like($term);
            $matches=$this->category_matches($term);
            $terms[]=( (sizeof($matches)) ? "({$like} or category_id in (" . implode(", ",$matches) . "))" : $like );
            if (!preg_match("/[a-z0-9]/i",$term)) continue;
            $matches=$this->category_matches($term,1);
            $category_ids=( (isset($category_matched)) ? array_intersect($category_ids,$matches) : $matches );
            $category_matched=TRUE;
        }
        if (!sizeof($terms)) $terms[]="1";
        $sql="(table_name <> 'category' and " . implode(" and ",$terms) . ")";
        if (sizeof($category_ids)) $sql="({$sql} or (table_name = 'category' and category_id in (" . implode(", ",$category_ids) . ")))";
        return array(new query_where($sql,"",""));
    }
    // Exact part number or name first (not search words, which many products can share); then, for
    // part numbers, codes starting with it; then codes or names containing every term ahead of
    // products found only through their category.
    function keyword_order($search_terms) {
        $code=$this->normalize_code(implode(" ",$search_terms));
        $results=array("(code_normalized=" . fn_escape($code) . " and table_name <> 'search_words') desc");
        if (preg_match("/[0-9]/",$code)) $results[]="code_normalized like " . fn_escape(str_replace("%","\\%",$code) . "%") . " desc";
        $results[]="(" . implode(" and ",array_map(array($this,"keyword_like"),$search_terms)) . ") desc";
        $results[]="ranking";
        $results[]="code";
        return "order by " . implode(", ",$results);
    }
    // Words used in category, product family and search-word names, with how often each appears.
    function keywords() {
        if (isset($this->keywords)) return $this->keywords;
        $this->keywords=array();
        $query_where=array();
        $query_where[]=new query_where("table_name","in",array("category","subcategory","search_words"));
        $query_where[]=new query_where("language_code","in",array("",$_SESSION['user']->language_code));
        $query=array("select code from search");
        $query[]=$this->where($query_where);
        $this->query($query);
        while ($this->fetch = $this->fetch_array() ) {
            foreach (preg_split("/[^a-z]+/",strtolower($this->fetch['code']),-1,PREG_SPLIT_NO_EMPTY) as $word) {
                if (strlen($word) < 4) continue;
                $this->keywords[$word]=( (isset($this->keywords[$word])) ? $this->keywords[$word] : 0 ) + 1;
            }
        }
        $this->free_result();
        return $this->keywords;
    }
    // Replaces each word not found in any name with the most common name word one typo away,
    // so "drill bushng" searches as "drill bushing". Returns the terms unchanged if nothing fits.
    function keyword_correct($search_terms) {
        $results=array();
        foreach ($search_terms as $term) {
            $results[]=$term;
            if (!preg_match("/^[a-z]{4,}$/i",$term)) continue;
            $stem=$this->keyword_stem($term);
            $known=FALSE;
            foreach ($this->keywords() as $word => $count) {
                if (strpos($word,$stem) !== FALSE) {
                    $known=TRUE;
                    break;
                }
            }
            if ($known) continue;
            $best=0;
            foreach ($this->typo_variants(strtoupper($term)) as $variant) {
                $variant=strtolower($variant);
                if ( (isset($this->keywords[$variant])) && ($this->keywords[$variant] > $best) ) {
                    $best=$this->keywords[$variant];
                    $results[sizeof($results)-1]=$variant;
                }
            }
        }
        return $results;
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
		if ($limit) $query[]="limit " . $this->ajax_limit($limit);
        $this->query($query);
        return TRUE;
    }
    function ajax_keyword($query_filter,$search_terms,$limit) {
        $query=array();
        $query[]="select * from search";
        $query[]=$this->where(array_merge($query_filter,$this->keyword_where($search_terms)));
        $query[]=$this->keyword_order($search_terms);
		if ($limit) $query[]="limit " . $this->ajax_limit($limit);
        $this->query($query);
    }
    // Results are fetched with headroom (see ajax_limit) since a product family already listed,
    // by name or through one of its search words, is skipped as a repeat.
    function ajax_limit($limit) {
        return ( ($limit) ? $limit * 3 : 0 );
    }
    function ajax_items($response,$limit=0) {
        $response->rows=$this->meta->rows;
        $response->found_rows=number_format($this->meta->found_rows);
        $items=array();
        $subcategory_ids=array();
	    while($this->fetch = $this->fetch_array() ) {
			$this->fetch();
            if (in_array($this->data->table_name,array("subcategory","search_words"))) {
                if (in_array($this->data->subcategory_id,$subcategory_ids)) continue;
                $subcategory_ids[]=$this->data->subcategory_id;
            }
            if ( ($limit) && (sizeof($items) >= $limit) ) continue;
			$obj=new stdClass();
			$obj->value=$this->search_terms;
            $obj->label=$this->data->code;
            $obj->category=ucwords($this->data->table_name);
            $obj->id=$this->data->id;
			$response->items[]=$obj;
            $items[]=array($obj,$this->data->table_name,$this->data->subcategory_id,$this->data->inventory_id);
		}
		$this->free_result();
        // A search word or competitor SKU can lead to several products, so name the destination
        $subcategory_ids=array();
        $inventory_ids=array();
        foreach ($items as $item) {
            if ($item[1] == "search_words") $subcategory_ids[]=$item[2];
            if ($item[1] == "competitor_sku") $inventory_ids[]=$item[3];
        }
        $subcategory_name=$this->ajax_names("subcategory","name",$subcategory_ids);
        $inventory_sku=$this->ajax_names("inventory","sku",$inventory_ids);
        foreach ($items as $item) {
            list($obj,$table_name,$subcategory_id,$inventory_id)=$item;
            if ( ($table_name == "search_words") && (isset($subcategory_name[$subcategory_id])) ) $obj->label .= " - " . $subcategory_name[$subcategory_id];
            if ( ($table_name == "competitor_sku") && (isset($inventory_sku[$inventory_id])) ) $obj->label .= " (Harcourt " . $inventory_sku[$inventory_id] . ")";
        }
    }
    function ajax_names($table,$field,$ids) {
        global $database;
        $results=array();
        $ids=array_values(array_unique(array_filter($ids)));
        if (!sizeof($ids)) return $results;
        $query_where=array();
        $query_where[]=new query_where("id","in",$ids);
        $query=array("select id, {$field} from {$table}");
        $query[]=$this->where($query_where);
        $database->temp->query($query);
        while ($database->temp->fetch=$database->temp->fetch_array()) {
            if (isset($database->temp->fetch[$field])) $results[ $database->temp->fetch['id'] ]=$database->temp->fetch[$field];
        }
        $database->temp->free_result();
        return $results;
    }
    function ajax($search_terms,$limit,$options=array()) {
    global $database;
		$this->search_terms=implode(" ",$search_terms);
	    $query_filter=array();
        $query_filter[]=new query_where("language_code","in", array("",$_SESSION['user']->language_code));
		if ($database->user->access("Administrator",FALSE)) $query_filter[]=new query_where("active","=",1);
        $query_filter[]=new query_where("category_id","not in",$this->blocked_categories());
        $response=new stdClass();
        $response->items=array();
        $this->ajax_keyword($query_filter,$search_terms,$limit);
        $this->ajax_items($response,$limit);
        if (sizeof($response->items)) return $response;
        $corrected=$this->keyword_correct($search_terms);
        if ($corrected != $search_terms) {
            $this->ajax_keyword($query_filter,$corrected,$limit);
            $this->ajax_items($response,$limit);
            if (sizeof($response->items)) return $response;
        }
        // Typo suggestions sit above the part# lookup, which still covers parts not in the index
        if ($this->ajax_typo($query_filter,$limit)) $this->ajax_items($response,$limit);
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
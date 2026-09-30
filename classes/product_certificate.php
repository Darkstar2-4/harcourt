<?php
$database->product_certificate = new product_certificate_class();
class product_certificate_class extends database_class {
	function scs_table_version() {
		$results=array();
        $results['09/30/2026']="Customer domain access";
        $results['08/05/2026']="Initial release";
        $results['file']=__FILE__;
        return $results;
    }
	function __construct() {
    	$this->data=new product_certificate_data_class();
    	$this->meta=new database_meta_class();
        $this->fetch=array();
        $this->constant=new product_certificate_constant_class();
        $this->domain_warning=array();
    }
    function fetch($fetch=FALSE) {
        if ($fetch) $this->fetch=$this->fetch_array();
	    $this->data=new product_certificate_data_class();
	    $this->data->id=$this->fetch['id'];
	    $this->data->sku=$this->fetch['sku'];
	    $this->data->manufacturing_date=$this->fetch['manufacturing_date'];
	    $this->data->testing_date=$this->fetch['testing_date'];
	    $this->data->product_family=$this->fetch['product_family'];
	    $this->data->product_description=$this->fetch['product_description'];
	    $this->data->product_weight=$this->fetch['product_weight'];
	    $this->data->rated_load=$this->fetch['rated_load'];
	    $this->data->conversion=$this->fetch['conversion'];
	    $this->data->test_machine=$this->fetch['test_machine'];
	    $this->data->test_machine_calibration_date=$this->fetch['test_machine_calibration_date'];
	    $this->data->update_timestamp=$this->fetch['update_timestamp'];
	    $this->data->customer_domain=$this->fetch['customer_domain'];
    }
    function read($id,$field="id") {
	    $query=array("select * from product_certificate");
	    $query[]="where {$field}=" . fn_escape($id);
        $this->query($query);
        if ($this->meta->rows) {
        	$this->fetch(TRUE);
			$this->free_result();
		  } else {
			$this->data=new product_certificate_data_class();
		}
    }
    function delete($id) {
	    $query=array("delete from product_certificate where");
        $query[]="id=" . fn_escape($id);
		$this->query($query);
    }
    function export_query() {
	    $query=array("select product_certificate.*,");
        $query[]="(select group_concat(domain order by domain separator '; ') from product_certificate_domain where product_certificate_domain.sku=product_certificate.sku) as customer_domain";
        $query[]="from product_certificate";
        $query[]="order by sku, testing_date";
        return $query;
    }
    function map($options=array()) {
        $this->map=new table_map_class($options);
	    $this->map->item("sku",array("name"=>"Part Number","type"=>"uppercase","required"=>TRUE));
	    $this->map->item("manufacturing_date",array("name"=>"Manufacturing Date","type"=>"date"));
	    $this->map->item("testing_date",array("name"=>"Testing Date","type"=>"date","required"=>TRUE));
	    $this->map->item("product_family",array("name"=>"Product Family","type"=>"uppercase"));
	    $this->map->item("product_description",array("name"=>"Product Description"));
	    $this->map->item("product_weight",array("name"=>"Product Weight"));
	    $this->map->item("rated_load",array("name"=>"Rated Load"));
	    $this->map->item("conversion",array("name"=>"Conversion"));
	    $this->map->item("test_machine",array("name"=>"Test Machine"));
	    $this->map->item("test_machine_calibration_date",array("name"=>"Test Machine Calibration Date","type"=>"date"));
	    $this->map->item("customer_domain",array("name"=>"Customer Domain"));
        $this->map->update_timestamp=strtotime("now");
    }
    function import_update($data,$line=0) {
		$this->data=new product_certificate_data_class();
        $this->data->sku=$data['sku'];
        $this->data->manufacturing_date=$data['manufacturing_date'];
        $this->data->testing_date=$data['testing_date'];
        $this->data->product_family=$data['product_family'];
        $this->data->product_description=$data['product_description'];
        $this->data->product_weight=$data['product_weight'];
        $this->data->rated_load=$data['rated_load'];
        $this->data->conversion=$data['conversion'];
        $this->data->test_machine=$data['test_machine'];
        $this->data->test_machine_calibration_date=$data['test_machine_calibration_date'];
        if (!$this->data->sku) $this->map->error['sku']="Cannot be blank";
        if ($this->date_value($this->data->testing_date)=="NULL") $this->map->error['testing_date']="Cannot be blank";
        // An invalid or public domain is skipped with a warning; the certificate itself still imports
        $domains=array();
        foreach ($this->domain_list($data['customer_domain']) as $domain) {
            $error=$this->domain_error($domain);
            if ($error) {
                $this->domain_warning[]="Line {$line} ({$this->data->sku}): {$error} (domain skipped)";
              } else {
                $domains[]=$domain;
            }
        }
    	if ( ($this->map->update) && (!sizeof($this->map->error)) ) {
			$fields=array();
            $fields[]="sku=" . fn_escape($this->data->sku);
            $fields[]="manufacturing_date=" . $this->date_value($this->data->manufacturing_date);
            $fields[]="testing_date=" . $this->date_value($this->data->testing_date);
            $fields[]="product_family=" . fn_escape($this->data->product_family);
            $fields[]="product_description=" . fn_escape($this->data->product_description);
            $fields[]="product_weight=" . fn_escape($this->data->product_weight);
            $fields[]="rated_load=" . fn_escape($this->data->rated_load);
            $fields[]="conversion=" . fn_escape($this->data->conversion);
            $fields[]="test_machine=" . fn_escape($this->data->test_machine);
            $fields[]="test_machine_calibration_date=" . $this->date_value($this->data->test_machine_calibration_date);
            $fields[]="update_timestamp=" . fn_escape($this->map->update_timestamp,FALSE);
	        $query=array("insert into product_certificate set");
			$query[]=implode(", \n", $fields);
	        $this->query($query);
            // Import only adds domains; removals are done on the Customer Domains screen
            foreach ($domains as $domain) {
                $this->domain_add($this->data->sku,$domain);
            }
		}
    }
    function date_value($value) {
    	return (in_array($value,array("0000/00/00","0000-00-00",""))) ? "NULL" : fn_escape($value,"date");
    }
    // Customer domain access: a customer sees a SKU's certificates only when their domain is assigned to the SKU.
    // SKUs with no domains stay private; Internal users and above see everything.
    function access($sku,$user) {
        if (role_access($user->type,"Internal")) return TRUE;
        $domain=$this->domain_normalize($user->domain);
        if ($this->domain_error($domain)) return FALSE;
        $query=array("select id from product_certificate_domain");
        $query[]="where sku=" . fn_escape($sku);
        $query[]="and domain=" . fn_escape($domain);
        $this->query($query);
        $rows=$this->meta->rows;
        $this->free_result();
        return ($rows > 0);
    }
    function domains($sku) {
        $query=array("select domain from product_certificate_domain");
        $query[]="where sku=" . fn_escape($sku);
        $query[]="order by domain";
        $this->query($query);
        $results=array();
        while ($row=$this->fetch_array()) {
            $results[]=$row['domain'];
        }
        $this->free_result();
        return $results;
    }
    function domain_add($sku,$domain) {
        $query=array("insert ignore into product_certificate_domain set");
        $query[]="sku=" . fn_escape($sku) . ",";
        $query[]="domain=" . fn_escape($domain) . ",";
        $query[]="create_timestamp=" . fn_escape(strtotime("now"),FALSE);
        $this->query($query);
    }
    function domain_delete($sku,$domain) {
        $query=array("delete from product_certificate_domain");
        $query[]="where sku=" . fn_escape($sku);
        $query[]="and domain=" . fn_escape($domain);
        $this->query($query);
    }
    // Splits a "Customer Domain" cell (semicolon, comma or space separated) into normalized domains
    function domain_list($text) {
        $results=array();
        foreach (preg_split("/[;,\s]+/",(string) $text) as $domain) {
            $domain=$this->domain_normalize($domain);
            if ( (strlen($domain)) && (!in_array($domain,$results)) ) $results[]=$domain;
        }
        return $results;
    }
    // Accepts a bare domain, an email address or a URL
    function domain_normalize($domain) {
        $domain=strtolower(trim((string) $domain));
        $domain=preg_replace("#^[a-z]+://#","",$domain);
        if (strpos($domain,"@") !== FALSE) $domain=substr(strrchr($domain,"@"),1);
        $domain=preg_replace("#[/?].*$#","",$domain);
        return preg_replace("/^www\./","",$domain);
    }
    function domain_error($domain) {
        switch (TRUE) {
          case (!strlen($domain)):
            return "Domain is blank";
          case (!preg_match("/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/",$domain)):
            return "Invalid domain: {$domain}";
          case (in_array($domain,$this->constant->public_domain)):
            return "Public email domain not allowed: {$domain}";
        }
        return "";
    }
}
class product_certificate_data_class {
	var $id=0;
	var $sku;
	var $manufacturing_date;
	var $testing_date;
	var $product_family;
	var $product_description;
	var $product_weight;
	var $rated_load;
	var $conversion;
	var $test_machine;
	var $test_machine_calibration_date;
	var $update_timestamp=0;
	var $customer_domain;
}
class product_certificate_constant_class {
    // Free email providers: anyone can sign up with these, so they can never grant certificate access
    var $public_domain=array("gmail.com","googlemail.com","outlook.com","hotmail.com","live.com","msn.com","yahoo.com","ymail.com","aol.com","icloud.com","me.com","mac.com","proton.me","protonmail.com","gmx.com","mail.com","zoho.com","yandex.com","comcast.net","att.net","verizon.net");
}
?>

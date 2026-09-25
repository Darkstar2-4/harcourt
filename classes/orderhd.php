<?php
$database->orderhd = new orderhd_class();
class orderhd_class extends database_class {
	function scs_table_version() {
		$results=array();
        $results['08/25/2026']="Add billing address";
        $results['06/07/2023']="Add cc";
        $results['07/28/2022']="Add currency_code";
        $results['03/18/2021']="Add punchout_id";
        $results['07/08/2018']="Change RFQ to PO";
        $results['02/01/2016']="Cart header chrome issues";
        $results['12/05/2015']="Add carrier_account";
        $results['11/25/2015']="Enhance ecommerce options";
        $results['08/22/2014']="Initial release";
        return $results;
    }
	function __construct() {
    	$this->data=new orderhd_data_class();
        $this->fetch=array();
    	$this->meta=new database_meta_class();
        $this->constant=new orderhd_constant_class();
    }
    function fetch($fetch=FALSE) {
        if ($fetch) $this->fetch=$this->fetch_array();
    	$this->data=new orderhd_data_class();
	    $this->data->id=$this->fetch['id'];
	    $this->data->user_id=$this->fetch['user_id'];
	    $this->data->punchout_id=$this->fetch['punchout_id'];
	    $this->data->order_date=$this->fetch['order_date'];
	    $this->data->email=$this->fetch['email'];
	    $this->data->name_first=$this->fetch['name_first'];
	    $this->data->name_last=$this->fetch['name_last'];
	    $this->data->name=$this->fetch['name'];
	    $this->data->company_name=$this->fetch['company_name'];
	    $this->data->address=$this->fetch['address'];
	    $this->data->city=$this->fetch['city'];
	    $this->data->state=$this->fetch['state'];
	    $this->data->zip=$this->fetch['zip'];
	    $this->data->country_code=$this->fetch['country_code'];
	    $this->data->phone=$this->fetch['phone'];
	    $this->data->po=$this->fetch['po'];
	    $this->data->comment=$this->fetch['comment'];
	    $this->data->status=$this->fetch['status'];
	    $this->data->status_comment=$this->fetch['status_comment'];
	    $this->data->status_user_id=$this->fetch['status_user_id'];
	    $this->data->status_date=$this->fetch['status_date'];
	    $this->data->carrier_name=$this->fetch['carrier_name'];
	    $this->data->carrier_account=$this->fetch['carrier_account'];
	    $this->data->currency_code=$this->fetch['currency_code'];
	    $this->data->cc=$this->fetch['cc'];
	    $this->data->billing_address=$this->fetch['billing_address'];
	    $this->data->billing_city=$this->fetch['billing_city'];
	    $this->data->billing_state=$this->fetch['billing_state'];
	    $this->data->billing_zip=$this->fetch['billing_zip'];
	    $this->data->billing_country_code=$this->fetch['billing_country_code'];
    }
    function read($id) {
        $query=array("select * from orderhd");
        $query[]="where id=" . fn_escape($id);
        $this->query($query);
        if ($this->meta->rows) {
        	$this->fetch(TRUE);
		  } else {
			$this->data=new orderhd_data_class();
		}
        $this->free_result();
    }
    function update($update=FALSE) {
	    $fields=array();
	    $fields[]="id=" . fn_escape($this->data->id,FALSE);
	    $fields[]="user_id=" . fn_escape($this->data->user_id,FALSE);
	    $fields[]="punchout_id=" . fn_escape($this->data->punchout_id,FALSE);
	    $fields[]="order_date=" . fn_escape($this->data->order_date,FALSE);
	    $fields[]="email=" . fn_escape($this->data->email);
	    $fields[]="name_first=" . fn_escape($this->data->name_first);
	    $fields[]="name_last=" . fn_escape($this->data->name_last);
	    $fields[]="name=" . fn_escape($this->data->name);
	    $fields[]="company_name=" . fn_escape($this->data->company_name);
	    $fields[]="address=" . fn_escape($this->data->address);
	    $fields[]="city=" . fn_escape($this->data->city);
	    $fields[]="state=" . fn_escape($this->data->state);
	    $fields[]="zip=" . fn_escape($this->data->zip);
	    $fields[]="country_code=" . fn_escape($this->data->country_code);
	    $fields[]="phone=" . fn_escape($this->data->phone);
	    $fields[]="po=" . fn_escape($this->data->po);
	    $fields[]="comment=" . fn_escape($this->data->comment);
	    $fields[]="type=" . fn_escape($this->data->type);
	    $fields[]="status=" . fn_escape($this->data->status);
	    $fields[]="status_comment=" . fn_escape($this->data->status_comment);
	    $fields[]="status_user_id=" . fn_escape($this->data->status_user_id,FALSE);
	    $fields[]="status_date=" . fn_escape($this->data->status_date,FALSE);
	    $fields[]="carrier_name=" . fn_escape($this->data->carrier_name);
	    $fields[]="carrier_account=" . fn_escape($this->data->carrier_account);
	    $fields[]="currency_code=" . fn_escape($this->data->currency_code);
	    $fields[]="cc=" . fn_escape($this->data->cc,"null");
	    $fields[]="billing_address=" . fn_escape($this->data->billing_address);
	    $fields[]="billing_city=" . fn_escape($this->data->billing_city);
	    $fields[]="billing_state=" . fn_escape($this->data->billing_state);
	    $fields[]="billing_zip=" . fn_escape($this->data->billing_zip);
	    $fields[]="billing_country_code=" . fn_escape($this->data->billing_country_code);
        $query=array();
    	if ($update) {
          	$query[]="update orderhd set";
            $query[]=implode(",\n",$fields);
            $query[]="where id=" . fn_escape($this->data->id);
		  } else {
          	$query[]="insert into orderhd set";
          	$query[]=implode(",\n",$fields);
        }
		$this->query($query);
        if ((!$this->data->id) && (!$this->meta->error)) $this->data->id = $this->insert_id();
    }
    function delete($id) {
	    $query="delete from orderhd where id=" . fn_escape($id);
		$this->query($query);
    }
    function output_header($options=array()) {
    global $database, $forms, $menu;
		if (isset($options['data'])) $this->data=$options['data'];
        if (!isset($options['entry'])) $options['entry']=FALSE;
        if (!isset($options['status'])) $options['status']=FALSE;
		$results=array();
        $results[]="<table class=noborder>";
        $results[]="<tr>";
        $results[]="<td width='60%'>";
        $results[]="<table class='checkout_entry noborder'>";
        if ($this->data->id) {
			$results[]="<tr><td class='checkout_caption' colspan=2>Order Information</td></tr>";
            $results[]="<tr><td>" . ( ($this->data->punchout_id) ? "Punchout " : "") . "Order #</td><td>" . $this->data->id . "</tr>";
            $results[]="<tr><td>Order Date/ Time</td><td>" . date("m/d/Y h:iA",$this->data->order_date) . "</tr>";
            if ($options['status']) {
            	$results[]="<tr><td>Status</td><td>" . $this->data->status . "</td></tr>";
	            if ($this->data->status_date) $results[]="<tr><td>Status Date/ Time</td><td>" . date("m/d/Y h:iA",$this->data->status_date) . "</td></tr>";
			}
        }
		$results[]="<tr><td class='checkout_caption' colspan=2>Customer Information</td></tr>";
        $address_options=array();
        $address_options['width']=array("field"=>20,"value"=>80);
        $address_options['omit']=array("company_name","address","city","state","zip","country_code");
        $address=new address_class("orderhd",$this->data,$address_options);
        switch (TRUE) {
          case ($this->data->punchout_id):
          	$results[]="<tr><td width='20%'>Company Name</td><td width='80%'>" . $this->data->company_name . "</td></tr>";
          	break;
          case ($options['entry']):
			$results[]=$address->input(array("output"=>"table"));
            break;
		  default:
			$results[]=$address->output("table");
		}
        $results[]="<tr><td class='checkout_caption' colspan=2>Shipping Address</td></tr>";
        $address_options['omit']=array("name","title","email","phone","fax");
        $address=new address_class("orderhd",$this->data,$address_options);
        switch (TRUE) {
          case ($options['entry']):
            $results[]=$address->input(array("output"=>"table"));
            break;
          default:
            $results[]=$address->output("table");
        }
        $results[]="<tr><td class='checkout_caption' colspan=2>Billing Address</td></tr>";
        if ($options['entry']) {
            $billing_same=( ($_SERVER['REQUEST_METHOD']=="POST") && ($_POST['action']=="complete") ) ? (isset($_POST['billing_same_as_shipping']) ? 1 : 0) : 0;
            $results[]="<tr><td colspan=2>" . $forms->checkbox("billing_same_as_shipping",1,$billing_same,array("onclick"=>"fn_billing_same_as_shipping(this.checked);")) . " Same as shipping address</td></tr>";
        }
        $billing_data=new stdClass();
        $billing_data->address=$this->data->billing_address;
        $billing_data->city=$this->data->billing_city;
        $billing_data->state=$this->data->billing_state;
        $billing_data->zip=$this->data->billing_zip;
        $billing_data->country_code=$this->data->billing_country_code;
        $address_options['omit']=array("name","title","email","phone","fax","company_name");
        $billing_address=new address_class("orderhd_billing",$billing_data,$address_options);
        switch (TRUE) {
          case ($options['entry']):
            $results[]=$billing_address->input(array("output"=>"table"));
            break;
          default:
            $results[]=$billing_address->output("table");
        }
        $results[]="<tr><td class='checkout_caption' colspan=2>Order Information</td></tr>";
        switch (TRUE) {
          case ($options['entry']):
            $results[]="<tr><td>PO#</td><td>" . $forms->text("po",$this->data->po,40,45,"",array("placeholder"=>"PO# (Optional)")) . "</td></tr>";
            break;
          case (strlen($this->data->po)):
            $results[]="<tr><td>PO#</td><td>" . $this->data->po . "</td></tr>";
            break;
        }
        switch (TRUE) {
          case ($options['entry']):
            $results[]="<tr><td>Comment</td><td>" . $forms->text("comment",$this->data->comment,40,60,"",array("placeholder"=>"Comment (Optional)")) . "</td></tr>";
            break;
          case (strlen($this->data->comment)):
            $results[]="<tr><td>Comment</td><td>" . str_replace("\n","<br>",$this->data->comment) . "</td></tr>";
            break;
        }
        switch (TRUE) {
          case ($options['entry']):
          	$results[]="<tr>";
            $results[]="<td>Credit Card on File</td>";
            $text=array();
            $text[]="Last 4 digits: " . $forms->text("cc",$this->data->cc,4,4,"",array("placeholder"=>"Optional"));
            if ($forms->error_text['cc']) $text[]=$forms->error_text['cc'];
            $results[]="<td>" . implode("<br>",$text) . "</td>";
          	$results[]="</tr>";
          	break;
          case (strlen($this->data->cc)):
            $results[]="<tr><td>Credit Card on File</td><td>.... " . $this->data->cc . "</td></tr>";
            break;
        }
        $results[]="<tr><td class='checkout_caption' colspan=2>Shipping Method</td></tr>";
        switch (TRUE) {
          case ($options['entry']):
            $results[]="<tr><td>Shipping Carrier</td><td>" . $database->carrier->select($this->data->carrier_name) . "</td></tr>";
            break;
          case (strlen($this->data->carrier_name)):
            $results[]="<tr><td>Shipping Carrier</td><td>" . $this->data->carrier_name . "</td></tr>";
            break;
        }
        switch (TRUE) {
          case ($options['entry']):
            $results[]="<tr><td>Collect Account#</td><td>" . $forms->text("carrier_account",$this->data->carrier_account,40,60,"",array("placeholder"=>"Collect Account# (Optional)")) . "</td></tr>";
            break;
          case (strlen($this->data->carrier_account)):
            $results[]="<tr><td>Collect Account#</td><td>" . $this->data->carrier_account . "</td></tr>";
            break;
        }
        $results[]="</table>";


        $results[]="<td width='40%'>";
        if ($options['entry']) {
	        $results[]="<div style='border: 1px solid; padding: 1em; width: 90%'>";
            if (strlen($database->registry->local->cc_checkout)) $results[]="<p>" . $database->registry->local->cc_checkout . "</p>";

	        $results[]="</div>";
        }
        $results[]="</td>";
        $results[]="</tr>";
        $results[]="</table>";
        $results[]="<p>Freight charges will be shown on your invoice unless shipping collect.</p>";
        return implode("\n",$results);
	}
    function currency_code() {
    global $menu;
		$this->data->currency_code=(!array_key_exists($_SESSION['user']->currency_code,$menu->currency)) ? "USD" : $_SESSION['user']->currency_code;
    }
    function currency_name($div=TRUE) {
    global $menu;
    	switch (TRUE) {
          case ($this->data->currency_code != "USD"):
          case (!array_key_exists($_SESSION['user']->currency_code,$menu->currency)):
          	$results=array();
            if ($div) $results[]="<div>";
            $results[]="Priced in <b>" . $menu->currency[$this->data->currency_code]->plural . "</b>";
            if ($div) $results[]="</div>";
            return implode("",$results);
		}
    }
    function currency_price($price) {
    	$text=array();
        if ( ($this->data->currency_code == "USD") && ($_SESSION['user']->currency_code == "USD") ) $text[]="$";
        $text[]=number_format($price, 2);
        if ( ($_SESSION['user']->currency_code != "USD") || ($this->data->currency_code != "USD") ) $text[]=" " . $this->data->currency_code;
		return implode("",$text);
	}
}
class orderhd_data_class {
	var $id=0;
	var $user_id=0;
	var $punchout_id=0;
	var $order_date=0;
    var $email;
	var $name_first;
	var $name_last;
	var $name;
	var $company_name;
	var $address;
	var $city;
	var $state;
	var $zip;
	var $country_code;
    var $phone;
	var $po;
	var $comment;
    var $type="PO";
	var $status="Pending";
    var $status_comment;
	var $status_user_id=0;
    var $status_date=0;
    var $carrier_name;
    var $carrier_account;
    var $currency_code="USD";
    var $cc;
    var $billing_address;
    var $billing_city;
    var $billing_state;
    var $billing_zip;
    var $billing_country_code;
}
class orderhd_constant_class {
    var $type=array('PO');
    var $status=array('Pending','Accept','Changes','Reject');
}
/*
ALTER TABLE `orderhd`
ADD COLUMN `cc` VARCHAR(4) NULL DEFAULT NULL AFTER `currency_code`,
COMMENT = 'Order header (06/07/2023)' ;

CREATE TABLE `orderhd` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `punchout_id` bigint(10) DEFAULT '0',
  `order_date` int(14) DEFAULT '0',
  `email` varchar(60) DEFAULT '',
  `name_first` varchar(45) DEFAULT NULL,
  `name_last` varchar(45) DEFAULT NULL,
  `name` varchar(100) DEFAULT '',
  `company_name` varchar(45) DEFAULT '',
  `address` mediumtext,
  `city` varchar(40) DEFAULT '',
  `state` char(40) DEFAULT '',
  `zip` varchar(20) DEFAULT '',
  `country_code` varchar(2) DEFAULT '',
  `phone` varchar(20) DEFAULT NULL,
  `po` varchar(45) DEFAULT NULL,
  `comment` mediumtext,
  `type` varchar(20) DEFAULT '',
  `status` varchar(20) NOT NULL DEFAULT 'Pending',
  `status_comment` mediumtext,
  `status_user_id` int(11) DEFAULT '0',
  `status_date` int(14) DEFAULT '0',
  `carrier_name` varchar(60) DEFAULT NULL,
  `carrier_account` varchar(60) DEFAULT NULL,
  `currency_code` char(3) DEFAULT 'USD',
  `cc` varchar(4) DEFAULT NULL,
  `billing_address` mediumtext,
  `billing_city` varchar(40) DEFAULT '',
  `billing_state` char(40) DEFAULT '',
  `billing_zip` varchar(20) DEFAULT '',
  `billing_country_code` varchar(2) DEFAULT '',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=1000 DEFAULT CHARSET=latin1 COMMENT='Order header (08/25/2026)'
*/
?>
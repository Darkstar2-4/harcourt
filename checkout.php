<?php
require_once "scs_header.php";
require_once "classes/scsmail_rev1.php";

if ( (!$_SESSION['user']->ecommerce) || (!sizeof($menu->cart)) )  fn_url_redirect($menu->page->home);
$obj=new checkout_class();
class checkout_class {
	var $action;
    var $data;
    var $ecommerce;
    var $html;
    var $buttons=array();
    function __construct() {
    global $database, $forms, $menu;
		$this->ecommerce=new ecommerce_class($_COOKIE['harcourt_ecommerce']);
		$this->action=$_POST['action'];
		$forms->title("Shopping Cart Checkout");
	    if (isset($_SESSION['checkout'])) {
	        $this->data=json_decode($_SESSION['checkout']);
	      } else {
	        $this->data=new orderhd_data_class();
	        $this->data->user_id=$_SESSION['user']->id;
	        $this->data->name_first=$this->ecommerce->name_first;
	        $this->data->name_last=$this->ecommerce->name_last;
	        $this->data->name=$this->ecommerce->name;
	        $this->data->email=$this->ecommerce->email;
	        $this->data->phone=$this->ecommerce->phone;
	        $this->data->company_name=$this->ecommerce->company_name;
	        $this->data->name=$this->ecommerce->name;
	        $this->data->address=$this->ecommerce->address;
	        $this->data->city=$this->ecommerce->city;
	        $this->data->state=$this->ecommerce->state;
	        $this->data->zip=$this->ecommerce->zip;
	        $this->data->country_code=$this->ecommerce->country_code;
	        $this->data->carrier_name=$this->ecommerce->carrier_name;
	        $this->data->carrier_account=$this->ecommerce->carrier_account;
	        $this->data->currency_code=$_SESSION['user']->currency_code;
		}
        switch ($this->action) {
		  case "complete":
        	$address=new address_class("orderhd",$this->data);
            $address->verify();
            $this->data->carrier_name=$_POST['carrier_name'];
            if (!$this->data->carrier_name) $forms->error('carrier_name');
            $this->data->carrier_account=fn_text($_POST['carrier_account']);
	        $this->data->po=fn_text($_POST['po'],TRUE);
	        $this->data->comment=fn_text($_POST['comment']);
	        $this->data->cc=fn_text($_POST['cc']);
            switch (TRUE) {
              case (!strlen($this->data->cc)):
              	break;
              case (!preg_match("/^\d{4}$/",$this->data->cc)):
            	$forms->error("cc","Valid entries are 4 digits or blank");
                break;
            }
            if (isset($_POST['billing_same_as_shipping'])) {
	        	$this->data->billing_address=$this->data->address;
	        	$this->data->billing_city=$this->data->city;
	        	$this->data->billing_state=$this->data->state;
	        	$this->data->billing_zip=$this->data->zip;
	        	$this->data->billing_country_code=$this->data->country_code;
	          } else {
	        	$billing_data=new stdClass();
	        	$billing_data->address=$this->data->billing_address;
	        	$billing_data->city=$this->data->billing_city;
	        	$billing_data->state=$this->data->billing_state;
	        	$billing_data->zip=$this->data->billing_zip;
	        	$billing_data->country_code=$this->data->billing_country_code;
	        	$billing_address=new address_class("orderhd_billing",$billing_data);
	            $billing_address->verify();
	        	$this->data->billing_address=$billing_data->address;
	        	$this->data->billing_city=$billing_data->city;
	        	$this->data->billing_state=$billing_data->state;
	        	$this->data->billing_zip=$billing_data->zip;
	        	$this->data->billing_country_code=$billing_data->country_code;
	        }
	        $_SESSION['checkout']=json_encode($this->data);
            if (sizeof($forms->error)) break;
			$this->ecommerce->cookie($this->data);
			$database->orderhd->data=$this->data;
			$database->orderhd->data->order_date=strtotime("now");
			$database->orderhd->update(FALSE);
            $line_no=0;
            foreach ($_SESSION['cart'] as $database->orderln->data) {
				$line_no++;
				$database->orderln->data->order_id=$database->orderhd->data->id;
				$database->orderln->data->line_no=$line_no;
				$database->orderln->update(FALSE);
            }
	        $forms->constant->html=$database->orderhd->output_header();
	        $forms->constant->html .= $database->orderln->cart();
			if (strlen($database->registry->local->cc_email)) $forms->constant->html .= "<p>" . $database->registry->local->cc_email . "</p>";
            $database->log->update("user:po",array("comment"=>"Order #" . $database->orderhd->data->id));
            $menu->cart=array();
            $forms->message[0]="Order Complete!";
            $mail=new scsmail_class("checkout",$this->data);
            $mail->html($forms->constant->html);
	        $forms->message[]=$mail->send();
            $mail_customer=new scsmail_class("checkout",$this->data,array("subject"=>"Order Confirmation"));
            $customer_html="<p>This confirms that your web order has been successfully sent to our team. A formal order acknowledgement will follow from sales@harcourt.co as soon as your order has been processed in our system.</p>" . $forms->constant->html;
            if (strlen($database->registry->local->cc_email)) $customer_html .= "<p>No orders will ship without a credit card on file.</p>";
            $mail_customer->html($customer_html);
            $mail_customer->recipient($this->data->email,$this->data->name);
	        $forms->message[]=$mail_customer->send();
			unset($_SESSION['checkout']);
			unset($_SESSION['cart']);
            $menu->cart=array();
        }
	    $menu->head();
	    print $forms->message();
	    ?>
	    <script>
	    function fn_action(action) {
	        if ( (action=='complete') && (!confirm('Place Order?')) ) return;
	        document.scs_form.action.value=action;
	        document.scs_form.submit();
	    }
	    function fn_billing_same_as_shipping(checked) {
	        jQuery('#orderhd_billing_address').val(checked ? jQuery('#orderhd_address').val() : '');
	        jQuery('#orderhd_billing_city').val(checked ? jQuery('#orderhd_city').val() : '');
	        jQuery('#orderhd_billing_state_us').val(checked ? jQuery('#orderhd_state_us').val() : '');
	        jQuery('#orderhd_billing_state_ca').val(checked ? jQuery('#orderhd_state_ca').val() : '');
	        jQuery('#orderhd_billing_state_other').val(checked ? jQuery('#orderhd_state_other').val() : '');
	        jQuery('#orderhd_billing_zip').val(checked ? jQuery('#orderhd_zip').val() : '');
	        jQuery('#orderhd_billing_country_code').val(checked ? jQuery('#orderhd_country_code').val() : '');
	        fn_country_address('orderhd_billing');
	    }
	    </script>
	    <?php
	    print $forms->open();
	    print $forms->hidden("action");
        $buttons=array();
	    switch (TRUE) {
	      case (( $this->action == "complete") && (!sizeof($forms->error)) ):
	        print "<p>Thank you " . $this->data->name . " for your order! You will receive an emailed order confirmation shortly with firm shipping dates. You will be notified further once the product ships.</p>\n";
	        print $forms->constant->html;
	        break;
	      default:
	        print $database->orderhd->output_header($options=array("data"=>$this->data,"entry"=>TRUE));
            print $database->orderln->cart($_SESSION['cart']);
	        $buttons[]=$forms->button("<< Edit Order",array("onclick"=>"window.location.href='/cart.php';","class"=>"cart_button"));
	        $buttons[]=$forms->button("Place Order >>",array("onclick"=>"fn_action('complete');","class"=>"cart_button"));
		}
        if (sizeof($buttons)) print "<p class=center>" . implode(" ",$buttons) . "</p>";
	    print $forms->close();
	    $menu->copyright();
    }
}
// Required for address verify
class ecommerce_class {
	var $name_first;
	var $name_last;
	var $name;
    var $email;
    var $phone;
	var $company_name;
	var $address;
	var $city;
	var $state;
	var $zip;
	var $country_code;
    var $carrier_name;
    var $carrier_account;
    function __construct($text) {
		$data=json_decode($text);
        switch (TRUE) {
          case (is_object($data)):
        	$this->load($data);
			break;
          default:
	        $this->email=$_COOKIE['harcourt'];
	        $this->name_first=$_SESSION['user']->first_name;
	        $this->name_last=$_SESSION['user']->last_name;
	        $this->company_name=$_SESSION['user']->company_name;
		}
    }
    function load($data) {
		$this->name_first=$data->name_first;
		$this->name_last=$data->name_last;
		$this->name=$data->name;
		$this->email=$data->email;
		$this->phone=$data->phone;
		$this->company_name=$data->company_name;
		$this->address=$data->address;
		$this->city=$data->city;
		$this->state=$data->state;
		$this->zip=$data->zip;
        $this->country_code=$data->country_code;
		$this->carrier_name=$data->carrier_name;
		$this->carrier_account=$data->carrier_account;
	}
    function cookie($data) {
		setcookie("harcourt_ecommerce",json_encode($data),strtotime("+ 1 year"));
    }
}
?>
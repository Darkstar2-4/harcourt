<?php
require_once $_SERVER['DOCUMENT_ROOT'] . (version_compare(PHP_VERSION, '8', '<') ? '/composer' : '/composer8') . '/vendor/autoload.php';
use net\authorize\api\contract\v1 as AnetAPI;
use net\authorize\api\controller as AnetController;
class authorizenet_class {
	var $meta;
    var $results;
	function scs_table_version() {
		$results=array();
        $results['03/01/2021']="Initial release";
		$results['file']= __FILE__;
        return $results;
    }
    function __construct($options=array()) {
    global $database;
    	$this->meta=new stdClass();
        $this->results=new stdClass();
        $this->constant=new authorizenet_constant_class();
		$this->meta->environment=( ($options['environment']) ? $options['environment'] : $database->registry->authorizenet->environment);
        if (!in_array($this->meta->environment,array("sandbox","production"))) {
        	$this->fatal(__FUNCTION__,$this->meta->environment . " Environment incorrect");
            return;
		}
        $this->meta->login_id=( ($options['login_id']) ? $options['login_id'] : $database->registry->authorizenet->{$this->meta->environment}['login_id']);
        $this->meta->transaction_key=( ($options['transaction_key']) ? $options['transaction_key'] : $database->registry->authorizenet->{$this->meta->environment}['transaction_key']);
        $this->meta->transaction_id="ref" . time();
        $this->meta->debug=intval($database->registry->authorizenet->debug);
        switch (TRUE) {
          case (!strlen($this->meta->login_id)):
        	$this->fatal(__FUNCTION__,$this->meta->environment . " Login ID missing");
            return;
		  case (!strlen($this->meta->transaction_key)):
        	$this->fatal(__FUNCTION__,$this->meta->environment . " Transaction Key missing");
            return;
		}
    }
    private function fatal($source, $error) {
    	if (!$this->results->fatal_error) {
	        $this->results->fatal_source=$source;
	        $this->results->fatal_error=$error;
		}
    }
    function authentication() {
	    $obj=new AnetAPI\MerchantAuthenticationType();
	    $obj->setName($this->meta->login_id);
	    $obj->setTransactionKey($this->meta->transaction_key);
        return $obj;
	}
    function settings($items) { // Needed?
// https://developer.authorize.net/api/reference/features/payment_transactions.html#Transaction_Settings
		if ($this->results->fatal_error) return;
    	$this->settings=array();
        foreach ($items as $key => $value) {
        	if ( (!strlen($key)) || (!strlen($value)) ) continue;
            $obj=new AnetAPI\SettingType();
            $obj->setSettingName($key);
            $obj->setSettingValue($value);
            $this->settings[]=$obj;
		}
    }
    function customer($id, $email, $business=TRUE) {
		if ($this->results->fatal_error) return;
    	$obj=new AnetAPI\CustomerDataType();
    	$obj->setType( ($business) ? "business" : "individual");  //Either individual or business.
    	$obj->setId($id);
    	$obj->setEmail($email);
        $this->customer=$obj;
    }
	function billto($company_name, $name_first, $name_last, $address, $city, $state, $zip, $country, $phone, $fax) {
    global $database;
		if ($this->results->fatal_error) return;
	    $obj=new AnetAPI\CustomerAddressType();
	    $obj->setFirstName($name_first);
	    $obj->setLastName($name_last);
	    $obj->setCompany($company_name);
	    $obj->setAddress($address);
	    $obj->setCity($city);
	    $obj->setState($state);  // us: iso-2 otherwise name
	    $obj->setZip($zip);
	    $obj->setCountry($country);  // name
	    $obj->setPhoneNumber($phone);
	    $obj->setFaxNumber($fax);
        if ( (!strlen($name_first)) && (!strlen($name_last)) && (!strlen($company_name)) ) {
        	$this->fatal(__FUNCTION__,"First/Last/Company name missing");
            return;
        }
        $this->billto=$obj;
    }
    function order($invoice, $order, $description) {
		if ($this->results->fatal_error) return;
		$obj=new AnetAPI\OrderType();
        $obj->setInvoiceNumber($invoice);
        $obj->setDescription($description);
        if ($order) $obj->setSupplierOrderReference($order);
        $this->order=$obj;
    }
    function card($account,$mm,$yyyy,$cvv) {
		if ($this->results->fatal_error) return;
        $obj=new AnetAPI\CreditCardType();
	    $obj->setCardNumber($account);  // 13-16 digits only
		$obj->setExpirationDate(date("Y-m",strtotime("{$mm}/1/{$yyyy}")));
	    if (strlen($cvv)) $obj->setCardCode($cvv);  // 3-4 digits only
        switch (TRUE) {
          case (!is_numeric($account)):
          	$this->fatal(__FUNCTION__,"Account contains non-numeric characters");
            return;
          case ( (strlen($account) < 13) || (strlen($account) > 16) ):
          	$this->fatal(__FUNCTION__,"Account must be 13-16 digits");
            return;
          case ( ($mm < 1) || ($mm > 12) ):
          	$this->fatal(__FUNCTION__,"Expiry month must be 01-12");
            return;
          case ($obj->getExpirationDate() < date("Y-m")):
			$this->fatal(__FUNCTION__,"Card expired");
            return;
          case ( (!$database->registry->authorizenet->cvv_mandatory) && (!strlen($cvv)) ):
          	break;
          case (!is_numeric($cvv)):
          	$this->fatal(__FUNCTION__,"CVV contains non-numeric characters");
            return;
          case ( (strlen($cvv) < 3) || (strlen($cvv) > 4) ):
          	$this->fatal(__FUNCTION__,"CVV must be 3-4 digits");
            return;
        }
	    $this->payment=new AnetAPI\PaymentType();
	    $this->payment->setCreditCard($obj);
    }
    function free_field($items) { // not required
		if ($this->results->fatal_error) return;
    	$this->free_field=array();
        foreach ($items as $key => $value) {
        	if ( (!strlen($key)) || (!strlen($value)) ) continue;
            $i=sizeof($this->free_field);
            $this->free_field[$i]=new AnetAPI\UserFieldType();
            $this->free_field[$i]->setName($key);
            $this->free_field[$i]->setValue($value);
		}
    }
    function charge($amount, $po) {
		if ($this->results->fatal_error) return;
    	switch (TRUE) {
          case (!isset($this->order)):
          	$this->fatal(__FUNCTION__,"Order not set");
            return;
          case (!isset($this->billto)):
          	$this->fatal(__FUNCTION__,"Billto not set");
            return;
          case (!isset($this->customer)):
          	$this->fatal(__FUNCTION__,"Customer not set");
            return;
          case (!isset($this->payment)):
          	$this->fatal(__FUNCTION__,"Payment not set");
            return;
          case (floatval($amount) <= 0):
          	$this->fatal(__FUNCTION__,"Amount not > $0.00");
            return;
        }
    	$this->transaction=new AnetAPI\TransactionRequestType();
    	$this->transaction->setTransactionType("authCaptureTransaction");
    	$this->transaction->setAmount($amount);
        if (strlen($po)) $this->transaction->setPoNumber($po);
    	$this->transaction->setOrder($this->order);
	    $this->transaction->setBillTo($this->billto);
	    $this->transaction->setCustomer($this->customer);
		$this->transaction->setPayment($this->payment);
        if (isset($this->settings)) {
	        foreach ($this->settings as $obj) {
	            $this->transaction->addToTransactionSettings($obj);
	        }
		}
        if (isset($this->free_field)) {
	        foreach ($this->free_field as $obj) {
	            $this->transaction->addToUserFields($obj);
	        }
		}
	    $request=new AnetAPI\CreateTransactionRequest();
		$request->setMerchantAuthentication($this->authentication());
		$request->setRefId($ths->meta->transaction_id);
		$request->setTransactionRequest($this->transaction);
		$controller=new AnetController\CreateTransactionController($request);
	    $this->response($controller);
		if ($this->results->fatal_error) return;
        $transaction=$this->response->getTransactionResponse();
        $error=$transaction->getErrors();
        $message=$transaction->getMessages();
        if (is_object($transaction)) {
            $this->results->transaction_id=$transaction->getTransId();
            $this->results->avs_result=$this->constant->getAVSResponse($transaction->getAvsResultCode());
            $this->results->cvv_result=$this->constant->getCardCodeResponse($transaction->getCvvResultCode());
        }
/*
fn_pre($transaction);
fn_pre($mesasge);
fn_pre($error);
fn_pre($this->results);
*/
/*
    [avsResultCode:net\authorize\api\contract\v1\TransactionResponseType:private] => N
    [cvvResultCode:net\authorize\api\contract\v1\TransactionResponseType:private] => M
*/
        if (sizeof($error)) {
          	$this->results->message_code=$error[0]->getErrorCode();
          	$this->results->message_text=$error[0]->getErrorText();
		  } else {
            $this->results->auth_code=$transaction->getAuthCode();
            $this->results->message_code=$message[0]->getCode();
            $this->results->message_text=$message[0]->getDescription();
		}
	}
    function unsettled($page=0) {
		if ($this->results->fatal_error) return;
		$request=new AnetAPI\GetUnsettledTransactionListRequest();
	    $request->setMerchantAuthentication($this->authentication());
		$controller=new AnetController\GetUnsettledTransactionListController($request);
	    $this->response($controller);
    }
	function settled($from_date, $thru_date) {
		if ($this->results->fatal_error) return;
	    $request=new AnetAPI\GetSettledBatchListRequest();
	    $request->setMerchantAuthentication($this->authentication());
	    $request->setIncludeStatistics(true);
	    $request->setFirstSettlementDate($this->date($from_date,0));
	    $request->setLastSettlementDate($this->date($thru_date,1));
	    $controller=new AnetController\GetSettledBatchListController($request);
	    $this->response($controller);
    }
    function batch_list($batch_id) {
		if ($this->results->fatal_error) return;
	    $request=new AnetAPI\GetTransactionListRequest();
	    $request->setBatchId($batch_id);
	    $request->setMerchantAuthentication($this->authentication());
    	$controller=new AnetController\GetTransactionListController($request);
	    $this->response($controller);
    }
    function transaction_detail($transaction_id) {
		if ($this->results->fatal_error) return;
	    $request=new AnetAPI\GetTransactionDetailsRequest();
	    $request->setMerchantAuthentication($this->authentication());
	    $request->setTransId($transaction_id);
	    $controller=new AnetController\GetTransactionDetailsController($request);
	    $this->response($controller);
    }
    function merchant() {
		if ($this->results->fatal_error) return;
    	$request=new AnetAPI\GetMerchantDetailsRequest();
	    $request->setMerchantAuthentication($this->authentication());
    	$controller=new AnetController\GetMerchantDetailsController($request);
	    $this->response($controller);
    }
    private function response($controller) {
        if ($this->meta->environment == "production") {
        	$this->response=$controller->executeWithApiResponse( \net\authorize\api\constants\ANetEnvironment::PRODUCTION);
		  } else {
        	$this->response=$controller->executeWithApiResponse( \net\authorize\api\constants\ANetEnvironment::SANDBOX);
		}
        switch (TRUE) {
          case (is_null($this->response)):
          	$this->fatal(__FUNCTION_,"No response");
            return;
        }
        $message=$this->response->getMessages();
        if (is_null($message)) $this->fatal(__FUNCTION__,"No response");
	    $this->results->api_result_code=$message->getResultCode();
	    $this->results->api_message_code=$message->getMessage()[0]->getCode();
	    $this->results->api_message_error=$message->getMessage()[0]->getText();
        if ($this->results->api_result_code != "Ok") $this->fatal(__FUNCTION__,$this->results->api_message_error);
    }
    private function date($text, $timestamp) {
		$items=explode("/",$text);
    	$results=new DateTime();
        $results->setDate($items[0],$items[1],$items[2]);
        switch (TRUE) {
          case ($timestamp == 0):
        	$results->setTime(0,0,0);
            break;
          case ($timestamp == 1):
        	$results->setTime(23,59,59);
			break;
		}
    	return $results;
    }
}
class authorizenet_constant_class {
	var $transaction=array();
	var $orderex=array();
    var $cardmaskedtype=array();
    var $customerdata=array();
    var $customeraddress=array();
	var $statistics=array();

    var $avs_response=array();
    var $cvv_response=array();
    var $sandbox_account=array();
    var $sandbox_solution=array();
    var $sandbox_zip=array();
    var $sandbox_cvv=array();
    function __construct() {
	    $this->transaction['Transaction ID']='getTransId';
	    $this->transaction['Submit Time']='getSubmitTimeLocal';
	    $this->transaction['Type']='getTransactionType';
	    $this->transaction['Status']='getTransactionStatus';
	    $this->transaction['Response Code']='getResponseCode';
	    $this->transaction['Response Reason Code']='getResponseReasonCode';
	    $this->transaction['Subscription']='getSubscription';
	    $this->transaction['Response Reason Description']='getResponseReasonDescription';
	    $this->transaction['Auth Code']='getAuthCode';
	    $this->transaction['AVS Response']='getAVSResponse';
	    $this->transaction['Card Code Response']='getCardCodeResponse';
	    $this->transaction['CAVV Response']='getCAVVResponse';
	    $this->transaction['FDS Filter Action']='getFDSFilterAction';
	    $this->transaction['FDS Filters']='getFDSFilters';
	    $this->transaction['Batch']='getBatch';
	    $this->transaction['Order']='getOrder';
	    $this->transaction['Requested Amount']='getRequestedAmount';
	    $this->transaction['Authorized Amount']='getAuthAmount';
	    $this->transaction['Settled Amount']='getSettleAmount';
	    $this->transaction['Tax']='getTax';
	    $this->transaction['Shipping']='getShipping';
	    $this->transaction['Duty']='getDuty';
	    $this->transaction['Line items']='getLineItems';
	    $this->transaction['Prepaid Balance Remaining']='getPrepaidBalanceRemaining';
	    $this->transaction['Tax Exempt']='getTaxExempt';
	    $this->transaction['Payment']='getPayment';
	    $this->transaction['Customer']='getCustomer';
	    $this->transaction['Bill To']='getBillTo';
	    $this->transaction['Ship To']='getShipTo';
	    $this->transaction['Recurring Billing']='getRecurringBilling';
	    $this->transaction['Customer IP']='getCustomerIP';
	    $this->transaction['Product']='getProduct';
	    $this->transaction['Entry Mode']='getEntryMode';
	    $this->transaction['Market Type']='getMarketType';
	    $this->transaction['Mobile Device ID']='getMobileDeviceId';
	    $this->transaction['Customer Signature']='getCustomerSignature';
	    $this->transaction['Returned Items']='getReturnedItems';
	    $this->transaction['Solution']='getSolution';
	    $this->transaction['Environment Details']='getEmvDetails';
	    $this->transaction['Profile']='getProfile';
	    $this->transaction['Surcharge']='getSurcharge';
	    $this->transaction['Employee ID']='getEmployeeId';
	    $this->transaction['Tip']='getTip';
	    $this->transaction['Other Tax']='getOtherTax';
	    $this->transaction['Ship From']='getShipFrom';

	    $this->orderex['Invoice Number']='getInvoiceNumber';
	    $this->orderex['Order Number']='getSupplierOrderReference';
	    $this->orderex['Purchase Order']='getPurchaseOrderNumber';
	    $this->orderex['Description']='getDescription';
	    $this->orderex['Discount Amount']='getDiscountAmount';
	    $this->orderex['Purchaser Code']='getPurchaserCode';
	    $this->orderex['Contact Name']='getAuthorizedContactName';

	    $this->cardmaskedtype['Card Type']='getCardType';
	    $this->cardmaskedtype['Card Number']='getCardNumber';
	    $this->cardmaskedtype['Expiration Date']='getExpirationDate';

	    $this->customerdata['ID']='getId';
	    $this->customerdata['Type']='getType';
	    $this->customerdata['Email']='getEmail';
	    $this->customerdata['Drivers License']='getDriversLicense';
	    $this->customerdata['Tax ID']='getTaxId';

	    $this->customeraddress['Company Name']='getCompany';
	    $this->customeraddress['Fist Name']='getFirstName';
	    $this->customeraddress['Last Name']='getLastName';
	    $this->customeraddress['Address']='getAddress';
	    $this->customeraddress['City']='getCity';
	    $this->customeraddress['State']='getState';
	    $this->customeraddress['Zip']='getZip';
	    $this->customeraddress['Country']='getCountry';
	    $this->customeraddress['Email']='getEmail';
	    $this->customeraddress['Phone']='getPhoneNumber';
	    $this->customeraddress['Fax']='getFaxNumber';

    	$this->statistics['Account Type']="getAccountType";
    	$this->statistics['Charge Amount']="getChargeAmount";
    	$this->statistics['Charge Count']="getChargeCount";
    	$this->statistics['Refund Amount']="getRefundAmount";
    	$this->statistics['Refund Count']="getRefundCount";
    	$this->statistics['Void Count']="getVoidCount";
    	$this->statistics['Decline Count']="getDeclineCount";
    	$this->statistics['Error Count']="getErrorCount";
    	$this->statistics['Returned Item Amount']="getReturnedItemAmount";
    	$this->statistics['Returned Item Count']="getReturnedItemCount";
    	$this->statistics['Chargeback Amount']="getChargebackAmount";
    	$this->statistics['Chargeback Count']="getChargebackCount";
    	$this->statistics['Correction Notice Count']="getCorrectionNoticeCount";
    	$this->statistics['Charge ChargeBack Amount']="getChargeChargeBackAmount";
    	$this->statistics['Charge ChargeBack Count']="getChargeChargeBackCount";
    	$this->statistics['Refund ChargeBack Amount']="getRefundChargeBackAmount";
    	$this->statistics['Refund ChargeBack Count']="getRefundChargeBackCount";
    	$this->statistics['Charge Returned Items Amount']="getChargeReturnedItemsAmount";
    	$this->statistics['Charge Returned Items Count']="getChargeReturnedItemsCount";
    	$this->statistics['Refund Returned Items Amount']="getRefundReturnedItemsAmount";
    	$this->statistics['Refund Returned Items Count']="getRefundReturnedItemsCount";

		$this->avs_response['A']="Street Address: Match -- First 5 Digits of ZIP: No Match";
		$this->avs_response['B']="Address not provided for AVS check or street address match, postal code could not be verified";
		$this->avs_response['E']="AVS Error";
		$this->avs_response['G']="Non U.S. Card Issuing Bank";
		$this->avs_response['N']="Street Address: No Match -- First 5 Digits of ZIP: No Match";
		$this->avs_response['P']="AVS not applicable for this transaction";
		$this->avs_response['R']="Retry, System Is Unavailable";
		$this->avs_response['S']="AVS Not Supported by Card Issuing Bank";
		$this->avs_response['U']="Address Information For This Cardholder Is Unavailable";
		$this->avs_response['W']="Street Address: No Match -- All 9 Digits of ZIP: Match";
		$this->avs_response['X']="Street Address: Match -- All 9 Digits of ZIP: Match";
		$this->avs_response['Y']="Street Address: Match - First 5 Digits of ZIP: Match";
		$this->avs_response['Z']="Street Address: No Match - First 5 Digits of ZIP: Match";

        $this->cvv_response['M']="Matched";
        $this->cvv_response['N']="Does not match";
        $this->cvv_response['S']="Should be on the card, but is not indicated";
        $this->cvv_response['U']="The issuer is not certified for CVV processing or has not provided an encryption key.";
        $this->cvv_response['P']="Is not processed.";

        $this->sandbox_account['370000000000002']="American Express";
        $this->sandbox_account['6011000000000012']="Discover";
        $this->sandbox_account['3088000000000017']="JCB";
        $this->sandbox_account['38000000000006']="Diners Club/ Carte Blanche";
        $this->sandbox_account['4007000000027']="Visa";
        $this->sandbox_account['4012888818888']="Visa";
        $this->sandbox_account['4111111111111111']="Visa";
        $this->sandbox_account['5424000000000015']="Mastercard";
        $this->sandbox_account['2223000010309703']="Mastercard";
        $this->sandbox_account['2223000010309711']="Mastercard";

        $this->sandbox_solution[]="AAA100302";
        $this->sandbox_solution[]="AAA100303";
        $this->sandbox_solution[]="AAA100304";

        $this->sandbox_zip['46282']="Declined";
        $this->sandbox_zip['46201']="A";
        $this->sandbox_zip['46203']="E";
        $this->sandbox_zip['46204']="G";
        $this->sandbox_zip['46205']="N";
        $this->sandbox_zip['46207']="R";
        $this->sandbox_zip['46208']="S";
        $this->sandbox_zip['46209']="U";
        $this->sandbox_zip['46211']="W";
        $this->sandbox_zip['46214']="X";
        $this->sandbox_zip['46217']="Z";

        $this->sandbox_cvv['900']="M";
        $this->sandbox_cvv['901']="N";
        $this->sandbox_cvv['902']="S";
        $this->sandbox_cvv['903']="U";
        $this->sandbox_cvv['904']="P";
    }
    function orderex($obj) {
    	$results=array();
        foreach ($this->orderex as $name => $field) {
			$text=$obj->$field();
            if (strlen($text)) $results[$name]=$text;
        }
		return $results;
    }
    function creditcardmasked($obj) {
    	$results=array();
        foreach ($this->cardmaskedtype as $name => $field) {
			$text=$obj->$field();
            switch (TRUE) {
              case (is_object($text)):
              	$results[$name]=$text->format("m/d/Y H:i");
                break;
              case (strlen($text)):
              	$results[$name]=$text;
                break;
			}
        }
		return $results;
    }
    function customerdata($obj) {
    	$results=array();
        foreach ($this->customerdata as $name => $field) {
			$text=$obj->$field();
            if (strlen($text)) $results[$name]=$text;
        }
		return $results;
	}
    function customeraddress($obj) {
    	$results=array();
        foreach ($this->customeraddress as $name => $field) {
			$text=$obj->$field();
            if (strlen($text)) $results[$name]=$text;
        }
		return $results;
	}
    function getAVSResponse($code) {
        if (isset($this->avs_response[$code])) {
        	return $this->avs_response[$code] . " ({$code})";
		  } else {
          	return $code;
        }
    }
    function getCardCodeResponse($code) {
        if (isset($this->cvv_response[$code])) {
        	return $this->cvv_response[$code] . " ({$code})";
		  } else {
          	return $code;
        }
    }
}
?>
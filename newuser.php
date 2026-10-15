<?php
/*
Initial action::
<blank>		None
remote		Remote access allowed
register    New user
cookie		Cookie not found

Ajax action:
register	Initial login (new remote is matching domman, otherwise new user
remote      Enter remote token
token       Verify remote token
*/
require_once "scs_header.php";
require_once "classes/scsmail_rev1.php";
require_once "recaptcha.php";
$obj=new remote_access_class();
class remote_access_class {
	var $action;
    var $access;
	var $results=array();
    var $error=array();
    var $meta;
    var $trace=array();
    function scs_table_version() {
    	$results=array();
        $results['10/15/2026']="Email register recipient when a new user is auto-approved by domain";
        $results['06/18/2026']="Add Google OAuth2 registration";
        $results['06/12/2026']="Microsoft OAuth: populate domain/company/address from profile on registration";
        $results['06/11/2026']="Microsoft OAuth: check remote table by email first";
        $results['06/10/2026']="Add Microsoft OAuth2 registration";
        $results['10/01/2025']="Add harcourt_remote_addr()";
        $results['05/06/2025']="Update harcourt_profile";
        $results['08/23/2024']="Live site invoked with HTTP_X_REQUESTED_WITH";
        $results['08/16/2024']="Add full address";
        $results['08/31/2023']="Ajax email lookup";
        $results['04/05/2023']="Extend expiry";
        $results['10/26/2020']="Initial release";
        return $results;
    }
    function __construct() {
        global $database, $forms, $menu;
        $this->trace[]=__FUNCTION__;
	    $this->access=$database->remote->access($_COOKIE['harcourt'],$_COOKIE['remote']);
	    $this->action=$database->remote->data->access;
        switch (TRUE) {
            case ( (isset($_SERVER['HTTP_X_REQUESTED_WITH'])) && ($_SERVER['REQUEST_METHOD'] == "POST") ):
               $this->json();
               break;
             default:
               $this->html();
           }
    }
    function json() {
        global $database, $forms, $menu;
        $this->trace[]=__FUNCTION__;
        $this->response=new stdClass();
        $this->response->error=new stdClass();
        $this->response->menu=new stdClass();
        $this->response->html=new stdClass();
    	$this->response->html->scscpq_input_status="";
		switch ($_POST['action']) {
          case "microsoft_oauth_init":
            die(json_encode(['redirect' => '/oauth/init.php']));
            break;
          case "register":
          	$this->json_register();
          	break;
          case "remote":
          	$this->json_verify();
          	break;
		  case "token":
          	$this->json_token();
          	break;
		}
		die(json_encode($this->response));
    }
    function json_register() {
        global $database, $forms, $menu;
        $this->trace[]=__FUNCTION__;
		$this->recaptcha=new recaptcha_class("newuser",1,array("local"=>TRUE));
		$this->recaptcha->score($_POST['g-recaptcha-response'] ?? '');

        $address=new address_class("profile", $database->profile->data, array("order"=>array("email", "name")));
        $address->registry->email="required";
        $address->verify();
        if ($this->recaptcha_spam("registration form", $address->data->email)) {
            $this->response->html->profile_register = "<p style='color:red;'>We were unable to verify your submission. Please try again, or contact us directly at <a href='mailto:sales@harcourt.co'>sales@harcourt.co</a> if the issue continues.</p>";
            return;
        }
        if ($database->blacklist->check($address->data->email)) {
            $options = array();
            $options['email'] = $address->data->email;
            $options['comment'] = "Blacklisted domain denied (registration form)";
            $database->log->update("Blacklist:Denied", $options);
            $this->response->html->profile_register = "<p style='color:red;'>Your domain is not authorized to access this site. Please contact us for more information.</p>";
            return;
        }
        $database->remote->access($address->data->email);
        if (strlen($database->remote->data->access)) {
            if (!$database->remote->meta->rows) $this->auto_approved_email($address->data->email, "Registration form (access token emailed for verification)");
            $menu->cookie($address->data->email);
            $this->json_email($address->data->email);
            $this->response->html->scscpq_content=$this->html_remote(TRUE);
            return;
        }
        foreach ($address->input as $field => $value) {
            $this->response->error->$field=(array_key_exists($field, $forms->error)) ? 1 : 0;
        }
        if (sizeof($forms->error)) {
            $this->response->html->profile_message="Correct " . sizeof($forms->error) . " error(s)";
          } else {
            $this->response->html->profile_register=$this->json_register_update();
        }
    }
    function recaptcha_spam($comment_context, $email="") {
        global $database;
        if ($this->recaptcha->score > $database->registry->recaptcha->score_spam) return FALSE;
        if ($database->captcha_bypass->check($email)) return FALSE;
        $options=array();
        if (strlen($email)) $options['email']=$email;
        $options['comment']="reCAPTCHA score " . $this->recaptcha->score . " at or below spam threshold " . $database->registry->recaptcha->score_spam . " ({$comment_context})";
        $database->log->update("Recaptcha:Blocked", $options);
        return TRUE;
    }
    function json_register_update() {
        global $database, $forms, $menu;
        $this->trace[]=__FUNCTION__;
        $database->user->data=new user_data_class();
        $database->user->data->ip=harcourt_remote_addr();
        $database->user->data->company_name=$database->profile->data->company_name;
        $database->user->data->address=$database->profile->data->address;
        $database->user->data->city=$database->profile->data->city;
        $database->user->data->state=$database->profile->data->state;
        $database->user->data->zip=$database->profile->data->zip;
        $database->user->data->country_code=$database->profile->data->country_code;
        $database->user->data->last_login=strtotime("now");
        $database->user->data->recaptcha_score=$this->recaptcha->score;
        $database->user->update(FALSE);
        if ($database->user->meta->error) return "Internal error detected";
        $database->user->data->ip=harcourt_remote_addr();
        $menu->cookie($database->profile->data->email);
        $_SESSION['user']=$database->user->data;
        $database->profile->cookie();

        $address=new address_class("profile", $database->profile->data, array("order"=>"email"));

        $options=array();
        $options['user_id']=$database->user->data->id;
        $comment=$address->output("html:array");
        $comment[]="reCAPTCHA Score: " . $this->recaptcha->score;
        $options['comment']=implode("<br>", $comment);
	    $database->log->update("user:signup",$options);
	    $mail=new scsmail_class("register",$database->user->data);

        $comment[]="";
        $comment[]="Browser: " . $_SERVER['HTTP_USER_AGENT'];
        $comment[]="IP Address: " . harcourt_remote_addr();
        $comment[]="DNS Name: " . gethostbyaddr(harcourt_remote_addr());
        $comment[]="Date/Time: " . date('m/d/Y h:i A');
        $mail->html("<p class='standard'>" . implode("<br>",$comment) . "</p>");
        $mail->send();

        $results=array();
        $results[]="<h2 class='page-subtitle editable h2-login'>Congratulations, your access request has been submitted!</h2>";
        $results[]="<p>Your request is pending approval. You will be able to enter our catalog as soon as it has been approved.</p>";
        return implode("",$results);
    }
    function json_verify() {
    	global $database, $forms, $menu;
        $this->trace[]=__FUNCTION__;
		$this->recaptcha=new recaptcha_class("newuser",1,array("local"=>TRUE));
		$this->recaptcha->score($_POST['g-recaptcha-response'] ?? '');
		if (!isset($_SESSION['remote'])) $_SESSION['remote']=0;
		$timestamp=hexdec($database->remote->data->unlock_code);
		$this->expiry=date("r",$timestamp);
        $this->unlock_code=trim($_POST['unlock_code']);
        $error=null;
        switch (TRUE) {
          case ($this->recaptcha_spam("access token verify", $database->profile->data->email)):
          	$_SESSION['remote']++;
          	$error="Invalid access token (" . $_SESSION['remote'] . " of 3)";
          	break;
          case (!strlen($this->unlock_code)):
          	$error="Cannot be blank";
          	break;
          case ($timestamp < strtotime("now")):
          	$error="Your access token has expired. A new token must be resent";
          	break;
          case ($_SESSION['remote'] > 1):
          	$error="Excess failures. A new token must be resent";
          	break;
          case ($this->unlock_code != $database->remote->data->unlock_code):
          	$_SESSION['remote']++;
          	$error="Invalid access token (" . $_SESSION['remote'] . " of 3)";
            break;
		}

		$this->count=$_SESSION['remote'];
        if ($error) {
        	$this->response->html->unlock_code_message="";
        	$this->response->html->unlock_code_error=$error;
		  } else {
	        $database->remote->expiry();
	        $database->remote->token();
	        $database->remote->unlock_code();
            $database->remote->data->unlock_code=dechex(strtotime("now"));
    		$database->remote->update(TRUE);
          	$menu->cookie($database->remote->data->token, "remote");
            $menu->login_update(1);
            $this->response->html->scscpq_content=$this->json_remote_message();
            $this->response->menu->scscpq_newuser=0;
            if ($_SESSION['user']->catalog) $this->response->menu->scscpq_catalog=1;
		}
    }
    function json_remote_message() {
    	global $database, $forms, $menu;
        $this->trace[]=__FUNCTION__;
    	$results=array();
	    $results[]="<h2 class='page-subtitle editable h2-login'>Congratulations, you now have access!<h2>";
		switch (TRUE) {
          case (fn_development_server()):
          	$url="/catalog.php";
            break;
	      case ($_SESSION['user']->language_code == "FR"):
          	$url="/catalogue/?lang=fr";
			break;
		  default:
			$url="/catalog";
		}
        if ($_SESSION['user']->catalog) $results[]="<p>" . $forms->button("Click Here To Enter Our Catalog" ,array("class"=>"red-button","onclick"=>"window.location.replace('{$url}');")) . "</p>";
        return implode("",$results);
    }
    function json_token() {
    	global $database;
        $this->trace[]=__FUNCTION__;
		$this->recaptcha=new recaptcha_class("newuser",1,array("local"=>TRUE));
		$this->recaptcha->score($_POST['g-recaptcha-response'] ?? '');
		if (!$this->recaptcha_spam("resend access token", $database->profile->data->email)) $this->json_email($database->profile->data->email);
        $this->response->html->unlock_code_error="";
        $this->response->html->unlock_code_message="Access token has been sent";
    }
    function json_email($email) {
    	global $database, $forms, $menu;
        $this->trace[]=__FUNCTION__;
		$_SESSION['remote']=0;
        $database->remote->read($email);
        $database->remote->data->email=$email;
	    $database->remote->expiry();
	    $database->remote->token();
	    $database->remote->unlock_code();
    	$database->remote->update($database->remote->meta->rows);
	    $mail=new scsmail_class("remote",$database->user->data);
	    $mail->recipient($email, "");
	    $html=array();
        $html[]="Your access code is: <b>" . $database->remote->data->unlock_code . "</b>";
	    $mail->html("<p class='standard'>" . implode("<br>",$html) . "</p>");
        $mail->send();
        return "Your access token has been emailed to: <b>{$email}</b>";
    }
    function auto_approved_email($email, $method) {
    	global $database;
        $this->trace[]=__FUNCTION__;
        // First access for this email via an Active company domain (no manual approval);
        // goes to the same "register" mailform recipient as new access requests
        $comment=array();
        $comment[]="<b>A new user was auto-approved because their email domain matches an active account.</b>";
        $comment[]="";
        $comment[]="Email: " . $email;
        $comment[]="Company: " . $database->user->data->company_name . " (" . $database->user->data->domain . ")";
        $comment[]="Method: " . $method;
        $comment[]="";
        $comment[]="Browser: " . $_SERVER['HTTP_USER_AGENT'];
        $comment[]="IP Address: " . harcourt_remote_addr();
        $comment[]="DNS Name: " . gethostbyaddr(harcourt_remote_addr());
        $comment[]="Date/Time: " . date('m/d/Y h:i A');
	    $mail=new scsmail_class("register",$database->user->data,array("subject"=>"New Account Auto-Approved: {$email}"));
        $mail->html("<p class='standard'>" . implode("<br>",$comment) . "</p>");
        $mail->send();
    }
    function html() {
        global $database, $forms, $menu;
        $this->trace[]=__FUNCTION__;

        if (isset($_SESSION['microsoft_oauth_result']) || isset($_SESSION['microsoft_oauth_error'])) {
            $this->process_oauth('Microsoft');
            return;
        }

        if (isset($_SESSION['google_oauth_result']) || isset($_SESSION['google_oauth_error'])) {
            $this->process_oauth('Google');
            return;
        }

		$this->recaptcha=new recaptcha_class("newuser",1,array("local"=>TRUE));
    	if ($this->access) $menu->login_update(1);
        switch (TRUE) {
          case ($this->action):
		  case (isset($_SESSION['user']->bot_id)):
  		  case ( ($_SESSION['user']->id) && ($_SESSION['user']->status != "Pending") ):
          	break;
  		  case (!$_SESSION['user']->id):
			$this->action="register";
	    	if ($_SESSION['impersonate']) {
	        	$database->user->data=$_SESSION['user'];
	      	  } else {
				$database->user->data=new user_data_class();
			}
            break;
  		  case ($_SESSION['user']->status == "Pending"):
			$this->action="pending";
            break;
		}
        if (isset($_SESSION['oauth_authenticated']) && $_SESSION['user']->id && $_SESSION['user']->status == "Active"
            && ($this->action == "remote" || $this->action == "token")) {
            $this->action = '';
        }
        if (fn_development_server()) $menu->head();
	    unset($forms->message[0]);
        print $this->css();
		print $this->js();
        print $forms->open(array(), array("onsubmit"=>"return false;"));
		print "<div id=scscpq_content>";
		print "<div id=scscpq_input_status></div>";;
        switch ($this->action) {
          case "register":
          	print $this->html_register();
          	break;
  		  case "remote":
  		  case "token":
            if ($this->action == "token") $this->json_email($database->profile->data->email);
          	print $this->html_remote(TRUE);
          	break;
          case "pending":
			print $this->html_pending();
          	break;
		}
	    print $forms->close();
        print "</div>";
        if (fn_development_server()) $menu->copyright();
    }
    function process_oauth($provider) {
        global $database, $forms, $menu;
        $this->trace[]=__FUNCTION__;

        $provider_lower = strtolower($provider);
        $error_key  = $provider_lower . '_oauth_error';
        $result_key = $provider_lower . '_oauth_result';

        $this->recaptcha=new recaptcha_class("newuser",1,array("local"=>TRUE));

        // Phase 1: process result and set cookies/session before any output
        $body = '';

        if (isset($_SESSION[$error_key])) {
            $error = $_SESSION[$error_key];
            unset($_SESSION[$error_key]);
            $body = "<p style='color:red;'>" . htmlspecialchars($error) . "</p>" . $this->html_register();
        } else {
            $oauth = $_SESSION[$result_key];
            unset($_SESSION[$result_key]);

            $email = $oauth['email'];
            if (!strlen($email)) {
                $body = "<p style='color:red;'>" . $provider . " did not provide an email address. Please use the registration form.</p>" . $this->html_register();
            } elseif ($database->blacklist->check($email)) {
                $options = array();
                $options['email'] = $email;
                $options['comment'] = "Blacklisted domain denied (" . $provider . " OAuth)";
                $database->log->update("Blacklist:Denied", $options);
                $body = "<p style='color:red;'>Your domain is not authorized to access this site. Please contact us for more information.</p>";
            } else {
                $profile_obj = new stdClass();
                $profile_obj->email      = $email;
                $profile_obj->name_first = $oauth['name_first'];
                $profile_obj->name_last  = $oauth['name_last'];
                $database->profile->load($profile_obj);

                $database->remote->access($email, '');
                $domain_user_found  = $database->user->meta->rows;
                $domain_user_status = $database->user->data->status;

                if ($domain_user_found && $domain_user_status == "Active") {
                    $is_update = $database->remote->meta->rows ? TRUE : FALSE;
                    if (!$is_update) $this->auto_approved_email($email, $provider . " OAuth");
                    $body = $this->grant_oauth_remote_access($email, $is_update);
                } elseif ($domain_user_found && $domain_user_status == "Pending") {
                    $body = $this->html_pending();
                } else {
                    $body = $this->process_oauth_register($email, $oauth, $provider);
                }
            }
        }

        // Phase 2: render page after cookies have been set
        if (fn_development_server()) $menu->head();
        unset($forms->message[0]);
        print $this->css();
        print $this->js();
        print $forms->open(array(), array("onsubmit"=>"return false;"));
        print "<div id=scscpq_content>";
        print $body;
        print "</div>";
        print $forms->close();
        if (fn_development_server()) $menu->copyright();
    }

    function grant_oauth_remote_access($email, $is_update) {
        global $database, $menu;
        $this->trace[]=__FUNCTION__;
        $_SESSION['oauth_authenticated'] = true;
        $menu->cookie($email);
        $database->remote->data->email = $email;
        $database->remote->expiry();
        $database->remote->token();
        $database->remote->data->unlock_code = dechex(strtotime('+1 year'));
        $database->remote->update($is_update);
        $menu->cookie($database->remote->data->token, 'remote');
        $menu->login_update(1);
        $results = $this->json_remote_message();
        $results .= '<script>'
            . 'if (typeof jQuery === "function" && typeof scscpq_url !== "undefined") {'
            . 'jQuery.ajax({'
            . 'url: scscpq_url,'
            . 'data: {action: "initial", page: "", document: "", token: sessionStorage["scscpq_token"], url: window.location.href},'
            . 'type: "post",'
            . 'dataType: "json",'
            . 'success: function(response) {'
            . 'for (var key in response) {'
            . 'var value = response[key];'
            . 'if (key === "token") { sessionStorage["scscpq_token"] = value; continue; }'
            . 'if (key.substring(0,6) !== "scscpq") continue;'
            . 'if (key === "scscpq_document" || key === "scscpq_page" || key === "scscpq_link" || key === "scscpq_iframe") continue;'
            . 'if (value == 0) { jQuery("." + key).hide(); } else { jQuery("." + key).show(); if (value != 1) jQuery("." + key).html(value); }'
            . '}'
            . '}'
            . '});'
            . '}'
            . '</script>';
        return $results;
    }

    function process_oauth_register($email, $oauth, $provider = 'Microsoft') {
        global $database, $forms, $menu;
        $this->trace[]=__FUNCTION__;
        $_SESSION['oauth_authenticated'] = true;
        $menu->cookie($email);
        list(, $domain) = explode("@", $email, 2);
        $database->user->data = new user_data_class();
        $database->user->data->ip           = harcourt_remote_addr();
        $database->user->data->domain       = $domain;
        $database->user->data->company_name = $oauth['company_name'] ?: $oauth['display_name'];
        $database->user->data->address      = $oauth['address'];
        $database->user->data->city         = $oauth['city'];
        $database->user->data->state        = $oauth['state'];
        $database->user->data->zip          = $oauth['zip'];
        $database->user->data->last_login   = strtotime("now");
        $database->user->data->recaptcha_score = 1.0;
        $database->user->update(FALSE);
        if ($database->user->meta->error) return "<p style='color:red;'>Internal error. Please try again.</p>";

        $menu->cookie($email);
        $_SESSION['user'] = $database->user->data;
        $database->profile->cookie();

        $database->remote->data->email = $email;
        $database->remote->expiry();
        $database->remote->token();
        $database->remote->data->unlock_code = dechex(strtotime('+1 year'));
        $database->remote->update(FALSE);
        $menu->cookie($database->remote->data->token, 'remote');

        $options = array();
        $options['user_id'] = $database->user->data->id;
        $comment = array();
        $comment[] = "Email: " . $email;
        $comment[] = "Name: " . $oauth['display_name'];
        $comment[] = $provider . " OAuth registration";
        $options['comment'] = implode("<br>", $comment);
        $database->log->update("user:signup", $options);

        $mail = new scsmail_class("register", $database->user->data);
        $comment[] = "";
        $comment[] = "Browser: "    . $_SERVER['HTTP_USER_AGENT'];
        $comment[] = "IP Address: " . harcourt_remote_addr();
        $comment[] = "DNS Name: "   . gethostbyaddr(harcourt_remote_addr());
        $comment[] = "Date/Time: "  . date('m/d/Y h:i A');
        $mail->html("<p class='standard'>" . implode("<br>", $comment) . "</p>");
        $mail->send();

        return "<h2 class='page-subtitle editable h2-login'>Congratulations, your access request has been submitted!<h2>";
    }

    function html_microsoft_button() {
        $results = array();
        $results[] = "<a href='/oauth/init.php' style='display:inline-flex;align-items:center;background:#2f2f2f;color:#fff;padding:10px 20px;text-decoration:none;border-radius:4px;font-family:\"Segoe UI\",sans-serif;font-size:15px;'>";
        $results[] = "<svg xmlns='http://www.w3.org/2000/svg' width='21' height='21' viewBox='0 0 21 21' style='margin-right:12px;'><rect x='1' y='1' width='9' height='9' fill='#f25022'/><rect x='11' y='1' width='9' height='9' fill='#7fba00'/><rect x='1' y='11' width='9' height='9' fill='#00a4ef'/><rect x='11' y='11' width='9' height='9' fill='#ffb900'/></svg>";
        $results[] = "Sign in with Microsoft";
        $results[] = "</a>";
        return implode("", $results);
    }

    function html_google_button() {
        $results = array();
        $results[] = "<a href='/oauth/google_init.php' style='display:inline-flex;align-items:center;background:#fff;color:#3c4043;padding:10px 20px;text-decoration:none;border-radius:4px;font-family:\"Roboto\",\"Segoe UI\",sans-serif;font-size:15px;border:1px solid #dadce0;'>";
        $results[] = "<svg xmlns='http://www.w3.org/2000/svg' width='21' height='21' viewBox='0 0 48 48' style='margin-right:12px;'><path fill='#EA4335' d='M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z'/><path fill='#4285F4' d='M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z'/><path fill='#34A853' d='M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z'/><path fill='#FBBC05' d='M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z'/></svg>";
        $results[] = "Sign in with Google";
        $results[] = "</a>";
        return implode("", $results);
    }

    function html_oauth_buttons() {
        $results = array();
        $results[] = "<div style='margin: 20px 0; text-align: left;'>";
        $results[] = "<div style='margin-bottom: 10px; font-weight: bold;'>Sign in with your work account:</div>";
        $results[] = "<div style='display:flex;gap:12px;flex-wrap:wrap;'>";
        $results[] = $this->html_microsoft_button();
        $results[] = $this->html_google_button();
        $results[] = "</div>";
        $results[] = "</div>";
        return implode("", $results);
    }

    function error($field, $text) {
    	$this->results["{$field}"]=$text;
        if (strlen($text)) $this->error[$field]=$text;
    }
    function html_register() {
        global $database, $forms, $menu;
        $this->trace[]=__FUNCTION__;
        $database->profile->load();
        $results=array();
		$results[]=$this->recaptcha->js();
        $text=array();
	    if ($_SESSION['impersonate']) $text[]="User impersonating / no update allowed.";
	    $results[]="<h2 class='page-subtitle editable h2-login'>" . implode(" ",$text) . "</h2>";
        $results[]="<div id=profile_register>";
        $text=array();
        $text[]="If your company email is already registered with Harcourt &reg;, click \"Sign in with Microsoft\" or \"Sign in with Google\" below for instant access.";
        $text[]="Otherwise, please complete the form below and click \"Submit\" to become an approved Harcourt &reg; user. Once you have completed the form, our team will be in touch to confirm your access.";
        $text[]="Only email address is required if your company is already registered and you are working remotely.";
        $text[]="Thank you!";
	    $results[]="<p class='company_registered'>" . implode("<br>", $text) . "</p>";
        $results[]=$this->html_oauth_buttons();
        $results[]="<hr style='margin: 20px 0; border: 0; border-top: 1px solid #ccc;'>";
        $results[]="<p style='font-weight:bold;'>Or complete the form below:</p>";
        $results[]=$database->profile->enter(array("register"=>TRUE));
        $results[]=$this->recaptcha->button("Submit",array("class"=>"red-button"));
        $results[]="<div id=recaptcha_status></div>";
        $results[]="</div>";
        return implode("",$results);
    }

    function html_pending() {
        global $database, $forms, $menu;
        $this->trace[]=__FUNCTION__;
    	$results=array();
        $results[]="<p>You access request is pending approval. Your request will be approved as soon as possible.</p>";
        return implode("",$results);
    }
    function html_remote($verbose=TRUE) {
        global $database, $forms, $menu;
        $this->trace[]=__FUNCTION__;
    	$results=array();
	    $results[]=$this->recaptcha->js();
	    $results[]="<h2 class='page-subtitle editable h2-login'>Welcome " . $database->user->data->company_name . " Employee</h2>";
        $results[]="<p>Access token has been mailed from " . $database->registry->email->webmaster . ". Please check your inbox or spam folder.</p>";
        $text=array();
        switch (TRUE) {
          case (!$verbose):
            break;
           case ($this->remote == "expiry"):
           case ($this->remote == "token"):
	        $text[]="Your remote access token has expired.";
	        $text[]="A new access code must be resent.";
	        break;
		}
        $results[]="<div id=scscpq_remote>" . implode(" ",$text)  . "</div>";
        $text=array("Enter Access Token:");
		$text[]=$forms->text("unlock_code","",10,10,"",array("class"=>"scscpq_input"));
		$text[]=$this->recaptcha->button("Submit",array("class"=>"red-button","id"=>"recaptcha_button_verify","data"=>array("data-callback"=>"recaptcha_script_verify")));
        $results[]="<div style='font-size: 20px;'>" . implode(" ",$text) . "</div>";
        $results[]="<div id=unlock_code_error></div>";
        $results[]="<div id=recaptcha_status></div>";
        $text=array();
        $text[]=$this->recaptcha->button("Resend Access Token",array("class"=>"red-button","id"=>"recaptcha_button_resend","data"=>array("data-callback"=>"recaptcha_script_resend")));
        $text[]="<span id=unlock_code_message></span>";
        $results[]="<div>" . implode(" ",$text) . "</div>";
        $results[]=$this->html_oauth_buttons();
        return implode("",$results);
	}
    function css() {
        $this->trace[]=__FUNCTION__;
        return <<< EOT
<style>
#scscpq_content {
    color: #000;
	background-color: #fff;
	font-family: "Open Sans",sans-serif;
    width: 100%;
    padding: 0px;
    margin: 0px;
}
#scs_form {display: flex;flex-wrap:wrap; background: transparent;}
form h2{flex-basis:100%; margin-top: 0; }

.scscpq_label {font-weight: 700; padding: 10px !important;}
.scscpq_input {padding: 10px;}

table.scscpq_table { width: 100%; border: 0px; }
table.scscpq_table th { padding: 2px; vertical-align: bottom; text-align: center; }
table.scscpq_table td { padding: 2px; vertical-align: top; }

.newuser_ok {border: 1px solid black; }
.newuser_error { border: 2px solid red; }

// .red-button { width: 220px !important; border-radius: 4px; text-align: center; text-transform: uppercase; padding: 20px 5px; display: inline-block; position: relative; z-index: 1; margin-top: 3.125rem; font-family: 'Rajdhani'; font-size: 1.25rem !important; font-weight: 600; border: none; background: linear-gradient(0deg, rgba(115,14,28,1) 0%, rgba(229,28,56,1) 100%);color:#fff; cursor: pointer}
.red-button { border-radius: 4px; text-align: center; text-transform: uppercase; padding: 20px 30px; display: inline-block; position: relative; z-index: 1; margin-top: 3.125rem; font-family: 'Rajdhani'; font-size: 1.25rem !important; font-weight: 600; border: none; background: linear-gradient(0deg, rgba(115,14,28,1) 0%, rgba(229,28,56,1) 100%);color:#fff; cursor: pointer}

</style>

EOT;
    }
	function js() {
        $this->trace[]=__FUNCTION__;
        return <<< EOT
<script>
jQuery(document).keypress(function(event) {
    if(event.key == "Enter") alert("The ENTER key is not available on this page. Please click the SUBMIT button.");
})
function recaptcha_script(token) {
	jQuery("#recaptcha_button").hide();
	jQuery("#recaptcha_status").html("Working ...");
	jQuery("#recaptcha_status").show();
	fn_newuser('register');
}

function recaptcha_script_verify(token) {
	jQuery("#recaptcha_button_verify").hide();
	jQuery("#recaptcha_status").html("Working ...");
	jQuery("#recaptcha_status").show();
	fn_newuser('remote');
}

function recaptcha_script_resend(token) {
	jQuery("#recaptcha_button_resend").hide();
	jQuery("#recaptcha_status").html("Working ...");
	jQuery("#recaptcha_status").show();
	fn_newuser('token');
}

function fn_newuser(action) {
	var data=new Object();
	var obj=jQuery("#scs_form :input");
    obj.each(function() {
        if (this.name.length) data[this.name] = jQuery(this).val();
    });
    data['action']=action;
	jQuery.ajax({
        url: "/newuser.php",
        type: "post",
	    dataType: "json",
		data: data ,
	    success: function(response) {
            if (response['redirect']) {
                window.location.href = response['redirect'];
                return;
            }
            fields=response['html'];
            for (var field in fields) {
                jQuery("#" + field).html(fields[field]);
            }
            fields=response['error'];
            for (var field in fields) {
                if (fields[field] == 1) {
                    jQuery("#" + field).removeClass('profile_error').addClass('profile_error');
                } else {
                    jQuery("#" + field).removeClass('profile_error');
                }
            }
            fields=response['menu'];
            for (var field in fields) {
                if (fields[field] == 1) {
                    jQuery("." + field).show();
                } else {
                    jQuery("." + field).hide();
                }
            }
    	    jQuery("#recaptcha_button, #recaptcha_button_verify, #recaptcha_button_resend").show();
	        jQuery("#recaptcha_status").hide();
        },
        error: function(xhr, response) {
        	console.log(xhr);
			jQuery("#scscpq_input_status").html("Internal Error");
        }
	});

}
</script>

EOT;
	}
}
?>

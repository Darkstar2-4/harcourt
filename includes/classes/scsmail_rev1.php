<?php
require_once "classes/mailform.php";
/* https://github.com/PHPMailer/PHPMailer/wiki/Troubleshooting */
require_once $_SERVER['DOCUMENT_ROOT'] . (version_compare(PHP_VERSION, '8', '<') ? '/composer' : '/composer8') . '/vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

class scsmail_class {
	var $meta;
    var $attachments=array();
    var $map;
    var $recipient;
    var $addresses=array("to"=>array(),"cc"=>array(),"bcc"=>array());
    var $output_addresses=array();
    var $mailform;
    var $results=array();
    var $error;
    var $html=array();
	function scs_table_version() {
	    $results=array();
        $results['02/25/2024']="Strip non-display characters from error";
	    $results['03/21/2023']="Add function: sender, account";
	    $results['04/20/2021']="Add mailform: recipient, bcc";
	    $results['12/17/2020']="Log delivery errors";
	    $results['08/25/2020']="Add registry->email->mailform";
	    $results['03/08/2020']="Based on scsmail.php";
		$results['file']= __FILE__;
	    return $results;
	}
    function __construct($form, $map_data=array(), $options=array()) {
    global $database;
    	$this->meta=new stdClass();
        $this->meta->file=__FILE__;
        $this->meta->version=key($this->scs_table_version());
		$this->meta->form=$form;
		$database->mailform->read($this->meta->form,"code");
        if (!$database->mailform->meta->rows) {
			$this->error="Missing mailform: " . $this->meta->form;
            return;
        }
		$this->map=new preg_replace_class();
	    $this->map->load('/%scs_date%/i',date("F j, Y"));
	    $this->map->load('/%scs_time%/i',date("g:i A"));
	    $this->map->load('/%scs_datetime%/i',date("F j, Y g:i A"));
	    $this->map->load('/%scs_mdy%/i',date("m/d/Y"));
	    $this->map->load('/%scs_hhmm%/i',date("H:i"));
	    $this->map->load('/%scs_ip4%/i',$_SERVER['REMOTE_ADDR']);
	    $this->map->load('/%scs_host%/i',$_SERVER['HTTP_HOST']);
	    $this->map->load('/%scs_user_id%/i',$_SESSION['user']->id);
	    $this->map->load('/%scs_user_name%/i',$_SESSION['user']->name);
	    $this->map->load('/%scs_company_name%/i',$database->registry->company->name);
	    $this->map->load('/%scs_company_address%/i',$database->registry->company->address);
	    $this->map->load('/%scs_company_city_state_zip%/i',$database->registry->company->city_state_zip);
	    $this->map->load('/%scs_company_phone%/i',$database->registry->company->phone);
	    $this->map->load('/%scs_company_fax%/i',$database->registry->company->fax);
		foreach ($map_data as $field => $value) {
        	switch (TRUE) {
              case (is_object($value)):
              case (is_array($value)):
				break;
			  default:
				$this->map->load('/%' . $field . '%/i',$value);
			}
        }
        $this->mailform=$database->mailform->data;
        $this->meta->subject=$this->map->filter( ((isset($options['subject'])) ? $options['subject'] : $this->mailform->subject ));
		$this->meta->base_href=( (isset($options['base_href'])) ? $this->meta->base_href=$options['base_href'] : $database->registry->email->base_href);
        $this->meta->encoding=( (isset($options['encoding'])) ? $options['encoding'] : "8bit");
        $this->meta->bcc_load=( (isset($options['bcc_load'])) ? $options['bcc_load'] : TRUE);
        $this->meta->debug=( (isset($options['debug'])) ? $options['debug'] : FALSE);
        $this->meta->user_id=( (isset($options['user_id'])) ? $options['user_id'] : $_SESSION['user']->id);
        switch (TRUE) {
          case ($options['css']):
			$this->meta->css=$options['css'];
            break;
          case ($this->mailform->css):
        	$this->meta->css=$this->mailform->css;
			break;
          default:
	        $this->meta->css=$database->registry->email->css;
		}
	    $this->meta->email_address=$database->registry->email->webmaster;
	    $this->meta->email_name=$database->registry->email->webmaster_name;
        switch (TRUE) {
          case (array_key_exists("email",$options)):
	        $this->meta->email_address=$options['email'];
            $this->meta->email_name=$options['email_name'];
			break;
		  case ($this->mailform->user_id):
			$temp=new database_temp_class();
        	$query="select * from user where id=" . fn_escape($this->mailform->user_id);
            $temp->query($query);
            $temp->fetch();
            $temp->free_result();
            if ($temp->meta->rows) {
				$this->meta->email_address=$temp->fetch['email'];
				$this->meta->email_name=$temp->fetch['name'];
            }
        }
	    list ($this->meta->account,$this->meta->domain) = preg_split("/@/",$this->meta->email_address) + Array(NULL,NULL);
        if ($this->meta->bcc_load) {
	        foreach ($database->mailform->data->bcc as $email) {
	            $this->address("bcc",$email);
	        }
        }
		$this->meta->host=$database->registry->email->host;
		$this->meta->secure=$database->registry->email->secure;
		$this->meta->smtp_port=$database->registry->email->smtp_port;
		$this->meta->authentication=$database->registry->email->authentication;
		$this->meta->username=$database->registry->email->username;
		$this->meta->password=$database->registry->email->password;
	}
    function sender($email_address, $email_name) {
	    $this->meta->email_address=$email_address;
	    $this->meta->email_name=$email_name;
    }
    function account($username, $password) {
		$this->meta->username=$username;
		$this->meta->password=$password;
    }
    function recipient($email,$name,$field="") {
		switch ($field) {
		  case "cc":
		  case "bcc":
			$this->address($field,$email,$name);
            break;
          case "":
          	$this->address("to",$email,$name);
        }
    }
	private function address($field,$email,$name="") {
    	$field=trim(strtolower($field));
		switch (TRUE) {
          case ( (!$field)|| (!array_key_exists($field,$this->addresses)) || (!$email) ):
          case (array_key_exists($email,$this->addresses)):
			break;
		  default:
			$this->addresses[$field][$email]=$name;
		}
    }
    function html($html) {
	global $database;
        $this->html=array();
        $this->html[]="<html>";
        $this->html[]="<head>";
		if ($this->meta->base_href) $this->html[]="<base href=" . fn_escape($this->meta->base_href) . ">";
		if ($this->meta->css) {
	        $css=$_SERVER['DOCUMENT_ROOT'] . $this->meta->css;
	        if (file_exists($css)) {
            	$this->html[]="<style>";
                $this->html[]=implode("\n",file($css));
            	$this->html[]="</style>";
            }
		}
        $this->html[]="</head>";
        $this->html[]="<body>";
		$this->html[]="<div id='content'>";
        if ($this->mailform->header_html) $this->html[]=$this->map->filter($this->mailform->header_html);
        if (is_array($html)) {
			$this->html[]=implode("",$html);
		  } else {
			$this->html[]=trim($html);
		}
        if ($this->mailform->footer_html) $this->html[]=$this->map->filter($this->mailform->footer_html);
        $this->html[]="</div>";
        $this->html[]="</body>";
        $this->html[]="</html>";
    }
    function attachment($file) {
		if (($file) && (!in_array($file,$this->attachments))) $this->attachments[]=$file;
    }
    function send($success_message="") {
        global $database;
		$this->error=$this->review(FALSE);
        if ($this->error) return;
		$this->results[]="Form: " . $this->meta->form  . " (" . $this->mailform->name . ")";
		$this->results[]="Subject: " . $this->meta->subject;
	    $mail=new PHPMailer();
		$mail->IsSMTP();
        $mail->SMTPDebug=$this->meta->debug;
	    $mail->Host=$this->meta->host;
	    $mail->SMTPSecure=$this->meta->secure;
	    $mail->Port=$this->meta->smtp_port;
		$mail->SMTPAuth=$this->meta->authentication;
		$mail->Username=$this->meta->username;
		$mail->Password=$this->meta->password;
		$mail->Subject=$this->meta->subject;
        $mail->WordWrap=50;
	    $mail->AltBody="To view the message, please use an HTML compatible email viewer!";
	    $mail->MsgHTML(implode("\n",$this->html));
        $mail->IsHTML(TRUE);
	    $mail->SetFrom($this->meta->email_address, $this->meta->email_name);
        $address=array();
        foreach ($this->addresses as $type => $type_list) {
			if (sizeof($type_list)) {
	            foreach ($type_list as $email => $email_name) {
                	$this->results[]="$type: $email ($email_name)";
	                switch ($type) {
	                  case "to":
	                    $mail->AddAddress($email,$email_name);
	                    break;
	                  case "cc":
	                    $mail->AddCC($email,$email_name);
	                    break;
	                  case "bcc":
	                    $mail->AddBCC($email,$email_name);
	                    break;
	                }
				}
            }
        }
        if (sizeof($this->attachments)) {
			foreach ($this->attachments as $file) {
                $this->results[]="Attachment: $file";
				$mail->AddAttachment($file);
			}
        }
        if ($this->error) {
			$this->log(FALSE,$this->error);
        	return "Email not sent due to errors detected";
		}
        if (!$mail->Send()) {
            list($this->error)=preg_split('/[\x00-\x1F\x7F-\xFF]/', $mail->ErrorInfo,2);
	        $this->log(FALSE,$this->error);
			return $this->error;
		  } else {
			$message="Email successfully sent to " . key($this->addresses['to']);
	        $this->log(TRUE,$message);
            if ($success_message) {
				return $success_message;
              } else {
				return $message;
			}
		}
    }
    function log($success,$message) {
        global $database;
		if (!method_exists($database->log,"ipc")) return;
		$this->results[]="";
		$this->results[]=$message;
        $this->results[]="Engine: " . $this->meta->version;
        $action=array();
        $action[]=( ($success) ? "Email" : "Email Error");
	    $action[]=$this->meta->form;
	    $database->log->ipc($action, $this->results, $this->meta->user_id);
    }
    function review($prelist=TRUE) {
    global $database;
    	switch (TRUE) {
          case (sizeof($this->addresses['to'])):
            break;
          case ($database->mailform->data->recipient):
          	$this->address("to",$database->mailform->data->recipient);
            break;
          default:
          	$this->address("to",$_SESSION['user']->email);
		}
		switch (TRUE) {
		  case ($this->error):
			$results=$this->error;
          	break;
		  case (!sizeof($this->html)):
          	$results="Empty message";
            break;
          case (!sizeof($this->addresses['to'])):
          	$results="To address missing";
            break;
          case ($prelist):
			$results="Deliver email to " . key($this->addresses['to']);
        }
        return $results;
    }
}
?>
<?php
$database->log = new log_class();
class log_class extends database_class {
    function scs_table_version() {
        $results=array();
        $results['10/01/2025']="Add harcourt_remote_addr()";
        $results['07/08/2025']="User profile:email";
        $results['08/09/2024']="Add CRON to function:ipc";
        $results['07/27/2022']="Expand SKU to varchar(80)";
        $results['07/08/2018']="Change RFQ to PO";
        $results['04/26/2018']="Add indexes for faster access";
        $results['07/25/2017']="Add type: Page:Portal";
        $results['04/11/2017']="Add type: Initial";
        $results['12/02/2016']="New file layout";
        $results['04/01/2016']="Add ip_index, record_type_index";
        $results['03/09/2016']="Add bot_id";
        $results['04/21/2015']="Improve update function";
        $results['03/17/2012']="Initial release";
        $results['file']=__FILE__;
        return $results;
    }

    function __construct() {
        $this->data=new log_data_class();
        $this->meta=new database_meta_class();
        $this->fetch=array();
        $this->constant=new log_constant_class();
        $this->meta->error_abort=FALSE;
    }
    function fetch($fetch=FALSE) {
        if ($fetch) $this->fetch=$this->fetch_array();
        $this->data=new log_data_class();
        $this->data->id=$this->fetch['id'];
        $this->data->ip=$this->fetch['ip'];
        $this->data->date_time=$this->fetch['date_time'];
        $this->data->user_id=$this->fetch['user_id'];
        $this->data->bot_id=$this->fetch['bot_id'];
        $this->data->email=$this->fetch['email'];
        $this->data->type=$this->fetch['type'];
        $this->data->subtype=$this->fetch['subtype'];
        $this->data->sku=$this->fetch['sku'];
        $this->data->subcategory_id=$this->fetch['subcategory_id'];
        $this->data->comment=$this->fetch['comment'];
    }
    function read($id) {
        $query="select * from log where id=" . fn_escape($id);
        $this->query($query);
        if ($this->meta->rows) {
            $this->fetch(TRUE);
            $this->free_result();
          } else {
            $this->data=new log_data_class();
        }
    }
    function update($type_subtype,$options=array()) {
        switch (TRUE) {
          case ($GLOBALS['SCS_API']):
          case ($options['cron']):
            break;
          case (!isset($_SESSION['user'])):
          case (isset($_SESSION['impersonate'])):
            return;
        }
        $this->data=new log_data_class();
        if (is_array($type_subtype)) {
            $type=$type_subtype[0];
            $subtype=$type_subtype[1];
          } else {
            list($type,$subtype)=preg_split("/\:/",$type_subtype);
        }
        $type=trim($type);
        $subtype=trim($subtype);
        $this->data->type=import_match($type,array_keys($this->constant->type),TRUE);
        if ($options['cron']) {
            $this->data->user_id=$options['cron'];
          } else {
            if (array_key_exists("ip",$options)) $this->data->ip=$options['ip'];
            if (array_key_exists("date_time",$options)) $this->data->date_time=$options['date_time'];
            if (array_key_exists("user_id",$options)) $this->data->user_id=$options['user_id'];
            if (array_key_exists("bot_id",$options)) $this->data->bot_id=$options['bot_id'];
            if (array_key_exists("email",$options)) $this->data->email=$options['email'];
            if (array_key_exists("sku",$options)) $this->data->sku=$options['sku'];
            if (array_key_exists("subcategory_id",$options)) $this->data->subcategory_id=$options['subcategory_id'];
        }
        switch (TRUE) {
          case (!$this->data->type):
            return;
            case (!sizeof($this->constant->type[$this->data->type])):
            $this->data->subtype=$subtype;
            break;
          default:
            $this->data->subtype=import_match($subtype,$this->constant->type[$this->data->type],TRUE);
        }
        switch (TRUE) {
          case (strlen($this->data->subtype)):
          case (preg_match("/^email/i", $this->data->type)):
            break;
          default:
            return;
        }
        switch (TRUE) {
          case (!array_key_exists("comment",$options)):
            break;
          case (is_array($options['comment'])):
            $this->data->comment=implode("\n",$options['comment']);
            break;
          default:
            $this->data->comment=$options['comment'];
        }
        $fields=array();
        $fields[]="id=" . fn_escape($this->data->id,FALSE);
        $fields[]="ip=" . fn_escape($this->data->ip);
        $fields[]="date_time=" . fn_escape($this->data->date_time,FALSE);
        $fields[]="user_id=" . fn_escape($this->data->user_id,FALSE);
        $fields[]="bot_id=" . fn_escape($this->data->bot_id,FALSE);
        $fields[]="email=" . fn_escape($this->data->email);
        $fields[]="type=" . fn_escape($this->data->type);
        $fields[]="subtype=" . fn_escape($this->data->subtype);
        $fields[]="sku=" . fn_escape($this->data->sku);
        $fields[]="subcategory_id=" . fn_escape($this->data->subcategory_id,FALSE);
        $fields[]="comment=" . fn_escape($this->data->comment);
        $query=array("insert into log set");
        $query[]=implode(",",$fields);
        $this->query($query);
        if ((!$this->data->id) && (!$this->meta->error)) $this->data->id=$this->insert_id();
        return $this->data->id;
    }
    function ipc($type,$comment,$user_id=0) {
        global $forms;
        $options=array();
        $options['comment']=$comment;
        if ($user_id) $options['user_id']=$user_id;
        if ($forms->cron) $options['cron']=$forms->cron;
        return $this->update($type,$options);
    }
    function track($subtype) {
        if (!$subtype) return;
        $comment=array();
        $comment[]="PHP session: " . session_id();
        $comment[]="Agent: " . $_SERVER['HTTP_USER_AGENT'];
        $comment[]="Server: " . $_SERVER['SERVER_NAME'] . (($_SERVER['HTTPS']) ? "" : " (Not Secure)");
        $comment[]="Page: " . $_SERVER['PHP_SELF'];
        if ($_SESSION['user']->id) {
            $comment[]="User status: " . $_SESSION['user']->status;
            $comment[]="User type: " . $_SESSION['user']->type;
            $comment[]="Last login: " . date("m/d/Y h:i A",$_SESSION['user']->last_login);
            if ($_SESSION['harcourt_profile']->email) $comment[]="Email: " . $_SESSION['harcourt_profile']->email;
            if ($_SESSION['user']->login_document_id) $comment[]="Login document: " . $_SESSION['user']->login_document_id;
            if ($_SESSION['user']->landing_document_id) $comment[]="Landing document: " . $_SESSION['user']->landing_document_id;
            if ($_SESSION['user']->portal_id) $comment[]="Landing document: " . $_SESSION['user']->portal_id;
        }
		$fields[]="id=" . fn_escape(0,FALSE);
		$fields[]="ip=" . fn_escape(harcourt_remote_addr());
		$fields[]="date_time=" . fn_escape(strtotime("now"),FALSE);
		$fields[]="user_id=" . fn_escape($_SESSION['user']->id,FALSE);
		$fields[]="bot_id=" . fn_escape($_SESSION['bot']->id,FALSE);
		$fields[]="email=" . fn_escape($_SESSION['harcourt_profile']->email);
		$fields[]="type=" . fn_escape("Track");
		$fields[]="subtype=" . fn_escape($subtype);
		$fields[]="sku=''";
		$fields[]="subcategory_id=0";
		$fields[]="comment=" . fn_escape(implode("\n",$comment));
        $query=array("insert into log set");
        $query[]=implode(",",$fields);
		$this->query($query);
    }
}
class log_data_class {
    var $id=0;
    var $ip;
    var $date_time=0;
    var $user_id=0;
    var $bot_id=0;
    var $email;
    var $type;
    var $subtype;
    var $sku;
    var $subcategory_id=0;
    var $comment;
    function __construct() {
        $this->date_time=strtotime("now");
        $this->ip=( (isset($_SESSION['user'])) ? $_SESSION['user']->ip : harcourt_remote_addr());
        $this->email=$_SESSION['harcourt_profile']->email;
        switch (TRUE) {
          case (!isset($_SESSION['user'])):
            break;
          case (isset($_SESSION['user']->bot_id)):
            $this->bot_id=$_SESSION['user']->bot_id;
            break;
          default:
            $this->user_id=$_SESSION['user']->id;
        }
    }
}
class log_constant_class {
    var $type=array();
    var $track=array();
    function __construct() {
        $this->type['User']=array('Login','Remote Login','Download','Signup','Email','PO','Click Through');
        $this->type['Page']=array('General','Solutions','Products','Portal');
        $this->type['Catalog']=array('Unit Filter','Unit Default');
        $this->type['Search']=array('General','Category','Subcategory','SKU','Competitor SKU','Lookup');
        $this->type['PARTSolutions']=array('Reverse Lookup Error','CAD','Quick CAD','Quick CAD Error','PDF Datasheet','Configurator','Security');
        $this->type['Initial']=array('Campaign','Referer');
        $this->type['IPStack']=array('Success','Error');
        $this->type['CRON']=array();
        $this->type['Export Table']=array();
        $this->type['Import Table']=array();
        $this->type['MySQL']=array();
        $this->type['Email']=array();
        $this->type['Email Error']=array();
        $this->type['Error']=array();
        $this->type['BOT']=array();
        $this->type['Ajax']=array();
        $this->type['Blacklist']=array('Denied','Add','Remove');
        $this->type['Recaptcha']=array('Blocked');
        $this->type['CaptchaBypass']=array('Add','Remove');
    }
}
/*
CREATE TABLE `log` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `ip` varchar(15) DEFAULT '',
  `date_time` int(14) unsigned DEFAULT '0',
  `user_id` int(10) DEFAULT '0',
  `bot_id` int(10) DEFAULT '0',
  `email` varchar(60) DEFAULT NULL,
  `type` varchar(40) DEFAULT '',
  `subtype` varchar(40) DEFAULT NULL,
  `sku` varchar(80) DEFAULT '',
  `subcategory_id` int(6) DEFAULT '0',
  `comment` mediumtext,
  `record_type` varchar(60) DEFAULT NULL,
  `bot_code` varchar(60) DEFAULT '',
  `cad_code` varchar(60) DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `user_id_index` (`user_id`),
  KEY `date_time_index` (`date_time`),
  KEY `type_index` (`type`),
  KEY `subtype_index` (`subtype`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COMMENT='Access log (07/27/2022)'
*/
?>
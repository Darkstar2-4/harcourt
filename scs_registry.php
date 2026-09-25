<?php
require_once "scs_header.php";
require_once "registry_rev0.php";
require_once "registry_ps.php";
require_once "classes/registry_local.php";
$database->user->access("Administrator");
$obj=new local_class();

class local_class {
	function scs_table_version() {
		$results=array();
        $results['08/05/2024']="Add profile";
        $results['10/24/2023']="Add api_key";
        $results['10/29/2012']="Initial release";
        $results['file']=__FILE__;
        return $results;
    }
    function __construct() {
        $registry=new registry_entry_class();
        if (preg_match("/^xmlhttprequest$/i",$_SERVER['HTTP_X_REQUESTED_WITH'])) {
            $this->json();
          } else {
            $this->html();
          }
    }
    function json() {
        $this->response=new stdClass();
        switch ($_POST['action']) {
          case "local_api_key":
            $this->response->local_api_key=bin2hex(openssl_random_pseudo_bytes(20));
            break;
        }
        die(json_encode($this->response));
    }
    function html() {
    global $database, $forms, $menu, $registry;
        $forms->title("Registry");
        $forms->message[]="Revised " . key($this->scs_table_version());
        $forms->report['action']=$_POST['action'];

        $watermark=array();
        $watermark['new_subcategory']=new registry_watermark_class("New subcategory",array("required"=>TRUE,"type"=>"png","location"=>1,"opacity"=>100));
        $watermark['new_inventory']=new registry_watermark_class("New inventory",array("required"=>TRUE,"type"=>"png","location"=>1,"opacity"=>100));
        if ($forms->report['action']) {
            if ($database->user->access("Super User",FALSE)) {
                $registry->www(TRUE);
                $registry->email(TRUE);
                $registry->watermark(TRUE,$watermark);
                $registry->ps->cad(TRUE);
                $registry->address(TRUE,"profile");
                $registry->address(TRUE,"orderhd");
                $registry->address(TRUE,"orderhd_billing");
                $registry->recaptcha(TRUE);
                $registry->cron(TRUE);
                $registry->local->local(TRUE);
                $registry->local->ipstack(TRUE);
            }
            $registry->local->leadscore(TRUE);
            $registry->local->motd(TRUE);
            $database->registry->load();
            $registry->message();
        }

        $menu->head();
        print $forms->message();
        print $forms->open();
        print $forms->hidden("action","update");
        if ($database->user->access("Super User",FALSE)) {
            print $registry->www();
            print $registry->email();
            print $registry->watermark(FALSE,$watermark);
            print $registry->ps->cad();
            print $registry->address(FALSE,"profile","User Profile");
            print $registry->address(FALSE,"orderhd","Orders");
            print $registry->address(FALSE,"orderhd_billing","Billing Address");
            print $registry->recaptcha();
            print $registry->cron();
        }
        print $registry->local->local();
        print $registry->local->ipstack();
        print $registry->local->leadscore();
        print $registry->local->motd();
        print "<p>" . $forms->submit("Update") . "</p>\n";
        print $forms->close();
        $menu->copyright();
    }
}
?>
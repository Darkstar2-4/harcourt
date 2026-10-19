<?php
require_once "scs_header.php";
require_once $_SERVER['DOCUMENT_ROOT'] . (version_compare(PHP_VERSION, '8', '<') ? '/composer' : '/composer8') . '/vendor/autoload.php';
$database->user->access("Administrator");
$obj=new local_class();

class local_class {
    var $trace=array();
    function scs_table_version() {
    	$results=array();
        $results['10/16/2026']="Initial release";
        return $results;
    }
    function __construct() {
        $this->constant=new stdClass;
        // Catalog log subtypes written by catalog_output_class::log_unit_filter()
        $this->constant->subtype=array(""=>"All","Unit Filter"=>"Filter choices","Unit Default"=>"Country default applied");
        $this->constant->region=array(""=>"All","us"=>"US users","non_us"=>"Non-US users");
        $this->input();
        $this->html();
    }
    function input() {
        global $database, $forms;
        $this->input=new stdClass;
        $this->input->action=$_POST['action'];
        if (!$this->input->action) {
            $this->input->from_date=date("Y/m/d",strtotime("-30 days"));
            $this->input->thru_date=date("Y/m/d");
            $this->input->subtype="";
            $this->input->region="";
            return;
        }
        $this->input->from_date=fn_date($_POST['from_date'],"mdy");
        $this->input->thru_date=fn_date($_POST['thru_date'],"mdy");
        if (($this->input->thru_date) && ($this->input->from_date > $this->input->thru_date)) $forms->error('thru_date');
        $this->input->subtype=array_key_exists($_POST['subtype'],$this->constant->subtype) ? $_POST['subtype'] : "";
        $this->input->region=array_key_exists($_POST['region'],$this->constant->region) ? $_POST['region'] : "";
        if (sizeof($forms->error)) return;
        $database->log->query($this->query());
        if (!$database->log->meta->rows) {
            $forms->error('null',"","No records found");
            return;
        }
        $this->download();
    }
    function download() {
        global $database;
        $workbook=new XLSXWriter();
        $workbook->setTitle("Metric Filter");
        $workbook->setAuthor($_SESSION['user']->name);
        $header=array();
        $header['Date/Time']="YYYY-MM-DD HH:MM:SS";
        $header['Type']="string";
        $header['User ID']="integer";
        $header['Email']="string";
        $header['Company Name']="string";
        $header['Country']="string";
        $header['Unit Filter']="string";
        $header['Default']="string";
        $header['Source']="string";
        $header['IP Address']="string";
        $workbook->writeSheetHeader("Metric Filter",$header,array("widths"=>array(20,14,10,30,30,9,12,10,10,16),"freeze_rows"=>1));
        while ($database->log->fetch = $database->log->fetch_array() ) {
            $database->log->fetch();
            $comment=$this->comment($database->log->fetch['comment']);
            $data=array();
            $data[]=date("Y-m-d H:i:s",$database->log->data->date_time);
            $data[]=$database->log->fetch['subtype'];
            $data[]=intval($database->log->fetch['user_id']);
            $data[]=$database->log->fetch['email'];
            $data[]=$database->log->fetch['company_name'];
            $data[]=$comment['Country'];
            $data[]=$comment['Unit filter'];
            $data[]=$comment['Default'];
            $data[]=$comment['Source'];
            $data[]=$database->log->fetch['ip'];
            $workbook->writeSheetRow("Metric Filter",$data);
        }
        $database->log->free_result();
        while (ob_get_level()) ob_end_clean();
        header("Content-type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet");
        header("Content-Disposition: attachment; filename=metric_filter_" . date("Ymd") . ".xlsx");
        header("Content-Transfer-Encoding: binary");
        header("Pragma: no-cache");
        header("Expires: 0");
        $workbook->writeToStdOut();
        die();
    }
    function comment($text) {
        // Comment lines are "Key: value" (see log_unit_filter()).
        $results=array("Unit filter"=>"","Default"=>"","Country"=>"","Source"=>"");
        foreach (explode("\n",(string) $text) as $line) {
            $parts=explode(":",$line,2);
            if (sizeof($parts) == 2) $results[trim($parts[0])]=trim($parts[1]);
        }
        return $results;
    }
    function query() {
        global $database;
        $query_where=array();
        $query_where[]=new query_where("log.type","=","Catalog");
        if ($this->input->subtype) $query_where[]=new query_where("log.subtype","=",$this->input->subtype);
        // Country as logged at the time (not the user's current country). Users
        // with no country count as Non-US.
        if ($this->input->region == "us") $query_where[]=new query_where("log.comment","like","%\nCountry: US\n%");
        if ($this->input->region == "non_us") $query_where[]=new query_where("log.comment","not like","%\nCountry: US\n%");
        if ($this->input->from_date) $query_where[]=new query_where("log.date_time",">=",strtotime($this->input->from_date));
        if ($this->input->thru_date) $query_where[]=new query_where("log.date_time","<=",strtotime($this->input->thru_date . " + 1 day") - 1);
        $query=array();
        $query[]="select user.company_name as 'company_name',";
        $query[]="log.* from log";
        $query[]="left join user on user.id=log.user_id";
        $query[]=$database->where($query_where);
        $query[]="order by log.date_time desc";
        return $query;
    }
    function html() {
        global $database, $forms, $menu;
        $forms->title("Metric Filter", key($this->scs_table_version()));
        $menu->head();
        print $forms->message();
        print $forms->open();
        print $forms->hidden("action","download");
        print "<p>Downloads the catalog Metric/Imperial filter usage log as an Excel file.</p>";
        print "<table class='standard noborder'>";
        print "<tr>";
        print "<td width='30%'>From Date</td>";
        print "<td width='70%'>" . $forms->text("from_date",$this->input->from_date,10,10,"date:ymd") . "</td>";
        print "</tr>";
        print "<tr>";
        print "<td>Thru Date</td>";
        print "<td>" . $forms->text("thru_date",$this->input->thru_date,10,10,"date:ymd") . "</td>";
        print "</tr>";
        print "<tr>";
        print "<td>Record type</td>";
        print "<td>" . $forms->select("subtype",$this->constant->subtype,$this->input->subtype,FALSE) . "</td>";
        print "</tr>";
        print "<tr>";
        print "<td>Users</td>";
        print "<td>" . $forms->select("region",$this->constant->region,$this->input->region,FALSE) . "</td>";
        print "</tr>";
        print "</table>";
        print "<p>" . $forms->submit("Download") . "</p>";
        print $forms->close();
        $menu->copyright();
    }
}
?>

<?php
/*
COPYRIGHT NOTICE:
Copyright 1981 - 2025 Suburban Computer Services, Inc All Rights Reserved.

This program is furnished under a license restricing its use solely for the operation
of a designated computer for a particular  purpose, and may  not be copied, reproduced
disclosed,  or otherwise  used without the prior written, consent of Suburban Computer
Services, Inc.  Title to and ownership of the program shall at all times remain the
property of Suburban Computer Services.
*/
$LOCAL_URL=explode("/",$_SERVER['HTTP_HOST']);
function fn_development_server() {
	switch (TRUE) {
	  case ( (array_key_exists("SERVER_ADMIN",$_SERVER)) && ($_SERVER['SERVER_ADMIN'] == "host@suburbancomputer.com") ): // web
	  case ( (array_key_exists("COMPUTERNAME",$_SERVER)) && (preg_match("/^scswin/i", $_SERVER['COMPUTERNAME'])) ): //command line
        return TRUE;
	  default:
		return FALSE;
    }
}
function scs_version() {
    $results=array();
    $results['08/27/2025']="Add GLOBALS:fn_page_item";
    $results['06/04/2025']="Add map_field_class";
    $results['03/24/2025']="Add fn_escape_javascript";
    $results['01/06/2025']="Update address_class";
    $results['11/11/2024']="Add verify_domain";
    $results['10/05/2024']="Add token";
    $results['08/05/2024']="Improve fn_cookie";
    $results['03/31/2023']="Add page_break_class (replacg page_item_break)";
    $results['03/19/2023']="Improve image";
    $results['08/17/2022']="merge_recursive ignores null/0";
    $results['03/19/2022']="Add merge_recursive";
    $results['02/13/2022']="Improve address";
    $results['07/25/2021']="Add rolodex";
    $results['07/07/2021']="Add expiry";
    $results['05/21/2021']="Add stylesheet";
    $results['02/25/2021']="Enhance address->js";
    $results['01/15/2021']="Add http_build_query_map, business_days";
    $results['10/26/2020']="Alter fn_url_redirect";
    $results['08/28/2020']="Add address_class";
    $results['07/26/2020']="Add fn_cookie";
    $results['06/26/2020']="Function address_output replaces address_html, address_table";
    $results['06/12/2020']="Add text_map";
    $results['11/11/2019']="Add fn_escape_array, fn_urlencode";
    $results['06/11/2019']=array("Improve email_class","Address single line");
    $results['03/26/2019']="Add verify_url_class";
    $results['03/08/2019']="Rewrite fn_url";
    $results['11/07/2018']="Add stopwatch";
    $results['03/21/2018']="Require jquery_rev2";
    $results['11/15/2017']="Enable HTTPS";
    $results['08/01/2017']="Add true_false";
    $results['03/05/2017']="Allow for NULL dates";
    $results['12/25/2016']="Add hex_dump, parse_object";
    $results['04/14/2016']="Add import_match_array";
    $results['03/11/2016']="Add import_match_id";
    $results['01/09/2016']="Add ship_date";
    $results['12/20/2015']="Add file_upload_class";
    $results['12/05/2015']="Add fn_base64";
    $results['07/08/2015']="Add tabs output_order";
    $results['06/11/2015']="Add role_access";
    $results['05/30/2015']="Add mod10 support";
    $results['03/31/2015']="Initial release";
	$results['file']= __FILE__;
    return $results;
}
function http_build_query_map($fields, $data, $supplemental=array()) {
    $results=array();
    foreach ($fields as $field => $obj) {
    	$map=( (is_object($obj)) ? $obj->map : $obj);
        $text=( (array_key_exists($field, $supplemental)) ? $supplemental[$field] : $data->$field);
        switch (TRUE) {
          case ($map == "boolean"):
            $value=( ($text) ? 1 : 0);
            break;
          case (is_null($text)):
          	$value="";
          	break;
          case (preg_match("/^decimal/i",$map)):
            $value=number_format($text,substr($map,-1),".","");
            break;
          default:
            $value=$text;
        }
        $results[$field]=$value;
    }
    return http_build_query($results);
}
function business_days($timestamp, $days, $holiday=array(), $dow=array("Saturday", "Sunday")) {
    $i=1;
    while ($days >= $i) {
        $timestamp=strtotime("+1 day", $timestamp);
        if ( (in_array(date("l", $timestamp), $dow)) || (in_array(date("Y/m/d", $timestamp), $holiday)) ) $days++;
        $i++;
    }
    return $timestamp;
}
function token($length) {
    return bin2hex(openssl_random_pseudo_bytes($length));
}
function fn_cookie($name, $text, $cookie_options=array()) {
	if (!strlen($name)) return;
// https://www.php.net/manual/en/function.setcookie.php
	$options=array();
    switch (TRUE) {
      case (!strlen($text)):
      	$options['expires']=strtotime("now -1 hour");
        break;
      case (isset($cookie_options['expires'])):
      	$options['expires']=$cookie_options['expires'];
        break;
      default:
    	$options['expires']=strtotime("+ 1 year");
	}
	$options['path']=( ($cookie_options['path']) ?  $cookie_options['path'] : "/");
    $options['secure']=( (isset($cookie_options['secure'])) ? $cookie_options['secure'] : FALSE);
    $options['httponly']=( (isset($cookie_options['httponly'])) ? $cookie_options['httponly'] : FALSE);
/* Not working properly
	$options['domain']=$cookie_options['domain'];
    $options['samesite']=( ($cookie_options['samesite']) ? $cookie_options['samesite'] : "None");
*/
    if (PHP_VERSION_ID < 70300) {
		return setcookie($name, $text, $options['expires']);
	  } else {
    	return setcookie($name, $text, $options);
    }
}
function text_map($text,$map,$binary_boolean=TRUE) {
    switch (TRUE) {
      case (preg_match("/^decimal/i",$map)):
        return number_format($text,substr($map,-1),".","");
      case ($map == "boolean"):
		switch (TRUE) {
          case (!strlen($text)):
          case (in_array($text,array("0","N"))):
          	return ( ($binary_boolean) ? 0 : "N");
          default:
          	return ( ($binary_boolean) ? 1 : "Y");
        }
      case (is_null($text)):
        return "";
      case ($map == "lowercase"):
        return strtolower($text);
      case ($map == "uppercase"):
        return strtoupper($text);
      default:
        return $text;
    }
}
function geocode_distance($location1, $location2, $miles=TRUE, $precision=2) {
// Haversine Formula
	if ( (!strlen($location1)) || (!strlen($location2)) ) return 0;
	list($lat1, $lon1)=explode(",",$location1);
	list($lat2, $lon2)=explode(",",$location2);
    $pi80 = M_PI / 180;
    $lat1 *= $pi80;
    $lon1 *= $pi80;
    $lat2 *= $pi80;
    $lon2 *= $pi80;

	$r=( ($miles) ? 3961 : 6373);
    $dlat = $lat2 - $lat1;
    $dlon = $lon2 - $lon1;
    $a = sin($dlat / 2) * sin($dlat / 2) + cos($lat1) * cos($lat2) * sin($dlon / 2) * sin($dlon / 2);
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return round($r * $c,$precision);
}
function fn_urlencode($data,$encode) {
	// Special case function that corrects encoding issues with parse_str and http_build_query
	$results=array();
	if ($encode) {
    	if (is_array($data)) {
        	foreach ($data as $key => $value) {
            	$results[]=rawurlencode($key) . "=" . rawurlencode($value);
            }
        }
        return implode("&",$results);
	  } else {
		if (is_string($data)) {
			$items=preg_split("/\&/",trim($data));
            foreach ($items as $item) {
            	list($key,$value)=preg_split("/\=/",$item);
                if (strlen($key)) $results[rawurldecode($key)]=rawurldecode($value);
            }
        }
		return $results;
    }
}
function fn_url_query($url_query,$options=array()) {
	if ( (!is_array($url_query)) || (!sizeof($url_query)) ) return;
// legacy programming
	if (!is_array($options)) {
    	$options=array();
        if (func_num_args() == 2) $options['raw']=func_get_arg(1);
	}
// end legacy
    if (!array_key_exists("raw",$options)) $options['raw']=FALSE;
	$results=array();
    foreach ($url_query as $key => $value) {
		switch (TRUE) {
          case (!strlen($value)):
			break;
		  case ($options['raw']):
            $results[]="{$key}=" . rawurlencode($value);
            break;
          default:
            $results[]="{$key}=" . urlencode($value);
        }
    }
    if (sizeof($results)) return "?" . implode("&",$results);
}
function fn_url($page,$url_query=array(),$options=array()) {
    switch (TRUE) {
      case (is_object($page)):
		return $page->url . fn_url_query($url_query);
      case (preg_match("/^http:/i", $page)):
      case (preg_match("/^https:/i", $page)):
      case (preg_match("/^javascript:/i", $page)):
		return $page . fn_url_query($url_query,$options);
      case (preg_match("/^mailto:/i", $page)):
		return $page . fn_url_query($url_query,array("raw"=>TRUE));
	  case ($page=="#"):
		return $page;
	}
	$url=parse_url($page);
    $results=array();
    if (!strlen($url['path'])) return;
    $results[]=$url['path'];
    if (isset($url['query'])) {
		parse_str($url['query'],$page_query);
		$url_query=array_merge($page_query,$url_query);
    }
    $results[]=trim(fn_url_query($url_query,$options));
    if (array_key_exists("fragment",$url)) $results[]="#" . $url['fragment'];
    return trim(implode("",$results));
}
function fn_url_redirect($page,$url_query=array(),$target="") {
	switch (TRUE) {
 	  case (is_object($page)):
    	$url=$page->url;
		$url_target=$page->target;
        break;
	  case (preg_match("/^http/i", $page)):
    	$url=$page . fn_url_query($url_query);
        break;
	  default:
    	$url=fn_url($page,$url_query);
        break;
	}
    if ($target) $url_target=$target;
	if ($url_target) {
		print "<script language=\"javascript\">\n";
	    print "window.open('" . $url . "','" . $url_target . "')\n";
	    print "</script>";
        exit();
	  } else {
    	header("Location: " . $url);
        die;
    }
}
function fn_href_absolute($name, $path, $url_query=array(), $forms_options=array()) {
	$results=array();
	$results[]=$_SERVER['REQUEST_SCHEME'];
	$results[]="://";
	$results[]=$_SERVER['HTTP_HOST'];
    if (substr($path,0,1) != "/") $results[]="/";
	$results[]=$path;
	return fn_href($name, implode("",$results), $url_query, $forms_options);
}
function fn_href($name, $url, $url_query=array(), $forms_options=array()) {
global $forms;
	$results=array();
	switch (TRUE) {
      case (is_null($name)):
      	break;
	  case (!$url):
	  	$results[]="<b>$name</b>";
        break;
	  default:
		if ($name == "&nbsp;") $name="";
		$data=array();
        $data[]=trim(fn_url($url,$url_query));
		if (isset($forms_options['bookmark'])) {
        	$data[]="#" . $forms_options['bookmark'];
			unset($forms_options['bookmark']);
		}
        $results[]="<a href=\"" . implode("",$data) . "\" " . trim($forms->options("",$forms_options)) . ">$name</a>";
	}
    return implode("\n",$results);
}
function fn_mailto($email,$subject="") {
	$results=array();
    $results[]="mailto:";
    $results[]=$email;
    if ($subject) $results[]="?subject=" . $subject;
	return fn_href($email,implode("",$results));
}
function fn_name($name,$text="") {
	return "<a name=" . fn_escape($name) . ">$text</a>\n";
}
function fn_escape($text,$type="") {
    global $database, $mysqli;
	switch (TRUE) {
      case (is_array($text)):
		return;
	  case (func_num_args() == 1):
      case (strlen($text)):
      case (in_array($type,array("null"))):
      	break;
      case (in_array($type,array(TRUE,"date"))):
		$text="0000/00/00";
        break;
      case ($type==FALSE):
		$text="0";
	}
    switch (TRUE) {
      case (!isset($mysqli)):
		return "'" . mysql_real_escape_string($text) . "'";
      case ( ($type == "null") && (!strlen($text)) ):
      case ( ($database->null_date) && (in_array($type,array(TRUE,"date"))) && ($text == "0000/00/00") ):
      	return "NULL";
      default:
        return "'" . $mysqli->real_escape_string($text) . "'";
    }
}
function fn_escape_array($data) {
	$results=array();
	foreach ($data as $key => $value) {
    	$results[]=fn_escape($value);
    }
    return implode(",",$results);
}
function fn_escape_javascript($function, $data) {
    return "javascript:{$function}(" . fn_escape_array($data) . ");";
}
function fn_text($text,$uppercase=FALSE) {
global $database;
	$text=preg_replace("/(<script)(.*)(>)/i","",$text,-1,$blacklist);
    switch (TRUE) {
	  case (!$blacklist):
      case (!isset($database->registry->www->blacklist_script)):
      case (!isset($database->blacklist)):
      case (!method_exists($database->blacklist,"blacklist_script_injection")):
		break;
      default:
		$database->blacklist->blacklist_script_injection();
	}
    if ($uppercase) {
    	return strtoupper(trim($text));
	  } else {
		return trim($text);
	}
}
function fn_number($text,$decimal=0) {
    $multiplier=(preg_match("/\%/",$text)) ? 0.01 : 1;
    $results=floatval(preg_replace("/[^0-9.-]*/ ","",$text));
    if 	(func_num_args() > 1) $results=number_format($results,$decimal,".","");
    return floatval($results * $multiplier);
}
function fn_pre($data,$die_info=FALSE) {
	switch (TRUE) {
      case (is_null($data)):
      	print "<p>Null Object</p>\n";
        break;
      case (is_object($data)):
	    print "<pre>\n";
	    print_r($data);
        print_r(get_class_methods(get_class($data)));
	    print "</pre>\n";
		break;
	  case (is_array($data)):
	    print "<pre>\n";
	    print_r($data);
	    print "</pre>\n";
		break;
	  default:
      	print "<p>" . str_replace("\n","<br>",$data) . "</p>\n";
	}
	if ($die_info) die();
}
function fn_date($text,$action) {
    global $forms, $date_ok;
    $data=preg_split("/[ \/\.-]/",$text);
    $results = "";
    $date_ok=TRUE;
    switch (TRUE) {
      case ($text == ""):
      case ($text == "0"):
      case ($text == "0000-00-00"):
      case ($text == "0000/00/00"):
        break;
      case ($action == "mysql"):
        $results = $data[0] . "/" . $data[1] . "/" . $data[2];
        break;
      case ($action == "ymd"):
        $results = $data[1] . "/" . $data[2] . "/" . $data[0];
        break;
      case ($action == "yymm"):
        $results=$data[1] . "/" . $data[0];
        break;
      case ($action == "unix_timestamp"):
      	if (date("H:i:s",$text) == "00:00:00") {
			$results=date("m/d/Y",$text);
		  } else {
			$results=date("m/d/Y H:i:s",$text);
		}
        break;
      case ($action == "timestamp_unix"):
		$results=strtotime($text);
        break;
      case ($action == "unix_ymd"):
		$results=date("m/d/Y",$text);
        break;
      case ($action == "ymd8"):
		$text=substr("00000000" . trim($text),-8);
		$data=array();
	    $data['yyyy']=substr($text,0,4);
	    $data['mm']=substr($text,4,2);
	    $data['dd']=substr($text,6,2);
        $date_ok=@checkdate($data['mm'],$data['dd'],$data['yyyy']);
		if (($data['yyyy'] < 1800) | ($data['yyyy'] > 2200)) $date_ok=FALSE;
        if ($date_ok) $results = $data['yyyy'] . "/" . $data['mm'] . "/" . $data['dd'];
      	break;
      case ($action == "mdy"):
      case ($action == "mmyy"):
        $data[0]=intval($data[0]);
        $data[1]=intval($data[1]);
        $data[2]=intval($data[2]);
        if ($action == "mmyy") {
            $data[2]=$data[1];
            $data[1]=1;
        }
        if ($data[0] < 10) $data[0] = "0" . $data[0];
        if ($data[1] < 10) $data[1] = "0" . $data[1];
        switch(TRUE) {
          case ($data[2] < 50):
            $data[2] += 2000;
            break;
          case ($data[2] < 100):
            $data[2] += 1900;
            break;
        }
        $date_ok=@checkdate($data[0],$data[1],$data[2]);;
		if (($data[2] < 1800) || ($data[2] > 2200)) $date_ok=FALSE;
        if ($date_ok) $results = $data[2] . "/" . $data[0] . "/" . $data[1];
        break;
      case ($action == "unix_mdy"):
		if (strtotime($text) < 0) {
			$date_ok=FALSE;
		  } else {
			$results=strtotime($text);
		}
        break;
	  default:
		print "Invalid date action: $action text: $text";
		scs_function_debug(__FILE__,__METHOD__,func_num_args(),func_get_args());
        die;
    }
    $forms->date_ok=$date_ok;
    return $results;
}
function fn_period($text, $action) {
    global $forms;
    $forms->date_ok=FALSE;
	$data=explode((($action == "html5") ? "-" : "/"), trim($text));
    $results=array();
    switch (TRUE) {
      case (sizeof($data) != 2):
      case (!is_numeric($data[0])):
      case (!is_numeric($data[1])):
      	return;
      case ($action == "mmyyyy"):
		$month=$data[0];
        $year=$data[1];
      	break;
      case ($action == "yyyymm"):
      case ($action == "html5"):
		$month=$data[1];
        $year=$data[0];
      	break;
      case ($action == "stardate"):
        return ($data[0] * 12 + $data[1]);
      default:
      	return;
	}
    if (strlen($month) == 1) $month="0{$month}";
    if (strlen($year) == 2) $year="20{$year}";
    switch (TRUE) {
      case ( ($month < "00") || ($month > "12") ):
      case ( ($year < "1970") || ($year > "2070") ):
      	return;
      case ($action == "mmyyyy"):
      case ($action == "html5"):
	    $forms->date_ok=TRUE;
        return "{$year}/{$month}";
      default:
	    $forms->date_ok=TRUE;
        return "{$month}/{$year}";
	}
}
function verify_domain($domain) {
    return (preg_match("/^((?!-))(xn--)?[a-z0-9][a-z0-9-_]{0,61}[a-z0-9]{0,}\.?((xn--)?([a-z0-9\-.]{1,61}|[a-z0-9-]{1,30})\.?[a-z]{2,})$/i",$domain));
}
function verify_file($file,$type,$required=FALSE) {
/* Always store files in this format
$_SERVER['DOCUMENT_ROOT'] . "/" . $file;
*/
	switch (TRUE) {
      case (is_array($type)):
      	break;
      case (strlen($type)):
		$type=array($type);
        break;
      default:
		$type=array();
	}
	switch (TRUE) {
	  case ((!$file) && (!$required)):
	    return;
      case ( (sizeof($type)) && (!preg_match("/.[." . implode("|.",$type) . "]$/i",$file)) ):
		return "{$file} not type " . implode(", ",$type);
	  case (!$file):
	    return "Cannot be blank";
	  case (substr($file,0,1) != "/"):
	    return "{$file} must begin with /";
	  case (!file_exists($_SERVER['DOCUMENT_ROOT'] . $file)):
    	return "{$file} not found";
	}
}
function scs_verify_folder($folder,$required=FALSE) {
	switch (TRUE) {
	  case ((!$folder) && (!$required)):
	    return;
	  case (!$folder):
	    return "Folder name cannot be blank";
	  case (substr($folder,0,1) != "/"):
      case (substr($folder,-1,1) != "/"):
	  case (substr($folder,0,2) == "//"):
	  case (substr($folder,-2,2) == "//"):
		return "Folder names must begin and end with a single /";
	  case (!is_dir(scs_filename($folder))):
    	return "Folder not found";
	}
}
function scs_filename($folder,$filename="") {
	return $_SERVER['DOCUMENT_ROOT'] . $folder . $filename;
}
function fn_sentence_trim($text,$count,$words=FALSE) {
	$results="";
    $text=trim($text);
	$offset=strlen($text);
	$data=str_word_count($text,2);
    switch (TRUE) {
      case (!$count):
      case (($words) && ($count >= sizeof($data))):
		return $text;
      case ($words):
		reset($data);
		for ($i=0; $i < $count; $i++) {
			next($data);
        }
		$offset=key($data);
	  	break;
	  default:
        foreach ($data as $key => $value) {
			if ($key > $count) break;
            $offset=$key;
        }
	}
    $results=trim(substr($text,0,$offset));
    switch (TRUE) {
      case ($results==$text):
        break;
      case (substr($results,-1,1) == "."):
        $results .= "..";
        break;
      default:
        $results .= " ...";
        break;
    }
	return $results;
}
function scs_function_debug($file,$method,$count,$options=array()) {
//usage: scs_function_debug(__FILE__,__METHOD__,func_num_args(),func_get_args());
    print "\n<!-- \n";
    print "File: $file\n";
    print "Method: $method\n";
    print "Arguments: $count\n";
    fn_pre($options);
    print "-->\n";
}
function url_sort($a,$b) {
    switch (TRUE) {
      case ((substr_count($a,"/") == substr_count($b,"/")) && ($a > $b)):
      case (substr_count($a,"/") > substr_count($b,"/")):
        return TRUE;
      default:
        return FALSE;

    }
}
function alpha_sort($a, $b) {
    if ($a == $b) {
        return 0;
    }
    return ($a < $b) ? -1 : 1;
}
function import_match($text,$data,$forced=TRUE) {
	$key=array_search(strtolower($text),array_map('strtolower',$data));
    switch (TRUE) {
      case (strlen($key)):
	    return $data[$key];
      case ($forced):
      	return "";
      default:
	    return $text;
	}
}
function import_match_id($text,$data=array()) {
	if (!is_array($data)) return;
	foreach ($data as $match => $pattern) {
    	if (preg_match("/^" . preg_quote($pattern,"/") . "$/i",$text)) return $match;
    }
}
function import_match_array($data,$match=array(),$separator="^") {
	$results=array();
    if (!is_array($match)) $match=array();
    $items=explode($separator,$data);
    foreach ($items as $item) {
        $item=trim($item);
        if (!strlen($item)) continue;
        $key=import_match_id($item,$match);
        if ( ($key) && (!in_array($key,$results)) ) $results[]=$key;
	}
    return $results;
}
function fn_next_poll($increment="") {
	return date("m/d/Y g:i:s A",strtotime("now $increment"));
}
function mod10_verify($data) {
	if ($data == mod10_calculate(substr($data,0,-1)) ) return TRUE;
}
function mod10_calculate($data) {
	$items=str_split($data);
    krsort($items);
    $multiplier=3;
    $bcc=0;
    foreach ($items as $value) {
    	$bcc += ($value * $multiplier);
        if ($multiplier == 1) {
        	$multiplier=3;
		  } else {
        	$multiplier=1;
        }
	}
	$bcc=substr(10 - ($bcc % 10), -1);
	return $data . $bcc;
}
function role_access($role,$role_minimum,$role_match=FALSE) {
global $database;
	$roles=array();
	switch (TRUE) {
      case (!strlen($role_minimum)):
      case ( ($role_match) && ($role == $role_minimum) ):
      	return TRUE;
	  case (!isset($database->user)):
		break;
	  case (isset($database->user->constant->role)):
		$roles=$database->user->constant->role;
        break;
	  case (isset($database->user->constant->type)):
		$roles=$database->user->constant->type;
        break;
    }
	switch (TRUE) {
	  case (!sizeof($roles)):
	  case (!$role):
		return FALSE;
	  case (!$role_minimum):
		return TRUE;
	  case (!is_array($roles)):
	  case (!in_array($role,$roles)):
	  case (!in_array($role_minimum,$roles)):
	  case (array_search($role,$roles) < array_search($role_minimum,$roles)):
		return FALSE;
	  default:
		return TRUE;
    }
}
function fn_base64($data,$encode=TRUE) {
	switch (TRUE) {
      case (!$encode):
		$results=base64_decode($data);
        if (preg_match("/\t/",$results)) {
          	return explode("\t",$results);
		  } else {
        	return $results;
        }
      case (!is_array($data)):
		return str_replace("=","",base64_encode($data));
      default:
		return str_replace("=","",base64_encode(implode("\t",$data)));
    }
}
function true_false($data) {
	return (preg_match("/^(true|1)$/i",$data) ? 1 : 0);
}
function ship_date($options=array()) {
	if (!array_key_exists("date",$options)) $options['date']=date("Y/m/d");
	if (!array_key_exists("days",$options)) $options['days']=0;
	if ( (!array_key_exists("holiday",$options)) || (!is_array($options['holiday'])) ) $options['holiday']=array();
	if (!preg_match("/\//",$options['date'])) $options['date']=date("Y/m/d",$options['date']);
    if (!strtotime($options['date'])) return;
	$date=strtotime($options['date'] . " +" . $options['days'] . " days");
    $eof=0;
    while ($eof==0) {
    	$results=date("Y/m/d",$date);
        if ( (date("N",strtotime($results)) < 6) && (!in_array($results,$options['holiday'])) ) {
        	return $results;
		  } else {
          	$date=strtotime("{$results} +1 day");
        }
    }
}
function hex_dump($data) {
    $chars=str_split($data);
	$results="";
    foreach ($chars as $char) {
       $results .= substr("00" . dechex(ord($char)),-2);
    }
    return $results;
}
function fn_elapsed($seconds, $verbose=FALSE, $precision=0) {
    $totals=array();
    $totals['input']=floor($seconds);
    $totals['fraction']=round($seconds - $totals['input'], $precision);
    $totals['days']=floor($totals['input'] / (24 * 60 * 60));
    $totals['hours']=floor(($totals['input'] % (24 * 60 * 60)) / (60 * 60));
    $totals['minutes']=floor(($totals['input'] % (60 * 60)) / 60);
    $totals['seconds']=($totals['input'] % 60);
    $results=array();
    if ($verbose) {
        if ($totals['days']) $results[]=number_format($totals['days'], 0) . " Days";
        if ($totals['hours']) $results[]="{$totals['hours']} Hours";
        if ($totals['minutes']) $results[]="{$totals['minutes']} Minutes";
        $results[]=number_format($totals['seconds'] + $totals['fraction'], $precision) . " Seconds";
        return implode(" ", $results);
      } else {
        if ($totals['days']) $results['days']=$totals['days'];
        switch (TRUE) {
          case ( (!sizeof($results)) && (!$totals['hours']) ):
            break;
          case (!sizeof($results)):
            $results['hours']=$totals['hours'];
            break;
          default:
            $results['hours']=substr("00" . $totals['hours'], -2) ;
        }
        $results['minutes']=(!sizeof($results)) ? $totals['minutes'] : substr("00" . $totals['minutes'], -2);
        $results[]=substr("00" . $totals['seconds'], -2) . ( ($precision) ? substr(number_format($totals['fraction'],$precision),1) : "");
        return implode(":", $results);
    }
}
function parse_object($parent,$die=FALSE) {
	$results=array();
	if ( (is_object($parent)) || (is_array($parent)) )  {
	    foreach ($parent as $parent_name => $parent_child) {
	        $results[]="<ul>Field: <b>$parent_name</b> Type: <b>" . gettype($parent_child) . "</b>";
	        if ( (is_object($parent_child)) || (is_array($parent_child)) ) {
	            $results[]=parse_object($parent_child);
	          } else {
	            $results[]=" Value: <b>$parent_child</b>";
	        }
	        $results[]="</ul>";
	    }
	  } else {
		$results[]="<ul>Type: <b>" . gettype($parent) . "</b> Value: <b>$parent</b></ul>";
	}
    return implode("\n",$results);
}
// Revised 04/26/2023
function stylesheet($files=array()) {
	if (!is_array($files)) $files=array($files);
    $results=array();
    foreach ($files as $file) {
    	$file=trim($file);
        if (!strlen($file)) continue;
		$text=explode("?",$file);
	    $filename=$_SERVER['DOCUMENT_ROOT'] . $text[0];
		if (file_exists($filename)) $results[]=file_get_contents($filename);
    }
    switch (TRUE) {
      case (func_num_args() == 1):
      	break;
      case (is_array(func_get_arg(1))):
      	$results[]=implode("\n",func_get_arg(1));
        break;
      default:
      	$results[]=func_get_arg(1);
	}
	if (sizeof($results)) return "\n<style>" . implode("\n",$results) . "</style>\n";
}
function expiry($timestamp, $unit, $um="days") {
    $date=date_create(date("r",$timestamp));
    date_add($date, date_interval_create_from_date_string($unit . " {$um}"));
    return date_format($date,"U");
}
function password_encoding() {
global $database;
	return ($database->registry->password->encoding) ? "passcode" : "password";
}
function merge_recursive($input, $options=array()) {
	if (!sizeof($input)) return array();
	$obj=new merge_recursive_class($input, $options);
    return $obj->output;
}
class merge_recursive_class {
	var $input=array();
	var $output=array();
    var $separator;
    var $limit;
    var $blank;
    var $counter=0;
	function __construct($input, $options=array()) {
    	$this->input=$input;
    	$this->separator=(array_key_exists("separator",$options) ? $options['separator'] : "");
    	$this->limit=(array_key_exists("limit",$options) ? $options['limit'] : 0);
        $this->iterate($input);
    }
    function iterate($input, $value=array()) {
    	if ( ($this->limit) && ($this->counter >= $this->limit) ) return;
        $this->counter++;
        $i=sizeof($value);
	    $items=array_shift($input);
	    foreach ($items as $item) {
	        $value[$i]=$item;
	        if (sizeof($input)) {
	            $this->iterate($input, $value);
	          } else {
	            $this->output[]=implode($this->separator,$value);
	        }
	    }
    }
}
class email_class {
	var $address;
    var $account;
    var $domain;
    var $error;
    function __construct($email,$forced=FALSE,$domain="") {
		$domains=array();
		switch (TRUE) {
		  case (func_num_args() < 3):
			break;
		  case (is_array($domain)):
			$domains=$domain;
            break;
		  default:
			$domains[]=$domain;
		}
		$this->address=strtolower(trim($email));
        $address_parse=explode("@",$this->address);
        $this->account=$address_parse[0];
        $this->domain=$address_parse[1];
		$domain_parse=explode(".",$this->domain);
        switch (TRUE) {
          case ((!strlen($this->address)) && (!$forced)):
          	break;
          case (!$this->address):
          	$this->error="Email address cannot be blank";
        	break;
          case (preg_match("/[ \/\\\\,?!:;]/",$this->address)):
	        $this->error="Address contains invalid characters";
	        break;
          case (sizeof($address_parse) != 2):
	        $this->error="Invalid email format";
			break;
          case (!strlen($this->account)):
	        $this->error="Account missing";
			break;
          case (!strlen($this->domain)):
          case (!is_array($domain_parse)):
		  case (!strlen($domain_parse[0])):
          case (strlen(end($domain_parse)) < 2):
			$this->error="Invalid domain";
            break;
          case (!preg_match("/^[_a-z0-9-]+(\.[_a-z0-9-]+)*@[a-z0-9-]+(\.[a-z0-9-]+)*(\.[a-z]{2,})$/i",$this->address)):
			$this->error="Email format error";
            break;
          case ( (sizeof($domains)) && (!in_array($this->domain,$domains)) ):
	        $this->error="Address must be in domain " . implode(", ",$domains);
			break;
	    }
    }
}
class page_break_class {
	var $type;
    var $name;
    var $visible=1;
    var $found_rows=0;
    var $page=0;
    var $item=0;
    var $url;
    var $return_url;
    var $current=0;
    var $last=0;
    function __construct($type, $page, $item) {
        global $database, $forms;
		$this->type=($type != "item") ? "page" : "item";
        $this->page=intval($page);
        $this->item=intval($item);
    }
    function limit() {
        global $database;
    	switch (TRUE) {
          case (!$database->query_limit):
          	return;
          case ($this->type == "page"):
          	return "limit " . ($this->page * $database->query_limit) . ", " . $database->query_limit;
          default:
          	return "limit " . ( ($this->page * $database->query_limit) + $this->item) . ", 1";
		}
    }
    function output($found_rows, $options=array()) {
        global $database, $forms;
        $this->found_rows=intval($found_rows);
        if (!$this->found_rows) return;
        switch (TRUE) {
          case ($options['name']):
        	$this->name=$options['name'];
          	break;
          case ($this->type == "page"):
        	$this->name="Records";
          	break;
          default:
        	$this->name="Record";
		}
        $this->script=($GLOBALS['fn_page_item']) ? $GLOBALS['fn_page_item'] : "fn_page_item";
        if (!$database->query_limit) return ($this->visible) ? "<p style='text-align: center'>" . number_format($this->found_rows) . " " . $this->name . " Found</p>" : "";
		$this->buttons=new stdClass();
        switch (TRUE) {
          case (isset($options['url'])):
        	$this->url=$options['url'];
            break;
          case ($this->type == "page"):
        	$this->url="{$this->script}('page',%page%,0);";
            break;
          default:
        	$this->url="{$this->script}('item',%page%,%item%);";
		}
        switch (TRUE) {
          case ($his->type == "page"):
          	break;
          case (isset($options['return_url'])):
        	$this->return_url=$options['return_url'];
            break;
          case ($options['return']):
        	$this->return_url="{$this->script}('page',%page%,0);";
		}
		$this->button=new stdClass();
        $this->button("first","<< First");
        $this->button("first","<< First");
        $this->button("prior","< Previous");
        $this->button("return","Return");
        $this->button("next","Next >");
        $this->button("last","Last >>");
        if ($this->type == "page") {
    		$this->current=$this->page;
	        $this->last=(ceil($this->found_rows / $database->query_limit)) - 1;
		  } else {
    		$this->current=($this->page * $database->query_limit) + $this->item;
            $this->last=$this->found_rows - 1;
		}
        $this->button->first->value=(!$this->current) ? -1 : 0;
        $this->button->prior->value=(!$this->current) ? -1 : $this->current - 1;
        $this->button->return->value=$this->current;
        $this->button->next->value=($this->current == $this->last) ? -1 : $this->current + 1;
        $this->button->last->value=($this->current == $this->last) ? -1 : $this->last;
        if (!$this->button->return->url) unset($this->button->return);
        $buttons=array();
        foreach ($this->button as $field => $obj) {
        	switch (TRUE) {
              case ($obj->value == -1):
            	$obj->url="";
                break;
              case ($this->type == "page"):
              	$obj->page=$obj->value;
                break;
              default:
                $obj->page=floor($obj->value / $database->query_limit);
              	$obj->item=($obj->value % $database->query_limit);
            }
            $obj->url=str_replace(array("%page%","%item%"),array($obj->page,$obj->item),$obj->url);
            $button_options=array();
            if ($obj->url) {
            	$button_options['onclick']=$obj->url;
            	$button_options['class']="nav_button";
			  } else {
            	$button_options['class']="nav_button_disabled";
			}
            $buttons[]=$forms->button($obj->name, $button_options);
        }
        $results=array();
	    $text=array();
        if ( ($this->last) || (property_exists($this->button,"return")) ) $results[]=implode(" ",$buttons);
        if ( ($this->type == "page") && ($this->found_rows > 1) ) $results[]=number_format($this->found_rows). " " . $this->name . " Found";
        if ( ($this->type == "item") || ($this->last) ) {
	        $text[]=($this->type == "page") ? "Page" : $this->name;
	        $text[]=number_format($this->current + 1);
	        $text[]="of";
	        $text[]=number_format($this->last + 1);
		}
		if (sizeof($text)) $results[]=implode(" ",$text);
        return ( ($this->visible) && (sizeof($results)) ) ? "<p style='text-align: center'>" . implode("<br>", $results) . "</p>" : "";
    }
    function button($field, $name) {
    	$obj=new stdClass();
        $obj->name=$name;
        $obj->url=($field == "return") ? $this->return_url : $this->url;
        $obj->page=0;
        $obj->item=0;
        $this->button->$field=$obj;
	}
}


class page_item_break {
    var $found_rows;
    var $url;
    var $page=0;
    var $item=0;
    var $first=0;
    var $last=0;
    var $current=0;
    var $query_limit;
	var $comment=array();
    var $items=array();
    var $debug=FALSE;
	function __construct($found_rows,$url="",$options=array()) {
	global $database, $forms;
		$this->debug=$forms->debug;
		if ($this->debug) scs_function_debug(__FILE__,__METHOD__,func_num_args(),func_get_args());
        $this->found_rows=$found_rows;
		$this->url=$url;
        if (array_key_exists("debug",$options)) {
        	$this->query_limit=3;
		  } else {
        	$this->query_limit=$database->query_limit;
		}
	    $this->items["First"]=array("text"=>"<< First");
	    $this->items["Previous"]=array("text"=>"< Previous");
	    $this->items["Return"]=array("text"=>"");
	    $this->items["Next"]=array("text"=>"Next >");
	    $this->items["Last"]=array("text"=>"Last >>");
    }
    function return_url($text,$url,$item=0) {
    	switch (TRUE) {
          case (!$this->query_limit):
          	break;
          case (func_num_args() == 3):
          	$url=preg_replace(array("/%page%/i","/%item%/i"),array(floor($item / $this->query_limit),$item),$url);
		}
	    $this->items["Return"]=array("text"=>$text,"url"=>$url);
    }
    function page($page,$comment="") {
    	switch (TRUE) {
          case (!$this->query_limit):
          	return number_format($this->found_rows) . " Records Found";
          case (!$this->url):
          	$this->url="{$this->script}('list',%page%,0);";
		}
		$this->page=$page;
	    if (!$this->page) $this->page=1;
        $this->comment[1]=number_format($this->found_rows) . " Records Found";
		if ($comment) $this->comment[3]=$comment;
        $pages=ceil($this->found_rows / $this->query_limit);
    	return $this->results("Page",$this->page,$pages);
	}
    function item($item,$comment="") {
		if ($forms->debug) scs_function_debug(__FILE__,__METHOD__,func_num_args(),func_get_args());
		if (!$this->url) $this->url="{$this->script}('list',0,%item%);";
		switch (TRUE) {
          case ($item < 1):
          	$this->item=1;
            break;
          case ($item > $this->found_rows):
          	$this->item=$this->found_rows;
            break;
          default:
			$this->item=$item;
		}
		$this->page=(floor($this->item / $this->query_limit) + 1);
		$this->items['Return']['url']=str_replace("%page%",$this->page,$this->items['Return']['url']);
        if ($comment) $this->comment[0]=$comment;
        return $this->results("Record",$this->item,$this->found_rows);
    }
    private function results($title,$current,$last) {
	global $forms;
	    if ($current > 1) {
	        $this->items['First']['item']=1;
	        $this->items['Previous']['item']=$current - 1;
	    }
	    if ($last > $current) {
	        $this->items['Next']['item']=$current + 1;
	        $this->items['Last']['item']=$last;
	    }
		$button_text="";
		$button=FALSE;
	    foreach ($this->items as $field => $item) {
	        switch (TRUE) {
	          case ($item['url']):
	            $url=$item['url'];
	            break;
              case (!isset($item['item'])):
	          case ($item['item'] < $first):
	          case ($item['item'] > $last):
	          case ($item['item'] == $current):
	            $url="";
	            break;
	          default:
				$url=preg_replace(array("/%item%/i","/%25item%25/i"),$item['item'],$this->url);
	        }
            $options=array();
	        if ($url) {
            	$button=TRUE;
            	$options["onclick"]=preg_replace(array("/%item%/i","/%25item%25/i","/%page%/i","/%25page%25/i"),$item['item'],$url);
			  } else {
				$options["class"]="nav_button_disabled";
            }
			if ($item['text']) $button_text .= $forms->button($item['text'],$options);
	    }
        $results=array();
		$results[]="<p class='standard center'>";
		if ($button) {
        	$results[]=$button_text;
			$this->comment[2]="$title " . number_format($current) . " of " . number_format($last);
		}
        ksort($this->comment);
        foreach ($this->comment as $comment) {
			$results[]="<br>$comment";
        }
		$results[]="</p>";
		return implode("\n",$results);
	}
}
class menu_page {
    var $name;
    var $title;
	var $page;
    var $page_target;
    var $url_query=array();
    var $href_options=array();
    var $url;
    var $href;
    var $icon_small;
    var $icon_small_href;
    var $icon_large;
    var $icon_large_href;
    function __construct($name,$page,$url_query=array(),$href_options=array(),$target="") {
        $this->name=$name;
		$this->page=$page;
		$this->url_query=$url_query;
        $this->page_target=$target;
        $this->href_options=$href_options;
		if (!array_key_exists("class",$this->href_options)) $this->href_options['class']="nobr";
        $this->build();
    }
    function name($name) {
		$this->name=$name;
        $this->build();
    }
    function title($title) {
		$this->title=$title;
    }
    function page($page,$url_query=array(),$href_options=array()) {
		$this->page=$page;
		$this->url_query=$url_query;
        $this->href_options=$href_options;
        $this->build();
    }
    function icon($icon,$img_options=array(),$large=TRUE) {
    global $forms;
		if (!isset($img_options['title'])) $img_options['title']=$this->name;
		if ($large) {
			$this->icon_large=$forms->img($icon,$img_options);
		  } else {
			$this->icon_small=$forms->img($icon,$img_options);
		}
	    $this->build();
    }
    private function build() {
    global $menu;
        switch (TRUE) {
          case preg_match("/^javascript:/", $this->page):
        	$this->url=$this->page;
			break;
          case preg_match("/^http:/", $this->page):
          case preg_match("/^https:/", $this->page):
        	$this->url=$this->page;
			break;
          default:
			$this->url=fn_url($this->page,$this->url_query);
		}
        $this->href=fn_href($this->name,$this,array(),$this->href_options);
        if ($this->icon_small) {
			$this->icon_small_href=fn_href($this->icon_small . " " . $this->name,$this,array(),$this->href_options);
		  } else {
          	$this->icon_small_href="";
        }
        if ($this->icon_large) {
			$options['title']=$this->name;
			$this->icon_large_href=fn_href($this->icon_large,$this,array(),$this->href_options);
		  } else {
          	$this->icon_large_href="";
        }
    }
}
class menu_group_class {
	var $items=array();
    var $group;
    var $group_class;
    var $href_class;
    function __construct($group_class="standard left",$href_class="nobr") {
    	$this->group_class=$group_class;
    	$this->href_class=$href_class;
    }
	function group($group) {
    	$this->group=$group;
    }
	function item($access,$name,$page,$url_query=array(),$href_options=array()) {
	global $database;
		if ( ($this->href_class) && (!array_key_exists("class",$href_options)) ) $href_options['class']=$this->href_class;
		if ($database->user->access($access,FALSE)) $this->items[$this->group][]=new menu_page($name,$page,$url_query,$href_options);
	}
    function output() {
		$results=array();
	    foreach ($this->items as $group => $item_list) {
            if (sizeof($item_list)) {
	    		$items=array();
	            foreach ($item_list as $item) {
	                $items[]=$item->href;
	            }
				$results[]="<p class='" . $this->group_class . "'>";
                $results[]="<span style='font-size: 140%;'>$group</span><br>";
                $results[]=implode("\n | ",$items);
                $results[]="</p>";
			}
	    }
		return implode("\n",$results);
    }
}
class preg_replace_class {
	var $filter=array();
	var $post_filter=array();
	var $pattern=array();
    var $replacement=array();
    function __construct($post_filter=FALSE,$additional_filters=array() ) {
		$this->filter['crlf']="Hard return (CR/LF) to Line Break (&lt;BR&gt;)";
		$this->filter['msword']="Microsoft Word";
		$this->filter['wordpress']="Wordpress";
		if ( ($_SERVER['REQUEST_METHOD']=="POST") && (sizeof($_POST)) ) {
	        foreach ($_POST as $key=>$value) {
	            if (substr($key,0,15)=="replace_filter_") $this->post_filter[]=substr($key,15);
	        }
        }
        switch (TRUE) {
          case (is_array($additional_filters)):
	        foreach ($additional_filters as $filter) {
	        	$this->post_filter[]=$filter;
	        }
            break;
          case (strlen($additional_filters)):
	    	$this->post_filter[]=$additional_filters;
            break;
		}
        foreach (array_unique($this->post_filter) as $filter) {
			if (method_exists($this,$filter)) $this->$filter();
        }
    }
	function crlf() {
		$this->load("/<br>\n|<br>\r\n|\r\n|\r|\n/","<br>\n");
    }
	function msword() {
		$this->load("/\xe2\x80\x93/","-");
		$this->load("/\xe2\x80\x98/","'");
		$this->load("/\xe2\x80\x99/","'");
		$this->load("/\xe2\x80\x9c/",'"');
		$this->load("/\xe2\x80\x9d/",'"');
		$this->load("/\xe2\x80\xa2/","&bull;");
		$this->load("/\xe2\x84\xa2/","&trade;");
		$this->load("/\xc2\x96/","-");
		$this->load("/\xc2\xa9/","&copy;");
		$this->load("/\xc2\xa2/","&cent;");
		$this->load("/\xc2\xae/","&reg;");
		$this->load("/\xc2\xb0/","&deg;");
		$this->load("/\xc2\xbc/","1/4");
		$this->load("/\xc2\xbd/","1/2");
		$this->load("/\xc2\xbe/","3/4");

		$this->load("/\t/"," ");
		$this->load("/\x91/","'");
		$this->load("/\x92/","'");
		$this->load("/\x93/",'"');
		$this->load("/\x94/",'"');
		$this->load("/\x96/","-");
		$this->load("/\x97/","-");
    }
	function wordpress() {
		$this->load("/[\x0a|\xa0]/","<br>");
		$this->load("/[\x80-\xff]/"," ");
    }
    function load($pattern,$replacement,$delimit_pattern=FALSE,$ignore_case=FALSE) {
		switch (TRUE) {
          case (!$pattern):
          	return;
          case ($delimit_pattern):
			$pattern="/" . preg_quote($pattern,"/") . "/";
            if ($ignore_case) $pattern .= "i";
			break;
		}
		if (!in_array($pattern,$this->pattern) ) {
	        $this->pattern[]=$pattern;
	        $this->replacement[]=$replacement;
		}
    }
    function filter($text) {
		if ( (sizeof($this->pattern)) && (strlen($text)) ) {
        	$results=preg_replace($this->pattern,$this->replacement,trim($text));
		  } else {
          	$results=trim($text);
		}
        return $results;
    }
    function filter_html() {
    global $forms;
		$results=array();
	    foreach ($this->filter as $key => $value) {
	        $results[]=$forms->checkbox("replace_filter_" . $key,1,in_array($key,$this->post_filter)) . " $value";
	    }
        return $results;
    }
}
class file_upload_class {
	var $field;
    var $fatal=FALSE;
    var $name;
    var $filetype;
    var $size=0;
    var $tmp_name;
    var $filename;
    var $url;
    var $url_backup;
    var $image_type;
	var $image_allowed=array();
    var $imagesize=array();
    var $error;
    var $error_num=0;
    function __construct($field,$options=array()) {
    	$this->field=$field;
    }
    function load() {
		switch (TRUE) {
          case (!strlen($this->field)):
          	$this->error("Field name required");
            return TRUE;
          case (!array_key_exists($this->field,$_FILES)):
          	$this->error("Field " . $this->field . " not found in FILES");
            return;
		}
        $this->name=$_FILES[$this->field]['name'];
        $this->tmp_name=$_FILES[$this->field]['tmp_name'];
        $this->filetype=strtolower(end(preg_split("/\./",$this->name)));
        $this->size=intval($_FILES[$this->field]['size']);
        $this->error_num=intval($_FILES[$this->field]['error']);
		switch (TRUE) {
          case (!strlen($this->name)):
          	return;
          case ($this->error_num):
			$this->error("Upload error #" . $this->error_num,$this->error_num);
            return TRUE;
		}
    }
    function image($options=array()) {
		$this->fatal=$this->load();
        switch (TRUE) {
          case ($this->error):
          	return;
          case (!$this->tmp_name):
          	return TRUE;
		}
	    if (!array_key_exists("size_max",$options)) $options['size_max']=0;
        if (array_key_exists("width",$options)) {
	        $options['width_min']=$options['width'];
	        $options['width_max']=$options['width'];
          } else {
	        if (!array_key_exists("width_min",$options)) $options['width_min']=0;
	        if (!array_key_exists("width_max",$options)) $options['width_max']=0;
		}
        if (array_key_exists("height",$options)) {
	        $options['height_min']=$options['height'];
	        $options['height_max']=$options['height'];
          } else {
	        if (!array_key_exists("height_min",$options)) $options['height_min']=0;
	        if (!array_key_exists("height_max",$options)) $options['height_max']=0;
		}
        if ( (!array_key_exists("deny",$options)) || (!is_array($options['deny'])) ) $options['deny']=array();
		$allowed_type=array();
	    if (!array_key_exists("gif",$options['deny'])) $this->image_allowed["gif"]=IMAGETYPE_GIF;
	    if (!array_key_exists("jpg",$options['deny'])) $this->image_allowed["jpg"]=IMAGETYPE_JPEG;
	    if (!array_key_exists("png",$options['deny'])) $this->image_allowed["png"]=IMAGETYPE_PNG;
        if (!function_exists("exif_imagetype")) {
			if (!in_array($this->filetype,array_keys($this->image_allowed))) {
            	 $this->error($this->filetype . " file type not supported");
                 return;
            }
          } else {
			$this->image_type=exif_imagetype($this->tmp_name);
	        switch (TRUE) {
	          case (!$this->image_type):
	            $this->error($this->name . " is not an image");
	            return;
	          case (!in_array($this->image_type, $this->image_allowed)):
	            $this->error($this->name . " file type not supported");
	            return;
	        }
	        $this->filetype=array_search($this->image_type, $this->image_allowed);
	        $this->imagesize=getimagesize($this->tmp_name);
	        switch (TRUE) {
	          case ( ($options['size_max']) && ($this->size > $options['size_max'] )):
	            $this->error("Image size " . $this->size . " > " . $options['size_max']);
	            return;
	          case ($this->imagesize[0] < $options['height_min']):
	            $this->error("Image width " . $this->imagesize[0] . " < " . $options['height_min']);
	            return;
	          case ( ($options['height_max']) && ($this->imagesize[0] > $options['height_max'] )):
	            $this->error("Image width " . $this->imagesize[0] . " > " . $options['height_max']);
	            return;
	          case ($this->imagesize[0] < $options['width_min']):
	            $this->error("Image width " . $this->imagesize[1] . " < " . $options['width_min']);
	            return;
	          case ( ($options['width_max']) && ($this->imagesize[1] > $options['width_max'] )):
	            $this->error("Image width " . $this->imagesize[1] . " > " . $options['width_max']);
	            return;
	        }
		}
        return TRUE;
    }
    function save($filename, $filename_backup="", $options=array()) {
    	if ( ($this->error) || (!$filename) ) return;
        $this->filename=$filename;
        if ($options['url']) {
        	$this->url=$filename;
            $this->url_backup=$filename_backup;
		  } else {
	        $this->url=$_SERVER['DOCUMENT_ROOT'] . $filename;
	        if ($filename_backup) $this->url_backup=$_SERVER['DOCUMENT_ROOT'] . $filename_backup;
		}
	    if ($this->url_backup) {
	        if (file_exists($this->url_backup)) unlink($this->url_backup);
	        if (file_exists($this->url)) rename($this->url,$this->url_backup);
		}
 		@move_uploaded_file($_FILES[$this->field]['tmp_name'],$this->url);
    }
    function error($error,$error_no=0) {
    global $forms;
		$this->error=$error;
        $this->error_no=$error_no;
        $forms->error($this->field,"",$this->error);
    }
}
class stopwatch_class {
	var $name;
    var $comment=array();
    var $bg;
    var $precision=0;
    var $start=0;
    var $stop=0;
    var $elapsed=0;
    var $splits=array();
    function __construct($name,$precision=2,$comment="") {
    	$this->start=microtime(TRUE);
		$this->name=$name;
        $this->precision=intval($precision);
        $this->comment($comment);
        $this->split("Initialize");
    }
    function split($name,$comment="") {
    	$this->split_stop();
		$this->split=new stopwatch_splt_class($name,$comment);
    }
    function stop($comment="") {
    	$this->split_stop();
        $this->comment($comment);
    	$this->stop=microtime(TRUE);
        $this->elapsed=$this->stop - $this->start;
    }
    function split_stop() {
    	if (!isset($this->split)) return;
        $this->split->stop();
        $this->splits[]=$this->split;
        unset($this->split);
    }
    function comment($comment) {
    	switch (TRUE) {
          case (is_string($comment)):
		  	if (strlen($comment)) $this->comment[]=$comment;
            break;
          case (is_array($comment)):
			if (sizeof($comment)) $this->comment[]=array_merge($this->comment,$comment);
            break;
          default:
			$this->comment[]="<pre>" . print_r($comment,TRUE) . "</pre>";
        }
    }
    function split_comment($comment) {
		if (isset($this->split)) $this->split->comment($comment);
    }
    function bg($bg) {
		$this->bg=$bg;
    }
    function json() {
		if (!$this->stop) $this->stop();
    	$results=array();
		$results["total"]=$this->json_results($this);
        foreach ($this->splits as $item => $split) {
            $results["split{$item}"]=$this->json_results($split);
        }
        return $results;
    }
    function json_results($split) {
    	$results=array();
		$results['name']=$split->name;
        if (sizeof($split->comment)) $results['comment']=$split->comment;
		$results['start']=$this->time($split->start);
		$results['stop']=$this->time($split->stop);
		$results['elapsed']=number_format($split->elapsed,$this->precision);
        $results['verbose']=fn_elapsed($split->elapsed, TRUE, $this->precision);
        return $results;
	}
    function results($verbose=FALSE) {
		if (!$this->stop) $this->stop();
    	$results=array();
        $results[]="<table class='small border'>";
        $results[]="<tr>";
        $results[]="<th width='10%'>Name</th>";
        $results[]="<th width='10%'>Elapsed</th>";
        if ($verbose) {
	        $results[]="<th width='20%'>Start</th>";
	        $results[]="<th width='20%'>Stop</th>";
        }
        $results[]="<th width='" . (($detail) ? "40%" : "80%") . "'>Comment</th>";
        $results[]="</tr>";
		$results[]=$this->split_results($this,$verbose);
        $results[]="<tr><th colspan=" . (($verbose) ? 5 : 3) . " style='text-align: left;'>Splits</th></tr>";
        foreach ($this->splits as $split) {
            $results[]=$this->split_results($split,$verbose);
        }
        $results[]="</table>";
        return $results;
    }
    function split_results($split,$verbose) {
    	$results=array();
        $results[]="<tr {$split->bg}>";
        $results[]="<td class='nobr'>" . (is_array($split->name) ? implode(": ",$split->name) : $split->name) . "</td>";
        $results[]="<td class='nobr right'>" . fn_elapsed($split->stop - $split->start, $verbose, $this->precision) . "</td>";
        if ($verbose) {
	        $results[]="<td class='nobr center'>" . $this->time($split->start) . "</td>";
	        $results[]="<td class='nobr center'>" . $this->time($split->stop) . "</td>";
        }
        $results[]="<td>" . (is_array($split->comment) ? implode("<br>",$split->comment) : $split->comment) . "</td>";
        $results[]="</tr>";
        return implode("",$results);
    }
    private function time($time) {
    	$results=array(date("m/d/Y H:i:s",$time));
        if ($this->precision) $results[]=substr("000000000" . floor(pow(10,$this->precision) * ($time - floor($time))), ($this->precision * -1));
        return implode(".",$results);
    }
}
class stopwatch_splt_class {
	var $name;
    var $comment=array();
    var $bg;
    var $start=0;
    var $stop=0;
    var $elapsed=0;
    function __construct($name,$comment="") {
    	$this->start=microtime(TRUE);
		$this->name=$name;
        $this->comment($comment);
    }
    function stop($comment="") {
    	$this->stop=microtime(TRUE);
        $this->elapsed=$this->stop - $this->start;
        $this->comment($comment);
    }
    function comment($comment) {
    	switch (TRUE) {
          case (is_string($comment)):
		  	if (strlen($comment)) $this->comment[]=$comment;
            break;
          case (is_array($comment)):
			if (sizeof($comment)) $this->comment=array_merge($this->comment,array_values($comment));
            break;
          default:
			$this->comment[]="<pre>" . print_r($comment,TRUE) . "</pre>";
        }
    }
    function bg($bg) {
    	$this->bg=$bg;
    }
}
class verify_url_class {
	var $url;
    var $scheme;
    var $host;
    var $path;
    var $query=array();
    var $filter=array();
    var $error;
	function __construct($url,$options=array()) {
		$this->url=trim($url);
        if ( (!strlen($this->url)) && (!$options['host']) ) return;
    	$parse_url=parse_url($this->url);
		switch (TRUE) {
          case (array_key_exists("scheme",$parse_url)):
        	$this->scheme=$parse_url['scheme'];
            $this->host=$parse_url['host'];
            $this->path=$parse_url['path'];
            break;
          case (strlen($parse_url['path'])):
			list($this->host,$this->path)=explode("/",$parse_url['path'],2);
            break;
		}
        $errors=array();
		if ( ($options['scheme']) && (!strlen($this->scheme))) $errors[]="Scheme [http|https] missing";
        switch (TRUE) {
          case (!strlen($this->host)):
          	$errors[]="Host missing";
			break;
          case (!preg_match("/^(www\.)?[a-z0-9]+([\-\.]{1}[a-z0-9]+)*\.[a-z]{2,5}(:[0-9]{1,5})?(\/.*)?$/i",$this->host));
          	$errors[]="Invalid host name";
            break;
		}
		if ( ($options['path']) && (!strlen($this->path))) $errors[]="Path missing (example index.htm)";
        if (sizeof($errors)) $this->error=implode(", ",$errors);
    }
}
class map_field_class {
    var $field;
    var $name;
    var $type;
    function __construct($field, $name, $type, $default_type="alphanumeric") {
        $this->field=$field;
        $this->name=$name;
        $this->type=(strlen($type)) ? $type : $default_type;
        $code=explode(":", $type);
        switch ($code[0]) {
          case "decimal":
            $this->decimal=$code[1];
            break;
          case "fixed":
            $this->fixed=$code[1];
            break;
        }
/* Allowed by export
		$type_list["alphanumeric"]="Alphanumeric text";
		$type_list["lowercase"]="Alphanumeric lower case text";
		$type_list["email"]="Email address";
		$type_list["uppercase"]="Alphanumeric upper case text";
		$type_list["text"]="Multiple line alphanumeric text";
		// Date / Time
		$type_list["date"]="Date (MM/DD/YYYY)";
		$type_list["period"]="Period (MM/YYYY)";
		$type_list["time"]="Time (HH:MM)";
		$type_list["timestamp"]="Timestamp (MM/DD/YYYY HH:MM:SS)";
        // Numeric
		$type_list["numeric"]="Number with floating decimal";
		$type_list["decimal"]="Number Decimal places:  ";
		$type_list["fixed"]="Numeric Digits: ";
		$type_list["mixed"]="Mixed Alphanumeric or Decimal places: ";
		$type_list["id"]="Unique record ID (integer)";
		$type_list["percent"]="Percent/Decimal places:  ";
		$type_list["boolean"]="True: Y, 1; False: N, 0";
        // Special
		$type_list["ipv4"]="IPV4 address";
		$type_list["ipv6"]="IPV6 address";
*/
    }
    function value($data) {
        if (!is_array($data)) $data=(array) $data;
        switch (TRUE) {
          case (!array_key_exists($this->field, $data)):
            return "";
          case ($this->type == "uppercase"):
            return strtoupper($data[$this->field]);
          case ($this->type == "lowercase"):
            return strtolower($data[$this->field]);
          case ($this->type == "boolean"):
            return preg_match("/^[1,Y]/i",$data[$this->field]);
          case (property_exists($this, "decimal")):
            return number_format($data[$this->field],$this->decimal,".","");
          case (property_exists($this, "fixed")):
            return substr("000000000" . intval($data[$this->field]), (-1 * $this->fixed));
          default:
            return $data[$this->field];
        }
    }
}
class address_class {
	var $version=20250218;
	var $table;
    var $name;
    var $data;
//    var $registry;
    var $first_last;
    var $fields=array();
    var $omit=array();
    var $width=array();
    var $options;
    var $error=array();
    var $trace=array();
    function __construct($table, $data, $options=array()) {
        global $database;
        $this->trace[]=__FUNCTION__;
        if (!strlen($table)) die("Class: " . __CLASS__ . ", table name blank");
        $this->name=$options['name'];
    	if ($options['create']) {
	        if (!isset($database->registry->address)) $database->registry->address=new stdClass();
	        if (!isset($database->registry->address->$table)) $database->registry->address->$table=array();
		}
        if ( (!property_exists($database->registry,"address")) || (!property_exists($database->registry->address, $table)) ) die("Class: " . __CLASS__ . ", Table {$table} not defined");
        $this->table=$table;
        $this->fields["company_name"]="Company Name";
        $this->fields["name"]="Name";
        $this->fields["title"]="Title";
        $this->fields["address"]="Address";
        $this->fields["city"]="City";
        $this->fields["state"]="State/Province";
        $this->fields["zip"]="Zip/Postal Code";
        $this->fields["country_code"]="Country";
        $this->fields["phone"]="Phone";
        $this->fields["fax"]="Fax";
        $this->fields["email"]="Email";
    	$this->options_default();
        $this->options($options);

        if (!property_exists($this,"registry")) $this->registry=(object) $database->registry->address->$table;
        if ($table == "user") {
        	$this->registry->email="required";
        	$this->registry->password="required";
			if ( (isset($_SESSION['myaccount'])) && ($_SESSION['myaccount']['password']) ) $database->user->fetch[password_encoding()]="";
            if ($_SESSION['user']->id) $this->registry->password_change="allowed";
			if ($database->registry->login->auto) $this->registry->auto_login="allowed";
			if ($database->registry->login->password_compare) $this->registry->password_compare="required";
        }
        $this->data=( (isset($options['data'])) ? $this->object($options['data']) : $data);
        switch (TRUE) {
          case (isset($options['first_last'])):
          case (!property_exists($database,$table)):
			$this->first_last=$options['first_last'];
            break;
		  case ( (property_exists($database->$table->data,"name_first")) && (property_exists($this->data,"name_last")) ):
			$this->first_last=TRUE;
            break;
          default:
			$this->first_last=FALSE;
		}
    }
    function options_default() {
        $this->trace[]=__FUNCTION__;
		$this->options=new stdClass;
        $this->options->omit=array();
        $this->options->width=array("field"=>"width='20%'","value"=>"width='80%'");
		$this->options->legend=TRUE;
		$this->options->maintain=FALSE;
		$this->options->password=($_SESSION['user']->id) ? TRUE : FALSE;
        $this->options->address_textarea=FALSE;
        $this->options->address_js=TRUE;
        $this->options->tr=array();
        $this->options->td=array();
        $this->options->class="";
        $fields=array_keys($this->fields);
        if ($this->table == "user") array_unshift($fields, "email");
        $this->options_order($fields);
        $this->input=$_POST;
    }
    function options($options) {
        $this->trace[]=__FUNCTION__;
		if (  (array_key_exists("default",$options)) && ($options['default']) )  $this->options_default();
		if ( (array_key_exists("omit",$options)) && (is_array($options['omit'])) ) $this->options->omit=$options['omit'];
		if ( (array_key_exists("width",$options)) && (is_array($options['width'])) ) {
        	$this->options->width=array();
	        foreach ($options['width'] as $field => $width) {
				if (in_array($field, array("required","field","value"),TRUE)) $this->options->width[$field]="width='" . ((is_numeric($width)) ? "{$width}%" : $width) . "'";
			}
		}
		if (array_key_exists("legend",$options)) $this->options->legend=$options['legend'];
		if (array_key_exists("maintain",$options)) $this->options->maintain=$options['maintain'];
		if (array_key_exists("password",$options)) $this->options->password=$options['password'];
		if (array_key_exists("address_js",$options)) $this->options->maintain=$options['address_js'];
		if (array_key_exists("address_textarea",$options)) $this->options->address_textarea=$options['address_textarea'];
		if (array_key_exists("class",$options)) $this->options->class=$options['class'];
		if ( (array_key_exists("tr",$options)) && (is_array($options['tr'])) ) $this->options->tr=$options['tr'];
		if ( (array_key_exists("td",$options)) && (is_array($options['td'])) ) $this->options->td=$options['td'];
		if ( (array_key_exists("registry",$options)) && (is_object($options['registry'])) ) $this->registry=$options['registry'];
		if (array_key_exists("input",$options)) $this->input=$options['input'];
        if (array_key_exists("order", $options)) $this->options_order( (is_array($options['order']) ? $options['order'] : array($options['order']) ) );
    }
    function options_order($fields) {
        $this->trace[]=__FUNCTION__;
        $this->options->order=array();
        foreach ($fields as $field) {
            if ( (array_key_exists($field, $this->fields)) && (!in_array($field, $this->options->order)) ) $this->options->order[]=$field;
        }
        foreach ($this->fields as $field => $name) {
            if (!in_array($field, $this->options->order)) $this->options->order[]=$field;
        }
    }
    function get_options($placeholder="") {
        $this->trace[]=__FUNCTION__;
        $results=$this->options->td;
        if (strlen($this->options->class)) $results['class']=$this->options->class;
        if (strlen($placeholder)) $results["placeholder"]=$placeholder;
        return $results;
    }
    function object($first_last) {
        $this->trace[]=__FUNCTION__;
    	$results=new stdClass();
        if ($first_last) {
			$results->name_first="";
			$results->name_last="";
        }
		foreach ($this->registry as $field => $required) {
        	$results->$field="";
        }
        return $results;
    }
    function load($data) {
        $this->trace[]=__FUNCTION__;
    	foreach ($this->fields as $field => $name) {
			$this->data->$field=( (property_exists($this->registry, $field)) ? $data->$field : "");
        }
    }
    function fetch($fetch, $field_remap=FALSE) {
        $this->trace[]=__FUNCTION__;
        /* $field_remap use when address object is embedded in record object */
        foreach ($this->fields as $field => $name) {
            $import_field=($field_remap) ? $field : $this->field($field);
			$this->data->$field=( (property_exists($this->registry, $field)) ? $fetch[$import_field] : "");
        }
    }
    function verify($options=array()) {
        global $database, $forms;
        $this->trace[]=__FUNCTION__;
    	$this->options($options);
		$this->verify=$this->registry;
		if (isset($options['mandatory'])) {
        	foreach ($this->verify as $key => $value) {
            	switch (TRUE) {
                  case ($key == "country_code"):
                  	break;
                  case ( ($value == "required") && (!in_array($key,$options['mandatory'])) ):
                  	$this->verify->{$key}="allowed";
                  	break;
				}
            }
        }
        $this->data->country_code=fn_text($this->input[$this->field('country_code')]);
        switch ($this->data->country_code) {
          case "US":
            $state_field="state_us";
            break;
          case "CA":
            $state_field="state_ca";
            break;
          default:
            $state_field="state_other";
        }
		$this->verify_field("company_name");
		if ($this->first_last) {
            $this->verify_field("name","name_first");
            $this->verify_field("name","name_last");
            $this->data->name=trim($this->data->name_first . " " . $this->data->name_last);
		  } else {
            $this->verify_field("name");
		}
		$this->verify_field("title");
		$this->verify_field("address");
		$this->verify_field("city");
		$this->verify_field("state","state",$state_field);
		$this->verify_field("zip");
		$this->verify_field("phone");
		$this->verify_field("fax");
		$this->verify_field("email");
        if ( ($this->table=="user") && (!$this->options->maintain) ) $this->verify_user();
    }
    private function password_fetch() {
        global $database;
		return $database->user->fetch[ ($database->registry->password->encoding) ? "passcode" : "password" ];
    }
    private function verify_user() {
        global $database, $forms;
        $this->trace[]=__FUNCTION__;
        $password_change=($this->options->password) ? $_POST['password_change'] : 1;
	    if ($password_change) {
	        $database->user->new_password=trim($_POST['password']);
            switch (TRUE) {
          	  case ($database->user->new_password == $database->user->constant->password):
          	  case (!strlen($database->user->new_password)):
              	$forms->error("password","Not allowed");
                break;
          	  case (strlen($database->user->new_password) < $database->registry->password->minimum):
              	$forms->error("password","Password too short");
                break;
            }
		}
	    if ( ($password_change) && ($database->registry->login->password_compare) ) {
	        $database->user->password_compare=trim($_POST['password_compare']);
            switch (TRUE) {
          	  case ($database->user->password_compare == $database->user->constant->password):
          	  case (!strlen($database->user->password_compare)):
              	$forms->error("password_compare","Not allowed");
                break;
          	  case (strlen($database->user->password_compare) < $database->registry->password->minimum):
              	$forms->error("password_compare","Password too short");
                break;
          	  case (strlen($database->user->password_compare <> $database->user->new_password)):
              	$forms->error("password_compare","Passwords do not match");
                break;
            }
		}
	    $database->user->data->auto_login=$_POST['auto_login'];
    }
    private function verify_field($field, $data_field="", $input_field="") {
        global $forms;
        if (!$this->verify->$field) {
        	$this->data->$field="";
        	return;
		}
        if (!$data_field) $data_field=$field;
        if (!$input_field) $input_field=$data_field;
        switch ($field) {
          case "zip":
        	$this->data->$data_field=fn_text($this->input[$this->field($input_field)],TRUE);
            break;
          case "email":
			$email=new email_class($this->input[$this->field($input_field)],( ($this->verify->{$field} == "required") ? TRUE : FALSE));
            $this->data->$field=$email->address;
          	break;
          default:
        	$this->data->$data_field=fn_text($this->input[$this->field($input_field)]);
		}

        switch (TRUE) {
          case ( ($field == "email") && ($email->error) ):
			$forms->error($this->field($input_field),$email->error);
            break;
          case ( ($this->verify->{$field} == "required") && (!strlen($this->data->{$data_field})) ):
          	$text=array();
            switch (TRUE) {
              case ($field != "name"):
              case (!$this->first_last):
            	$text[]=$this->fields[$field];
				break;
              case ($input_field == "name_first"):
              	$text[]="First Name";
              	break;
              default:
              	$text[]="Last Name";
			}
            $text[]="cannot be blank";
          	$forms->error($this->field($input_field),implode(" ",$text));
		}
        if (strlen($forms->error_text[$this->field($input_field)])) $this->error[$input_field]=$forms->error_text[$this->field($input_field)];
    }
	private function field($field) {
		return $this->table . "_" . $field;
    }
    function partsolutions($input) {
		if ( (!is_array($input)) || (!array_key_exists("user",$input)) ) return;
        $data=array();
		$data['company_name']=$input['user']['company'];
		$data['name_first']=$input['user']['firstname'];
		$data['name_last']=$input['user']['lastname'];
		$data['name']=trim($data['name_first'] . " " . $data['name_last']);
		$data['address']=$input['user']['street'];
		$data['city']=$input['user']['city'];
		$data['state']=$input['user']['state'];
		$data['zip']=$input['user']['zip'];
		$data['country_code']=$input['user']['country'];
		$data['address']=$input['user']['street'];
		$data['email']=$input['user']['email'];
		$data['phone']=$input['user']['phone'];
		$data['fax']=$input['user']['fax'];
        if ( (!strlen($data['email'])) || (preg_match("/" . preg_quote("@partcommunity.com","/") . "$/i",$data['email'])) ) return;
        foreach ($data as $key => $value) {
        	if (property_exists($this->data,$key)) $this->data->$key=$value;
        }
        return TRUE;
    }
    function input($options=array()) {
        global $database, $forms;
		$this->options($options);
        if ( ($_SERVER['REQUEST_METHOD'] == "GET") && (isset($_SESSION['myaccount'])) ) {
        	foreach ($_SESSION['myaccount'] as $key => $value) {
            	$forms->error_text[$key]=$value;
            }
        }
        $results=array();
        $this->script=array();
        foreach ($this->options->order as $field) {
            switch ($field) {
              case "company_name":
                $results[]=$this->input_field("company_name", "Company Name", $forms->text($this->field("company_name"),$this->data->company_name,40,40,"",$this->get_options("Company Name")));
                break;
              case "name":
                if ($this->first_last) {
                    $results[]=$this->input_field("name", "First Name", $forms->text($this->field("name_first"),$this->data->name_first,40,40,"",$this->get_options("First Name")));
                    $results[]=$this->input_field("name", "Last Name", $forms->text($this->field("name_last"),$this->data->name_last,40,40,"",$this->get_options("Last Name")));
                  } else {
                    $results[]=$this->input_field("name", "Name", $forms->text($this->field("name"),$this->data->name,40,40,"",$this->get_options("Name")));
                }
                break;
              case "title":
                $results[]=$this->input_field("title", "Title", $forms->text($this->field("title"),$this->data->title,40,40,"",$this->get_options("Title")));
                break;
              case "address":
                $options=$this->get_options("Address");
                $results[]=($this->options->address_textarea) ? $this->input_field("address", "Address", $forms->textarea($this->field("address"),$this->data->address,3,40,$options)) : $this->input_field("address", "Address", $forms->text($this->field("address"),$this->data->address,40,100,"",$options));
                break;
              case "city":
                $results[]=$this->input_field("city", "City", $forms->text($this->field("city"),$this->data->city,40,40,"",$this->get_options("City")));
                break;
              case "state":
                $content=array();
                $content[]=$database->country->select("US",$this->data->state,array("field"=>$this->field("state_us"),"blank"=>"Select State"),$this->get_options());
                $content[]=$database->country->select("CA",$this->data->state,array("field"=>$this->field("state_ca"),"blank"=>"Select Province"),$this->get_options());
                $options=$this->get_options("Province/State");
                $content[]=$forms->text($this->field("state_other"),$this->data->state,40,40,"",$options);
                $results[]=$this->input_field("state", "<span id=" . $this->field("state_text") . "></span>", implode(" ",$content));
                break;
              case "zip":
                $results[]=$this->input_field("zip", "<span id=" . $this->field("zip_text") . "></span>", $forms->text($this->field("zip"),$this->data->zip,25,40,"",$this->get_options("Zip/Postal Code/Zip Code")));
                break;
              case "country_code":
                $options=$this->get_options();
                $options["onchange"]="fn_country_address(" . fn_escape($this->table) . ");";
                $results[]=$this->input_field("country_code","Country", $database->country->select("", $this->data->country_code, array("field"=>$this->field("country_code"),"blank"=>"Select Country"), $options));
                if (!$this->registry->country_code) $results[]=$forms->hidden("country_code","US");
                break;
              case "phone":
                $results[]=$this->input_field("phone", "Phone", $forms->text($this->field("phone"),$this->data->phone,40,40,"",$this->get_options("Phone")));
                break;
              case "fax":
                $results[]=$this->input_field("fax", "Fax", $forms->text($this->field("fax"),$this->data->fax,40,40,"",$this->get_options("Fax")));
                break;
              case "email":
                $results[]=$this->input_field("email", "Email", $forms->text($this->field("email"),$this->data->email,40,60,"email",$this->get_options("Email")));
                switch (TRUE) {
                  case ($this->table != "user"):
                  case ($this->options->maintain):
                    break;
                  case ($database->registry->www->jquery):
                    $results[]=$this->input_user_jquery();
                    break;
                  default:
                    $results[]=$this->input_user();
                  }
                break;
            }
        }
		if ($this->options->legend) $results[]=$this->legend();
	    $js=array("");
	    $js[]="<script>";
        $js[]=implode("\n",$this->script);
        $js[]="fn_country_address(" . fn_escape($this->table) . ");";
        if ($this->options->address_js) $js[]=implode("\n",$this->js());
        $js[]="";
        $js[]="</script>";
		$results[]=implode("\n",$js) . "\n";
        return implode("",$results);
    }
    private function input_user() {
        global $database, $forms;
	    $this->script[]="function password_blank(obj) {";
	    $this->script[]="  if (obj.className) {";
	    $this->script[]="    obj.className='';";
	    $this->script[]="    obj.value='';";
	    $this->script[]="  }";
	    $this->script[]="}";
	    if (isset($database->registry->password->js)) $this->script[]=$database->registry->password->js;
		$results=array();
        $options=$this->td;
        switch (TRUE) {
          case ($database->user->new_password):
            $password=$database->user->new_password;
            break;
          case ( (isset($_SESSION['myaccount'])) && ($_SESSION['myaccount']['password']) ) :
          	$password="";
            $options["class"]="password_red";
            $options["onfocus"]="password_blank(this);";
          	break;
          case (strlen($this->password_fetch())):
            $password=$database->user->constant->password;
            $options["class"]="password_green";
            $options["onfocus"]="password_blank(this);";
            break;
          default:
            $password=$database->user->constant->password;
            $options["class"]="password_red";
            $options["onfocus"]="password_blank(this);";
        }
        if ($forms->error[$this->field('password')]) unset($options['class']);
		$options["placeholder"]="Password";
		$title="Change password? " . $forms->checkbox("password_change",1,$_POST['password_change'],array("onclick"=>"document.scs_form.password.focus();"));
        $text=array();
        $content=array($forms->text("password",$password,20,$database->registry->password->maximum,"",$options));
        $content[]=" (" . $database->registry->password->minimum;
        if ($database->registry->password->minimum != $database->registry->password->maximum) $content[]="-" . $database->registry->password->maximum;
        $content[]=" characters, case sensitive)";
        if ($database->user->data->password_timestamp) $content[]=" (Changed " . date("m/d/Y h:i A",$database->user->data->password_timestamp) . ")";
        $text[]=implode(" ",$content);
        if ($database->registry->password->charset) $text[]=fn_href("Generate random password","javascript:random_password();");
        $results[]=$this->input_field("password",$title,implode("<br>",$text));
	    $title="Auto login? " . $forms->checkbox("auto_login",1,$database->user->data->auto_login);
	    $text="(Automatic access if last successful login was from the same browser on the same desktop)";
	    $results[]=$this->input_field("auto_login",$title,$text);
        return implode("",$results);
    }
    private function input_user_jquery() {
        global $database, $forms;
	    if (isset($database->registry->password->js)) $this->script[]=$database->registry->password->js;
		$this->script[]="jQuery(document).ready(function(){";
		$this->script[]="user_password();";
		$this->script[]="});";
		$this->script[]="function user_password() {";
		$this->script[]="pwd_checked=( (jQuery('#password_change').prop('checked') == false) ? 0 : 1);";
		$this->script[]="jQuery('#pwd_checked').val(pwd_checked);";
		$this->script[]="if (pwd_checked) {";
		$this->script[]="jQuery('.pwd_tr').show();";
		$this->script[]="} else {";
		$this->script[]="jQuery('.pwd_tr').hide();";
		$this->script[]="}";
		$this->script[]="}";
		$this->script[]="function user_password_show(id) {";
		$this->script[]="var type=( (arguments.length > 1) ? 'password' : jQuery('#' + id).attr('type') );";
		$this->script[]="if (type == 'password') {";
		$this->script[]="jQuery('#' + id + '_show').text('Hide');";
		$this->script[]="jQuery('#' + id).attr('type','text');";
		$this->script[]="} else {";
		$this->script[]="jQuery('#' + id + '_show').text('Show');";
		$this->script[]="jQuery('#' + id).attr('type','password');";
		$this->script[]="}";
		$this->script[]="}";
        $this->script[]="function user_password_generate() {";
		$this->script[]="random_password();";
		$this->script[]="jQuery('#password_compare').val( jQuery('#password').val() );";
		$this->script[]="user_password_show('password',1);";
		$this->script[]="user_password_show('password_compare',1);";
        $this->script[]="}";
		$results=array();
        $options=$this->td;
        if ($database->registry->login->auto) {
	        $title="Auto login? " . $forms->checkbox("auto_login",1,$database->user->data->auto_login);
	        $text="(Automatic access if last successful login was from the same browser on the same desktop)";
	        $results[]=$this->input_field("auto_login",$title,$text);
        }
        if ($this->options->password) {
			$title="Change password? " . $forms->checkbox("password_change",1,$_POST['password_change'],array("onclick"=>"user_password();"));
            if ( (property_exists($database->user->data,"password_timestamp")) && ($database->user->data->password_timestamp) ) {
            	$text="Updated: " . date("F j, Y g:i A",$database->user->data->password_timestamp);
			  } else {
              	$text="";
			}
	        $results[]=$this->input_field("password_change",$title,$text);
        }
        $title="Password (" .  $database->registry->password->minimum . "-" . $database->registry->password->maximum . " characters)";
        $options=$this->td;
		$options["placeholder"]="Password";
        $content=array( $forms->text("password",$database->user->new_password,$database->registry->password->maximum,$database->registry->password->maximum,"password",$options) );
        $content[]="(" . fn_href("<span id='password_show'>Show</span>","javascript:user_password_show('password');") .")</span>";
        if ($database->registry->login->password_generate) $content[]=fn_href("(Create random password)","javascript:user_password_generate();");
		$text=array( implode(" ",$content) );
        if ($form->error_text['password']) $text[]=$form->error_text['password'];
		$results[]=$this->input_field("password",$title,implode("<br>",$text),array("class"=>"pwd_tr"));
        if ($database->registry->login->password_compare) {
	        $options=$this->td;
	        $options["placeholder"]="Confirm";
            $content=array( $forms->text("password_compare",$database->user->password_compare,$database->registry->password->maximum,$database->registry->password->maximum,"password",$options) );
	        $content[]="(" . fn_href("<span id='password_compare_show'>Show</span>","javascript:user_password_show('password_compare');") .")</span>";
			$text=array( implode(" ",$content) );
	        if ($form->error_text['password_compare']) $text[]=$form->error_text['password_compare'];
	        $results[]=$this->input_field("password_compare","Confirm password",implode("<br>",$text),array("class"=>"pwd_tr"));
        }
        return implode("",$results);
    }
    function input_field($field, $title, $content, $options=array()) {
        global $database, $forms;
		if ( (!isset($this->registry->{$field})) || (in_array($field, $this->options->omit)) ) return;
        $item=array();
		$item[]=( ($this->registry->{$field} == "required") ? $forms->font("red","<b>*</b>") : "&nbsp;");
        $item[]=$title;
        $text=array($content);
        if ($forms->error_text[$field]) $text[]=$forms->error_text[$field];
        $item[]=implode("<br>",$text);
		$results=array();
        $results[]="<tr " . $forms->options("",( (sizeof($options)) ? $options : $this->options->tr) ) . ">";
        if (sizeof($this->options->width) == 3) {
        	$results[]="<td " . $this->options->width['required'] . ">" . $item[0] . "</td>";
        	$results[]="<td " . $this->options->width['field'] . ">" . $item[1] . "</td>";
		  } else {
        	$results[]="<td " . $this->options->width['field'] . ">" . $item[0] . $item[1] . "</td>";
        }
        $results[]="<td " . $this->options->width['value'] . ">" . $item[2] . "</td>";
        $results[]="</tr>";
        return implode("",$results);
    }
    function legend($text="Indicates mandatory fields") {
    global $database, $forms;
    	$results=array();
	    $results[]="<tr " . $forms->options("",$this->options->tr) . ">";
        if (sizeof($this->options->width) == 3) {
	        $results[]="<td class=center>" . $forms->font("red","<b>*</b>") . "</td>";
	        $results[]="<td colspan=2>{$text}</td>";
		  } else {
	        $results[]="<td colspan=2>" . $forms->font("red","<b>*</b>",$text) . "</td>";
		}
	    $results[]="</tr>";
        return implode("",$results);
    }
    function json() {
        $results=new stdClass();
        foreach ($this->registry as $key => $value) {
            if ( ($key == "name") && ($this->first_last) ) {
                $results->name_first=$this->data->name_first;
                $results->name_last=$this->data->name_last;
            }
            $results->$key=$this->data->$key;
        }
        return $results;
    }
    function review() {
        global $forms;
        foreach ($this->options->order as $field) {
            switch (TRUE) {
              case (!property_exists($this->registry, $field)):
                break;
              case ( ($this->registry->$field == "required") && (!strlen($this->data->$field)) ):
                $forms->error("{$this->table}_{$field}", "{$this->fields[$field]}: Cannot be blank");
                break;
            }
        }
    }
    function query_field($items=array()) {
        foreach ($this->options->order as $field) {
            if (!property_exists($this->registry, $field)) continue;
            $items[]="{$this->table}.{$field} as `{$this->table}_{$field}`";
        }
        return $items;
    }
    function map($map, $table=FALSE) {
        foreach ($this->options->order as $field) {
            if (!property_exists($this->registry, $field)) continue;
            $options=array();
            $text=array();
            if (strlen($this->name)) $text[]=$this->name;
            $text[]=$this->fields[$field];
            $options["name"]=implode(": ", $text);
            $options["width"]=30;
            if ($field == "email") $options['type']="email";
            $map_field=(($table) ? "{$this->table}_" : "") . $field;
            $map->item($map_field, $options);
        }
    }
    function export($obj) {
        foreach ($this->registry as $field => $name) {
            $export_field="{$this->table}_{$field}";
            $obj->$export_field=$this->data->$field;
        }
    }
    function js() {
    global $database;
    	$results=array();
        $results[]="function fn_country_address(table) {";
        if ($database->registry->www->jquery) {
	        $results[]="jQuery('#' + table + '_state_us').hide();";
	        $results[]="jQuery('#' + table + '_state_ca').hide();";
	        $results[]="jQuery('#' + table + '_state_other').hide();";
	        $results[]="jQuery('#' + table + '_state_us_div').hide();";
	        $results[]="jQuery('#' + table + '_state_ca_div').hide();";
	        $results[]="jQuery('#' + table + '_state_other_div').hide();";
	        $results[]="var state;";
	        $results[]="var zip='Postal Code';";
	        $results[]="country_code=jQuery('#' + table + '_country_code').val();";
	        $results[]="switch (true) {";
	        $results[]="case (country_code == 'US'):";
	        $results[]="jQuery('#' + table + '_state_us').show();";
	        $results[]="jQuery('#' + table + '_state_us_div').show();";
	        $results[]="state='State';";
	        $results[]="zip='ZIP Code';";
	        $results[]="break;";
	        $results[]="case (country_code == 'CA'):";
            $results[]="jQuery('#' + table + '_state_ca').show();";
            $results[]="jQuery('#' + table + '_state_ca_div').show();";
	        $results[]="state='Province';";
	        $results[]="break;";
	        $results[]="default:";
	        $results[]="jQuery('#' + table + '_state_other').show();";
	        $results[]="jQuery('#' + table + '_state_other_div').show();";
	        $results[]="state='Province/State';";
	        $results[]="}";
	        $results[]="jQuery('#' + table + '_state_text').html(state);";
	        $results[]="jQuery('#' + table + '_zip_text').html(zip);";
          } else {
	        $results[]=" country_obj=document.getElementById(table + 'country_code');";
	        $results[]=" switch (true) {";
	        $results[]="   case (!country_obj):";
	        $results[]="   return;";
	        $results[]="  case (country_obj.value == 'US'):";
	        $results[]="   state='State';";
	        $results[]="   zip='ZIP Code';";
	        $results[]="   div_show(table + '_state_us',1);";
	        $results[]="   div_show(table + 'state_ca',0);";
	        $results[]="   div_show(table + 'state_other',0);";
	        $results[]="   break;";
	        $results[]="  case (country_obj.value == 'CA'):";
	        $results[]="   state='Province';";
	        $results[]="   zip='Postal Code';";
	        $results[]="   div_show(table + 'state_us',0);";
	        $results[]="   div_show(table + 'state_ca',1);";
	        $results[]="   div_show(table + 'state_other',0);";
	        $results[]="   break;";
	        $results[]="  default:";
	        $results[]="   state='Province/State';";
	        $results[]="   zip='Postal Code';";
	        $results[]="   div_show(table + 'state_us',0);";
	        $results[]="   div_show(table + 'state_ca',0);";
	        $results[]="   div_show(table + 'state_other',1);";
	        $results[]=" }";
	        $results[]=" state_obj=document.getElementById(table + 'state_text');";
	        $results[]=" if (state_obj) state_obj.innerHTML=state;";
	        $results[]=" zip_obj=document.getElementById(table + 'zip_text');";
	        $results[]=" if (zip_obj) zip_obj.innerHTML=zip;";
		}
		$results[]="}";
		return $results;
    }
    function output($output, $options=array()) {
    global $database;
		$this->options($options);
    	$names=array();
        $names['state']=$this->data->state;
        $names['country_code']=$this->data->country_code;
		$query=array("select * from country");
        $query[]="where (country=" . fn_escape($this->data->country_code) . " and state='')";
        $query[]="or (country=" . fn_escape($this->data->country_code) . " and state=" . fn_escape($this->data->state) . ")";
		$database->temp->query($query);
        while ($database->temp->fetch = $database->temp->fetch_array() ) {
        	if ($database->temp->fetch['state']) {
        		$names['state']=$database->temp->fetch['name'];
			  } else {
        		$names['country_code']=$database->temp->fetch['name'];
            }
        }
		$items=array();
        $obj=new stdClass();
        foreach ($this->options->order as $field) {
			if ( (!isset($this->registry->{$field})) || (in_array($field,$this->options->omit)) ) continue;
            $obj->$field=$this->data->$field;
            $text=( (array_key_exists($field, $names)) ? $names[$field] : $this->data->$field);
            if (strlen($text)) $items[$this->fields[$field]]=$text;
        }
	    $results=array();
	    switch ($output) {
          case "object":
          	if ( (property_exists($obj,"name")) && ($this->first_last) ) {
            	$obj->name_first=$this->data->name_first;
            	$obj->name_last=$this->data->name_last;
            }
          	return $obj;
	      case "array":
	        return $items;
	      case "table":
	        foreach ($items as $key => $value) {
            	if ($key == "Address") $value=str_replace("\n","<br>",$value);
	            $results[]="<tr><td " .  $this->options->width['field'] . ">{$key}</td><td " .  $this->options->width['value'] . ">{$value}</td></tr>";
	        }
	        return implode("\n",$results);
          case "list":
	        return implode("<br>\n",$items);
	      case "html":
          case "html:array":
	        foreach ($items as $key => $value) {
	            $results[]="{$key}: {$value}";
	        }
	        return ($output == "html") ? implode("<br>\n",$results) : $results;
	      default:
          	die("Method: " . __METHOD__ . ", Output: {$output}" );
		}
	}
    function fields() {
    	switch ($this->data->country_code) {
          case "US":
	       $this->fields["state"]="State";
	       $this->fields["zip"]="Zip Code";
			break;
          case "CA":
	       $this->fields["state"]="Province";
	       $this->fields["zip"]="Postal Code";
			break;
		  default:
	       $this->fields["state"]="Province/State";
	       $this->fields["zip"]="Postal Code";
	    }
    }
    function output_field() {
        $results=array();
        foreach ($this->options->order as $field) {
            if (property_exists($this->registry, $field)) $results[$field]=$this->fields[$field];
        }
        return $results;
    }
}
if (!class_exists("jquery_tab_class")) require_once "jquery_rev1.php";
?>
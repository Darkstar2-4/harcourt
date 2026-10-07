<?php
class crawl_class {
	var $curl;
    var $options;
    var $constant;
    var $unprocessed=array();
    var $rejected=array();
    var $pages=array();
    var $error;
    function scs_version() {
    	$results=array();
        $results['01/13/2013'][]="Default index.htm or index.php";
        $results['01/13/2013'][]="Include full URL on upload";
        $results['12/18/2012']="Add timeout limit";
        $results['11/30/2012']="General cleanup";
        $results['10/11/2012']="Initial release";
		$results['file']= __FILE__;          
        return $results;
    }
	function __construct($data=array()) {
        $this->constant=new crawl_constant_class();
        $this->options=new crawl_options_class($data);
    }
    function site() {
	global $forms;
		$this->curl=curl_init();
	    if ($this->options->action=="crawl") {
 	        $url=parse_url($this->options->url);
	        if (!file_exists($url['path'])) {
            	$forms->error('url');
                return;
			}
	        if (!$this->constant->scheme) $this->constant->scheme="http";
	        if (!$this->constant->host) $this->constant->host=$_SERVER['SERVER_NAME'];
			$this->unprocessed[]="/" . $this->options->url;
 	    }
		if (!$this->options->timeout) {
			$timeout_seconds=600;
		  } else {
			$timeout_seconds=$this->options->timeout;
        }
        if ($timeout_seconds > 30) ini_set('max_execution_time',600);
		if ($timeout_seconds < 20) {
			$timeout=strtotime("+ " . $timeout_seconds . " seconds");
		  } else {
			$timeout=strtotime("+ " . ($timeout_seconds - 3) . " seconds");
		}
        $starttime=microtime(TRUE);
	    $eof=FALSE;
	    while (!$eof) {
			$path=array_pop($this->unprocessed);
			$page=new crawl_page_class($this,$this->constant->scheme . "://" . $this->constant->host . $path);
			foreach ($page->links as $link) {
            	switch (TRUE) {
                  case (in_array($link,$this->unprocessed)):
                  case (in_array($link,$this->rejected)):
                  case (array_key_exists($link,$this->pages)):
                  	break;
                  default:
					$this->unprocessed[]=$link;
                }
            }
            switch (TRUE) {
              case (($this->options->problem=="Missing link") && ($page->info['http_code'] == 200)):
              case (($this->options->problem=="Title/Description") && (!sizeof($page->comment))):
              case ($page->info['content_type'] != "text/html"):
				$this->rejected[]=$path;
				break;
              default:
				$this->pages[$path]=$page;
            }
            switch (TRUE) {
              case (!sizeof($this->unprocessed)):
              case (($this->options->depth != "All") && (sizeof($this->pages) >= $this->options->depth)):
				$eof=TRUE;
				uksort($this->pages,"url_sort");
                $this->options->action="success";
                $message="Scan complete";
                break;
	          case (strtotime("now") > $timeout):
	            $eof=TRUE;
                $this->options->action="timeout";
                $message="Scan timeout";
                break;
			}
		}
		$this->constant->elapsedtime += (microtime(TRUE) - $starttime);
		return $message . " elapsed time: " . number_format($this->constant->elapsedtime,2) . " seconds";
    }
    function output_google() {
        foreach ($this->pages as $page_data) {
        	if ($page_data->info['http_code'] == 200) print $page_data->google;
        }
    }
    function output_analysis() {
        print "<table class='small border'>\n";
        print "<caption>&nbsp;</caption>\n";
        print "<tr>\n";
        print "<th>Page</th>\n";
        print "<th>Title</th>\n";
        print "<th>Meta Tags</th>\n";
        print "<th>Links</th>\n";
        print "<th class=nobr>Errors & Warnings</th>\n";
        print "</tr>\n";
        foreach ($this->pages as $page_data) {
            switch (TRUE) {
              case ($page_data->info['http_code'] == 200):
                if (!sizeof($page_data->comment)) {
                    print "<tr>\n";
                  } else {
                    print "<tr " . $forms->background->yellow . ">\n";
                }
                print "<td>" . $page_data->path . "</a></td>\n";
                print "<td>" . str_replace("\n","<br>",wordwrap($page_data->title, 30)) . "</td>\n";
                print "<td>";
                $first_pass=FALSE;
                foreach ($page_data->meta_tags as $key => $value) {
                    if ($first_pass) print "<br>";
                    $first_pass=TRUE;
                    print "<b>$key:</b> " . wordwrap($value, 100);
                }
                print "</td>\n";
                print "<td>";
                foreach ($page_data->links as $link) {
                    print "$link<br>";
                }
                print "</td>\n";
                print "<td>";
                foreach ($page_data->comment as $comment) {
                    print "$comment<br>";
                }
                print "</td>\n";
                print "</tr>\n";
                break;
              default:
                print "<tr " . $forms->background->yellow . ">\n";
                print "<td>$page</a></td>\n";
                print "<td colspan=3>Not found / access denied</td>\n";
                print "<td>HTTP return code: " . $page_data->info['http_code'] . "</td>\n";
                print "</tr>\n";
                break;
            }
        }
        print "</table>\n";
    }
    function output_table() {
    	if (sizeof($this->rejected)) {
        	$width="33%";
		  } else {
        	$width="50%";
        }
		print "<table class='small border'>\n";
        print "<tr>\n";
        print "<th width=$width>Processed " . sizeof($this->pages) . "</th>\n";
        if (sizeof($this->rejected)) print "<th width=$width>Rejected " . sizeof($this->rejected) . "</th>\n";
        print "<th width=$width>Unprocessed " . sizeof($this->unprocessed) . "</th>\n";
        print "</tr>\n";
        print "<tr>\n";
        print "<td>\n";
	    foreach ($this->pages as $page => $item) {
	        print $page . "<br>\n";
	    }
        print "</td>\n";
		if (sizeof($this->rejected)) {
	        print "<td>\n";
	        foreach ($this->rejected as $page) {
	            print $page . "<br>\n";
	        }
		}
        print "</td>\n";
        print "<td>\n";
	    foreach ($this->unprocessed as $page) {
	        print $page . "<br>\n";
	    }
        print "</td>\n";
        print "</tr>\n";
        print "</table>\n";
    }
}
class crawl_options_class {
	var $action;
    var $url;
    var $timeout=28;
    var $depth=1;
    var $output="Google";
    var $problem;
    function __construct($data) {
		$this->action=$data['action'];
        if (!$this->action) {
        	switch (TRUE) {
              case (file_exists("index.php")):
            	$this->url="index.php";
				break;
              case (file_exists("index.html")):
            	$this->url="index.html";
				break;
              default:
            	$this->url="index.htm";
            }
        	return;
		}
		$this->url=trim($data['url']);
        $this->timeout=$data['timeout'];
		$this->depth=$data['depth'];
		$this->output=$data['output'];
        $this->problem=$data['problem'];
        $this->elapsedtime=$data['elapsedtime'];
    }
}
class crawl_constant_class {
	var $depth=array(1,5,10,25,50,100,150,200,250,"All");
	var $output=array("Google","Analysis","Table","Upload");
	var $problem=array("","Missing link","Title/Description");
    var $elapsedtime=0;
    var $scheme;
    var $host;
}
class crawl_page_class {
	var $path;
    var $query;
    var $title;
    var $description;
    var $error;
    var $info;
    var $google;
    var $meta_tags=array();
    var $links=array();
    var $comment=array();
    function __construct($crawl,$url) {
		$url_parse=parse_url($url);
        $this->path=$url_parse['path'];
        $this->query=$url_parse['query'];
		curl_setopt($crawl->curl, CURLOPT_URL, $url);
		curl_setopt($crawl->curl, CURLOPT_RETURNTRANSFER, 1);
		$content=curl_exec($crawl->curl);
        $this->info=curl_getinfo($crawl->curl);
        if ($this->info['content_type'] != "text/html") return;
 		$this->meta_tags=get_meta_tags($url);
		preg_match("/<title>(.+)<\/title>/i",$content,$title);
        $this->title=trim($title[1]);
		$title=explode("\n",wordwrap($this->title,66));
		$dom = new DOMDocument();
		@$dom->loadHTML($content);
		$xpath = new DOMXPath($dom);
		$hrefs = $xpath->evaluate("//a"); //get all a tags
		for ($i = 0; $i < $hrefs->length; $i++) {
			$href = $hrefs->item($i);//select an a tag
			$href_url=parse_url($href->getAttribute('href'));
            switch (TRUE) {
              case ($href_url['path'][0] == "/"):
              case ($href_url['path'][0] == "."):
            	$path=$href_url['path'];
				break;
              default:
            	$path="/" . trim($parse['path']);
            }
            if ($href_url['query']) $path .= "?" . $href_url['query'];
            switch (TRUE) {
              case (in_array($path,$this->links)):
              	break;
              case (($href_url['scheme']) && (strtolower($href_url['scheme']) != $crawl->constant->scheme)):
              case (($href_url['host']) && (strtolower($href_url['host']) != $crawl->constant->host)):
              	break;
              default:
				$this->links[]=$path;
			}
		}
        switch (TRUE) {
          case (!$this->title):
          	$this->comment['title']="Missing title";
          	break;
          case ($this->title != strip_tags($this->title)):
          	$this->comment['title']="Title contains html tags";
          	break;
          case (strlen($this->title) > 60):
          	$this->comment['title']="Long title (" . strlen($this->title) . ") characters";
          	break;
          case (strlen($this->title) < 10):
          	$this->comment['title']="Short title (" . strlen($this->title) . ") characters";
          	break;
        }
        switch (TRUE) {
          case (!$this->meta_tags['description']):
          	$this->comment['description']="Missing description";
          	break;
          case ($this->meta_tags['description'] != strip_tags($this->meta_tags['description'])):
          	$this->comment['description']="Description contains html tags";
          	break;
          case ($this->meta_tags['description'] == $this->title):
          	$this->comment['description']="Title & description are identical";
          	break;
          case (strlen($this->meta_tags['description']) > 160):
          	$this->comment['description']="Long description (" . strlen($this->meta_tags['description']) . ") characters";
          	break;
          case (strlen($this->meta_tags['description']) < 20):
          	$this->comment['description']="Short description (" . strlen($this->meta_tags['description']) . ") characters";
          	break;
        }
        $this->google .=
        	"<p style='font:13.5px arial,sans-serif;width:600px;'><b>" .
            "<a href=\"" . $url . "\">";
        switch (TRUE) {
          case (!$title[0]):
        	$this->google .= "No Title";
            break;
          case (!$title[1]):
        	$this->google .= $this->title;
            break;
          default:
        	$this->google .= $title[0] . " ...";
        }
        $this->google .= "</a></b><br>";
	    if ($this->meta_tags['description']) {
        	$this->description=$this->meta_tags['description'];
            $description=$this->description;
          } else {
			$description=trim(strip_tags($content));
	    }
        switch (TRUE) {
          case (!$description):
			$this->google .= "No description";
            break;
          case (strlen($description) < 160):
			$this->google .= $description;
            break;
          default:
			$this->google .= substr($description,0,155) . " ...";
        }
        $this->google .= "<br><font color=#0e774a>$url</font></p>\n";
    }
}
?>
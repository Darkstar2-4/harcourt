<?php
require_once $_SERVER['DOCUMENT_ROOT'] . (version_compare(PHP_VERSION, '8', '<') ? '/composer' : '/composer8') . '/vendor/autoload.php';
use Dompdf\Dompdf;
/*  CSS Fonts
@font-face {
  font-family: 'Open Sans';
  font-style: normal;
  font-weight: normal;
  src: url(http://themes.googleusercontent.com/static/fonts/opensans/v8/cJZKeOuBrn4kERxqtaUH3aCWcynf_cDxXwCLxiixG1c.ttf) format('truetype');
}
See Options.php for complete list
*/
class scs_pdf_class {
    var $file;
    var $stream;
    var $options=array();
    var $description=array("Title","Author","Subject","Keywords","Creator","Producer","CreationDate","ModDate","Trapped");
	var $pdf;
    function scs_table_version() {
    	$results=array();
        $results['03/31/2021']="dompdf 1.0.2, add description, custom";
        $results['12/29/2017']="Initial release/Based on dompdf 0.8.2";
		$results['file']= __FILE__;
        return $results;
    }
    function __construct($html,$options=array(),$description=array()) {
		switch (TRUE) {
          case (!is_array($options)):
          	die("PDF options must be an array");
          case (!array_key_exists("file",$options)):
          	break;
          case (is_array($options["file"])):
          	$this->file=implode(DIRECTORY_SEPARATOR,$options["file"]);
            break;
          default:
          	$this->file=$options["file"];
		}
        if (!strlen($this->file)) $this->file=strtotime("now") . ".pdf";
        $this->stream=( (array_key_exists("stream",$options)) ? $options['stream'] : TRUE);
		$this->options['isHtml5ParserEnabled']=TRUE;
		$this->options['isRemoteEnabled']=TRUE;
        $this->options['defaultPaperSize']=( (isset($options['paper'])) ? $options['paper'] : "letter");
        $this->options['defaultPaperOrientation']=( (isset($options['orientation'])) ? $options['orientation'] : "portrait");
        $this->options['defaultFont']=( (isset($options['font'])) ? $options['font'] : "sans-serif");
        $this->options['chroot']=$_SERVER['DOCUMENT_ROOT'];
		$this->pdf=new Dompdf($this->options);
    	$this->pdf->load_html( (is_array($html) ? implode("",$html) : $html));
        $this->pdf->render();
        foreach ($description as $key =>  $value) {
			$field=import_match($key, $this->description);
            if ( (strlen($field)) && (strlen($value)) ) $this->pdf->add_info($field, $value);
        }
        if ($this->stream) {
	        $this->pdf->stream($this->file);
	        die();
		  } else {
			file_put_contents($this->file, $this->pdf->output());
		}
    }
}
?>
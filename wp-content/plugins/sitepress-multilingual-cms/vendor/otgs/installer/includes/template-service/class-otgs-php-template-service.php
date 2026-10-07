<?php

class OTGS_Php_Template_Service implements OTGS_Template_Service {

	const FILE_EXTENSION = '.php';

	private $template_dir;

	public function __construct( $template_dir ) {
		$this->template_dir = $template_dir;
	}

	public function show( $model_params, $template ) {
		$model = new OTGS_Template_Service_Php_Model( $model_params );
		include $this->getTemplatePath($template);
	}

	private function getTemplatePath( $template ) {
		return sprintf('%s/%s%s', $this->template_dir, $template, self::FILE_EXTENSION);
	}
}
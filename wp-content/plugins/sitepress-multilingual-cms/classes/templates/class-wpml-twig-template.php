<?php

use WPML\Core\Twig_Environment;

class WPML_Twig_Template implements IWPML_Template_Service {
	private $twig;

	public function __construct( Twig_Environment $twig ) {
		$this->twig = $twig;
	}

	public function show( $model, $template ) {
		return $this->twig->render( $template, $model );
	}
}
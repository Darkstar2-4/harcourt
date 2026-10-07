<?php

use WPML\Core\Twig_Loader_Filesystem;
use WPML\Core\Twig_Environment;

class WPML_Twig_Template_Loader {

	private $paths;

	public function __construct( array $paths ) {
		$this->paths = $paths;
	}

	public function get_template() {
		$twig_loader      = new Twig_Loader_Filesystem( $this->paths );
		$environment_args = array();
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$environment_args['debug'] = true;
		}
		$twig = new Twig_Environment( $twig_loader, $environment_args );
		return new WPML_Twig_Template( $twig );
	}
}
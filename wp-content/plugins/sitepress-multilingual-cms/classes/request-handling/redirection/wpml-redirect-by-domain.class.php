<?php

class WPML_Redirect_By_Domain extends WPML_Redirection {

	private $domains;
	private $wp_api;

	public function __construct( $domains, &$wp_api, &$request_handler, &$url_converter, &$lang_resolution ) {
		parent::__construct( $url_converter, $request_handler, $lang_resolution );
		$this->domains = $domains;
		$this->wp_api  = &$wp_api;
	}

	public function get_redirect_target( $language = false ) {
		if ( $this->wp_api->is_admin() && $this->lang_resolution->is_language_hidden( $language )
			&& strpos( $_SERVER['REQUEST_URI'], 'wp-login.php' ) === false
			&& ! $this->wp_api->user_can( wp_get_current_user(), 'manage_options' )
			) {
			$target = trailingslashit( $this->domains[ $language ] ) . 'wp-login.php';
		} else {
			$target = $this->redirect_hidden_home();
		}

		return $target;
	}
}

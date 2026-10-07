<?php

use WPML\Language\Detection\Frontend;

class WPML_Frontend_Redirection extends WPML_SP_User {

	private $request_handler;

	private $redirect_helper;

	private $lang_resolution;

	public function __construct(
		&$sitepress,
		&$request_handler,
		&$redir_helper,
		&$lang_resolution
	) {
		parent::__construct( $sitepress );
		$this->request_handler = &$request_handler;
		$this->redirect_helper = &$redir_helper;
		$this->lang_resolution = &$lang_resolution;
	}

	public function maybe_redirect() {
		$target = $this->redirect_helper->get_redirect_target();
		if ( false !== $target ) {
			$frontend_redirection_url = new WPML_Frontend_Redirection_Url( $target );
			$target                   = $frontend_redirection_url->encode_apostrophes_in_url();
			$this->sitepress->get_wp_api()->wp_safe_redirect( $target );
		};

		return $this->lang_resolution->current_lang_filter( $this->request_handler->get_requested_lang(), $this->request_handler );
	}

}

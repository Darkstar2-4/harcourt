<?php

class WPML_Post_Edit_Screen {

	private $sitepress;

	public function __construct( SitePress $sitepress ) {
		$this->sitepress = $sitepress;

		if ( $this->sitepress->get_setting( 'language_negotiation_type' ) == 2 ) {
			add_filter( 'preview_post_link', array( $this, 'preview_post_link_filter' ), 10, 1 );
			add_filter( 'preview_page_link ', array( $this, 'preview_post_link_filter' ), 10, 1 );
		}
		add_action( 'wpml_scripts_setup', array( $this, 'scripts_setup' ), 10, 1 );
		add_filter( 'get_sample_permalink', array( $this, 'get_sample_permalink_filter' ) );
	}

	function scripts_setup() {
		wp_enqueue_style( 'wp-jquery-ui-dialog' );
		wp_enqueue_style( 'sitepress-post-edit',
		                  ICL_PLUGIN_URL . '/res/css/post-edit.css',
		                  array(),
		                  ICL_SITEPRESS_SCRIPT_VERSION );
		wp_enqueue_script( 'sitepress-post-edit',
		                   ICL_PLUGIN_URL . '/res/js/post-edit.js',
		                   array( 'jquery-ui-dialog', 'jquery-ui-autocomplete' ),
		                   ICL_SITEPRESS_SCRIPT_VERSION );
	}

	public function preview_post_link_filter( $link ) {
		if ( ! $this->sitepress->get_setting( 'language_per_domain_sso_enabled' ) ) {
			$original_host = filter_var( $_SERVER['HTTP_HOST'], FILTER_SANITIZE_URL );

			if ( $original_host ) {
				$domain = wpml_parse_url( $link, PHP_URL_HOST );
				$link   = str_replace( '//' . $domain . '/', '//' . $original_host . '/', $link );
			}
		}

		return $link;
	}

	public function get_sample_permalink_filter( array $permalink ) {
		$permalink[0] = $this->sitepress->convert_url( $permalink[0] );
		return $permalink;
	}
}

<?php

use WPML\FP\Str;

class WPML_Language_Resolution {

	private $active_language_codes = array();
	private $current_request_lang  = null;
	private $default_lang          = null;
	private $hidden_lang_codes = null;

	public function __construct( $active_language_codes, $default_lang ) {
		add_action( 'wpml_cache_clear', array( $this, 'reload' ), 11, 0 );
		$this->active_language_codes = array_fill_keys(
			$active_language_codes,
			1
		);
		$this->default_lang          = $default_lang;
		$this->hidden_lang_codes     = array_fill_keys(
			wpml_get_setting_filter(
				array(),
				'hidden_languages'
			),
			1
		);
	}

	public function reload() {
		$this->active_language_codes = array();
		$this->hidden_lang_codes     = null;
		$this->default_lang          = null;
		$this->maybe_reload();
	}

	public function current_lang_filter( $lang, WPML_Request $wpml_request_handler ) {
		if ( $this->current_request_lang !== $lang ) {
			$preview_lang = apply_filters( 'wpml_should_filter_preview_lang', true )
				? $this->filter_preview_language_code()
				: null;

			if ( $preview_lang ) {
				$lang = $preview_lang;
			} elseif ( $this->use_cookie_language() ) {
				$lang = $wpml_request_handler->get_cookie_lang();
			}

			$this->current_request_lang = $this->filter_for_legal_langs( $lang );
		}

		return $this->current_request_lang;
	}

	public function get_active_language_codes() {
		$this->maybe_reload();

		return array_keys( $this->active_language_codes );
	}

	public function is_language_hidden( $lang_code ) {
		$this->maybe_reload();

		if ( null === $lang_code ) {
			return false;
		}

		return isset( $this->hidden_lang_codes[ $lang_code ] );
	}

	public function is_language_active( $lang_code, $is_all_active = false ) {
		global $wpml_request_handler;
		$this->maybe_reload();

		if ( null === $lang_code ) {
			return false;
		}

		return ( $is_all_active === true && $lang_code === 'all' )
			   || isset( $this->active_language_codes[ $lang_code ] )
			   || ( $wpml_request_handler->show_hidden() && $this->is_language_hidden( $lang_code ) );
	}

	private function maybe_reload() {
		$this->default_lang          = $this->default_lang
			? $this->default_lang : wpml_get_setting_filter( false, 'default_language' );
		$this->active_language_codes = (bool) $this->active_language_codes === true
			? $this->active_language_codes : array_fill_keys( wpml_reload_active_languages_setting( true ), 1 );
	}

	public function get_referrer_language_code() {
		if ( ! empty( $_SERVER['HTTP_REFERER'] ) ) {
			$query_string = wpml_parse_url( $_SERVER['HTTP_REFERER'], PHP_URL_QUERY );
			$query        = array();
			parse_str( (string) $query_string, $query );
			$language_code = isset( $query['lang'] ) ? $query['lang'] : null;
		}

		return isset( $language_code ) ? $language_code : null;
	}

	private function filter_for_legal_langs( $lang ) {
		if ( Str::includes( 'wp-login.php', $_SERVER['REQUEST_URI'] ) ) {
			return $lang;
		}

		$this->maybe_reload();

		$is_wp_cli_request = defined( 'WP_CLI' ) && WP_CLI;
		if ( $lang === 'all' && ( is_admin() || $is_wp_cli_request ) ) {
			return 'all';
		}

		if ( null === $lang ) {
			$lang = $this->default_lang ? $this->default_lang : icl_get_setting( 'default_language' );
		}

		if ( null !== $lang && ! isset( $this->hidden_lang_codes[ $lang ] ) && ! isset( $this->active_language_codes[ $lang ] ) ) {
			$lang = $this->default_lang ? $this->default_lang : icl_get_setting( 'default_language' );
		}

		return $lang;
	}

	private function use_cookie_language() {

		return ( isset( $_GET['action'] ) && $_GET['action'] === 'ajax-tag-search' )
			|| ( isset( $_POST['action'] ) && in_array(
				$_POST['action'],
				array( 'get-tagcloud', 'wp-link-ajax' ),
				true
			) );
	}

	private function filter_preview_language_code() {
		$preview_id   = filter_var(
			isset( $_GET['preview_id'] ) ? $_GET['preview_id'] : '',
			FILTER_SANITIZE_NUMBER_INT
		);
		$preview_flag = filter_input( INPUT_GET, 'preview' ) || $preview_id;
		$preview_id   = $preview_id ? $preview_id : filter_input( INPUT_GET, 'p' );
		$preview_id   = $preview_id ? $preview_id : filter_input( INPUT_GET, 'page_id' );
		$lang         = null;

		if ( $preview_flag && $preview_id ) {
			global $wpml_post_translations;
			$lang = $wpml_post_translations->get_element_lang_code( $preview_id );
		}

		return $lang;
	}
}

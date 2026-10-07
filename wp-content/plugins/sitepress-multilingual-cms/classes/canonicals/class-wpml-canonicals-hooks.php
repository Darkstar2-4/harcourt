<?php

class WPML_Canonicals_Hooks {

	private $sitepress;

	private $url_converter;

	private $is_current_request_root_callback;

	public function __construct( SitePress $sitepress, WPML_URL_Converter $url_converter, $is_current_request_root_callback ) {
		$this->sitepress                        = $sitepress;
		$this->url_converter                    = $url_converter;
		$this->is_current_request_root_callback = $is_current_request_root_callback;
	}

	public function add_hooks() {
		$urls             = $this->sitepress->get_setting( 'urls' );
		$lang_negotiation = (int) $this->sitepress->get_setting( 'language_negotiation_type' );

		if ( WPML_LANGUAGE_NEGOTIATION_TYPE_DIRECTORY === $lang_negotiation
			 && ! empty( $urls['directory_for_default_language'] )
		) {
			add_action( 'template_redirect', array( $this, 'redirect_pages_from_root_to_default_lang_dir' ) );
			add_action( 'template_redirect', [ $this, 'redirectArchivePageToDefaultLangDir' ] );
		} elseif ( WPML_LANGUAGE_NEGOTIATION_TYPE_PARAMETER === $lang_negotiation ) {
			add_filter( 'redirect_canonical', array( $this, 'prevent_redirection_with_translated_paged_content' ) );
		}

		if ( WPML_LANGUAGE_NEGOTIATION_TYPE_DIRECTORY === $lang_negotiation ) {
			add_filter( 'redirect_canonical', [ $this, 'prevent_redirection_of_frontpage_on_secondary_language' ], 10, 2 );
		}
	}

	public function redirect_pages_from_root_to_default_lang_dir() {
		global $wp_query;

		if ( ! ( ( $wp_query->is_page() || $wp_query->is_posts_page ) && ! call_user_func( $this->is_current_request_root_callback ) ) ) {
			return;
		}

		$lang           = $this->sitepress->get_current_language();
		$current_uri    = $_SERVER['REQUEST_URI'];
		$abs_home       = $this->url_converter->get_abs_home();
		$install_subdir = wpml_parse_url( $abs_home, PHP_URL_PATH );

		$actual_uri = is_string( $install_subdir )
			? preg_replace( '#^' . $install_subdir . '#', '', $current_uri )
			: $current_uri;
		$actual_uri = '/' . ltrim( $actual_uri, '/' );

		if ( 0 === strpos( $actual_uri, '/' . $lang ) ) {
			return;
		}

		$canonical_uri = is_string( $install_subdir )
			? trailingslashit( $install_subdir ) . $lang . $actual_uri
			: '/' . $lang . $actual_uri;
		$canonical_uri = user_trailingslashit( $canonical_uri );
		$this->redirectTo( $canonical_uri );
	}

	public function redirectArchivePageToDefaultLangDir() {
		$isValidForRedirect = is_archive() && ! call_user_func( $this->is_current_request_root_callback );
		if ( ! $isValidForRedirect ) {
			return;
		}

		$currentUri = $_SERVER['REQUEST_URI'];
		$lang       = $this->sitepress->get_current_language();

		$home_url        = rtrim( $this->url_converter->get_abs_home(), '/' );
		$parsed_site_url = wp_parse_url( $home_url );

		if ( isset( $parsed_site_url['path'] ) ) {
			$path = $parsed_site_url['path'];

			if ( ! empty( $path ) && strpos( $currentUri, $path ) === 0 ) {
				$currentUri = substr( $currentUri, strlen( $path ) );
			}
		}

		if ( 0 !== strpos( $currentUri, '/' . $lang ) ) {
			$canonicalUri = user_trailingslashit(
				$home_url . '/' . $lang . $currentUri
			);

			$this->redirectTo( $canonicalUri );
		}
	}

	private function redirectTo( $uri ) {
		$this->sitepress->get_wp_api()->wp_safe_redirect( $uri, 301 );
	}

	public function prevent_redirection_of_frontpage_on_secondary_language( $redirect_url, $requested_url ) {
		if ( ! is_front_page() || $this->sitepress->get_current_language() === $this->sitepress->get_default_language() ) {
			return $redirect_url;
		}

		if ( substr( get_option( 'permalink_structure' ), - 1 ) !== '/' ) {
			if ( $redirect_url === $requested_url . '/' ) {
				return false;
			}

			$redirect_url = untrailingslashit( $redirect_url );
		}

		return $redirect_url;
	}

	public function prevent_redirection_with_translated_paged_content( $redirect_url ) {
		if ( ! is_singular() || ! isset( $_GET['lang'] ) ) {
			return $redirect_url;
		}

		$page = (int) get_query_var( 'page' );

		if ( $page < 2 ) {
			return $redirect_url;
		}

		return false;
	}
}

<?php

class WPML_Browser_Redirect {

	private $sitepress;

	public function __construct( $sitepress ) {
		$this->sitepress = $sitepress;
	}

	public function init_hooks() {
		add_action( 'init', array( $this, 'init' ) );
	}

	public function init() {
		if ( ! isset( $_GET['redirect_to'] ) &&
			! is_admin() && ! is_customize_preview() &&
			( ! isset( $_SERVER['REQUEST_URI'] ) || ! preg_match( '#wp-login\.php$#', preg_replace( '@\?(.*)$@', '', $_SERVER['REQUEST_URI'] ) ) )
		) {
			add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		}
	}

	public function enqueue_scripts() {
		wp_register_script( 'wpml-browser-redirect', ICL_PLUGIN_URL . '/dist/js/browser-redirect/app.js', array(), ICL_SITEPRESS_SCRIPT_VERSION );

		$args['skip_missing'] = intval( $this->sitepress->get_setting( 'automatic_redirect' ) == 1 );

		$languages     = $this->sitepress->get_ls_languages( $args );
		$language_urls = [];
		foreach ( $languages as $language ) {
			if ( isset( $language['default_locale'] ) && $language['default_locale'] ) {
				$default_locale                   = strtolower( $language['default_locale'] );
				$language_urls[ $default_locale ] = $language['url'];
				$language_parts                   = explode( '_', $default_locale );
				if ( count( $language_parts ) > 1 ) {
					foreach ( $language_parts as $language_part ) {
						if ( ! isset( $language_urls[ $language_part ] ) ) {
							$language_urls[ $language_part ] = $language['url'];
						}
					}
				}
			}
			$language_urls[ $language['language_code'] ] = $language['url'];
		}
		$http_host = $_SERVER['HTTP_HOST'] == 'localhost' ? '' : $_SERVER['HTTP_HOST'];
		$cookie    = array(
			'name'       => '_icl_visitor_lang_js',
			'domain'     => ( defined( 'COOKIE_DOMAIN' ) && COOKIE_DOMAIN ? COOKIE_DOMAIN : $http_host ),
			'path'       => ( defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/' ),
			'expiration' => $this->sitepress->get_setting( 'remember_language' ),
		);

		$params = array(
			'pageLanguage' => defined( 'ICL_LANGUAGE_CODE' ) ? ICL_LANGUAGE_CODE : get_bloginfo( 'language' ),
			'languageUrls' => $language_urls,
			'cookie'       => $cookie,
		);

		$current_page_id = get_queried_object_id();
		$url             = $this->sitepress->get_setting( 'urls' );
		if ( $url && isset( $url['root_page'] ) && $current_page_id === (int) $url['root_page'] ) {
			$params['pageLanguage'] = '';
		}

		$params = apply_filters( 'wpml_browser_redirect_language_params', $params );

		$enqueue = false;
		if ( $params && isset( $params['pageLanguage'], $params['languageUrls'] ) ) {
			wp_localize_script( 'wpml-browser-redirect', 'wpml_browser_redirect_params', $params );

			$enqueue = apply_filters( 'wpml_enqueue_browser_redirect_language', true );
			if ( $enqueue ) {
				wp_enqueue_script( 'wpml-browser-redirect' );
			}
		}

		do_action( 'wpml_enqueued_browser_redirect_language', $enqueue, $params );
	}
}

<?php


use WPML\Language\Detection\CookieLanguage;
use WPML\UrlHandling\WPLoginUrlConverterRules;
use WPML\FP\Obj;

abstract class WPML_Request {

	protected $url_converter;
	protected $active_languages;
	protected $default_language;

	protected $cookieLanguage;

	public function __construct(
		WPML_URL_Converter $url_converter,
		$active_languages,
		$default_language,
		CookieLanguage $cookieLanguage
	) {
		$this->url_converter    = $url_converter;
		$this->active_languages = $active_languages;
		$this->default_language = $default_language;
		$this->cookieLanguage   = $cookieLanguage;
	}

	abstract protected function get_cookie_name();

	abstract public function get_requested_lang();

	public function get_request_uri( $filter = null ) {
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '/';
		if ( $filter !== null ) {
			$request_uri = filter_var( $request_uri, $filter );
		}

		return $request_uri;
	}

	public function get_request_uri_lang() {
		if ( apply_filters( 'wpml_should_skip_saving_language_in_cookies', false ) ) {
			return false;
		}

		$req_url = isset( $_SERVER['HTTP_HOST'] )
			? untrailingslashit( $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'] ) : '';

		return $this->url_converter->get_language_from_url( $req_url );
	}

	public function get_cookie_lang() {
		return $this->cookieLanguage->get( $this->get_cookie_name() );
	}

	public function show_hidden() {
		$queryVars = [];
		if ( isset( $_SERVER['QUERY_STRING'] ) ) {
			parse_str( $_SERVER['QUERY_STRING'], $queryVars );
		}

		$isReviewPostPage = (
			Obj::has( 'wpmlReviewPostType', $queryVars ) &&
			Obj::has( 'preview_id', $queryVars ) &&
			Obj::has( 'preview_nonce', $queryVars ) &&
			Obj::has( 'preview', $queryVars ) &&
			Obj::has( 'jobId', $queryVars ) &&
			Obj::has( 'returnUrl', $queryVars )
		);

		$isPostsListPage = false;
		if ( isset( $_SERVER['REQUEST_URI'] ) ) {
			$uri = urldecode( $_SERVER['REQUEST_URI'] );
			if ( is_admin() && $uri && strpos( $uri, 'edit.php' ) !== false ) {
				$isPostsListPage = true;
			}
		}

		return ! did_action( 'init' )
			   || ( get_user_meta( get_current_user_id(), 'icl_show_hidden_languages', true )
					|| ( ( is_admin() || wpml_is_rest_request() ) && $this->isAdmin() ) )
			  || ( ( $isPostsListPage || $isReviewPostPage ) && ( $this->isAdmin() || $this->isEditor() ) )
			  || ( $isReviewPostPage && is_user_logged_in() && $this->isSubscriber() );
	}

	private function isAdmin() {
		return current_user_can( 'manage_options' );
	}

	private function isEditor() {
		$can = $this->getCaps();

		return $can['read'] && $can['publish'] && $can['edit'] && \WPML\LIB\WP\User::isTranslator();
	}

	private function isSubscriber() {
		return current_user_can( 'read' ) && \WPML\LIB\WP\User::isTranslator();
	}

	private function getCaps() {
		$canPublish   = current_user_can( 'publish_pages' ) || current_user_can( 'publish_posts' );
		$canRead      = current_user_can( 'read_private_pages' ) || current_user_can( 'read_private_posts' );
		$canEdit      = current_user_can( 'edit_posts' );

		return [
			'publish' => $canPublish,
			'read'    => $canRead,
			'edit'    => $canEdit,
		];
	}

	public function set_language_cookie( $lang_code ) {
		$this->cookieLanguage->set( $this->get_cookie_name(), $lang_code );
	}
}

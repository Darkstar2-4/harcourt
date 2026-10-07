<?php

class WPML_URL_HTTP_Referer {

	private $rest;

	public function __construct( WPML_Rest $rest ) {
		$this->rest = $rest;
	}

	public function get_url( $backup_url ) {
		if ( ! isset( $_SERVER['HTTP_REFERER'] ) ) {
			return $backup_url;
		}

		if ( WPML_Ajax::is_admin_ajax_request_called_from_frontend( $backup_url )
			 || $this->is_rest_request_called_from_post_edit_page()
		) {
			return $_SERVER['HTTP_REFERER'];
		}

		return $backup_url;
	}

	public function get_trid() {
		$referer_data = $request_uri_data = array();

		if ( array_key_exists( 'HTTP_REFERER', $_SERVER ) ) {
			$query = (string) wpml_parse_url( $_SERVER['HTTP_REFERER'], PHP_URL_QUERY );
			parse_str( $query, $referer_data );
		}

		if ( array_key_exists( 'REQUEST_URI', $_SERVER ) ) {
			$request_uri = (string) wpml_parse_url( $_SERVER['REQUEST_URI'], PHP_URL_QUERY );
			parse_str( $request_uri, $request_uri_data );
		}

		return array_key_exists( 'trid', $referer_data ) && array_key_exists( 'trid', $request_uri_data )
			   || ( array_key_exists( 'trid', $referer_data ) && $this->is_rest_request_called_from_post_edit_page() )
			? (int) $referer_data['trid']
			: false;
	}

	public function is_rest_request_called_from_post_edit_page() {
		return $this->rest->is_rest_request() && self::is_post_edit_page();
	}

	public static function is_post_edit_page() {
		return isset( $_SERVER['HTTP_REFERER'] )
			   && ( strpos( $_SERVER['HTTP_REFERER'], 'wp-admin/post.php' )
					|| strpos( $_SERVER['HTTP_REFERER'], 'wp-admin/post-new.php' )
					|| strpos( $_SERVER['HTTP_REFERER'], 'wp-admin/edit.php' ) );
	}
}

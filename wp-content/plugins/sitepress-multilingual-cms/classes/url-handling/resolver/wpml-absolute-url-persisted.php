<?php

class WPML_Absolute_Url_Persisted {

	const OPTION_KEY = 'wpml_resolved_url_persist';

	private static $instance;

	private $urls;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	protected function __construct() {}

	private function __clone() {}

	public function __wakeup() {
		throw new Exception( 'Cannot unserialize singleton' );
	}

	private function get_urls() {
		if ( null === $this->urls ) {
			$this->urls = get_option( self::OPTION_KEY, array() );

			if ( ! is_array( $this->urls ) ) {
				$this->urls = array();
			}
		}

		return $this->urls;
	}

	public function has_urls() {
		return (bool) $this->get_urls();
	}

	public function set( $original_url, $lang, $converted_url ) {
		$this->get_urls();
		$this->urls[ $original_url ][ $lang ] = $converted_url;
		$this->persist_in_shutdown();
	}

	public function get( $original_url, $lang ) {
		$this->get_urls();

		if ( isset( $this->urls[ $original_url ][ $lang ] ) ) {
			return $this->urls[ $original_url ][ $lang ];
		}

		return null;
	}

	public function reset() {
		$this->urls = [];
		$this->persist();
		$this->urls = null;
	}

	public function persist() {
		update_option( self::OPTION_KEY, $this->urls );
	}

	private function persist_in_shutdown() {
		if ( ! has_action( 'shutdown', array( $this, 'persist' ) ) ) {
			add_action( 'shutdown', array( $this, 'persist' ) );
		}
	}
}

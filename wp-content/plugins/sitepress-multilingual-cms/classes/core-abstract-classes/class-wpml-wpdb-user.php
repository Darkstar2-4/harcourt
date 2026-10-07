<?php

abstract class WPML_WPDB_User {

	public $wpdb;

	public function __construct( &$wpdb ) {
		$this->wpdb = &$wpdb;
	}

	public function get_wpdb() {
		return $this->wpdb;
	}
}

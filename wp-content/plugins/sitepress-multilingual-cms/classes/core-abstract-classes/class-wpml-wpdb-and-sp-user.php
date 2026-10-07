<?php

abstract class WPML_WPDB_And_SP_User extends WPML_WPDB_User {

	protected $sitepress;

	public function __construct( &$wpdb, &$sitepress ) {
		parent::__construct( $wpdb );
		$this->sitepress = &$sitepress;
	}
}

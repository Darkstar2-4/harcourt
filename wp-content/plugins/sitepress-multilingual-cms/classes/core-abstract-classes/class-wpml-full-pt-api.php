<?php

abstract class WPML_Full_PT_API extends WPML_WPDB_And_SP_User {

	protected $post_translations;

	public function __construct( &$wpdb, &$sitepress, &$post_translations ) {
		parent::__construct( $wpdb, $sitepress );
		$this->post_translations = &$post_translations;
	}
}

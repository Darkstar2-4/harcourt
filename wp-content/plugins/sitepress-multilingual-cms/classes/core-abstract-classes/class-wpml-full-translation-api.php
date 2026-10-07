<?php

class WPML_Full_Translation_API extends WPML_Full_PT_API {

	protected $term_translations;

	function __construct( &$sitepress, &$wpdb, &$post_translations, &$term_translations ) {
		parent::__construct( $wpdb, $sitepress, $post_translations );
		$this->term_translations = &$term_translations;
	}
}

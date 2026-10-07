<?php

class WPML_Menu_Element extends WPML_Term_Element {

	public function __construct( $id, SitePress $sitepress, ?WPML_WP_Cache $wpml_cache = null ) {
		$this->taxonomy = 'nav_menu';
		parent::__construct( $id, $sitepress, $this->taxonomy, $wpml_cache );
	}

	public function get_new_instance( $element_data ) {
		return new WPML_Menu_Element( $element_data->term_id, $this->sitepress, $this->wpml_cache );
	}
}

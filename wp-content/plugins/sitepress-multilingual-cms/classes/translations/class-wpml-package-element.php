<?php

use WPML\FP\Obj;

class WPML_Package_Element extends WPML_Translation_Element {

	protected $kind_slug;

	public function __construct( $id, SitePress $sitepress, $kind_slug = '', ?WPML_WP_Cache $wpml_cache = null ) {
		$this->kind_slug = $kind_slug;
		parent::__construct( $id, $sitepress, $wpml_cache );
	}

	public function get_wp_object() {
		return null;
	}

	public function get_type( $element = null ) {
		if ( ! $this->kind_slug && $element instanceof WPML_Package ) {
			$this->kind_slug = $element->kind_slug;
		}

		return $this->kind_slug;
	}

	public function get_wpml_element_type() {
		return $this->get_element_type() . '_' . $this->get_type();
	}

	public function get_element_type() {
		return 'package';
	}

	public function get_element_id() {
		return $this->id;
	}

	public function get_new_instance( $element_data ) {
		$id        = Obj::prop( 'ID', $element_data );
		$kind_slug = Obj::prop( 'kind_slug', $element_data );

		return new WPML_Package_Element( $id, $this->sitepress, $kind_slug, $this->wpml_cache );
	}

	public function is_translatable() {
		return true;
	}

	public function is_display_as_translated() {
		return true;
	}
}

<?php

class WPML_Term_Element extends WPML_Translation_Element {
	protected $taxonomy;

	public function __construct( $id, SitePress $sitepress, $taxonomy = '', ?WPML_WP_Cache $wpml_cache = null ) {
		$this->taxonomy = $taxonomy;
		parent::__construct( $id, $sitepress, $wpml_cache );
	}

	public function get_wp_object() {
		$has_filter = remove_filter( 'get_term', array( $this->sitepress, 'get_term_adjust_id' ), 1 );

		$term = get_term( $this->id, $this->taxonomy );
		if ( ! $term || is_wp_error( $term ) ) {
			$term = get_term_by( 'term_taxonomy_id', $this->id, $this->taxonomy );
			$term = $term ?: null;
		}

		if ( $has_filter ) {
			add_filter( 'get_term', array( $this->sitepress, 'get_term_adjust_id' ), 1, 1 );
		}

		return $term;
	}

	public function get_type( $term = null ) {
		if ( ! $this->taxonomy && $term instanceof WP_Term ) {
			$this->taxonomy = $term->taxonomy;
		}

		return $this->taxonomy;
	}

	public function get_wpml_element_type() {
		$element_type = '';
		if ( ! is_wp_error( $this->get_wp_element_type() ) ) {
			$element_type = $this->get_element_type() . '_' . $this->get_wp_element_type();
		}

		return $element_type;
	}

	public function get_element_type() {
		return 'tax';
	}

	public function get_element_id() {
		$element_id = null;
		$term       = $this->get_wp_object();

		if ( $term && ! is_wp_error( $term ) ) {
			$element_id = $term->term_taxonomy_id;
		}

		return $element_id;
	}

	public function get_new_instance( $element_data ) {
		return new WPML_Term_Element( $element_data->element_id, $this->sitepress, $this->taxonomy, $this->wpml_cache );
	}

	public function is_translatable() {
		return $this->sitepress->is_translated_taxonomy( $this->get_wp_element_type() );
	}

	public function is_display_as_translated() {
		return $this->sitepress->is_display_as_translated_taxonomy( $this->get_wp_element_type() );
	}

}

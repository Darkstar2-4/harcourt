<?php

class WPML_Term_Hierarchy_Sync extends WPML_Hierarchy_Sync {

	protected $element_id_column        = 'term_taxonomy_id';
	protected $parent_id_column         = 'parent';
	protected $parent_element_id_column = 'term_id';
	protected $element_type_column      = 'taxonomy';
	protected $element_type_prefix      = 'tax_';

	public function __construct( &$wpdb ) {
		parent::__construct( $wpdb );

		$this->elements_table = $wpdb->term_taxonomy;
	}

	public function is_need_sync( $taxonomy, $ref_lang = false, $term_id = null ) {

		return (bool) $this->get_unsynced_elements( $taxonomy, $ref_lang, $term_id );
	}

	public function sync_element_hierarchy( $element_types, $ref_lang = false, $term_id = null ) {
		global $wpml_term_filters_general;

		parent::sync_element_hierarchy( $element_types, $ref_lang, $term_id );
		do_action( 'wpml_sync_term_hierarchy_done' );

		$element_types = (array) $element_types;

		foreach ( $element_types as $taxonomy ) {
			$wpml_term_filters_general->update_tax_children_option( $taxonomy );
		}
	}

	public function is_hierarchical( $element_type ) {
		return is_taxonomy_hierarchical( $element_type );
	}
}

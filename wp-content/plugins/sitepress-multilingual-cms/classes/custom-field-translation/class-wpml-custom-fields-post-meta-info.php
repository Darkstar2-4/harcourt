<?php

class WPML_Custom_Fields_Post_Meta_Info implements IWPML_Action {
	const RESOURCES_HANDLE = 'wpml-cf-info';

	private $translatable_element_factory;

	public function __construct( WPML_Translation_Element_Factory $translatable_element_factory ) {
		$this->translatable_element_factory = $translatable_element_factory;
	}

	public function add_hooks() {
		add_filter( 'wpml_custom_field_original_data', array( $this, 'get_info_filter' ), 10, 3 );
	}

	public function get_info_filter( $ignore, $post_id, $meta_key ) {
		return $this->get_info( $post_id, $meta_key );
	}

	private function get_info( $post_id, $meta_key ) {
		$post_element     = $this->translatable_element_factory->create( $post_id, 'post' );
		$original_element = $post_element->get_source_element();

		if ( $original_element && $original_element->get_id() !== $post_element->get_id() ) {

			return array(
				'meta_key' => $meta_key,
				'value'    => get_post_meta( $original_element->get_id(), $meta_key, true )
			);
		}

		return null;
	}
}

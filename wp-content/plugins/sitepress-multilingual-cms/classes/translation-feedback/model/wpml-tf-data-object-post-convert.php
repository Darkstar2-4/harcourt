<?php

abstract class WPML_TF_Data_Object_Post_Convert {

	abstract public function get_post_fields();


	abstract public function get_meta_fields();

	abstract public function to_post_data( IWPML_TF_Data_Object $data_object );

	abstract public function get_post_type();

	abstract public function to_object( array $post_data );

	protected function build_object_data_for_constructor( array $post_data ) {
		$object_data = array();

		foreach ( $this->get_post_fields() as $feedback_field => $post_field ) {
			$object_data[ $feedback_field ] = isset( $post_data['post']->{$post_field} )
				? $post_data['post']->{$post_field} : null;
		}

		foreach ( $this->get_meta_fields() as $meta_field ) {
			$object_data[ $meta_field ] = isset( $post_data['metadata'][ $meta_field ] )
				? $post_data['metadata'][ $meta_field ] : null;
		}

		return $object_data;
	}
}

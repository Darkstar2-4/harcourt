<?php

class WPML_Display_As_Translated_Posts_Query extends WPML_Display_As_Translated_Query {

	private $post_table;

	public function __construct( wpdb $wpdb, $post_table_alias = null ) {
		parent::__construct( $wpdb );
		$this->post_table = $post_table_alias ? $post_table_alias : $wpdb->posts;
	}

	protected function get_content_types_query( $post_types ) {
		$post_types = wpml_prepare_in( $post_types );
		return "{$this->post_table}.post_type IN ( {$post_types} )";
	}

	protected function get_query_for_translation_not_published( $language ) {
		return $this->wpdb->prepare( "
			( SELECT COUNT(element_id)
				FROM {$this->wpdb->prefix}icl_translations t2
				JOIN {$this->wpdb->posts} p ON p.id = t2.element_id
				WHERE t2.trid = {$this->icl_translation_table_alias}.trid
				AND t2.language_code = %s
                AND (
                    p.post_status = 'publish' OR p.post_status = 'private' OR 
                    ( p.post_type='attachment' AND p.post_status = 'inherit' )
                )
			) = 0",
			$language );
	}

}
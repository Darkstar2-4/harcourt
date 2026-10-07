<?php

class WPML_WP_Post {
	public $wpdb;

	private $post_id;

	public function __construct( wpdb $wpdb, $post_id ) {
		$this->wpdb = $wpdb;
		$this->post_id = $post_id;
	}

	public function update( array $post_data_array, $direct_db_update = false) {
		if ( $direct_db_update ) {
			$this->wpdb->update( $this->wpdb->posts, $post_data_array, array( 'ID' => $this->post_id ) );
			clean_post_cache( $this->post_id );
		} else {
			$post_data_array['ID'] = $this->post_id;
			wpml_update_escaped_post( $post_data_array );
		}
	}
}
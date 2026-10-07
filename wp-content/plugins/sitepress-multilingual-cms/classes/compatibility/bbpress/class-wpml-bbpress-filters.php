<?php

class WPML_BBPress_Filters {

	private $wpml_bbpress_api;

	public function __construct( $wpml_bbpress_api ) {
		$this->wpml_bbpress_api = $wpml_bbpress_api;
	}

	public function __destruct() {
		$this->remove_hooks();
	}

	public function add_hooks() {
		add_filter( 'author_link', array( $this, 'author_link_filter' ), 10, 3 );
	}

	public function remove_hooks() {
		remove_filter( 'author_link', array( $this, 'author_link_filter' ), 10 );
	}

	public function author_link_filter( $link, $author_id, $author_nicename ) {
		if (
			doing_action( 'wpseo_head' ) ||
			doing_action( 'wp_head' ) ||
			doing_filter( 'wpml_active_languages' )
		) {
			return $this->wpml_bbpress_api->bbp_get_user_profile_url( $author_id, $author_nicename );
		}

		return $link;
	}
}

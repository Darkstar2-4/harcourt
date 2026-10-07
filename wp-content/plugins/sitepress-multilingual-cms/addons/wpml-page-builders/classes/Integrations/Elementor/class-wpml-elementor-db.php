<?php

class WPML_Elementor_DB {

	private $elementor_db;

	public function __construct( \Elementor\DB $elementor_db ) {
		$this->elementor_db = $elementor_db;
	}

	public function save_plain_text( $post_id ) {
		$this->elementor_db->save_plain_text( $post_id );
	}
}

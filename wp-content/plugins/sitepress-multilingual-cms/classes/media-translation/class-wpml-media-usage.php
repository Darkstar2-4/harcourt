<?php

class WPML_Media_Usage {

	const FIELD_NAME = '_wpml_media_usage';

	private $attachment_id;
	private $usage;

	public function __construct( $attachment_id ) {
		$this->attachment_id = $attachment_id;

		$usage       = get_post_meta( $this->attachment_id, self::FIELD_NAME, true );
		$this->usage = empty( $usage ) ? array() : $usage;
	}

	public function get_posts() {
		return empty( $this->usage['posts'] ) ? array() : $this->usage['posts'];
	}

	public function add_post( $post_id ) {
		$posts                = $this->get_posts();
		$posts[]              = $post_id;
		$this->usage['posts'] = array_unique( $posts );
		$this->update_usage();
	}

	public function remove_post( $post_id ) {
		$this->usage['posts'] = array_values( array_diff( (array) $this->usage['posts'], array( $post_id ) ) );
		$this->update_usage();
	}

	private function update_usage() {
		update_post_meta( $this->attachment_id, self::FIELD_NAME, $this->usage );
	}

}

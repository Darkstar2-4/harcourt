<?php

class WPML_Compatibility_Jetpack implements IWPML_Action {

	public function add_hooks() {
		add_filter(
			'publicize_should_publicize_published_post',
			array( $this, 'publicize_should_publicize_published_post_filter' ),
			10,
			2
		);
	}

	public function publicize_should_publicize_published_post_filter( $should_publicize, $post ) {
		return ! $this->is_post_duplicated( $post );
	}

	private function is_post_duplicated( $post ) {
		if (
			apply_filters( 'wpml_is_translated_post_type', false, $post->post_type ) &&
			did_action( 'wpml_before_make_duplicate' )
		) {
			return true;
		}

		return false;
	}
}

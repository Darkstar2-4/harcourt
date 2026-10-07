<?php

class WPML_Create_Post_Helper {

	private $sitepress;

	public function __construct( SitePress $sitepress ) {
		$this->sitepress = $sitepress;
	}

	public function insert_post( array $postarr, $lang = null, $wp_error = false ) {
		$current_language = null;
		$postarr          = $this->slash_and_preserve_tag_ids( $postarr );

		if ( $lang ) {
			$current_language = $this->sitepress->get_current_language();
			$this->sitepress->switch_lang( $lang, false );
		}

		if ( isset( $postarr['ID'] ) ) {
			$returnTrue = \WPML\FP\Fns::always( true );
			add_filter( 'wpml_disable_term_adjust_id', $returnTrue );
			$new_post_id = wp_update_post( $postarr, $wp_error );
			remove_filter( 'wpml_disable_term_adjust_id', $returnTrue );
		} else {
			add_filter( 'wp_insert_post_empty_content', array( $this, 'allow_empty_post' ), 10, 0 );
			$new_post_id = wp_insert_post( $postarr, $wp_error );
			remove_filter( 'wp_insert_post_empty_content', array( $this, 'allow_empty_post' ) );

		}

		if ( $current_language ) {
			$this->sitepress->switch_lang( $current_language, false );
		}

		return $new_post_id;
	}

	public function allow_empty_post() {
		return false;
	}

	private function slash_and_preserve_tag_ids( array $postarr ) {
		if ( array_key_exists( 'tags_input', $postarr ) ) {
			$tagIds                = array_filter( $postarr['tags_input'], 'is_int' );
			$postarr               = wp_slash( $postarr );
			$postarr['tags_input'] = array_merge( $tagIds, $this->parse_tag( $postarr['tags_input'] ) );
		} else {
			$postarr = wp_slash( $postarr );
		}

		return $postarr;
	}

	private function parse_tag( $tags ) {
		if ( empty( $tags ) ) {
			$tags = array();
		}

		if ( ! is_array( $tags ) ) {
			$comma = _x( ',', 'tag delimiter' );
			if ( ',' !== $comma ) {
				$tags = str_replace( $comma, ',', $tags );
			}
			$tags = explode( ',', trim( $tags, " \n\t\r\0\x0B," ) );
		}

		return $tags;
	}
}
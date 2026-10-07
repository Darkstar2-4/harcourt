<?php

class WPML_Page_Builders_Media_Shortcodes_Update implements IWPML_PB_Media_Update {

	private $element_factory;

	private $media_shortcodes;

	private $media_usage;

	public function __construct(
		WPML_Translation_Element_Factory $element_factory,
		WPML_Page_Builders_Media_Shortcodes $media_shortcodes,
		?WPML_Page_Builders_Media_Usage $media_usage = null
	) {
		$this->element_factory  = $element_factory;
		$this->media_shortcodes = $media_shortcodes;
		$this->media_usage      = $media_usage;
	}

	public function translate( $post ) {
		if ( ! $this->media_shortcodes->has_media_shortcode( $post->post_content ) ) {
			return;
		}

		$element = $this->element_factory->create_post( $post->ID );

		if ( ! $element->get_source_language_code() ) {
			return;
		}

		$post_content = $this->media_shortcodes->set_target_lang( $element->get_language_code() )
			->set_source_lang( $element->get_source_language_code() )
			->translate( $post->post_content );

		if ( $this->media_usage ) {
			$this->media_usage->update( $element->get_source_element()->get_id() );
		}

		if ( $post->post_content !== $post_content ) {
			$post->post_content = $post_content;

			$tag_ids = wp_get_post_tags( $post->ID, array( 'fields' => 'ids' ) );
			$postarr = array(
				'ID'           => $post->ID,
				'post_content' => $post->post_content,
				'tags_input'   => $tag_ids,
			);
			kses_remove_filters();
			wpml_update_escaped_post( $postarr, $element->get_language_code() );
			kses_init();
		}
	}

	public function find_media( $post ) {
		if ( ! $this->media_shortcodes->has_media_shortcode( $post->post_content ) ) {
			return;
		}

		$element = $this->element_factory->create_post( $post->ID );

		$this->media_shortcodes->set_target_lang( $element->get_language_code() )
			->set_source_lang( $element->get_language_code() )
			->translate( $post->post_content );
	}

	public function get_media() {
		return $this->media_shortcodes->get_media();
	}
}

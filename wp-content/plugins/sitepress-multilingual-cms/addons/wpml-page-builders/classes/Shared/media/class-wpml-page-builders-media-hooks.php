<?php

class WPML_Page_Builders_Media_Hooks implements IWPML_Action {

	private $media_update_factory;

	private $page_builder_slug;

	public function __construct( IWPML_PB_Media_Update_Factory $media_update_factory, $page_builder_slug ) {
		$this->media_update_factory = $media_update_factory;
		$this->page_builder_slug    = $page_builder_slug;
	}

	public function add_hooks( $should_add_hooks_for_media_translation = true ) {
		add_filter( 'wpml_pb_get_media_finders', array( $this, 'add_media_finder' ) );
		add_filter( 'wpml_pb_get_media_updaters', array( $this, 'add_media_updater' ) );

		if ( ! $should_add_hooks_for_media_translation ) {
			return;
		}

		add_filter( 'wpml_media_content_for_media_usage', array( $this, 'add_package_strings_content' ), 10, 2 );
	}

	public function add_media_finder( $updaters, $post = null ) {
		return $this->add_media_updater( $updaters, $post, true );
	}

	public function add_media_updater( $updaters, $post = null, $find_usage_instead_of_translate = false ) {
		if ( ! array_key_exists( $this->page_builder_slug, $updaters ) ) {
			$updaters[ $this->page_builder_slug ] = $this->media_update_factory->create( $find_usage_instead_of_translate );
		}
		return $updaters;
	}

	public function add_package_strings_content( $content, $post ) {
		$packages = apply_filters( 'wpml_st_get_post_string_packages', array(), $post->ID );

		foreach ( $packages as $package ) {
			$strings = $package->get_package_strings();

			foreach ( $strings as $string ) {
				$content .= PHP_EOL . $string->value;
			}
		}

		return $content;
	}
}

<?php

namespace WPML\Compatibility\WPBakery;

use WPML\Element\API\PostTranslations;
use WPML\FP\Fns;
use WPML\FP\Maybe;
use WPML_Custom_Field_Setting_Factory;
use WPML_PB_Last_Translation_Edit_Mode;
use function WPML\FP\partial;
use function WPML\FP\partialRight;

class Styles implements \IWPML_Frontend_Action, \IWPML_Backend_Action {

	const META_CUSTOM_CSS = [
		'_wpb_shortcodes_custom_css',
		'_wpb_post_custom_css',
	];

	private $metaSettingFactory;

	public function __construct( WPML_Custom_Field_Setting_Factory $metaSettingFactory ) {
		$this->metaSettingFactory = $metaSettingFactory;
	}

	public function add_hooks() {
		add_action( 'save_post', [ $this, 'copyCssFromOriginal' ] );
		add_action( 'init', [ $this, 'adjustMetaSetting' ] );
	}

	public function copyCssFromOriginal( $postId ) {
		$ifUsingTranslationEditor = [ WPML_PB_Last_Translation_Edit_Mode::class, 'is_translation_editor' ];

		$ifUsingWpBakery = partialRight( 'get_post_meta', '_wpb_vc_js_status', true );

		$copyCss = function( $originalPostId ) use ( $postId ) {
			wpml_collect( self::META_CUSTOM_CSS )->map( function( $key ) use ( $postId, $originalPostId ) {
				$css = get_post_meta( $originalPostId, $key, true );
				if ( $css ) {
					update_post_meta( $postId, $key, $css );
				}
			} );
		};

		Maybe::of( $postId )
			->filter( $ifUsingTranslationEditor )
			->map( PostTranslations::getOriginalId() )
			->filter( $ifUsingWpBakery )
			->map( $copyCss );
	}

	public function adjustMetaSetting() {
		wpml_collect( self::META_CUSTOM_CSS )->map( function( $key ) {
			$metaSetting = $this->metaSettingFactory->post_meta_setting( $key );

			if ( ! $metaSetting->is_unlocked() ) {
				$metaSetting->set_to_copy_once();
			}
		} );
	}
}

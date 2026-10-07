<?php


namespace WPML\PB\Elementor\Media\Modules;

class AllNodes extends \WPML_Elementor_Media_Node {

	private $backgroundKeys;

	private function getBackgroundKeys( $type ) {
		if ( ! isset( $this->backgroundKeys ) ) {
			$this->backgroundKeys = apply_filters(
				'wpml_elementor_media_backgrounds_to_translate',
				[
					'simple_fields'   => [
						'_background_image',
						'background_image',
						'background_overlay_image',
						'background_hover_image',
						'background_image_mobile',
						'background_image_tablet',
					],
					'repeater_fields' => [
						'background_slideshow_gallery',
					],
				]
			);
		}

		return isset( $this->backgroundKeys[ $type ] ) && is_array( $this->backgroundKeys[ $type ] ) ? $this->backgroundKeys[ $type ] : [];
	}

	public function translate( $settings, $target_lang, $source_lang ) {
		foreach ( $this->getBackgroundKeys( 'simple_fields' ) as $background ) {
			$settings = $this->translate_image_property( $settings, $background, $target_lang, $source_lang );
		}

		foreach ( $this->getBackgroundKeys( 'repeater_fields' ) as $background ) {
			$settings = $this->translate_images_property( $settings, $background, $target_lang, $source_lang );
		}

		return $settings;
	}

}

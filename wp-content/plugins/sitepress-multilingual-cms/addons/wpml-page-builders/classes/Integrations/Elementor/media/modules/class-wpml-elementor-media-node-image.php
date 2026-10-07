<?php

class WPML_Elementor_Media_Node_Image extends WPML_Elementor_Media_Node {

	public function translate( $settings, $target_lang, $source_lang ) {
		$settings = $this->translate_image_property( $settings, 'image', $target_lang, $source_lang );
		$settings = $this->translate_image_property( $settings, '_background_image', $target_lang, $source_lang );
		$settings = $this->translate_image_property( $settings, '_background_hover_image', $target_lang, $source_lang );

		if ( ! isset( $settings['caption'] ) && isset( $settings['image']['id'] ) ) {
			$image_data = wp_prepare_attachment_for_js( $settings['image']['id'] );

			if ( is_array( $image_data ) && isset( $image_data['caption'] ) ) {
				$settings['caption'] = $image_data['caption'];
			}
		}

		return $settings;
	}
}

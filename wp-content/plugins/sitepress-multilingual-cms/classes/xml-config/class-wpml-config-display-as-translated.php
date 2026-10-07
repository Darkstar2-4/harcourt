<?php

use WPML\FP\Obj;

class WPML_Config_Display_As_Translated {

	public static function merge_to_translate_mode( $config ) {
		$config = self::merge_to_translate_mode_for_key( $config, 'custom-types', 'custom-type' );
		$config = self::merge_to_translate_mode_for_key( $config, 'taxonomies', 'taxonomy' );

		return $config;
	}

	private static function merge_to_translate_mode_for_key( $config, $key_plural, $key_singular ) {
		foreach ( Obj::pathOr( [], [ 'wpml-config', $key_plural, $key_singular ], $config ) as $index => $settings ) {
			if ( WPML_CONTENT_TYPE_TRANSLATE == Obj::path( [ 'attr', 'translate' ], $settings ) &&
			     1 == Obj::path( [ 'attr', 'display-as-translated' ], $settings )
			) {
				$settings['attr']['translate'] = WPML_CONTENT_TYPE_DISPLAY_AS_IF_TRANSLATED;
				unset( $settings['attr']['display-as-translated'] );
				$config['wpml-config'][ $key_plural ][ $key_singular ][ $index ] = $settings;
			}
		}

		return $config;
	}

}

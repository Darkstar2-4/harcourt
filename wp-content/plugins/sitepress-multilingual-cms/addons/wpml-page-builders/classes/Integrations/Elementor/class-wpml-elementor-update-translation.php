<?php

use WPML\PB\Elementor\Helper\Node;
use WPML\PB\Elementor\Helper\StringFormat;

class WPML_Elementor_Update_Translation extends WPML_Page_Builders_Update_Translation {

	protected function update_strings_in_modules( array &$data_array ) {
		foreach ( $data_array as &$element ) {
			if ( Node::hasChildren( $element ) ) {
				$this->update_strings_in_modules( $element['elements'] );
			}

			if ( Node::isTranslatable( $element ) ) {
				$element = $this->update_strings_in_node( $element[ $this->data_settings->get_node_id_field() ], $element );
			}
		}
	}

	protected function update_strings_in_node( $node_id, $settings ) {
		return WPML_Elementor_Translatable_Nodes::with_active_element_settings_cache(
			function () use ( $node_id, $settings ) {
				$strings = $this->translatable_nodes->get( $node_id, $settings );

				foreach ( $strings as $string ) {
					$translation = $this->get_translation( $string );

					if ( StringFormat::useWpAutoP( $settings, $string ) ) {
						$translation->set_value( wpautop( $translation->get_value() ) );
					}

					$settings = $this->translatable_nodes->update( $node_id, $settings, $translation );
				}

				return $settings;
			}
		);
	}
}

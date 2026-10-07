<?php

use WPML\PB\Elementor\Helper\Node;
use WPML\PB\TranslationJob\Groups;

class WPML_Elementor_Register_Strings extends WPML_Page_Builders_Register_Strings {

	protected function filter_string_to_register( WPML_PB_String $string, $node_id, $element, $package ) {
		if ( ! empty( $element['settings']['image']['id'] ) && Groups::isGroupLabel( $string->get_title() ) ) {
			$string->set_title( Groups::appendImageIdToGroupLabel( $string->get_title(), $element['settings']['image']['id'] ) );
		}

		return $string;
	}

	protected function register_strings_for_node( $node_id, $element, array $package ) {
		WPML_Elementor_Translatable_Nodes::with_active_element_settings_cache(
			function () use ( $node_id, $element, $package ) {
				parent::register_strings_for_node( $node_id, $element, $package );
			}
		);
	}

	protected function register_strings_for_modules( array $data_array, array $package ) {
		foreach ( $data_array as $data ) {
			if ( Node::isTranslatable( $data ) ) {
				$this->register_strings_for_node( $data[ $this->data_settings->get_node_id_field() ], $data, $package );
			}
			if ( Node::hasChildren( $data ) ) {
				$this->register_strings_for_modules( $data['elements'], $package );
			}
		}
	}
}

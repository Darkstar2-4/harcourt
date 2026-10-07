<?php

namespace WPML\PB\SiteOrigin;

use WPML\PB\TranslationJob\Groups;
use WPML_PB_String;

class RegisterStrings extends \WPML_Page_Builders_Register_Strings {

	public function register_strings_for_modules( array $data_array, array $package ) {
		foreach ( $data_array as $data ) {
			if ( isset( $data[ TranslatableNodes::SETTINGS_FIELD ] ) ) {
				if ( TranslatableNodes::isWrappingModule( $data ) ) {
					$this->register_strings_for_modules( $data[ TranslatableNodes::CHILDREN_FIELD ], $package );
				} else {
					$this->register_strings_for_node( $data[ TranslatableNodes::SETTINGS_FIELD ]['class'], $data, $package );
				}
			} elseif ( is_array( $data ) ) {
				$this->register_strings_for_modules( $data, $package );
			}
		}
	}

	protected function filter_string_to_register( WPML_PB_String $pbString, $node_id, $element, $package ) {
		if ( isset( $element['image'] ) ) {
			$pbString->set_title( Groups::appendImageIdToGroupLabel( $pbString->get_title(), (int) $element['image'] ) );
		}

		return $pbString;
	}
}

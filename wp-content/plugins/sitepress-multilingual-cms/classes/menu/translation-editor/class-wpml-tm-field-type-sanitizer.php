<?php

class WPML_TM_Field_Type_Sanitizer {


	public static function sanitize( $custom_field_type ) {
		$element_field_type_parts = explode( '-', $custom_field_type );
		$last_part                = array_pop( $element_field_type_parts );

		if ( empty( $element_field_type_parts ) ) {
			return $custom_field_type;
		}

		$field_type = implode( '-', $element_field_type_parts );
		if ( is_numeric( $last_part ) ) {
			return $field_type;
		} else {
			return $custom_field_type;
		}
	}
}


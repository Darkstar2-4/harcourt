<?php

class WPML_TM_Serialized_Custom_Field_Package_Handler {

	private $custom_field_setting_factory;

	public function __construct( WPML_Custom_Field_Setting_Factory $custom_field_setting_factory ) {
		$this->custom_field_setting_factory = $custom_field_setting_factory;
	}

	public function add_hooks() {
		add_filter(
			'wpml_translation_job_post_meta_value_translated',
			array(
				$this,
				'translate_only_whitelisted_attributes',
			),
			10,
			2
		);

		add_filter(
			'wpml_tm_adjust_translation_fields',
			array(
				$this,
				'set_title_for_whitelisted_attributes',
			)
		);
	}

	public function translate_only_whitelisted_attributes( $translated, $custom_field_job_type ) {
		if ( $translated ) {
			list( $custom_field, $attributes ) = WPML_TM_Field_Type_Encoding::decode( $custom_field_job_type );
			if ( $custom_field && $attributes ) {
				$settings             = $this->custom_field_setting_factory->post_meta_setting( $custom_field );
				$attributes_whitelist = $settings->get_attributes_whitelist();

				if ( $attributes_whitelist ) {
					$translated = $this->match_in_order( $attributes, $attributes_whitelist ) ? $translated : 0;
				}
			}
		}

		return $translated;
	}

	private function match_in_order( $attributes, $whitelist, $current_depth = 0 ) {
		$current_attribute = $attributes[ $current_depth ];
		$wildcard_match    = $this->match_with_wildcards( $current_attribute, array_keys( $whitelist ) );
		if ( $wildcard_match ) {
			if ( count( $attributes ) === $current_depth + 1 ) {
				return true;
			} else {
				return $this->match_in_order( $attributes, $whitelist[ $wildcard_match ], $current_depth + 1 );
			}
		}

		return false;
	}

	public function set_title_for_whitelisted_attributes( $fields ) {
		foreach ( $fields as $index => $field ) {
			list( $custom_field, $attributes ) = WPML_TM_Field_Type_Encoding::decode( $field['field_type'] );
			if ( $custom_field && $attributes ) {
				$settings             = $this->custom_field_setting_factory->post_meta_setting( $custom_field );
				$attributes_whitelist = $settings->get_attributes_whitelist();

				if ( $attributes_whitelist ) {
					$title = $this->find_title_in_order( $attributes, $attributes_whitelist );
					if ( '' !== $title ) {
						$fields[ $index ]['title'] = $title;
					}
				}
			}
		}

		return $fields;
	}

	private function find_title_in_order( $attributes, $whitelist, $current_depth = 0 ) {
		$current_attribute = $attributes[ $current_depth ];
		$wildcard_match    = $this->match_with_wildcards( $current_attribute, array_keys( $whitelist ) );
		if ( $wildcard_match ) {
			if ( count( $attributes ) === $current_depth + 1 ) {
				return $whitelist[ $current_attribute ] ?? '';
			} else {
				return $this->find_title_in_order( $attributes, $whitelist[ $wildcard_match ], $current_depth + 1 );
			}
		}

		return '';
	}

	private function match_with_wildcards( $attribute, $whitelist ) {
		foreach ( $whitelist as $white_value ) {
			$asterisk_pos = strpos( $white_value, '*' );
			if ( false === $asterisk_pos ) {
				if ( $attribute === $white_value ) {
					return $white_value;
				}
			} else {
				if (
					0 === $asterisk_pos ||
					substr( $attribute, 0, $asterisk_pos ) === substr( $white_value, 0, $asterisk_pos )
				) {
					return $white_value;
				}
			}
		}

		return '';
	}
}

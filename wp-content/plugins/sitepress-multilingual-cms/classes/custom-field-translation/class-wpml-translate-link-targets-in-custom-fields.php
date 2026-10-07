<?php

class WPML_Translate_Link_Targets_In_Custom_Fields extends WPML_Translate_Link_Targets {

	private $tm_instance;
	private $wp_api;
	private $meta_keys;

	public function __construct( &$tm_instance, &$wp_api, $absolute_links, $permalinks_converter ) {
		parent::__construct( $absolute_links, $permalinks_converter );
		$this->tm_instance = &$tm_instance;
		$this->wp_api      = &$wp_api;

		$this->tm_instance->load_settings_if_required();
		if ( isset( $this->tm_instance->settings['custom_fields_translate_link_target'] ) &&
				! empty( $this->tm_instance->settings['custom_fields_translate_link_target'] ) ) {

			$this->meta_keys = $this->tm_instance->settings['custom_fields_translate_link_target'];
		}
	}

	public function has_meta_keys() {
		return (bool) $this->meta_keys;
	}

	public function maybe_translate_link_targets( $metadata, $object_id, $meta_key, $single ) {

		if ( array_key_exists( $meta_key, $this->meta_keys ) ) {
			$custom_field_setting = new WPML_Post_Custom_Field_Setting( $this->tm_instance, $meta_key );
			if ( $custom_field_setting->is_translate_link_target() ) {

				$sub_fields = $custom_field_setting->get_translate_link_target_sub_fields();

				$this->wp_api->remove_filter( 'get_post_metadata', array( $this, 'maybe_translate_link_targets' ), 10 );
				$metadata_raw = maybe_unserialize( $this->wp_api->get_post_meta( $object_id, $meta_key, $single ) );
				$this->wp_api->add_filter( 'get_post_metadata', array( $this, 'maybe_translate_link_targets' ), 10, 4 );

				if ( $metadata_raw ) {
					if ( $single ) {
						$metadata_raw = array( $metadata_raw );
					}
					foreach ( $metadata_raw as $index => $metadata ) {
						if ( ! empty( $sub_fields ) ) {
							$metadata = $this->convert_sub_fields( $sub_fields, $metadata );
						} else {
							$metadata = $this->convert_text( $metadata );
						}
						$metadata_raw[ $index ] = $metadata;
					}

					if ( $single && ! is_array( $metadata_raw[0] ) ) {
						$metadata_raw = $metadata_raw[0];
					}
				}
				$metadata = $metadata_raw;
			}
		}
		return $metadata;
	}

	private function convert_sub_fields( $sub_fields, $metadata ) {
		foreach ( $sub_fields as $sub_field ) {
			if ( isset( $sub_field['value'], $sub_field['attr']['translate_link_target'] ) && $sub_field['attr']['translate_link_target'] ) {
				$key = trim( $sub_field['value'] );
				if ( isset( $metadata[ $key ] ) ) {
					$metadata[ $key ] = $this->convert_text( $metadata[ $key ] );
				}
			}
		}

		return $metadata;
	}
}




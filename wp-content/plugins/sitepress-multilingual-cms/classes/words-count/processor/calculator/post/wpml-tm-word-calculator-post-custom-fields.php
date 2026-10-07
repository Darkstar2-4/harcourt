<?php

class WPML_TM_Word_Calculator_Post_Custom_Fields implements IWPML_TM_Word_Calculator_Post {

	private $calculator;

	private $cf_settings;

	private $fields_to_count = array();

	public function __construct( WPML_TM_Word_Calculator $calculator, ?array $cf_settings = null ) {
		$this->calculator  = $calculator;
		$this->cf_settings = $cf_settings;
	}

	public function count_words( WPML_Post_Element $post_element, $lang = null ) {
		$words     = 0;
		$post_id   = $post_element->get_id();
		$post_lang = $post_element->get_language_code();

		if ( ! $post_lang || ! $post_id || ! $this->is_registered_type( $post_element )
			|| empty( $this->cf_settings ) || ! is_array( $this->cf_settings )
		) {
			return $words;
		}

		$cf_to_count = $this->get_translatable_fields_to_count( $post_id );

		foreach ( $cf_to_count as $cf ) {
			$custom_fields_value = get_post_meta( $post_id, $cf );

			if ( ! $custom_fields_value ) {
				continue;
			}

			if ( is_scalar( $custom_fields_value ) ) {
				$words += $this->calculator->count_words( $custom_fields_value, $post_lang );
			} else {

				foreach ( $custom_fields_value as $custom_fields_value_item ) {

					if ( $custom_fields_value_item && is_scalar( $custom_fields_value_item ) ) {
						$words += $this->calculator->count_words( $custom_fields_value_item, $post_lang );
					}
				}
			}
		}

		return (int) $words;
	}

	private function is_registered_type( WPML_Post_Element $post_element ) {
		$post_types = get_post_types();
		return in_array( $post_element->get_type(), $post_types );
	}

	private function get_translatable_fields_to_count( $post_id ) {
		if ( ! $this->fields_to_count ) {
			foreach ( $this->cf_settings as $cf => $mode ) {
				if ( (int) $mode === WPML_TRANSLATE_CUSTOM_FIELD ) {
					$this->fields_to_count[] = $cf;
				}
			}
		}

		return apply_filters( 'wpml_words_count_custom_fields_to_count', $this->fields_to_count, $post_id );
	}
}

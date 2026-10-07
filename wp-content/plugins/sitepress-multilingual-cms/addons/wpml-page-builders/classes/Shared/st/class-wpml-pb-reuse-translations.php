<?php

class WPML_PB_Reuse_Translations {

	private $string_factory;

	private $original_strings;

	private $current_strings;

	public function __construct( WPML_ST_String_Factory $string_factory ) {
		$this->string_factory = $string_factory;
	}

	public function find_and_reuse_translations( array $original_strings, array $current_strings, array $leftover_strings ) {
		$this->original_strings = $original_strings;
		$this->current_strings  = $current_strings;
		$new_strings            = $this->find_new_strings();
		$new_strings_to_update  = $this->find_existing_strings_for_new_strings( $new_strings, $leftover_strings );
		$this->reuse_translations( $new_strings_to_update );
	}

	private function find_new_strings() {
		$new_strings = array();

		foreach ( $this->current_strings as $current_string ) {
			$found = false;
			foreach ( $this->original_strings as $original_string ) {
				if ( $current_string['id'] == $original_string['id'] ) {
					$found = true;
					break;
				}
			}
			if ( ! $found ) {
				$new_strings[ $current_string['id'] ] = 0;
			}
		}

		return $new_strings;
	}

	private function find_existing_strings_for_new_strings( array $new_strings, array $leftover_strings ) {

		list( $new_strings, $leftover_strings ) = $this->find_by_location( $new_strings, $leftover_strings );
		$new_strings = $this->find_by_similar_text( $new_strings, $leftover_strings );

		return $new_strings;
	}

	private function find_by_location( array $new_strings, array $leftover_strings ) {
		if ( ! $leftover_strings ) {
			return array( $new_strings, $leftover_strings );
		}

		if ( ( count( $this->current_strings ) - count( $leftover_strings ) ) !== count( $this->original_strings ) ) {
			return array( $new_strings, $leftover_strings );
		}

		foreach ( $leftover_strings as $key => $leftover_string ) {
			foreach ( $this->current_strings as $current_string ) {
				if ( isset( $new_strings[ $current_string['id'] ] ) ) {
					if ( $this->is_same_location_and_different_ids( $current_string, $leftover_string )
					     && $this->is_similar_text( $leftover_string['value'], $current_string['value'] ) ) {
						$new_strings[ $current_string['id'] ] = $leftover_string['id'];
						unset( $leftover_strings[ $key ] );
					}
				}
			}
		}

		return array( $new_strings, $leftover_strings );
	}

	private function find_by_similar_text( array $new_strings, array $leftover_strings ) {

		if ( $leftover_strings ) {
			foreach ( $new_strings as $new_string_id => $old_string_id ) {
				if ( ! $old_string_id ) {
					$new_string       = $this->string_factory->find_by_id( $new_string_id );
					$new_string_value = $new_string->get_value();
					foreach ( $leftover_strings as $key => $leftover_string ) {
						$leftover_string_id    = $leftover_string['id'];
						$leftover_string       = $this->string_factory->find_by_id( $leftover_string_id );
						$leftover_string_value = $leftover_string->get_value();

						if ( $this->is_similar_text( $leftover_string_value, $new_string_value ) ) {
							$new_strings[ $new_string_id ] = $leftover_string_id;
							unset( $leftover_strings[ $key ] );
						}
					}
				}
			}
		}

		return $new_strings;
	}

	private function is_same_location_and_different_ids( array $current_string, array $leftover_string ) {
		return $current_string['location'] === $leftover_string['location']
		       && $current_string['id'] !== $leftover_string['id'];
	}

	private function is_similar_text( $old_text, $new_text ) {
		return WPML_ST_Diff::get_sameness_percent( $old_text, $new_text ) > 50;
	}

	private function reuse_translations( array $strings ) {
		foreach ( $strings as $new_string_id => $old_string_id ) {

			if ( $old_string_id ) {
				$new_string   = $this->string_factory->find_by_id( $new_string_id );
				$old_string   = $this->string_factory->find_by_id( $old_string_id );
				$translations = $old_string->get_translations();

				foreach ( $translations as $translation ) {
					$status = $translation->status == ICL_TM_COMPLETE ? ICL_TM_NEEDS_UPDATE : $translation->status;
					$new_string->set_translation(
						$translation->language,
						$translation->value,
						$status,
						$translation->translator_id,
						$translation->translation_service,
						$translation->batch_id
					);
				}
			}
		}
	}
}

<?php

class WPML_TM_Translation_Batch_Element {
	private $element_id;

	private $element_type;

	private $source_lang;

	private $target_langs;

	private $media_to_translations;

	public function __construct(
		$element_id,
		$element_type,
		$source_lang,
		array $target_languages,
		array $media_to_translations = array()
	) {
		if ( ! $element_id ) {
			throw new InvalidArgumentException( 'Element id has to be defined' );
		}

		if ( empty( $element_type ) ) {
			throw new InvalidArgumentException( 'Element type has to be defined' );
		}

		if ( ! is_string( $source_lang ) || empty( $source_lang ) ) {
			throw new InvalidArgumentException( 'Source lang has to be not empty string' );
		}

		if ( empty( $target_languages ) ) {
			throw new InvalidArgumentException( 'Target languages array cannot be empty' );
		}

		$possible_actions = array(
			TranslationManagement::TRANSLATE_ELEMENT_ACTION,
			TranslationManagement::DUPLICATE_ELEMENT_ACTION,
		);
		foreach ( $target_languages as $lang => $action ) {
			if ( ! is_string( $lang ) || ! in_array( $action, $possible_actions, true ) ) {
				throw new InvalidArgumentException( 'Target languages must be an associative array with the language code as a key and the action as a numeric value.' );
			}
		}

		$this->element_id            = $element_id;
		$this->element_type          = $element_type;
		$this->source_lang           = $source_lang;
		$this->target_langs          = $target_languages;
		$this->media_to_translations = $media_to_translations;
	}


	public function get_element_id() {
		return $this->element_id;
	}

	public function get_element_type() {
		return $this->element_type;
	}

	public function get_source_lang() {
		return $this->source_lang;
	}

	public function get_target_langs() {
		return $this->target_langs;
	}

	public function get_media_to_translations() {
		return $this->media_to_translations;
	}
}

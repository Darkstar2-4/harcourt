<?php

class WPML_TM_Word_Count_Set_Post {

	private $element_factory;

	private $records;

	private $post_calculators;

	private $active_langs;

	private $post_element;

	public function __construct(
		WPML_Translation_Element_Factory $element_factory,
		WPML_TM_Word_Count_Records $records,
		array $calculators,
		array $active_langs
	) {
		$this->element_factory  = $element_factory;
		$this->records          = $records;
		$this->post_calculators = $calculators;
		$this->active_langs     = $active_langs;
	}

	public function process( $post_id ) {
		$this->post_element = $this->element_factory->create( $post_id, 'post' );
		$word_count = new WPML_TM_Count();

		foreach ( $this->active_langs as $lang ) {
			if ( $this->post_element->get_language_code() === $lang ) {
				$word_count->set_total_words( $this->calculate_in_lang( null ) );
			} else {
				$word_count->set_words_to_translate( $lang, $this->calculate_in_lang( $lang ) );
			}
		}

		$this->records->set_post_word_count( $post_id, $word_count );
	}

	private function calculate_in_lang( $lang ) {
		$words = 0;

		foreach ( $this->post_calculators as $calculator ) {
			$words += $calculator->count_words( $this->post_element, $lang );
		}

		return $words;
	}
}

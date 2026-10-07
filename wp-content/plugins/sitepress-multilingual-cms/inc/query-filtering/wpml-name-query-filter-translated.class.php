<?php

class WPML_Name_Query_Filter_Translated extends WPML_Name_Query_Filter {

	private $pages_to_langs = array();

	protected function select_best_match( $pages_with_name ) {

		if ( ! empty( $pages_with_name['matching_ids'] ) ) {
			$matching_page = $this->get_matching_page_in_requested_lang( $pages_with_name['matching_ids'] );

			if ( $matching_page ) {
				return $matching_page;
			}

			$display_as_translated_page = $this->get_matching_page_displayed_as_translated();

			if ( $display_as_translated_page ) {
				return $display_as_translated_page;
			}
		}

		if ( ! empty( $pages_with_name['related_ids'] ) ) {
			return $this->get_the_best_related_page_to_redirect( $pages_with_name['related_ids'] );
		}

		return null;
	}

	private function get_matching_page_in_requested_lang( array $matching_ids ) {
		foreach ( $matching_ids as $matching_id ) {
			$page_lang = $this->post_translation->get_element_lang_code( (int) $matching_id );

			if ( $this->sitepress->get_current_language() === $page_lang ) {
				return (int) $matching_id;
			}

			$this->pages_to_langs[ $matching_id ] = $page_lang;
		}

		return null;
	}

	private function get_matching_page_displayed_as_translated() {
		foreach ( $this->pages_to_langs as $page_id => $lang ) {
			if ( $lang === $this->sitepress->get_default_language()
				 && $this->sitepress->is_display_as_translated_post_type( get_post_type( $page_id ) )
			) {
				return $this->post_translation->element_id_in( $page_id, $this->sitepress->get_current_language(), true );
			}
		}

		return null;
	}

	private function get_the_best_related_page_to_redirect( array $related_page_ids ) {
		foreach ( $this->active_languages as $lang_code ) {
			foreach ( $related_page_ids as $related_page_id ) {
				$page_lang = $this->post_translation->get_element_lang_code( (int) $related_page_id );

				if ( $page_lang === $lang_code ) {
					return $related_page_id;
				}
			}
		}

		return null;
	}

	protected function get_from_join_snippet() {

		return " FROM {$this->wpdb->posts} p
	             JOIN {$this->wpdb->prefix}icl_translations wpml_translations
					ON p.ID = wpml_translations.element_id
						AND wpml_translations.element_type = CONCAT('post_', p.post_type ) ";
	}
}

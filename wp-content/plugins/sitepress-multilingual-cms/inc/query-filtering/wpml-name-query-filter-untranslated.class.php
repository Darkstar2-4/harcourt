<?php

class WPML_Name_Query_Filter_Untranslated extends WPML_Name_Query_Filter {

	protected function select_best_match( $pages_with_name ) {
		if ( ! empty( $pages_with_name['matching_ids'] ) ) {
			return reset( $pages_with_name['matching_ids'] );
		} elseif ( ! empty( $pages_with_name['related_ids'] ) ) {
			return reset( $pages_with_name['related_ids'] );
		}

		return null;
	}

	protected function get_from_join_snippet() {

		return " FROM {$this->wpdb->posts} p ";
	}
}

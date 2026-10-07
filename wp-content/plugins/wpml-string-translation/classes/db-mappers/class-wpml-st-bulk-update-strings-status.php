<?php

class WPML_ST_Bulk_Update_Strings_Status {

	private $wpdb;

	private $active_lang_codes;

	public function __construct( wpdb $wpdb, array $active_lang_codes ) {
		$this->wpdb              = $wpdb;
		$this->active_lang_codes = $active_lang_codes;
	}

	public function run() {
		$updated_ids = $this->update_strings_with_no_translation();
		$updated_ids = $this->update_strings_with_all_translations_not_translated( $updated_ids );
		$updated_ids = $this->update_strings_with_one_translation_waiting_for_translator( $updated_ids );
		$updated_ids = $this->update_strings_with_one_translation_needs_update( $updated_ids );
		$updated_ids = $this->update_strings_with_less_translations_than_langs_and_one_translation_completed( $updated_ids );
		$updated_ids = $this->update_strings_with_less_translations_than_langs_and_no_translation_completed( $updated_ids );
		$updated_ids = $this->update_remaining_strings_with_one_not_translated( $updated_ids );
		$updated_ids = $this->update_remaining_strings( $updated_ids );

		return $updated_ids;
	}

	private function update_strings_with_no_translation() {
		$ids = $this->wpdb->get_col(
			"SELECT DISTINCT s.id FROM {$this->wpdb->prefix}icl_strings AS s
			 LEFT JOIN {$this->wpdb->prefix}icl_string_translations AS st ON st.string_id = s.id
			 WHERE st.string_id IS NULL"
		);

		$this->update_strings_status( $ids, ICL_TM_NOT_TRANSLATED );

		return $ids;
	}

	private function update_strings_with_all_translations_not_translated( array $updated_ids ) {
		$sql = $this->wpdb->prepare( " AND (st.status != %d OR (st.mo_string != '' AND st.mo_string IS NOT NULL))", ICL_TM_NOT_TRANSLATED );
		$subquery_not_exists = $this->get_translations_snippet() . $sql;

		$ids = $this->wpdb->get_col(
			"SELECT DISTINCT s.id FROM {$this->wpdb->prefix}icl_strings AS s
			 WHERE NOT EXISTS(" . $subquery_not_exists . ')'
				. $this->get_and_not_in_updated_snippet( $updated_ids )
		);

		$this->update_strings_status( $ids, ICL_TM_NOT_TRANSLATED );

		return array_merge( $updated_ids, $ids );
	}

	private function update_strings_with_one_translation_waiting_for_translator( array $updated_ids ) {
		$sql = $this->wpdb->prepare( ' AND st.status = %d', ICL_TM_WAITING_FOR_TRANSLATOR );
		$subquery = $this->get_translations_snippet() . $sql;

		return $this->update_string_ids_if_subquery_exists( $subquery, $updated_ids, ICL_TM_WAITING_FOR_TRANSLATOR );
	}

	private function update_strings_with_one_translation_needs_update( array $updated_ids ) {
		$sql = $this->wpdb->prepare( ' AND st.status = %d', ICL_TM_NEEDS_UPDATE );
		$subquery = $this->get_translations_snippet() . $sql;

		return $this->update_string_ids_if_subquery_exists( $subquery, $updated_ids, ICL_TM_NEEDS_UPDATE );
	}

	private function update_strings_with_less_translations_than_langs_and_one_translation_completed( array $updated_ids ) {
		$sql = $this->wpdb->prepare( " AND (st.status = %d OR (st.mo_string != '' AND st.mo_string IS NOT NULL))", ICL_TM_COMPLETE );
		$subquery = $this->get_translations_snippet() . $sql . $this->get_and_translations_less_than_secondary_languages_snippet();

		return $this->update_string_ids_if_subquery_exists( $subquery, $updated_ids, ICL_STRING_TRANSLATION_PARTIAL );
	}

	private function update_strings_with_less_translations_than_langs_and_no_translation_completed( array $updated_ids ) {
		$sql = $this->wpdb->prepare( ' AND st.status != %d', ICL_TM_COMPLETE );
		$subquery = $this->get_translations_snippet() . $sql . $this->get_and_translations_less_than_secondary_languages_snippet();

		return $this->update_string_ids_if_subquery_exists( $subquery, $updated_ids, ICL_TM_NOT_TRANSLATED );
	}

	private function update_remaining_strings_with_one_not_translated( array $updated_ids ) {
		$sql = $this->wpdb->prepare( " AND st.status = %d AND (st.mo_string = '' OR st.mo_string IS NULL)", ICL_TM_NOT_TRANSLATED );
		$subquery = $this->get_translations_snippet() . $sql;

		return $this->update_string_ids_if_subquery_exists( $subquery, $updated_ids, ICL_STRING_TRANSLATION_PARTIAL );
	}

	private function update_remaining_strings( array $updated_ids ) {
		$subquery = $this->get_translations_snippet();

		return $this->update_string_ids_if_subquery_exists( $subquery, $updated_ids, ICL_TM_COMPLETE );
	}

	private function update_string_ids_if_subquery_exists( $subquery, array $updated_ids, $new_status ) {
		$ids = $this->wpdb->get_col(
			"SELECT DISTINCT s.id FROM {$this->wpdb->prefix}icl_strings AS s
			 WHERE EXISTS(" . $subquery . ')'
			. $this->get_and_not_in_updated_snippet( $updated_ids )
		);

		$this->update_strings_status( $ids, $new_status );

		return array_merge( $updated_ids, $ids );
	}

	private function get_translations_snippet() {
		return "SELECT DISTINCT st.string_id
				FROM {$this->wpdb->prefix}icl_string_translations AS st
				WHERE st.string_id = s.id
					AND st.language != s.language";
	}

	private function get_and_translations_less_than_secondary_languages_snippet() {
		$secondary_languages_count = count( $this->active_lang_codes ) - 1;

		return $this->wpdb->prepare(
			" AND (
					SELECT COUNT( st2.id )
					FROM {$this->wpdb->prefix}icl_string_translations AS st2
					WHERE st2.string_id = s.id
						AND st2.language != s.language
						AND st2.language IN(" . wpml_prepare_in( $this->active_lang_codes ) . ')
			) < %d',
			$secondary_languages_count
		);
	}

	private function get_and_not_in_updated_snippet( array $updated_ids ) {
		return ' AND s.id NOT IN(' . wpml_prepare_in( $updated_ids ) . ')';
	}

	private function update_strings_status( array $ids, $status ) {
		if ( ! $ids ) {
			return;
		}

		$sql = $this->wpdb->prepare(
			"UPDATE {$this->wpdb->prefix}icl_strings SET status = %d WHERE id IN(" . wpml_prepare_in( $ids ) . ')',
			$status
		);

		$this->wpdb->query( $sql );
	}
}

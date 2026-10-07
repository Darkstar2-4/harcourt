<?php

class WPML_Display_As_Translated_Taxonomy_Query extends WPML_Display_As_Translated_Query {

	private $term_taxonomy_table;

	public function __construct( wpdb $wpdb, $term_taxonomy_table_alias = null ) {
		parent::__construct( $wpdb, 'icl_t' );
		$this->term_taxonomy_table = $term_taxonomy_table_alias ? $term_taxonomy_table_alias : $wpdb->term_taxonomy;
	}

	public function update_count( $clauses, $default_lang ) {
		$clauses['fields'] = $this->update_count_in_fields( $clauses['fields'], $default_lang );
		$clauses['where']  = $this->update_count_in_where( $clauses['where'], $default_lang );

		return $clauses;
	}

	private function update_count_in_fields( $fields, $default_lang ) {
		$sql = "
			tt.*,
			GREATEST(
				tt.count,
				(
			        SELECT term_taxonomy.count
		            FROM {$this->wpdb->term_taxonomy} term_taxonomy
		            INNER JOIN {$this->wpdb->prefix}icl_translations translations ON translations.element_id = term_taxonomy.term_taxonomy_id
		            WHERE translations.trid = icl_t.trid AND translations.language_code = %s
				)
			) as `count`
		";
		$sql = $this->wpdb->prepare( $sql, $default_lang );

		return str_replace( 'tt.*', $sql, $fields );
	}

	private function update_count_in_where( $where, $default_lang ) {
		$sql = "
		(
	        tt.count > 0
	        OR (
		        SELECT term_taxonomy.count
		        FROM {$this->wpdb->term_taxonomy} term_taxonomy
		        INNER JOIN {$this->wpdb->prefix}icl_translations translations ON translations.element_id = term_taxonomy.term_taxonomy_id
		        WHERE translations.trid = icl_t.trid AND translations.language_code = %s
	        ) > 0
	    )
		";
		$sql = $this->wpdb->prepare( $sql, $default_lang );

		return str_replace( 'tt.count > 0', $sql, $where );
	}

	protected function get_content_types_query( $taxonomies ) {
		$taxonomies = wpml_prepare_in( $taxonomies );
		return "{$this->term_taxonomy_table}.taxonomy IN ( {$taxonomies} )";
	}

	protected function get_query_for_translation_not_published( $language ) {
		return '0';
	}

}

<?php

abstract class WPML_Display_As_Translated_Query {

	protected $wpdb;

	protected $icl_translation_table_alias;

	public function __construct( wpdb $wpdb, $icl_translation_table_alias = 'wpml_translations' ) {
		$this->wpdb                        = $wpdb;
		$this->icl_translation_table_alias = $icl_translation_table_alias;
	}

	public function get_icl_translations_table_alias() {
		return is_string( $this->icl_translation_table_alias ) && ! empty( $this->icl_translation_table_alias )
			? $this->icl_translation_table_alias
			: 'wpml_translations';
	}

	public function get_language_snippet( $current_language, $fallback_language, $content_types, $skip_content_type_check = false ) {
		if ( ! $fallback_language || ( ! $skip_content_type_check && ! $content_types ) ) {
			return '0';
		}

		$content_types_query = $skip_content_type_check ? '' : 'AND ' . $this->get_content_types_query( $content_types );

		$sub_query_no_translation            = $this->get_query_for_no_translation( $current_language );
		$sub_query_translation_not_published = $this->get_query_for_translation_not_published( $current_language );

		return $this->wpdb->prepare(
			"(
					{$this->icl_translation_table_alias}.language_code = %s
					{$content_types_query}
					AND ( ( {$sub_query_no_translation} ) OR ( {$sub_query_translation_not_published} ) ) 
				)",
			$fallback_language
		);
	}

	private function get_query_for_no_translation( $language ) {
		return $this->wpdb->prepare(
			"
			( SELECT COUNT(element_id)
			  FROM {$this->wpdb->prefix}icl_translations
			  WHERE trid = {$this->icl_translation_table_alias}.trid
			  AND language_code = %s
			) = 0
			",
			$language
		);
	}

	abstract protected function get_content_types_query( $content_types );

	abstract protected function get_query_for_translation_not_published( $language );

}

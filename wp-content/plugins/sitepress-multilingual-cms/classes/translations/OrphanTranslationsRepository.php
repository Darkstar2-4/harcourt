<?php

namespace WPML\Translation;

class OrphanTranslationsRepository {

	private $wpdb;

	public function __construct( \wpdb $wpdb ) {
		$this->wpdb = $wpdb;
	}

	public function hasOrphans( $element_type, $source_language ) {
		$sql = "SELECT 1
			FROM {$this->wpdb->posts} p
			INNER JOIN {$this->wpdb->prefix}icl_translations t
				ON p.ID = t.element_id
				AND t.element_type = %s
				AND t.language_code <> %s
			LEFT JOIN {$this->wpdb->prefix}icl_translations s
				ON s.trid = t.trid
				AND s.element_type = %s
				AND s.language_code = %s
			WHERE s.translation_id IS NULL
			LIMIT 1";
		$sql_prepared = $this->wpdb->prepare( $sql, array( $element_type, $source_language, $element_type, $source_language ) );

		return (bool) $this->wpdb->get_var( $sql_prepared );
	}

	public function getOrphans( $element_type, $source_language ) {
		$sql = "SELECT t.trid AS value,
		       CONCAT('[', t.language_code, '] ', (CASE p.post_title WHEN '' THEN CONCAT(LEFT(p.post_content, 30), '...') ELSE p.post_title END)) AS label
			FROM {$this->wpdb->posts} p
			INNER JOIN {$this->wpdb->prefix}icl_translations t
				ON p.ID = t.element_id
				AND t.element_type = %s
				AND t.language_code <> %s
			LEFT JOIN {$this->wpdb->prefix}icl_translations s
				ON s.trid = t.trid
				AND s.element_type = %s
				AND s.language_code = %s
			WHERE s.translation_id IS NULL
			ORDER BY t.trid";
		$sql_prepared = $this->wpdb->prepare( $sql, array( $element_type, $source_language, $element_type, $source_language ) );

		return $this->wpdb->get_results( $sql_prepared );
	}
}

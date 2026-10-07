<?php

class WPML_Fix_Type_Assignments extends WPML_WPDB_And_SP_User {

	public function __construct( $sitepress ) {
		$wpdb = $sitepress->wpdb();
		parent::__construct( $wpdb, $sitepress );
	}

	public function run( $data = [] ) {
		$rows_left = 0;

		$rows_fixed  = $this->fix_broken_duplicate_rows();
		$rows_fixed += $this->fix_missing_original();
		$rows_fixed += $this->fix_wrong_source_language();
		$rows_fixed += $this->fix_broken_type_assignments();
		$rows_fixed += $this->fix_broken_taxonomy_assignments();
		$rows_fixed += $this->fix_broken_post_assignments();
		$rows_fixed += $this->fix_mismatched_types();

		$res = $this->fix_orphan_attachments( $data );

		$rows_fixed += $res[0];
		$rows_left  += $res[1];

		icl_cache_clear();
		wp_cache_init();

		return [
			'rowsFixed' => $rows_fixed,
			'rowsLeft'  => $rows_left,
		];
	}

	private function fix_broken_duplicate_rows() {

		$rows_fixed = $this->wpdb->query(
			"
			DELETE t
			FROM {$this->wpdb->prefix}icl_translations i
			  JOIN {$this->wpdb->prefix}icl_translations t
			    ON i.element_id = t.element_id
			       AND SUBSTRING_INDEX(i.element_type, '_', 1) =
			           SUBSTRING_INDEX(t.element_type, '_', 1)
			       AND i.element_type != t.element_type
			       AND i.translation_id != t.translation_id
			  JOIN (SELECT
			          CONCAT('post_', p.post_type) AS element_type,
			          p.ID                         AS element_id
			        FROM {$this->wpdb->posts} p
			        UNION ALL
			        SELECT
			          CONCAT('tax_', tt.taxonomy) AS element_type,
			          tt.term_taxonomy_id         AS element_id
			        FROM {$this->wpdb->term_taxonomy} tt) AS data
			    ON data.element_id = i.element_id
			       AND data.element_type = i.element_type"
		);

		if ( 0 < $rows_fixed ) {
			do_action(
				'wpml_translation_update',
				array(
					'type'          => 'delete',
					'rows_affected' => $rows_fixed,
				)
			);
		}

		return $rows_fixed;
	}

	private function fix_broken_taxonomy_assignments() {

		$rows_fixed = $this->wpdb->query(
			"UPDATE {$this->wpdb->prefix}icl_translations t
									JOIN {$this->wpdb->term_taxonomy} tt
										ON tt.term_taxonomy_id = t.element_id
											AND t.element_type LIKE 'tax%'
											AND t.element_type <> CONCAT('tax_', tt.taxonomy)
									SET t.element_type = CONCAT('tax_', tt.taxonomy)"
		);

		if ( 0 < $rows_fixed ) {
			do_action(
				'wpml_translation_update',
				array(
					'context'       => 'tax',
					'type'          => 'element_type_update',
					'rows_affected' => $rows_fixed,
				)
			);
		}

		return $rows_fixed;
	}

	private function fix_broken_post_assignments() {

		$rows_fixed = $this->wpdb->query(
			"UPDATE {$this->wpdb->prefix}icl_translations t
									JOIN {$this->wpdb->posts} p
										ON p.ID = t.element_id
											AND t.element_type LIKE 'post%'
											AND t.element_type <> CONCAT('post_', p.post_type)
									SET t.element_type = CONCAT('post_', p.post_type)"
		);

		if ( 0 < $rows_fixed ) {
			do_action(
				'wpml_translation_update',
				array(
					'context'       => 'tax',
					'type'          => 'element_type_update',
					'rows_affected' => $rows_fixed,
				)
			);
		}

		return $rows_fixed;
	}

	private function fix_broken_type_assignments() {

		$rows_fixed = $this->wpdb->query(
			"UPDATE {$this->wpdb->prefix}icl_translations t
									JOIN {$this->wpdb->prefix}icl_translations c
										ON c.trid = t.trid
											AND c.language_code != t.language_code
									SET t.element_type = c.element_type
									WHERE c.source_language_code IS NULL
										AND t.source_language_code IS NOT NULL"
		);

		if ( 0 < $rows_fixed ) {
			do_action(
				'wpml_translation_update',
				array(
					'type'          => 'element_type_update',
					'rows_affected' => $rows_fixed,
				)
			);
		}

		return $rows_fixed;
	}

	private function fix_wrong_source_language() {

		return $this->wpdb->query(
			"UPDATE {$this->wpdb->prefix}icl_translations
									SET source_language_code = NULL
									WHERE source_language_code = ''
										OR source_language_code = language_code"
		);
	}

	private function fix_missing_original() {
		$broken_elements = $this->wpdb->get_results(
			"	SELECT MIN(iclt.element_id) AS element_id, iclt.trid
				FROM {$this->wpdb->prefix}icl_translations iclt
				LEFT JOIN {$this->wpdb->prefix}icl_translations iclo
					ON iclt.trid = iclo.trid
					AND iclo.source_language_code IS NULL
				WHERE iclo.translation_id IS NULL
				GROUP BY iclt.trid"
		);
		$rows_affected   = 0;
		foreach ( $broken_elements as $element ) {
			$rows_affected_per_element = $this->wpdb->query(
				$this->wpdb->prepare(
					"UPDATE {$this->wpdb->prefix}icl_translations
					 SET source_language_code = NULL
					 WHERE trid = %d AND element_id = %d",
					$element->trid,
					$element->element_id
				)
			);

			if ( 0 < $rows_affected_per_element ) {
				do_action(
					'wpml_translation_update',
					array( 'trid' => $element->trid )
				);
			}

			$rows_affected += $rows_affected_per_element;
		}

		return $rows_affected;
	}

	private function fix_mismatched_types() {
		$rows_affected = $this->wpdb->query(
			"DELETE t
				FROM {$this->wpdb->prefix}icl_translations t
				JOIN {$this->wpdb->prefix}icl_translations o
					ON o.trid = t.trid
					AND o.language_code != t.language_code
					AND o.source_language_code IS NULL
					AND t.source_language_code IS NOT NULL
					AND o.element_type <> t.element_type"
		);

		if ( 0 < $rows_affected ) {
			do_action(
				'wpml_translation_update',
				array(
					'type'          => 'delete',
					'rows_affected' => $rows_affected,
				)
			);
		}

		return $rows_affected;
	}

	private function fix_orphan_attachments( $data ) {
		$has_orphan_attachments = $this->wpdb->get_var(
			"SELECT ID
			FROM {$this->wpdb->posts} as posts
			LEFT JOIN {$this->wpdb->prefix}icl_translations as translations
			ON posts.ID = translations.element_id
			WHERE posts.post_type = 'attachment'
			AND translations.element_id IS NULL
			LIMIT 0, 1"
		);

		if ( ! $has_orphan_attachments ) {
			return [ 0, 0 ];
		}

		$default_language     = $this->sitepress->get_default_language();
		$limit                = array_key_exists( 'limit', $data ) ? (int) $data['limit'] : 10;
		$attachments_prepared = $this->wpdb->prepare(
			"
        SELECT SQL_CALC_FOUND_ROWS ID FROM {$this->wpdb->posts} WHERE post_type = %s AND ID NOT IN
        (SELECT element_id FROM {$this->wpdb->prefix}icl_translations WHERE element_type=%s) LIMIT %d",
			array(
				'attachment',
				'post_attachment',
				$limit,
			)
		);

		$attachments = $this->wpdb->get_col( $attachments_prepared );
		$found       = (int) $this->wpdb->get_var( 'SELECT FOUND_ROWS()' );

		foreach ( $attachments as $attachment_id ) {
			$this->sitepress->set_element_language_details( $attachment_id, 'post_attachment', false, $default_language );
		}

		$left = max( $found - $limit, 0 );

		return [ $limit, $left ];
	}
}

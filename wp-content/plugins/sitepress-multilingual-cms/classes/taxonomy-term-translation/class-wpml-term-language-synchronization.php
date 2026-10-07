<?php

class WPML_Term_Language_Synchronization extends WPML_WPDB_And_SP_User {

	private $taxonomy;
	private $data;
	private $missing_terms = array();
	private $term_utils;

	public function __construct( &$sitepress, &$term_utils, $taxonomy ) {
		$wpdb = $sitepress->wpdb();
		parent::__construct( $wpdb, $sitepress );
		$this->term_utils = $term_utils;
		$this->taxonomy   = $taxonomy;
		$this->data       = $this->set_affected_ids();
		$this->prepare_missing_terms_data();
	}

	public function set_translated() {
		$this->prepare_missing_originals();
		$this->reassign_terms();
		$this->set_initial_term_language();
	}

	public function set_initial_term_language() {
		$element_ids      = $this->wpdb->get_col(
			$this->wpdb->prepare(
				"
													SELECT tt.term_taxonomy_id
													FROM {$this->wpdb->term_taxonomy} AS tt
													LEFT JOIN {$this->wpdb->prefix}icl_translations AS i
														ON tt.term_taxonomy_id = i.element_id
															AND CONCAT('tax_', tt.taxonomy) = i.element_type
													WHERE taxonomy = %s
														AND i.element_id IS NULL",
				$this->taxonomy
			)
		);
		$default_language = $this->sitepress->get_default_language();
		foreach ( $element_ids as $id ) {
			$this->sitepress->set_element_language_details( $id, 'tax_' . $this->taxonomy, false, $default_language );
		}
	}

	private function reassign_terms() {
		$update_query = $this->wpdb->prepare(
			"UPDATE {$this->wpdb->term_relationships} AS o,
					{$this->wpdb->prefix}icl_translations AS ic,
					{$this->wpdb->prefix}icl_translations AS iw,
					{$this->wpdb->prefix}icl_translations AS ip,
					{$this->wpdb->posts} AS p
						SET o.term_taxonomy_id = ic.element_id
						WHERE ic.trid = iw.trid
							AND ic.element_type = iw.element_type
							AND iw.element_id = o.term_taxonomy_id
							AND ic.language_code = ip.language_code
							AND ip.element_type = CONCAT('post_', p.post_type)
							AND ip.element_id = p.ID
							AND o.object_id = p.ID
							AND o.term_taxonomy_id != ic.element_id
							AND iw.element_type = %s",
			'tax_' . $this->taxonomy
		);

		$rows_affected = $this->wpdb->query( $update_query );
		if ( $rows_affected ) {
			$term_ids = $this->wpdb->get_col(
				$this->wpdb->prepare(
					"SELECT term_taxonomy_id FROM {$this->wpdb->term_taxonomy} WHERE taxonomy = %s",
					$this->taxonomy
				)
			);
			$taxonomy_object = $this->sitepress->get_wp_api()->get_taxonomy( $this->taxonomy );
			if ( $taxonomy_object && isset( $taxonomy_object->object_type ) ) {
				$this->sitepress->get_wp_api()->wp_update_term_count( $term_ids, $this->taxonomy );
			}
		}
	}

	private function format_data( $sql_result ) {
		$res = array();
		foreach ( $sql_result as $pair ) {
			$res[ $pair->ttid ] = isset( $res[ $pair->ttid ] )
				? $res[ $pair->ttid ]
				: array(
					'tlang'  => array(),
					'plangs' => array(),
				);

			if ( $pair->term_lang && $pair->trid ) {
				$res[ $pair->ttid ]['tlang'] = array(
					'lang' => $pair->term_lang,
					'trid' => $pair->trid,
				);
			}
			if ( $pair->post_lang ) {
				$res[ $pair->ttid ]['plangs'][ $pair->post_id ] = $pair->post_lang;
			}
		}

		return $res;
	}

	private function prepare_missing_translations(
		$trid,
		$source_lang,
		$langs
	) {
		$existing_translations = $this->sitepress->term_translations()->get_element_translations(
			false,
			$trid
		);
		foreach ( $langs as $lang ) {
			if ( ! isset( $existing_translations[ $lang ] ) ) {
				$this->term_utils->create_automatic_translation(
					array(
						'lang_code'       => $lang,
						'source_language' => $source_lang,
						'trid'            => $trid,
						'taxonomy'        => $this->taxonomy,
					)
				);
			}
		}
	}

	private function set_affected_ids() {
		$query_for_post_ids = $this->wpdb->prepare(
			"
				SELECT tl.trid AS trid, tl.ttid AS ttid, tl.tlang AS term_lang, tl.pid AS post_id, pl.plang AS post_lang
				FROM (
					SELECT
					o.object_id AS pid,
					tt.term_taxonomy_id AS ttid,
					i.language_code AS tlang,
					i.trid AS trid
				FROM {$this->wpdb->term_relationships} AS o
				JOIN {$this->wpdb->term_taxonomy} AS tt
					ON o.term_taxonomy_id = tt.term_taxonomy_id
				LEFT JOIN {$this->wpdb->prefix}icl_translations AS i
					ON i.element_id = tt.term_taxonomy_id
						AND i.element_type = CONCAT('tax_', tt.taxonomy)
				WHERE tt.taxonomy = %s) AS tl
				LEFT JOIN
				( SELECT p.ID AS pid, i.language_code AS plang
					FROM {$this->wpdb->posts} AS p
					JOIN {$this->wpdb->prefix}icl_translations AS i
						ON i.element_id = p.ID
							AND i.element_type = CONCAT('post_', p.post_type)
				) AS pl
					ON tl.pid = pl.pid
				",
			$this->taxonomy
		);

		$ttid_pid_pairs = $this->wpdb->get_results( $query_for_post_ids );

		return is_array( $ttid_pid_pairs )
			? $this->format_data( $ttid_pid_pairs )
			: [];
	}

	private function prepare_missing_originals() {
		foreach ( $this->missing_terms as $ttid => $missing_lang_data ) {
			if ( ! isset( $this->data[ $ttid ]['tlang']['trid'] ) ) {
				foreach ( $missing_lang_data as $lang => $post_ids ) {
					$this->sitepress->set_element_language_details(
						$ttid,
						'tax_' . $this->taxonomy,
						null,
						$lang
					);
					$trid = $this->sitepress->term_translations()->get_element_trid( $ttid );
					if ( $trid ) {
						$this->data[ $ttid ]['tlang']['trid'] = $trid;
						$this->data[ $ttid ]['tlang']['lang'] = $lang;
						unset( $this->missing_terms[ $ttid ][ $lang ] );
						break;
					}
				}
			}
			if ( isset( $this->data[ $ttid ]['tlang']['trid'] ) ) {
				$this->prepare_missing_translations(
					$this->data[ $ttid ]['tlang']['trid'],
					$this->data[ $ttid ]['tlang']['lang'],
					array_keys( $this->missing_terms[ $ttid ] )
				);
			}
		}
	}

	private function prepare_missing_terms_data() {
		$default_lang = $this->sitepress->get_default_language();
		$data         = $this->data;
		$missing      = array();
		foreach ( $data as $ttid => $data_item ) {
			if ( empty( $data_item['plangs'] ) && empty( $data_item['tlang'] ) ) {
				$missing[ $ttid ][ $default_lang ] = - 1;
			} else {
				$affected_languages = array_diff( $data_item['plangs'], $data_item['tlang'] );
				if ( ! empty( $affected_languages ) ) {
					foreach ( $data_item['plangs'] as $post_id => $lang ) {
						if ( ! isset( $missing[ $ttid ][ $lang ] ) ) {
							$missing[ $ttid ][ $lang ] = array( $post_id );
						} else {
							$missing[ $ttid ][ $lang ][] = $post_id;
						}
					}
				}
			}
		}
		$this->missing_terms = $missing;
	}
}

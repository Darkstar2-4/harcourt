<?php

use WPML\FP\Fns;
use WPML\LIB\WP\Cache;

class WPML_Term_Translation extends WPML_Element_Translation {
	const CACHE_MAX_WARMUP_COUNT = 200;
	const CACHE_EXPIRE           = 0;
	const CACHE_GROUP            = 'wpml_term_translation';

	protected $cache_max_warmup_count = self::CACHE_MAX_WARMUP_COUNT;

	public function get_cache_max_warmup_count() {
		return $this->cache_max_warmup_count;
	}

	public function set_cache_max_warmup_count( $cache_max_warmup_count ) {
		$this->cache_max_warmup_count = $cache_max_warmup_count;
	}

	public function add_hooks() {
		add_action( 'delete_term', array( $this, 'invalidate_cache' ) );

		add_action( 'set_object_terms', array( $this, 'invalidate_cache' ) );

		add_action( 'saved_term', array( $this, 'invalidate_cache' ) );

		add_action( 'deleted_term_relationships', array( $this, 'invalidate_cache' ) );

		add_action( 'clean_object_term_cache', array( $this, 'invalidate_cache' ) );

		add_action( 'clean_term_cache', array( $this, 'invalidate_cache' ) );
	}

	private function get_cache_expire() {
		return self::CACHE_EXPIRE;
	}

	public function invalidate_cache() {
		Cache::flushGroup( self::CACHE_GROUP );
	}

	public function lang_code_by_termid( $term_id ) {
		return $this->get_element_lang_code( (int) $this->adjust_ttid_for_term_id( $term_id ) );
	}

	private function set_data_to_cache( array $data ) {
		$expire = $this->get_cache_expire();
		foreach ( $data as $row ) {
			Cache::set( self::CACHE_GROUP, 'ttid_by_termid_' . $row['term_id'], $expire, (int) $row['element_id'] );
			Cache::set( self::CACHE_GROUP, 'ttid_by_termidtaxonomy_' . $row['term_id'] . $row['taxonomy'], $expire, (int) $row['element_id'] );
			Cache::set( self::CACHE_GROUP, 'termid_by_ttid_' . $row['element_id'], $expire, (int) $row['term_id'] );
		}
	}

	private function is_cache_type_select_all_items_in_one_query( $count ) {
		return $count <= $this->get_cache_max_warmup_count();
	}

	public function adjust_ttid_for_term_id( $term_id ) {
		$count = $this->maybe_warm_term_id_cache();
		if ( 0 === $count ) {
			return $term_id;
		}

		$key_prefix = 'ttid_by_termid_';
		$key        = $key_prefix . $term_id;

		$get_ttid_or_fallback_to_term_id = function() use ( $key_prefix, $key, $term_id, $count ) {
			if ( $this->is_cache_type_select_all_items_in_one_query( $count ) ) {
				return $term_id;
			}

			$data = $this->get_maybe_warm_term_id_cache_data( ' AND tax.term_id = %d', array( $term_id ) );
			if ( count( $data ) ) {
				$this->set_data_to_cache( $data );
			} else {
				Cache::set( self::CACHE_GROUP, $key_prefix . $term_id, $this->get_cache_expire(), $term_id );
			}

			return Cache::get( self::CACHE_GROUP, $key )->getOrElse( $term_id );
		};

		return Cache::get( self::CACHE_GROUP, $key )->getOrElse( $get_ttid_or_fallback_to_term_id );
	}

	public function adjust_term_id_for_ttid( $ttid ) {
		$count = $this->maybe_warm_term_id_cache();
		if ( 0 === $count ) {
			return $ttid;
		}

		$key_prefix = 'termid_by_ttid_';
		$key        = $key_prefix . $ttid;

		$get_term_id_or_fallback_to_ttid = function() use ( $key_prefix, $key, $ttid, $count ) {
			if ( $this->is_cache_type_select_all_items_in_one_query( $count ) ) {
				return $ttid;
			}

			$data = $this->get_maybe_warm_term_id_cache_data( ' AND tax.term_taxonomy_id = %d', array( $ttid ) );
			if ( count( $data ) ) {
				$this->set_data_to_cache( $data );
			} else {
				Cache::set( self::CACHE_GROUP, $key_prefix . $ttid, $this->get_cache_expire(), $ttid );
			}

			return Cache::get( self::CACHE_GROUP, $key )->getOrElse( $ttid );
		};

		return Cache::get( self::CACHE_GROUP, $key )->getOrElse( $get_term_id_or_fallback_to_ttid );
	}

	public function term_id_in( $term_id, $lang_code, $original_fallback = false ) {

		return $this->adjust_term_id_for_ttid(
			$this->element_id_in( (int) $this->adjust_ttid_for_term_id( $term_id ), $lang_code, $original_fallback )
		);
	}

	public function trid_from_tax_and_id( $term_id, $taxonomy ) {
		$count = $this->maybe_warm_term_id_cache();
		if ( 0 === $count ) {
			return $this->get_element_trid( $term_id );
		}

		$key_prefix = 'ttid_by_termidtaxonomy_';
		$key        = $key_prefix . $term_id . $taxonomy;

		$get_term_id = function() use ( $key_prefix, $key, $term_id, $taxonomy, $count ) {
			if ( $this->is_cache_type_select_all_items_in_one_query( $count ) ) {
				return $term_id;
			}

			$data = $this->get_maybe_warm_term_id_cache_data( ' AND tax.term_id = %d AND tax.taxonomy = %s', array( $term_id, $taxonomy ) );
			if ( count( $data ) ) {
				$this->set_data_to_cache( $data );
			} else {
				Cache::set( self::CACHE_GROUP, $key_prefix . $term_id . $taxonomy, $this->get_cache_expire(), $term_id );
			}

			return Cache::get( self::CACHE_GROUP, $key )->getOrElse( $term_id );
		};

		$ttid = Cache::get( self::CACHE_GROUP, $key )->getOrElse( $get_term_id );

		return $this->get_element_trid( $ttid );
	}

	public function get_taxonomy_post_types( $taxonomy ) {
		return WPML_WP_Taxonomy::get_linked_post_types( $taxonomy );
	}

	protected function get_element_join() {

		return "
				JOIN {$this->wpdb->term_taxonomy} tax
					ON wpml_translations.element_id = tax.term_taxonomy_id
						AND wpml_translations.element_type = CONCAT('tax_', tax.taxonomy)
		";
	}

	protected function get_query_sql( $cols = 'wpml_translations.element_id, tax.term_id, tax.taxonomy' ) {
		$sql  = '';
		$sql .= "SELECT {$cols} FROM {$this->wpdb->prefix}icl_translations wpml_translations" . $this->get_element_join();
		$sql .= " JOIN {$this->wpdb->terms} terms";
		$sql .= ' ON terms.term_id = tax.term_id';
		$sql .= ' WHERE tax.term_id != tax.term_taxonomy_id';

		return $sql;
	}

	protected function get_type_prefix() {
		return 'tax_';
	}

	private function maybe_warm_term_id_cache() {
		$key = 'items_count_for_cache';

		$get_count = function() use ( $key ) {
			$sql = $this->get_query_sql( 'tax.term_id' );
			$sql = $sql . ' LIMIT 0, ' . ( $this->get_cache_max_warmup_count() + 1 );

			$data  = $this->wpdb->get_results( $sql, ARRAY_A );
			$count = is_array( $data ) ? count( $data ) : 0;

			Cache::set( self::CACHE_GROUP, $key, $this->get_cache_expire(), $count );

			if ( $count > 0 && $count <= $this->get_cache_max_warmup_count() ) {
				$data = $this->get_maybe_warm_term_id_cache_data();
				$this->set_data_to_cache( $data );
			}

			return $count;
		};

		return Cache::get( self::CACHE_GROUP, $key )->getOrElse( $get_count );
	}

	private function get_maybe_warm_term_id_cache_data( $where_sql = '', $where_sql_params = array() ) {
		$sql = $this->get_query_sql();

		if ( count( $where_sql_params ) > 0 ) {
			$sql = $this->wpdb->prepare( $sql . $where_sql, $where_sql_params );
		}

		$data = $this->wpdb->get_results( $sql, ARRAY_A );

		return $data;
	}

	public function generate_unique_term_slug( $term, $slug, $taxonomy, $lang_code ) {
		if ( '' === trim( $slug ) ) {
			$slug = sanitize_title( $term );
		}
		return WPML_Terms_Translations::term_unique_slug( $slug, $taxonomy, $lang_code );
	}

	public static function getGlobalInstance() {
		global $wpml_term_translations, $wpdb;

		if ( ! isset( $wpml_term_translations ) ) {
			$wpml_term_translations = new WPML_Term_Translation( $wpdb );
		}

		return $wpml_term_translations;
	}
}

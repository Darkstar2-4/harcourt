<?php

abstract class WPML_Name_Query_Filter extends WPML_Slug_Resolution {

	protected $post_type;

	protected $indexes = array( 'name' );

	protected $id_index = 'p';

	protected $active_languages = array();

	protected $al_regexp;

	protected $post_translation;

	protected $is_translated;

	public function __construct( $post_type, &$sitepress, &$post_translations, &$wpdb ) {
		parent::__construct( $wpdb, $sitepress );
		$this->post_type        = $post_type;
		$this->indexes[]        = $post_type;
		$this->is_translated    = $this->sitepress->is_translated_post_type( $post_type );
		$this->post_translation = &$post_translations;
	}

	public function filter_page_name( WP_Query $page_query ) {
		$this->active_languages = $this->get_ordered_langs();
		$this->al_regexp        = $this->generate_al_regexp( $this->active_languages );
		foreach ( $this->indexes as $index ) {
			list( $pages_with_name, $page_name_for_query ) = $this->query_needs_adjustment( $page_query, $index );
			if ( ! empty( $pages_with_name['matching_ids'] ) || ! empty( $pages_with_name['related_ids'] ) ) {
				$pid = $this->select_best_match( $pages_with_name );
				if ( isset( $pid ) ) {
					if ( ! isset( $page_query->queried_object_id ) || $pid != $page_query->queried_object_id ) {
						$page_query = $this->maybe_adjust_query_by_pid( $page_query, $pid, $index );
						break;
					} else {
						unset( $pid );
					}
				} elseif ( (bool) $page_name_for_query === true ) {
					$page_query->query_vars[ $index ]    = $page_name_for_query;
					$page_query->query_vars['post_type'] = $this->post_type;
				}
			}
		}

		return array( $page_query, isset( $pid ) ? $pid : false );
	}

	abstract protected function select_best_match( $pages_with_name );

	protected function maybe_adjust_query_by_pid( $page_query, $pid, $index ) {
		if ( ! ( isset( $page_query->queried_object )
				 && isset( $page_query->queried_object->ID )
				 && (int) $page_query->queried_object->ID === (int) $pid )
		) {
			if ( isset( $page_query->query['page_id'] ) ) {
				$page_query->query['page_id'] = (int) $pid;
			}
			$page_query->query_vars['page_id']         = null;
			$page_query->query_vars[ $this->id_index ] = (int) $pid;
			if ( isset( $page_query->query[ $this->id_index ] ) ) {
				$page_query->query[ $this->id_index ] = (int) $pid;
			}
			$page_query->is_page = false;
			if ( isset( $page_query->query_vars[ $this->post_type ] )
				 && $this->post_type !== 'page'
			) {
				$this->set_query_var_to_restore( $this->post_type, $page_query );
				unset( $page_query->query_vars[ $this->post_type ] );
			}
			unset( $page_query->query_vars[ $index ] );
			if ( ! empty( $page_query->queried_object ) ) {
				$page_query->queried_object    = get_post( $pid );
				$page_query->queried_object_id = (int) $pid;
			}

			$page_query = $this->adjusting_id( $page_query );
		}

		return $page_query;
	}


	protected function adjusting_id( $page_query ) {
		$page_query->is_single = true;

		return $page_query;
	}

	abstract protected function get_from_join_snippet();

	private function generate_al_regexp( $active_language_codes ) {

		return '/^(' . implode( '|', $active_language_codes ) . ')\//';
	}

	private function query_needs_adjustment( WP_Query $page_query, $index ) {
		if ( empty( $page_query->query_vars[ $index ] ) ) {
			$pages_with_name     = false;
			$page_name_for_query = false;
		} else {
			$page_name_for_query = preg_replace( $this->al_regexp, '', $page_query->query_vars[ $index ] );

			if ( $this->page_name_has_parent( $page_name_for_query ) ) {
				$pages_with_name = $this->get_multiple_slug_adjusted_IDs( explode( '/', $page_name_for_query ) );
			} else {
				$pages_with_name = $this->get_single_slug_adjusted_IDs(
					$page_name_for_query,
					$this->get_post_parent_query_var( $page_query )
				);
			}
		}

		return array( $pages_with_name, $page_name_for_query );
	}

	private function page_name_has_parent( $page_name_for_query ) {
		return false !== strpos( $page_name_for_query, '/' );
	}

	private function get_post_parent_query_var( WP_Query $page_query ) {
		$post_parent = 0;

		if ( isset( $page_query->query_vars['post_parent'] ) ) {
			$post_parent = $page_query->query_vars['post_parent'];
		}

		return $post_parent;
	}

	private function get_single_slug_adjusted_IDs( $page_name_for_query, $post_parent ) {
		$cache     = new WPML_WP_Cache( get_class( $this ) );
		$cache_key = 'get_single_slug_adjusted_IDs' . $this->post_type . $page_name_for_query . $post_parent;
		$found     = false;

		$pages_with_name = $cache->get( $cache_key, $found );

		if ( ! $found ) {
			$pages_with_name = $this->get_single_slug_adjusted_IDs_from_DB( $page_name_for_query, $post_parent );
			$cache->set( $cache_key, $pages_with_name );
		}

		return array( 'matching_ids' => $pages_with_name );
	}

	private function get_single_slug_adjusted_IDs_from_DB( $page_name_for_query, $post_parent ) {
		$pages_with_name = $this->wpdb->get_col(
			$this->wpdb->prepare(
				'
				SELECT ID
				' . $this->get_from_join_snippet()
				. $this->get_where_snippet() . ' p.post_name = %s
				ORDER BY p.post_parent = %d DESC, p.ID DESC
				',
				$page_name_for_query,
				$post_parent
			)
		);

		return $pages_with_name;
	}

	private function get_multiple_slug_adjusted_IDs( $slugs ) {
		$parent_slugs    = array_slice( $slugs, 0, - 1 );

		$pages_with_name = $this->wpdb->get_results(
			'   SELECT p.ID, p.post_name, p.post_parent, par.post_name as parent_name
			' . $this->get_from_join_snippet() . "
			LEFT JOIN {$this->wpdb->posts} par
				ON par.ID = p.post_parent
			" . $this->get_where_snippet() . ' p.post_name IN (' . wpml_prepare_in( $slugs ) . ')
			ORDER BY par.post_name IN (' . wpml_prepare_in( $parent_slugs ) . ') DESC'
		);

		if ( ! $pages_with_name ) {
			return [];
		}

		$query_scorer = new WPML_Score_Hierarchy( $pages_with_name, $slugs );

		return $query_scorer->get_possible_ids_ordered();
	}

	private function get_where_snippet() {

		return $this->wpdb->prepare( ' WHERE p.post_type = %s AND ', $this->post_type );
	}
}

<?php

class WPML_Query_Utils {

	private $wpdb;

	private $wp_api;

	private $display_as_translated_post_types;

	private $was_full_author_query_executed_in_last_call = false;

	public function __construct( wpdb $wpdb, WPML_WP_API $wp_api, $display_as_translated_post_types ) {
		$this->wpdb                             = $wpdb;
		$this->wp_api                           = $wp_api;
		$this->display_as_translated_post_types = $display_as_translated_post_types;
	}

	public function get_was_full_author_query_executed_in_last_call() {
		return $this->was_full_author_query_executed_in_last_call;
	}

	public function author_query_has_posts( $post_type, $author_data, $lang, $fallback_lang ) {
		$this->was_full_author_query_executed_in_last_call = false;

		$post_types        = (array) $post_type;
		$post_type_snippet = (bool) $post_types ? ' AND post_type IN (' . wpml_prepare_in( $post_types ) . ') ' : '';
		$language_snippet  = $this->get_language_snippet( $lang, $fallback_lang, $post_type );

		$has_at_least_one_post = (bool) $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT 1 FROM {$this->wpdb->posts} p
				 WHERE p.post_author=%d
				" . $post_type_snippet . "
				 AND post_status='publish'
				 LIMIT 1
				",
				$author_data->ID
			)
		);

		if ( ! $has_at_least_one_post ) {
			return false;
		}

		$this->was_full_author_query_executed_in_last_call = true;

		return (bool) $this->wpdb->get_var(
			$this->wpdb->prepare(
				"	SELECT COUNT(p.ID) FROM {$this->wpdb->posts} p
				JOIN {$this->wpdb->prefix}icl_translations wpml_translations
					ON p.ID=wpml_translations.element_id AND wpml_translations.element_type = CONCAT('post_', p.post_type)
				WHERE p.post_author=%d
				  " . $post_type_snippet . "
				  AND post_status='publish'
				  " . $language_snippet . ' LIMIT 1',
				$author_data->ID
			)
		);
	}

	public function archive_query_has_posts( $lang, $fallback_lang, $year = null, $month = null, $day = null, $post_type = 'post' ) {
		$cache_args                  = array();
		$cache_args['lang']          = $lang;
		$cache_args['fallback_lang'] = $fallback_lang;
		$cache_args['year']          = $year;
		$cache_args['month']         = $month;
		$cache_args['day']           = $day;
		$cache_args['post_type']     = $post_type;

		$cache_key   = md5( (string) json_encode( $cache_args ) );
		$cache_group = 'archive_query_has_posts';
		$cache       = new WPML_WP_Cache( $cache_group );
		$found       = false;
		$result      = $cache->get( $cache_key, $found );

		if ( ! $found ) {
			$post_status_snippet = $this->wp_api->current_user_can( 'read' )
				? 'p.post_status IN (' . wpml_prepare_in( array( 'publish', 'private' ) ) . ') '
				: "p.post_status = 'publish'";
			$post_type_snippet   = is_array( $post_type )
				? ' AND post_type IN (' . wpml_prepare_in( $post_type ) . ') '
				: $this->wpdb->prepare( ' AND p.post_type = %s ', $post_type );
			$year_snippet        = (bool) $year === true ? $this->wpdb->prepare( ' AND year(p.post_date) = %d ', $year ) : '';
			$month_snippet       = (bool) $month === true ? $this->wpdb->prepare( ' AND month(p.post_date) = %d ', $month ) : '';
			$day_snippet         = (bool) $day === true ? $this->wpdb->prepare( ' AND day(p.post_date) = %d ', $day ) : '';

			$lang_snippet = $this->get_language_snippet( $lang, $fallback_lang, $post_type );

			$result = $this->wpdb->get_var(
				"
                        SELECT p.ID FROM {$this->wpdb->posts} p
						JOIN {$this->wpdb->prefix}icl_translations wpml_translations
							ON p.ID = wpml_translations.element_id AND wpml_translations.element_type = CONCAT('post_', p.post_type)
						WHERE " . $post_status_snippet .
							$year_snippet . $month_snippet . $day_snippet . $post_type_snippet . $lang_snippet . '
						LIMIT 1'
			);

			$cache->set( $cache_key, $result );
		}

		return (bool) $result;
	}

	private function get_language_snippet( $lang, $fallback_lang, $post_type ) {
		if ( in_array( $post_type, $this->display_as_translated_post_types ) ) {
			$display_as_translated_query   = new WPML_Display_As_Translated_Posts_Query( $this->wpdb, 'p' );
			$display_as_translated_snippet = $display_as_translated_query->get_language_snippet( $lang, $fallback_lang, $this->display_as_translated_post_types );
		} else {
			$display_as_translated_snippet = '0';
		}

		return $this->wpdb->prepare( " AND (wpml_translations.language_code = %s OR {$display_as_translated_snippet}) ", $lang );
	}

}

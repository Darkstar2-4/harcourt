<?php

class WPML_Term_Display_As_Translated_Adjust_Count {

	private $sitepress;

	private $wpdb;

	private $taxonomies_display_as_translated;

	public function __construct(
		SitePress $sitepress,
		wpdb $wpdb
    ) {
        if (
            ! isset( $GLOBALS['wp_version'] )
			|| version_compare( $GLOBALS['wp_version'], '6.0', '<' )
        ) {
            return;
        }

        if ( is_admin() && ! WPML_Ajax::is_frontend_ajax_request() ) {
            return;
        }

		$this->sitepress                        = $sitepress;
		$this->wpdb                             = $wpdb;
        $this->taxonomies_display_as_translated = $sitepress->get_display_as_translated_taxonomies();

		add_filter( 'get_term', [ $this, 'add_get_term_adjust_count' ], 10, 2 );
	}

	public function add_get_term_adjust_count( $term, $taxonomy ) {

		if ( ! in_array( $taxonomy, $this->taxonomies_display_as_translated, true ) ) {
			return $term;
		}

		add_filter( 'get_term', [ $this, 'get_term_adjust_count' ] );

		add_filter( 'get_terms', [ $this, 'remove_get_term_adjust_count' ] );

		return $term;
	}

    public function remove_get_term_adjust_count( $terms ) {
        remove_filter( 'get_term', [ $this, 'get_term_adjust_count' ] );

        return $terms;
    }

    public function get_term_adjust_count( $term ) {
        if ( ! is_object( $term ) ) {
            return $term;
        }

		$table_prefix = $this->wpdb->prefix;

        $originalTermCount = (int) $this->wpdb->get_var(
            $this->wpdb->prepare(
				"SELECT
                (
                    SELECT term_taxonomy.count
                    FROM {$table_prefix}term_taxonomy term_taxonomy
                    INNER JOIN {$table_prefix}icl_translations translations
                        ON translations.element_id = term_taxonomy.term_taxonomy_id
                    WHERE translations.trid = icl_t.trid
                    AND translations.language_code = %s
                ) as `originalCount`
                FROM {$table_prefix}terms AS t
                INNER JOIN {$table_prefix}term_taxonomy AS tt
                    ON t.term_id = tt.term_id
                LEFT JOIN {$table_prefix}icl_translations icl_t
                    ON icl_t.element_id = tt.term_taxonomy_id
                WHERE t.term_id = %d AND icl_t.element_type = %s
                ",
				$this->sitepress->get_default_language(),
				$term->term_id,
				'tax_' . $term->taxonomy
            )
        );

        if ( $originalTermCount > $term->count ) {
            $term->count = $originalTermCount;
        }

        return $term;
    }
}

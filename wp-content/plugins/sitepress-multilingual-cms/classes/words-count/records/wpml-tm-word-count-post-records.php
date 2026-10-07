<?php

class WPML_TM_Word_Count_Post_Records {

	const META_KEY = '_wpml_word_count';

	private $wpdb;

	public function __construct( wpdb $wpdb ) {
		$this->wpdb = $wpdb;
	}

	public function get_all_ids_without_word_count() {
		$query = "
			SELECT ID FROM {$this->wpdb->posts} AS p
			LEFT JOIN {$this->wpdb->prefix}icl_translations AS t
				ON t.element_id = p.ID AND t.element_type = CONCAT('post_', p.post_type)
			WHERE t.source_language_code IS NULL
				AND t.element_type IS NOT NULL
				AND p.ID NOT IN(
					SELECT post_id FROM {$this->wpdb->postmeta}
					WHERE meta_key = '" . self::META_KEY . "'
				);
		";

		return array_map( 'intval', $this->wpdb->get_col( $query ) );
	}

	public function get_word_count( $post_id ) {
		return get_post_meta( $post_id, self::META_KEY, true );
	}

	public function set_word_count( $post_id, $word_count ) {
		return update_post_meta( $post_id, self::META_KEY, $word_count );
	}

	public function reset_all( array $post_types ) {
		if ( ! $post_types ) {
			return;
		}

		$query = "DELETE pm FROM {$this->wpdb->postmeta} AS pm
				  INNER JOIN {$this->wpdb->posts} AS p
				  	ON p.ID = pm.post_id
				  WHERE pm.meta_key = %s AND p.post_type IN(" . wpml_prepare_in( $post_types ) . ")";

		$this->wpdb->query( $this->wpdb->prepare( $query, self::META_KEY ) );
	}

	public function get_source_ids_from_types( array $post_types ) {
		$query = "SELECT ID FROM {$this->wpdb->posts} AS p
				  LEFT JOIN {$this->wpdb->prefix}icl_translations AS t
				  	ON t.element_id = p.ID AND t.element_type = CONCAT('post_', p.post_type)
				  WHERE t.source_language_code IS NULL
				  	AND t.language_code IS NOT NULL
					AND p.post_type IN (" . wpml_prepare_in( $post_types ) . ")";

		return array_map( 'intval', $this->wpdb->get_col( $query ) );
	}

	public function count_source_items_by_type( $post_type ) {
		$query = "SELECT COUNT(*) FROM {$this->wpdb->posts} AS p
				  LEFT JOIN {$this->wpdb->prefix}icl_translations AS t
				  	ON t.element_id = p.ID AND t.element_type = CONCAT('post_', p.post_type)
				  WHERE t.source_language_code IS NULL
				    AND t.element_type = %s
				    AND p.post_status <> 'trash'";

		return (int) $this->wpdb->get_var( $this->wpdb->prepare( $query, 'post_' . $post_type ) );
	}

	public function count_word_counts_by_type( $post_type ) {
		$query = "SELECT COUNT(*) FROM {$this->wpdb->postmeta} AS pm
				  LEFT JOIN {$this->wpdb->posts} AS p
				  	ON p.ID = pm.post_id
				  WHERE pm.meta_key = '" . self::META_KEY . "'
				  	AND p.post_type = %s
				  	AND p.post_status <> 'trash'";

		return (int) $this->wpdb->get_var( $this->wpdb->prepare( $query, $post_type ) );
	}

	public function get_word_counts_by_type( $post_type ) {
		$query = "SELECT meta_value FROM {$this->wpdb->postmeta} AS pm
				  LEFT JOIN {$this->wpdb->posts} AS p
				  	ON p.ID = pm.post_id
				  WHERE p.post_type = %s AND pm.meta_key = %s
				  	AND p.post_status <> 'trash'";

		return $this->wpdb->get_col( $this->wpdb->prepare( $query, $post_type, self::META_KEY ) );
	}
}

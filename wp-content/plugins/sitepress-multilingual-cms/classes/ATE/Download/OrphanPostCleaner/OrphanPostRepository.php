<?php

namespace WPML\TM\ATE\Download\OrphanPostCleaner;

class OrphanPostRepository {

	private $wpdb;

	public function __construct( \wpdb $wpdb ) {
		$this->wpdb = $wpdb;
	}

	public function getMaxPostId() {
		return (int) $this->wpdb->get_var(
			"SELECT MAX(ID) FROM {$this->wpdb->posts}"
		);
	}

	public function getOrphanPostIds( $afterId ) {
		return $this->wpdb->get_col( $this->wpdb->prepare(
			"SELECT p.ID FROM {$this->wpdb->posts} p
			 LEFT JOIN {$this->wpdb->prefix}icl_translations t
			    ON t.element_id = p.ID AND t.element_type LIKE 'post_%%'
			 WHERE p.ID > %d AND t.translation_id IS NULL",
			$afterId
		) );
	}

	public function deletePost( $postId ) {
		wp_delete_post( $postId, true );
	}
}

<?php

class WPML_Media_Get_Attachment_Translation_Data implements IWPML_Action {

	/**
	 * @var SitePress
	 */
	private $sitepress;

	/**
	 * @var wpdb
	 */
	private $wpdb;

	/**
	 * @param SitePress $sitepress
	 * @param wpdb      $wpdb
	 */
	public function __construct( SitePress $sitepress, wpdb $wpdb ) {
		$this->sitepress = $sitepress;
		$this->wpdb      = $wpdb;
	}

	public function add_hooks() {
		add_action( 'wp_ajax_wpml_media_get_attachment_translation_data', array( $this, 'get_attachment_translation_data' ) );
	}

	public function get_attachment_translation_data() {
		if ( ! wp_verify_nonce( $_GET['wpnonce'], 'media-translation' ) ) {
			wp_send_json_error( array( 'error' => __( 'Invalid nonce', 'wpml-media' ) ) );
		}

		$attachment_id       = (int) $_GET['attachmentId'];
		$translated_language = sanitize_text_field( $_GET['translatedLanguage'] );

		$post_ids                              = $this->get_all_posts_in_which_media_file_was_used_by_copy( $attachment_id );
		$was_any_post_translated_automatically = $this->wasAnyPostTranslatedAutomatically( $post_ids, $translated_language );

		$response = array(
			'wasAttachmentTranslatedAutomaticallyInAnyPostAsCopy' => $was_any_post_translated_automatically,
		);

		wp_send_json_success( $response );
	}

	/**
	 * @param int $post_id
	 *
	 * @return array
	 */
	private function get_all_posts_in_which_media_file_was_used_by_copy( $post_id ) {
		if ( ! class_exists( '\WPML\MediaTranslation\UsageOfMediaFilesInPosts' ) ||
			! class_exists( '\WPML\MediaTranslation\PostWithMediaFilesFactory' ) ) {
			return [];
		}

		$post_with_media_files_factory = new \WPML\MediaTranslation\PostWithMediaFilesFactory();
		$post_with_media_files         = $post_with_media_files_factory->create( $post_id );
		$data                          = $post_with_media_files->get_usages_of_media_file_in_posts( $post_id );

		return $data[0];
	}

	/**
	 * @param array  $post_ids
	 * @param string $translated_language
	 *
	 * @return bool
	 */
	private function wasAnyPostTranslatedAutomatically( $post_ids, $translated_language ) {
		if ( count( $post_ids ) === 0 ) {
			return false;
		}

		$posts = $this->wpdb->get_results(
			// phpcs:disable WordPress.WP.PreparedSQL.NotPrepared
			"SELECT ID, post_type FROM {$this->wpdb->posts} WHERE ID IN (" . wpml_prepare_in( $post_ids, '%d' ) . ')',
			ARRAY_A
		);

		if ( count( $posts ) === 0 ) {
			return false;
		}

		$conditions = array();
		$params     = array();
		foreach ( $posts as $post ) {
			$conditions[] = '(element_id=%d AND element_type=%s AND language_code=%s AND source_language_code IS NOT NULL)';
			$params[]     = $post['ID'];
			$params[]     = 'post_' . $post['post_type'];
			$params[]     = $translated_language;
		}

		$where        = implode( ' OR ', $conditions );
		$query        = "SELECT * FROM {$this->wpdb->prefix}icl_translations WHERE " . $where;
		$translations = $this->wpdb->get_results(
		// phpcs:disable WordPress.WP.PreparedSQL.NotPrepared
			$this->wpdb->prepare(
				$query,
				$params
			),
			ARRAY_A
		);

		$translation_ids = [];
		foreach ( $translations as $translation ) {
			$translation_ids[] = $translation['translation_id'];
		}

		if ( count( $translation_ids ) === 0 ) {
			return false;
		}

		$query = "
			SELECT ts.*
			FROM {$this->wpdb->prefix}icl_translation_status ts
			INNER JOIN (
				SELECT translation_id, MAX(rid) as max_rid
				FROM {$this->wpdb->prefix}icl_translation_status
				WHERE translation_id IN (" . wpml_prepare_in( $translation_ids, '%d' ) . ')
				GROUP BY translation_id
			) latest
			ON ts.translation_id = latest.translation_id AND ts.rid = latest.max_rid
		';

		// phpcs:disable WordPress.WP.PreparedSQL.NotPrepared
		$results = $this->wpdb->get_results( $query, ARRAY_A );
		$rids    = [];
		foreach ( $results as $row ) {
			$rids[] = $row['rid'];
		}

		// phpcs:disable WordPress.WP.PreparedSQL.NotPrepared
		$query = "
			SELECT tj.*
			FROM {$this->wpdb->prefix}icl_translate_job tj
			WHERE tj.rid IN (" . wpml_prepare_in( $rids, '%d' ) . ')
		';

		$results = $this->wpdb->get_results( $query, ARRAY_A );
		foreach ( $results as $row ) {
			if ( 1 === (int) $row['automatic'] ) {
				return true;
			}
		}

		return false;
	}
}

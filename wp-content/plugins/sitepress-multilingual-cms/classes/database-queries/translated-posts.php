<?php

namespace WPML\DatabaseQueries;

class TranslatedPosts {
	public static function getIdsForLangs( $langs ) {
		global $wpdb;
		$languagesIn = wpml_prepare_in( $langs );

		$contentIdsQuery = "
		SELECT posts.ID
		FROM {$wpdb->posts} posts
		INNER JOIN {$wpdb->prefix}icl_translations translations ON translations.element_id = posts.ID AND translations.element_type = CONCAT('post_', posts.post_type)
		WHERE translations.language_code IN ({$languagesIn})
		";

		return $wpdb->get_col( $contentIdsQuery );
	}
}
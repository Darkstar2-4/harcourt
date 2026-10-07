<?php

abstract class WPML_Slug_Resolution extends WPML_WPDB_And_SP_User {

	const WPML_BACKUP_KEY = '_wpml_backup';

	protected function get_ordered_langs() {
		$lang_order = $this->sitepress->get_setting( 'languages_order' );
		$lang_order = $lang_order ? $lang_order : array_keys( $this->sitepress->get_active_languages() );
		$lang_order = is_array( $lang_order ) ? $lang_order : array();
		array_unshift( $lang_order, $this->sitepress->get_current_language() );

		return array_unique( $lang_order );
	}

	protected function set_query_var_to_restore( $key, WP_Query $wp_query ) {
		$wp_query->query_vars[ self::WPML_BACKUP_KEY ][ $key ] = $wp_query->query_vars[ $key ];
		add_filter( 'the_posts', array( $this, 'restore_query_vars' ), 10, 2 );
	}

	public function restore_query_vars( $posts, $wp_query ) {
		if ( isset( $wp_query->query_vars[ self::WPML_BACKUP_KEY ] ) ) {
			foreach ( $wp_query->query_vars[ self::WPML_BACKUP_KEY ] as $key => $value ) {
				$wp_query->query_vars[ $key ] = $value;
			}

			unset( $wp_query->query_vars[ self::WPML_BACKUP_KEY ] );
			remove_filter( 'the_posts', array( $this, 'restore_query_vars' ), 10 );
		}

		return $posts;
	}
}

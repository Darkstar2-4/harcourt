<?php

function new_duplicated_terms_filter( $post_ids, $duplicates_only = true ) {
	global $wpdb, $sitepress, $wpml_admin_notices;

	require_once WPML_PLUGIN_PATH . '/inc/taxonomy-term-translation/wpml-term-hierarchy-duplication.class.php';
	$hier_dupl  = new WPML_Term_Hierarchy_Duplication( $wpdb, $sitepress );
	$taxonomies = $hier_dupl->duplicates_require_sync( $post_ids, $duplicates_only );
	if ( (bool) $taxonomies ) {
		$text      = __(
			'<p>Some taxonomy terms are out of sync between languages. This means that content in some languages will not have the correct tags or categories.</p>
			 <p>In order to synchronize the taxonomies, you need to go over each of them from the following list and click the "Update taxonomy hierarchy" button.</p>',
			'sitepress'
		);
		$collapsed = 'Taxonomy sync problem';

		foreach ( $taxonomies as $taxonomy ) {
			$tax = get_taxonomy( $taxonomy );
			if ( ! $tax ) {
				continue;
			}
			$text .= '<p><a href="admin.php?page='
					 . WPML_PLUGIN_FOLDER . '/menu/taxonomy-translation.php&taxonomy='
				     . $taxonomy . '&sync=1">' .
				    	get_taxonomy_labels( $tax )->name . '</a></p>';
		}

		$text .= '<p align="right"><a target="_blank" href="https://wpml.org/documentation/getting-started-guide/translating-post-categories-and-custom-taxonomies/?utm_source=plugin&utm_medium=gui&utm_campaign=wpmlcore#synchronizing-hierarchical-taxonomies">Help about translating taxonomy >></a></p>';

		$notice = new WPML_Notice( 'wpml-taxonomy-hierarchy-sync', $text, 'wpml-core' );
		$notice->set_css_class_types( 'info' );
		$notice->set_collapsed_text( $collapsed );
		$notice->set_hideable( false );
		$notice->set_dismissible( false );
		$notice->set_collapsable( true );
		$wpml_admin_notices = wpml_get_admin_notices();
		$wpml_admin_notices->add_notice( $notice );

		$event_key = 'wpml_taxonomy_sync_event_captured_' . md5( serialize( $taxonomies ) );
		if ( ! get_transient( $event_key ) ) {
			$event_props = array(
				'taxonomies_count' => count( $taxonomies ),
				'taxonomies'       => $taxonomies,
				'sync_scope'       => $duplicates_only ? 'duplicates_only' : 'all_terms',
			);

			\WPML\PostHog\Event\CaptureEvent::capture(
				( new \WPML\Core\Component\PostHog\Application\Service\Event\EventInstanceService() )
					->getTaxonomyHierarchySyncNoticeDisplayedEvent( $event_props )
			);

			set_transient( $event_key, true, HOUR_IN_SECONDS );

			$registry = get_transient( 'wpml_taxonomy_sync_capture_event_transient_keys' );
			$registry = is_array( $registry ) ? $registry : [];
			$registry[ $event_key ] = true;
			set_transient( 'wpml_taxonomy_sync_capture_event_transient_keys', $registry, HOUR_IN_SECONDS );
		}
	} else {
		remove_taxonomy_hierarchy_message();
	}
}

add_action( 'wpml_new_duplicated_terms', 'new_duplicated_terms_filter', 10, 2 );

function display_tax_sync_message( $post_id ) {
	if ( ! is_admin() ) {
		return;
	}
	do_action( 'wpml_new_duplicated_terms', array( 0 => $post_id ), false );
}

add_action( 'save_post', 'display_tax_sync_message', 10 );

function remove_taxonomy_hierarchy_message() {
	$wpml_admin_notices = wpml_get_admin_notices();
	$wpml_admin_notices->remove_notice( 'wpml-core', 'wpml-taxonomy-hierarchy-sync' );
}

add_action( 'wpml_sync_term_hierarchy_done', 'remove_taxonomy_hierarchy_message' );

function clear_taxonomy_sync_event_transients() {
	$registry = get_transient( 'wpml_taxonomy_sync_capture_event_transient_keys' );
	if ( is_array( $registry ) ) {
		foreach ( array_keys( $registry ) as $key ) {
			delete_transient( $key );
		}
	}
	delete_transient( 'wpml_taxonomy_sync_capture_event_transient_keys' );
}

add_action( 'wpml_sync_term_hierarchy_done', 'clear_taxonomy_sync_event_transients' );

function wpml_get_admin_notices() {
	global $wpml_admin_notices, $sitepress;

	if ( ! $wpml_admin_notices ) {
		$wpml_admin_notices = new WPML_Notices( new WPML_Notice_Render(), $sitepress );
		$wpml_admin_notices->init_hooks();
	}

	return $wpml_admin_notices;
}

function wpml_validate_language_domain_action() {

	if ( wp_verify_nonce(
		filter_input( INPUT_POST, 'nonce' ),
		filter_input( INPUT_POST, 'action' )
	) ) {
		global $sitepress;
		$http                    = new WP_Http();
		$wp_api                  = $sitepress->get_wp_api();
		$language_domains_helper = new WPML_Language_Domain_Validation( $wp_api, $http );
		$res                     = $language_domains_helper->is_valid( filter_input( INPUT_POST, 'url' ) );
	}
	if ( ! empty( $res ) ) {
		wp_send_json_success( __( 'Valid', 'sitepress' ) );
	}
	wp_send_json_error( __( 'Not valid', 'sitepress' ) );
}

add_action( 'wp_ajax_validate_language_domain', 'wpml_validate_language_domain_action' );

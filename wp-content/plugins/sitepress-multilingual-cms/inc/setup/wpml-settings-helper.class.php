<?php

class WPML_Settings_Helper {

	const KEY_CPT_UNLOCK_OPTION      = 'custom_posts_unlocked_option';
	const KEY_TAXONOMY_UNLOCK_OPTION = 'taxonomies_unlocked_option';

	protected $sitepress;

	protected $post_translation;

	private $filters;

	public function __construct( WPML_Post_Translation $post_translation, SitePress $sitepress ) {
		$this->sitepress        = $sitepress;
		$this->post_translation = $post_translation;
	}

	private function get_filters() {
		if ( ! $this->filters ) {
			$this->filters = new WPML_Settings_Filters();
		}

		return $this->filters;
	}

	function set_post_type_translatable( $post_type ) {
		$this->set_post_type_translate_mode( $post_type, WPML_CONTENT_TYPE_TRANSLATE );
	}

	function set_post_type_display_as_translated( $post_type ) {
		$this->set_post_type_translate_mode( $post_type, WPML_CONTENT_TYPE_DISPLAY_AS_IF_TRANSLATED );
	}

	function set_post_type_not_translatable( $post_type ) {
		$sync_settings = $this->sitepress->get_setting( 'custom_posts_sync_option', array() );
		if ( isset( $sync_settings[ $post_type ] ) ) {
			unset( $sync_settings[ $post_type ] );
		}

		$this->clear_ls_languages_cache();
		$this->sitepress->set_setting( 'custom_posts_sync_option', $sync_settings, true );
	}

	private function set_post_type_translate_mode( $post_type, $mode ) {
		$sync_settings               = $this->sitepress->get_setting( 'custom_posts_sync_option', array() );
		$sync_settings[ $post_type ] = $mode;
		$this->clear_ls_languages_cache();
		$this->sitepress->set_setting( 'custom_posts_sync_option', $sync_settings, true );
		$this->sitepress->verify_post_translations( $post_type );
		$this->post_translation->reload();
	}

	function set_taxonomy_translatable( $taxonomy ) {
		$this->set_taxonomy_translatable_mode( $taxonomy, WPML_CONTENT_TYPE_TRANSLATE );
	}

	function set_taxonomy_display_as_translated( $taxonomy ) {
		$this->set_taxonomy_translatable_mode( $taxonomy, WPML_CONTENT_TYPE_DISPLAY_AS_IF_TRANSLATED );
	}

	function set_taxonomy_translatable_mode( $taxonomy, $mode ) {
		$sync_settings              = $this->sitepress->get_setting( 'taxonomies_sync_option', array() );
		$sync_settings[ $taxonomy ] = $mode;
		$this->clear_ls_languages_cache();
		$this->sitepress->set_setting( 'taxonomies_sync_option', $sync_settings, true );
		$this->sitepress->verify_taxonomy_translations( $taxonomy );
	}

	function set_taxonomy_not_translatable( $taxonomy ) {
		$sync_settings = $this->sitepress->get_setting( 'taxonomies_sync_option', array() );
		if ( isset( $sync_settings[ $taxonomy ] ) ) {
			unset( $sync_settings[ $taxonomy ] );
		}

		$this->clear_ls_languages_cache();
		$this->sitepress->set_setting( 'taxonomies_sync_option', $sync_settings, true );
	}

	function set_post_type_translation_unlocked_option( $post_type, $unlocked = true ) {

		$unlocked_settings = $this->sitepress->get_setting( 'custom_posts_unlocked_option', array() );

		$unlocked_settings[ $post_type ] = $unlocked ? 1 : 0;

		$this->sitepress->set_setting( 'custom_posts_unlocked_option', $unlocked_settings, true );
	}

	function set_taxonomy_translation_unlocked_option( $taxonomy, $unlocked = true ) {

		$unlocked_settings = $this->sitepress->get_setting( 'taxonomies_unlocked_option', array() );

		$unlocked_settings[ $taxonomy ] = $unlocked ? 1 : 0;

		$this->sitepress->set_setting( 'taxonomies_unlocked_option', $unlocked_settings, true );
	}

	function activate_slug_translation( $post_type ) {
		$slug_settings                        = $this->sitepress->get_setting( 'posts_slug_translation', array() );
		$slug_settings['types']               = isset( $slug_settings['types'] )
			? $slug_settings['types'] : array();
		$slug_settings['types'][ $post_type ] = 1;
		$slug_settings['on'] = 1;

		$this->clear_ls_languages_cache();
		$this->sitepress->set_setting( 'posts_slug_translation', $slug_settings, true );
		update_option( 'wpml_base_slug_translation', 1 );
	}

	function deactivate_slug_translation( $post_type ) {
		$slug_settings = $this->sitepress->get_setting( 'posts_slug_translation', array() );
		if ( isset( $slug_settings['types'][ $post_type ] ) ) {
			unset( $slug_settings['types'][ $post_type ] );
		}

		$this->clear_ls_languages_cache();
		$this->sitepress->set_setting( 'posts_slug_translation', $slug_settings, true );
	}

	function _override_get_translatable_taxonomies( $taxs_obj_type ) {
		global $wp_taxonomies;

		$taxs        = $taxs_obj_type['taxs'];
		$object_type = $taxs_obj_type['object_type'];
		foreach ( $taxs as $k => $tax ) {
			if ( ! $this->sitepress->is_translated_taxonomy( $tax ) ) {
				unset( $taxs[ $k ] );
			}
		}
		$tm_settings = $this->sitepress->get_setting( 'translation-management', array() );
		foreach ( $tm_settings['taxonomies_readonly_config'] as $tx => $translate ) {
			if ( $translate
				 && ! in_array( $tx, $taxs )
				 && isset( $wp_taxonomies[ $tx ] )
				 && in_array( $object_type, $wp_taxonomies[ $tx ]->object_type )
			) {
				$taxs[] = $tx;
			}
		}

		$ret = array(
			'taxs'        => $taxs,
			'object_type' => $taxs_obj_type['object_type'],
		);

		return $ret;
	}

	function _override_get_translatable_documents( $types ) {
		$tm_settings          = $this->sitepress->get_setting( 'translation-management', array() );
		$cpt_unlocked_options = $this->sitepress->get_setting( 'custom_posts_unlocked_option', array() );
		foreach ( $types as $k => $type ) {
			if ( isset( $tm_settings['custom-types_readonly_config'][ $k ] )
				 && ! $tm_settings['custom-types_readonly_config'][ $k ]
			) {
				unset( $types[ $k ] );
			}
		}
		$types = $this->get_filters()->get_translatable_documents( $types, $tm_settings['custom-types_readonly_config'], $cpt_unlocked_options );

		return $types;
	}

	function update_cpt_sync_settings( array $new_options ) {
		$cpt_sync_options = $this->sitepress->get_setting( WPML_Element_Sync_Settings_Factory::KEY_POST_SYNC_OPTION, array() );
		$cpt_sync_options = array_merge( $cpt_sync_options, $new_options );
		$new_options      = array_filter( $new_options );

		$this->clear_ls_languages_cache();

		do_action( 'wpml_verify_post_translations', $new_options );
		do_action( 'wpml_save_cpt_sync_settings' );
		$this->sitepress->set_setting( WPML_Element_Sync_Settings_Factory::KEY_POST_SYNC_OPTION, $cpt_sync_options, true );

		return $cpt_sync_options;
	}

	function update_taxonomy_sync_settings( array $new_options ) {
		$taxonomy_sync_options = $this->sitepress->get_setting( WPML_Element_Sync_Settings_Factory::KEY_TAX_SYNC_OPTION, [] );
		$changed_sync_options  = array_diff_assoc( $new_options, $taxonomy_sync_options );
		$taxonomy_sync_options = array_merge( $taxonomy_sync_options, $new_options );

		$this->clear_ls_languages_cache();

		do_action( 'wpml_save_taxonomy_sync_settings' );
		$this->sitepress->set_setting( WPML_Element_Sync_Settings_Factory::KEY_TAX_SYNC_OPTION, $taxonomy_sync_options, true );

		foreach ( $changed_sync_options as $taxonomy_name => $taxonomy_sync_option ) {
			$this->sitepress->verify_taxonomy_translations( $taxonomy_name );
		}

		return $taxonomy_sync_options;
	}

	function update_cpt_unlocked_settings( array $unlock_options ) {
		return $this->update_unlocked_settings( $unlock_options, self::KEY_CPT_UNLOCK_OPTION );
	}

	function update_taxonomy_unlocked_settings( array $unlock_options ) {
		return $this->update_unlocked_settings( $unlock_options, self::KEY_TAXONOMY_UNLOCK_OPTION );
	}

	private function update_unlocked_settings( array $unlock_options, $setting_key ) {
		$cpt_unlock_options = $this->sitepress->get_setting( $setting_key, array() );
		$cpt_unlock_options = array_merge( $cpt_unlock_options, $unlock_options );
		$this->sitepress->set_setting( $setting_key, $cpt_unlock_options, true );
		return $cpt_unlock_options;
	}

	function maybe_add_filter( $config_type ) {
		if ( $config_type === 'taxonomies' ) {
			add_filter(
				'get_translatable_taxonomies',
				array( $this, '_override_get_translatable_taxonomies' )
			);
		} elseif ( $config_type === 'custom-types' ) {
			add_filter(
				'get_translatable_documents',
				array( $this, '_override_get_translatable_documents' )
			);
		}
	}

	private function clear_ls_languages_cache() {
		$cache = new WPML_WP_Cache( 'ls_languages' );
		$cache->flush_group_cache();
	}
}

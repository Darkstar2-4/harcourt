<?php

class WPML_Compatibility_Divi implements \IWPML_DIC_Action, \IWPML_Backend_Action, \IWPML_Frontend_Action {

	const REGEX_REMOVE_OPENING_PARAGRAPH = '/(<p>[\n\r]*)([\n\r]{1}\[\/et_)/m';
	const REGEX_REMOVE_CLOSING_PARAGRAPH = '/(\[et_.*\][\n\r]{1})([\n\r]*<\/p>)/m';

	private $sitepress;

	public function __construct( SitePress $sitepress ) {
		$this->sitepress = $sitepress;
	}

	public function add_hooks() {
		if ( $this->sitepress->is_setup_complete() ) {
			add_action( 'init', [ $this, 'load_resources_if_they_are_required' ], 10, 0 );
			add_filter( 'divi_off_canvas_should_load', [ $this, 'should_load_off_canvas' ] );
			add_filter( 'et_builder_load_actions', [ $this, 'load_builder_for_ajax_actions' ] );

			add_action( 'admin_init', [ $this, 'display_warning_notice' ], 10, 0 );

			add_filter( 'wpml_pb_should_handle_content', [ $this, 'should_handle_shortcode_content' ], 10, 2 );
			add_filter( 'wpml_pb_shortcode_content_for_translation', [ $this, 'cleanup_global_layout_content' ], 10, 2 );

			add_filter( 'icl_job_elements', [ $this, 'remove_old_content_from_translation' ], 10, 2 );
			add_filter( 'wpml_words_count_custom_fields_to_count', [ $this, 'remove_old_content_from_words_count' ], 10, 2 );
		}
	}

	private function is_standard_editor_used() {
		$tm_settings = $this->sitepress->get_setting( 'translation-management', [] );

		return ! isset( $tm_settings['doc_translation_method'] ) ||
			ICL_TM_TMETHOD_MANUAL === $tm_settings['doc_translation_method'];
	}

	public function display_warning_notice() {
		$notices = wpml_get_admin_notices();

		if ( $this->is_standard_editor_used() ) {
			$notices->add_notice( new WPML_Compatibility_Divi_Notice() );
		} elseif ( $notices->get_notice( WPML_Compatibility_Divi_Notice::ID, WPML_Compatibility_Divi_Notice::GROUP ) ) {
			$notices->remove_notice( WPML_Compatibility_Divi_Notice::GROUP, WPML_Compatibility_Divi_Notice::ID );
		}
	}

	public function load_builder_for_ajax_actions( $actions ) {
		$actions[] = 'save-widget';
		$actions[] = 'widgets-order';
		$actions[] = 'wpml-ls-save-settings';

		return $actions;
	}

	public function load_resources_if_they_are_required() {
		if ( $this->is_wpml_page() ) {
			$this->register_layouts();
		}
	}

	public function should_load_off_canvas( $shouldLoad ) {
		return $shouldLoad || $this->is_wpml_page();
	}

	private function is_wpml_page() {
		if ( ! isset( $_GET['page'] ) || ! is_admin() ) {  
			return false;
		}

		$pages = [ self::get_duplication_action_page() ];
		if ( self::is_tm_active() ) {
			$pages[] = self::get_translation_dashboard_page();
			$pages[] = self::get_translation_editor_page();
			$pages[] = self::get_settings_page();
		}
		if ( self::is_sl_active() ) {
			$pages[] = self::get_sl_page();
		}

		return in_array( $_GET['page'], $pages, true );  
	}

	private static function get_translation_dashboard_page() {
		return constant( 'WPML_TM_FOLDER' ) . '/menu/main.php';
	}

	private static function get_translation_editor_page() {
		return constant( 'WPML_TM_FOLDER' ) . '/menu/translations-queue.php';
	}

	private static function get_duplication_action_page() {
		return constant( 'WPML_PLUGIN_FOLDER' ) . '/menu/languages.php';
	}

	private static function get_sl_page() {
		return 'wpml-sticky-links';
	}

	private static function get_settings_page() {
		return constant( 'WPML_TM_FOLDER' ) . '/menu/settings';
	}

	private static function is_tm_active() {
		return defined( 'WPML_TM_FOLDER' );
	}

	private static function is_sl_active() {
		return defined( 'WPML_STICKY_LINKS_VERSION' );
	}

	private function register_layouts() {
		if ( function_exists( 'et_builder_should_load_framework' ) && ! et_builder_should_load_framework() ) {
			if ( function_exists( 'et_builder_register_layouts' ) ) {
				et_builder_register_layouts();
			} else {
				$lib_file = ET_BUILDER_DIR . 'feature/Library.php';

				if ( ! class_exists( 'ET_Builder_Library' )
					&& defined( 'ET_BUILDER_DIR' )
					&& file_exists( $lib_file )
				) {
					require_once $lib_file;
				}

				if ( class_exists( 'ET_Builder_Library' ) ) {
					ET_Builder_Library::instance();
				}
			}
		}
	}

	public function cleanup_global_layout_content( $content, $post_id ) {
		if ( 'et_pb_layout' === get_post_type( $post_id ) ) {
			$content = preg_replace( self::REGEX_REMOVE_OPENING_PARAGRAPH, '$2', $content );
			$content = preg_replace( self::REGEX_REMOVE_CLOSING_PARAGRAPH, '$1', $content );
			$content = preg_replace( '/( prev_background_color="#[0-9a-f]*")/', '', $content );
			$content = preg_replace( '/( next_background_color="#[0-9a-f]*")/', '', $content );
		}

		return $content;
	}

	public function should_handle_shortcode_content( $handle_content, $shortcode ) {
		if (
			strpos( $shortcode['tag'], 'et_' ) === 0 &&
			strpos( $shortcode['attributes'], 'global_module=' ) !== false
		) {
			$handle_content = $this->is_excluded_from_sync( $shortcode );
		}
		return $handle_content;
	}

	private function is_excluded_from_sync( $shortcode ) {
		$handle_content = false;

		preg_match( '/global_module="([0-9]+)"/', $shortcode['attributes'], $matches );
		$excluded = json_decode( get_post_meta( (int) $matches[1], '_et_pb_excluded_global_options', true ), true );

		if ( is_array( $excluded ) && count( $excluded ) > 0 ) {
			$attributes = $this->get_translatable_shortcode_attributes( $shortcode['tag'] );

			foreach ( $excluded as $field ) {
				if ( in_array( $field, $attributes, true ) ) {
					$handle_content = true;
					break;
				}
			}
		}

		return $handle_content;
	}

	private function get_translatable_shortcode_attributes( $tag ) {
		$attributes = [ 'et_pb_content_field' ];
		$settings   = get_option( 'icl_st_settings', [] );

		if ( ! isset( $settings['pb_shortcode'] ) ) {
			return $attributes;
		}

		foreach ( $settings['pb_shortcode'] as $setting ) {
			if ( $tag === $setting['tag']['value'] ) {
				foreach ( $setting['attributes'] as $attribute ) {
					if ( empty( $attribute['type'] ) ) {
						$attributes[] = $attribute['value'];
					}
				}
				break;
			}
		}

		return $attributes;
	}

	public function remove_old_content_from_translation( $fields, $post_id ) {
		if ( 'product' === get_post_type( $post_id ) ) {
			return $fields;
		}

		$field_types = wp_list_pluck( $fields, 'field_type' );
		$index       = array_search( 'field-_et_pb_old_content-0', $field_types, true );
		if ( false !== $index ) {
			$fields[ $index ]->field_data            = '';
			$fields[ $index ]->field_data_translated = '';
		}

		return $fields;
	}

	public function remove_old_content_from_words_count( $fields_to_count, $post_id ) {
		if ( 'product' !== get_post_type( $post_id ) ) {
			$index = array_search( '_et_pb_old_content', $fields_to_count, true );
			if ( false !== $index ) {
				unset( $fields_to_count[ $index ] );
			}
		}

		return $fields_to_count;
	}
}

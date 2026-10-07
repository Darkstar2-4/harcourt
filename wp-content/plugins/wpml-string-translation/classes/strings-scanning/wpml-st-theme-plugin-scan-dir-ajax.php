<?php

use WPML\ST\StringsScanning\JS\ScriptRegistry;

class WPML_ST_Theme_Plugin_Scan_Dir_Ajax {

	private $scan_dir;

	public function __construct( WPML_ST_Scan_Dir $scan_dir ) {
		$this->scan_dir = $scan_dir;
	}

	public function add_hooks() {
		add_action( 'wp_ajax_wpml_get_files_to_scan', array( $this, 'get_files' ) );
	}

	public function get_files() {
		if ( ! current_user_can( 'wpml_manage_theme_and_plugin_localization' ) ) {
			wp_send_json_error( __( 'not allowed', 'wpml-string-translation' ) );
			return;
		}

		list( $type, $id, $folder ) = $this->get_component_data();
		$files_found_chunks         = [];
		$result                     = [];

		if ( $folder ) {
			$file_type = [ 'php', 'inc' ];

			$files_found_chunks[] = $this->scan_dir->scan(
				$folder,
				$file_type,
				$this->is_one_file_plugin(),
				$this->get_folders_to_ignore()
			);

			$files_found_chunks[] = ScriptRegistry::getAbsScriptPathsForComponents( $id, $type );

			$files = call_user_func_array( 'array_merge', $files_found_chunks );

			if ( ! $files ) {
				$this->clear_items_to_scan_buffer();
			}

			$result = array(
				'files'            => $files,
				'no_files_message' => __( 'Files already scanned.', 'wpml-string-translation' ),
			);
		}

		wp_send_json_success( $result );
	}

	private function clear_items_to_scan_buffer() {
		wpml_get_admin_notices()->remove_notice(
			WPML_ST_Themes_And_Plugins_Settings::NOTICES_GROUP,
			WPML_ST_Themes_And_Plugins_Updates::WPML_ST_SCAN_NOTICE_ID
		);
		delete_option( WPML_ST_Themes_And_Plugins_Updates::WPML_ST_ITEMS_TO_SCAN );
	}

	private function get_component_data() {
		$type   = null;
		$id     = null;
		$root   = null;
		$folder = null;

		if ( array_key_exists( 'theme', $_POST ) ) {
			$type = 'theme';
			$id   = sanitize_text_field( $_POST['theme'] );
			if ( $this->is_single_path_segment( $id ) ) {
				$theme = wp_get_theme( $id );
				if ( $theme->exists() ) {
					$root   = $theme->get_theme_root();
					$folder = $theme->get_stylesheet_directory();
				}
			}
		} elseif ( array_key_exists( 'plugin', $_POST ) ) {
			$type          = 'plugin';
			$id            = sanitize_text_field( $_POST['plugin'] );
			$root          = WPML_PLUGINS_DIR;
			$plugin_folder = explode( '/', $_POST['plugin'] );
			$folder        = $root . '/' . sanitize_text_field( $plugin_folder[0] );
		} elseif ( array_key_exists( 'mu-plugin', $_POST ) ) {
			$type   = 'mu-plugin';
			$id     = sanitize_text_field( $_POST['mu-plugin'] );
			$root   = WPMU_PLUGIN_DIR;
			$folder = $this->is_single_path_segment( $id ) ? $root . '/' . $id : null;
		}

		if ( $folder ) {
			$confined = WPML_ST_Path_Confinement::resolve_contained( $folder, $root );
			$folder   = false !== $confined ? $confined : null;
		}

		return [ $type, $id, $folder ];
	}

	private function is_single_path_segment( $id ) {
		return '' !== $id && false === strpos( $id, '/' ) && false === strpos( $id, '\\' );
	}

	private function is_one_file_plugin() {
		$is_one_file_plugin = false;

		if ( array_key_exists( 'plugin', $_POST ) ) {
			$is_one_file_plugin = false === strpos( $_POST['plugin'], 'plugins/' );
		}

		if ( array_key_exists( 'mu-plugin', $_POST ) ) {
			$is_one_file_plugin = false === strpos( $_POST['mu-plugin'], 'mu-plugins/' );
		}

		return $is_one_file_plugin;
	}

	private function get_folders_to_ignore() {
		$folders = [
			WPML_ST_Scan_Dir::PLACEHOLDERS_ROOT . '/node_modules',
			WPML_ST_Scan_Dir::PLACEHOLDERS_ROOT . '/tests',
		];

		return $folders;
	}
}

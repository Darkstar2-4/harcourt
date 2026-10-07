<?php

class WPML_ST_Translations_File_Components_Find_Plugin implements WPML_ST_Translations_File_Components_Find {
	private $debug_backtrace;

	private $plugin_dir;

	private $plugin_ids;

	private $languages_plugin_dir;

	public function __construct( WPML_Debug_BackTrace $debug_backtrace ) {
		$this->debug_backtrace = $debug_backtrace;
		$this->plugin_dir = realpath( WPML_PLUGINS_DIR );
		$this->languages_plugin_dir = WP_LANG_DIR . '/plugins/';
	}

	public function find_id( $file ) {
		$directory = $this->find_plugin_directory( $file );
		if ( ! $directory ) {
			return null;
		}

		return $this->get_plugin_id_by_directory( $directory );
	}

	private function find_plugin_directory( $file ) {
		if ( false !== strpos( $file, $this->plugin_dir ) ) {
			return $this->extract_plugin_directory( $file );
		}

		if ( false !== strpos( $file, $this->languages_plugin_dir ) ) {
			return $this->extract_plugin_directory_from_languages_directory( $file );
		}

		return $this->find_plugin_directory_in_backtrace();
	}

	private function find_plugin_directory_in_backtrace() {
		$file = $this->find_file_in_backtrace();
		if ( ! $file ) {
			return null;
		}

		return $this->extract_plugin_directory( $file );
	}

	private function find_file_in_backtrace() {
		$stack = $this->debug_backtrace->get_backtrace();

		foreach ( $stack as $call ) {
			if ( isset( $call['function'] ) && 'load_plugin_textdomain' === $call['function'] ) {
				return $call['file'];
			}
		}

		return null;
	}

	private function extract_plugin_directory( $file_path ) {
		$dir = ltrim( str_replace( $this->plugin_dir, '', $file_path ), DIRECTORY_SEPARATOR );
		$dir = explode( DIRECTORY_SEPARATOR, $dir );

		return trim( $dir[0], DIRECTORY_SEPARATOR );
	}

	private function extract_plugin_directory_from_languages_directory( $file_path ) {
		$parts     = explode( DIRECTORY_SEPARATOR, $file_path );
		$file_name = current( explode( '.', end( $parts ) ) );

		if ( false !== strpos( $file_name, '_' ) ) {
			return substr( current( explode( '_', $file_name ) ), 0, -3 );
		}

		$parts = explode( '-', $file_name );
		array_pop( $parts );

		return implode( '-', $parts );
	}

	private function get_plugin_id_by_directory( $directory ) {
		foreach ( $this->get_plugin_ids() as $plugin_id ) {
			if ( 0 === strpos( $plugin_id, $directory . '/' ) ) {
				return $plugin_id;
			}
		}

		return null;
	}

	private function get_plugin_ids() {
		if ( null === $this->plugin_ids ) {
			$this->plugin_ids = array_keys( get_plugins() );
		}

		return $this->plugin_ids;
	}
}
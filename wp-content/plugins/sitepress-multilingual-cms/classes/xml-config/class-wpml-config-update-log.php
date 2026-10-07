<?php

class WPML_Config_Update_Log implements WPML_Log {
	const OPTION_NAME = 'wpml-xml-config-update-log';

	public function get( $page_size = 0, $page = 0 ) {
		$data = get_option( self::OPTION_NAME );
		if ( ! $data ) {
			$data = array();
		}

		return $this->paginate( $data, $page_size, $page );
	}

	public function insert( $timestamp, array $entry ) {
		if ( $entry && is_array( $entry ) ) {
			$log = $this->get();
			if ( ! $log ) {
				$log = array();
			}
			$log[ (string) $timestamp ] = $entry;
			$this->save( $log );
		}
	}

	public function clear() {
		$this->save( array() );
	}

	public function save( array $data ) {
		if ( $data === array() ) {
			delete_option( self::OPTION_NAME );

			return;
		}

		update_option( self::OPTION_NAME, $data, false );
	}

	public function is_empty() {
		return ! $this->get();
	}

	protected function paginate( array $data, $page_size, $page ) {
		if ( (int) $page_size > 0 ) {
			$total      = count( $data );
			$limit      = $page_size;
			$totalPages = ceil( $total / $limit );
			$page       = max( $page, 1 );
			$page       = min( $page, $totalPages );
			$offset     = ( $page - 1 ) * $limit;
			if ( $offset < 0 ) {
				$offset = 0;
			}

			$data = array_slice( $data, (int) $offset, $limit );
		}

		return $data;
	}

	public function get_log_url() {
		return add_query_arg( array( 'page' => self::get_support_page_log_section() ), get_admin_url( null, 'admin.php#xml-config-log' ) );
	}

	public static function get_support_page_log_section() {
		return WPML_PLUGIN_FOLDER . '/menu/support.php';
	}
}

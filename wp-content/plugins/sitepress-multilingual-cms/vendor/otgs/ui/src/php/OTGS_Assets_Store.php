<?php

class OTGS_Assets_Store {

	private $assets_files_store = array();
	private $assets = array();

	public function get( $type, $handle = null ) {
		$result = array();

		$this->parse_assets();

		if ( array_key_exists( $type, $this->assets ) ) {
			$result = $this->assets[ $type ];

			if ( $handle ) {
				if ( array_key_exists( $handle, $this->assets[ $type ] ) ) {
					$result = $this->assets[ $type ][ $handle ];
				} else {
					$result = array();
				}
			}
		}

		return $result;
	}

	public function add_assets_location( $path ) {
		if ( ! in_array( $path, $this->assets, true ) ) {
			$this->assets_files_store[] = $path;
		}
	}

	private function parse_assets() {
		if ( ! $this->assets ) {
			foreach ( $this->assets_files_store as $assets_file ) {
				$this->add_asset( $assets_file );
			}
		}
	}

	private function add_asset( $assets_file ) {
		if ( ! is_file( $assets_file ) ) {
			return;
		}

		$assets = file_get_contents( $assets_file );
		if ( ! $assets || ! is_string( $assets ) ) {
			return;
		}

		$assets_data = json_decode( $assets, true );

		if ( $assets_data && array_key_exists( 'entrypoints', $assets_data ) ) {
			foreach ( $assets_data['entrypoints'] as $handle => $resources ) {
				$this->add_resources( $handle, $resources );
			}
		}
	}

	private function add_resources( $handle, $resources ) {
		foreach ( $resources as $type => $path ) {
			if ( ! array_key_exists( $type, $this->assets ) ) {
				$this->assets[ $type ] = array();
			}
			$this->assets[ $type ][ $handle ] = $path;
		}
	}
}

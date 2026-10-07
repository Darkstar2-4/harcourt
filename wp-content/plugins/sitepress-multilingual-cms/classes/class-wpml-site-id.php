<?php

class WPML_Site_ID {
	const SITE_ID_KEY = 'WPML_SITE_ID';

	const SITE_SCOPES_GLOBAL = 'global';

	private $site_ids = array();

	public function get_site_id( $scope = self::SITE_SCOPES_GLOBAL ) {
		if ( ! $this->read_value( $scope ) && ! $this->generate_site_id( $scope ) ) {
			return null;
		}

		return $this->get_from_cache( $scope );
	}

	private function generate_site_id( $scope ) {
		$site_url  = get_site_url();
		$site_uuid = uuid_v5( $site_url, wp_generate_uuid4() );
		$time_uuid = uuid_v5( time(), wp_generate_uuid4() );

		return $this->write_value( uuid_v5( $site_uuid, $time_uuid ), $scope );
	}

	private function read_value( $scope ) {
		if ( ! $this->get_from_cache( $scope ) ) {
			$this->site_ids[ $scope ] = get_option( $this->get_option_key( $scope ), null );
		}

		return $this->site_ids[ $scope ];
	}

	private function write_value( $value, $scope ) {
		if ( update_option( $this->get_option_key( $scope ), $value, false ) ) {
			$this->site_ids[ $scope ] = $value;

			return true;
		}

		return false;
	}

	private function get_option_key( $scope ) {
		return self::SITE_ID_KEY . ':' . $scope;
	}

	private function get_from_cache( $scope ) {
		if ( array_key_exists( $scope, $this->site_ids ) && $this->site_ids[ $scope ] ) {
			return $this->site_ids[ $scope ];
		}

		return null;
	}

	public function reset( string $scope = self::SITE_SCOPES_GLOBAL ) {
		delete_option( $this->get_option_key( $scope ) );
		unset( $this->site_ids[ $scope ] );
	}
}

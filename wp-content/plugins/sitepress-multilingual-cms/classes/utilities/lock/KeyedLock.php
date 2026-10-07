<?php

namespace WPML\Utilities;

class KeyedLock extends Lock {

	private $keyName;

	public function __construct( \wpdb $wpdb, $name ) {
		$this->keyName = 'wpml.' . $name . '.lock.key';
		parent::__construct( $wpdb, $name );
	}

	public function create( $key = null, $release_timeout = null ) {
		$acquired = parent::create( $release_timeout );

		if ( $acquired ) {

			if ( ! $key ) {
				$key = wp_generate_uuid4();
			}

			$this->maybePurgeInvalidCache();
			update_option( $this->keyName, $key, false );

			return $key;
		} elseif ( $key === get_option( $this->keyName ) ) {
			$this->extendTimeout();

			return $key;
		}

		return false;
	}

	public function release() {
		delete_option( $this->keyName );
		wp_cache_delete( $this->keyName, 'options' );

		return parent::release();
	}

	private function extendTimeout() {
		update_option( $this->name, time(), false );
	}

	private function maybePurgeInvalidCache() {
		$alloptions = wp_cache_get( 'alloptions', 'options', true );
		if ( is_array( $alloptions ) && isset( $alloptions[ $this->keyName ] ) ) {
			wp_cache_delete( 'alloptions', 'options' );
		}
	}
}

<?php

namespace WPML\TM\Settings\Flags\Command;

use WPML\FP\Either;
use WPML\FP\Left;
use WPML\FP\Lst;
use WPML\FP\Right;
use WPML\TM\Settings\Flags\Options;
use WPML\TM\Settings\Flags\FlagsRepository;

class ConvertFlags {
	private $wpdb;

	private $flags_repository;

	public function __construct(
		\wpdb $wpdb,
		FlagsRepository $flags_repository
	) {
		$this->wpdb             = $wpdb;
		$this->flags_repository = $flags_repository;
	}

	public function run( $targetExt = 'svg' ) {
		if ( ! Lst::includes( $targetExt, Options::getAllowedFormats() ) ) {
			return Either::left( 'Invalid target extension' );
		}

		$flags = $this->flags_repository->getItemsInstalledByDefault();
		if ( ! is_array( $flags ) || ( is_array( $flags ) && 0 === count( $flags ) ) ) {
			return Either::left( 'There is no flags in DB. Your data are corrupted' );
		}

		foreach ( $flags as $flag ) {
			$flagName = pathinfo( $flag->flag, PATHINFO_FILENAME );
			if ( empty( $flagName ) ) {
				continue;
			}

			$flagFilename = $flagName . '.' . $targetExt;
			if ( file_exists( WPML_PLUGIN_PATH . '/res/flags/' . $flagFilename ) ) {
				$this->updateFlagFile( $flagFilename, $flag );
			}
		}

		icl_cache_clear();

		return Either::of( $targetExt );
	}

	private function is_custom_flag( $flag ) {
		return '1' === $flag->from_template;
	}

	private function updateFlagFile( $file, $flag ) {
		$this->wpdb->update(
			$this->wpdb->prefix . 'icl_flags',
			[ 'flag' => $file ],
			[ 'id' => $flag->id ]
		);
	}
}

<?php


namespace WPML\TM\ATE\ClonedSites;

use WPML\FP\Obj;
use WPML\API\Settings;
use WPML\TM\ATE\API\FingerprintGenerator;
use function WPML\Container\make;

class Lock {
	const CLONED_SITE_OPTION = 'otgs_wpml_tm_ate_cloned_site_lock';

	private static $fingerprint_generator;

	public function lock( $lockData ) {
		if ( $this->isLockDataPresent( $lockData ) ) {
			update_option(
				self::CLONED_SITE_OPTION,
				[
					'stored_fingerprint'            => $lockData['stored_fingerprint'],
					'received_fingerprint'          => $lockData['received_fingerprint'],
					'fingerprint_confirmed'         => $lockData['fingerprint_confirmed'],
					'identical_url_before_movement' => isset( $lockData['identical_url_before_movement'] ) ? $lockData['identical_url_before_movement'] : false,
				],
				'no'
			);
		}
	}

	public function getLockData() {
		$option = get_option( self::CLONED_SITE_OPTION, [] );
		$urls   = $this->extractUrls( $option );

		return [
			'urlCurrentlyRegisteredInAMS' => $urls['old_url'],
			'urlUsedToMakeRequest'        => $urls['new_url'],
			'identicalUrlBeforeMovement'  => Obj::propOr( false, 'identical_url_before_movement', $option ),
		];
	}

	public function extractUrls( array $data ) {
		$oldUrl = Obj::pathOr( '', [ 'stored_fingerprint', 'wp_url' ], $data );

		$receivedFingerprint = isset( $data['received_fingerprint'] ) ? $data['received_fingerprint'] : [];
		$newUrl = Obj::propOr(
			'',
			'wp_url',
			is_string( $receivedFingerprint ) ? json_decode( $receivedFingerprint ) : $receivedFingerprint
		);

		return [
			'old_url' => $oldUrl,
			'new_url' => $newUrl,
		];
	}

	public function getUrlRegisteredInAMS() {
		$lockData = $this->getLockData();

		return $lockData['urlCurrentlyRegisteredInAMS'];
	}

	private function isLockDataPresent( $lockData ) {
		return isset( $lockData['stored_fingerprint'] )
		       && isset( $lockData['received_fingerprint'] )
		       && isset( $lockData['fingerprint_confirmed'] );
	}

	public function unlock() {
		$lockData = $this->getLockData();

		if ( $lockData['urlCurrentlyRegisteredInAMS'] && $lockData['urlUsedToMakeRequest'] ) {
			Settings::setAndSave( 'migrated_site',
				[
					'old_url' => $lockData['urlCurrentlyRegisteredInAMS'],
					'new_url' => $lockData['urlUsedToMakeRequest'],
				]
			);
		}

		static::doUnlock();
	}

	private static function doUnlock() {
		delete_option( self::CLONED_SITE_OPTION );
		AliasDomainResetFlag::clear();
	}

	public static function isLocked() {
		$option = get_option( self::CLONED_SITE_OPTION, false );

		if ( $option && isset( $option['stored_fingerprint'] ) && isset( $option['stored_fingerprint']['wp_url'] ) ) {
			$stored_url = $option['stored_fingerprint']['wp_url'];

			$current_url = self::getFingerPrintGenerator()->getClonedSiteUrl();

			if ( $stored_url === $current_url ) {
				static::doUnlock();
				return false;
			}
		}

		return (bool) $option && \WPML_TM_ATE_Status::is_enabled();
	}

	private static function getFingerPrintGenerator() {
		if ( ! self::$fingerprint_generator ) {
			self::$fingerprint_generator = make( FingerprintGenerator::class );
		}
		return self::$fingerprint_generator;
	}

}

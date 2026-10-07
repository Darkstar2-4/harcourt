<?php

namespace WPML\TM\ATE\Sitekey;

use WPML\TM\ATE\Log\Storage;
use WPML\TM\ATE\Log\Entry;
use WPML\TM\ATE\Log\EventsTypes;

class SitekeyLogger {

	private $sitekeyProvider;

	public function __construct( SitekeyProvider $sitekeyProvider ) {
		$this->sitekeyProvider = $sitekeyProvider;
	}

	public function logError( $message ) {
		$this->logErrorWithKey( $message, $this->sitekeyProvider->getSitekey() );
	}

	public function logSuccess( $message, $sitekey = null ) {
		Storage::add(
			Entry::createForType(
				EventsTypes::SERVER_AMS,
				[
					'message' => $message,
					'sitekey' => $this->maskSitekey( $sitekey ?: $this->sitekeyProvider->getSitekey() )
				]
			)
		);
	}

	public function logErrorWithKey( $message, $sitekey ) {
		Storage::add(
			Entry::createForType(
				EventsTypes::SERVER_AMS,
				[
					'error'   => $message,
					'sitekey' => $this->maskSitekey( $sitekey )
				]
			)
		);
	}

	private function maskSitekey( $sitekey ) {
		$lastDigitsCount = 4;

		return $sitekey
			? str_repeat( 'x', strlen( $sitekey ) - $lastDigitsCount ) . substr( $sitekey, -$lastDigitsCount )
			: 'empty';
	}
}

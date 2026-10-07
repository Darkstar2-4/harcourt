<?php

namespace WPML\TM\ATE\Sitekey;

use function WPML\Container\make;

class SitekeyApiClient {

	private $sitekeyProvider;

	private $logger;

	public function __construct( SitekeyProvider $sitekeyProvider, SitekeyLogger $logger ) {
		$this->sitekeyProvider = $sitekeyProvider;
		$this->logger = $logger;
	}

	public function sendSitekey() {
		$sitekey = $this->sitekeyProvider->getSitekey();

		if ( ! $sitekey ) {
			$this->logger->logError( 'Site key is empty' );
			return false;
		}

		return $this->sendToAMS( $sitekey );
	}

	private function sendToAMS( $sitekey ) {
		try {
			$result = make( \WPML_TM_AMS_API::class )->send_sitekey( $sitekey );

			if ( ! $result ) {
				$this->logger->logError( 'AMS API returned false' );
			}

			return (bool) $result;
		} catch ( \Exception $e ) {
			$this->logger->logError( $e->getMessage() );
			return false;
		}
	}

}

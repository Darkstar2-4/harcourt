<?php

namespace WPML\TM\ATE\ClonedSites;

use WPML\LIB\WP\Option;
use WPML\TM\ATE\API\FingerprintGenerator;

class SecondaryDomains {
	const OPTION = 'wpml_tm_ate_secondary_domains';
	const ORIGINAL_SITE_URL = 'wpml_tm_ate_original_site_url';

	public function add( $domain, $originalSiteUrl ) {
		$domains = $this->get();
		if ( ! in_array( $domain, $domains, true ) ) {
			$domains[] = $domain;
		}

		Option::update( self::OPTION, $domains );
		Option::update( self::ORIGINAL_SITE_URL, $originalSiteUrl );

		return $domains;
	}

	public function maybeFallBackToTheOriginalURL( $currentSiteUrl ) {
		$originalSiteUrl = Option::get( self::ORIGINAL_SITE_URL );

		if ( $currentSiteUrl === $originalSiteUrl ) {
			return $currentSiteUrl;
		}

		if ( $this->isRegistered( $currentSiteUrl ) ) {
			return $originalSiteUrl;
		}

		return $currentSiteUrl;
	}

	public function getInfo() {
		$domains = $this->get();

		$domains = $this->checkIfTheOriginalSiteUrlIsInAliasDomains( $domains );

		if ( ! $domains ) {
			return null;
		}

		return [
			'originalSiteUrl' => Option::get( self::ORIGINAL_SITE_URL ),
			'aliasDomains'    => $domains,
		];
	}

	public function reset() {
		Option::delete( self::OPTION );
		Option::delete( self::ORIGINAL_SITE_URL );
	}

	private function get() {
		return Option::getOr( self::OPTION, [] );
	}

	private function isRegistered( $domain ) {
		return in_array( $domain, $this->get(), true );
	}

	private function checkIfTheOriginalSiteUrlIsInAliasDomains( $domains ) {
		$originalSiteUrl = Option::get( self::ORIGINAL_SITE_URL );
		if ( in_array( $originalSiteUrl, $domains, true ) ) {
			Option::delete( self::OPTION );
			Option::delete( self::ORIGINAL_SITE_URL );

			return null;
		}

		return $domains;
	}
}
<?php

namespace WPML\TM\Upgrade\Commands;

use WPML\TM\ATE\ClonedSites\AliasDomainProber;
use WPML\TM\ATE\ClonedSites\AliasDomainResetFlag;
use WPML\TM\ATE\ClonedSites\SecondaryDomains;

class ValidateAliasDomain implements \IWPML_Upgrade_Command {

	const RETRY_OPTION      = 'wpml_alias_domain_validation_retry';
	const MAX_ATTEMPTS      = 3;
	const MIN_DELAY_SECONDS = 60;

	private $prober;

	private $amsApi;

	private $result = false;

	public function __construct( $prober = null, $amsApi = null ) {
		$this->prober = $prober instanceof AliasDomainProber ? $prober : new AliasDomainProber();
		$this->amsApi = $amsApi instanceof \WPML_TM_AMS_API ? $amsApi : \WPML\Container\make( \WPML_TM_AMS_API::class );
	}

	public function run_admin() {
		$this->result = $this->run();

		return $this->result;
	}

	public function run_ajax() {
		return null;
	}

	public function run_frontend() {
		return null;
	}

	public function get_results() {
		return $this->result;
	}

	private function run() {
		$domains = get_option( SecondaryDomains::OPTION, [] );
		if ( empty( $domains ) ) {
			delete_option( self::RETRY_OPTION );

			return true;
		}

		$originalSiteUrl = get_option( SecondaryDomains::ORIGINAL_SITE_URL, '' );
		if ( empty( $originalSiteUrl ) ) {
			delete_option( self::RETRY_OPTION );

			return true;
		}

		$retryState = get_option( self::RETRY_OPTION, null );

		if ( $this->isTooSoonToRetry( $retryState ) ) {
			return false;
		}

		$token    = $retryState ? $retryState['token'] : wp_generate_password( 32, false );
		$attempts = $retryState ? $retryState['attempts'] : 0;

		if ( $this->prober->probe( $originalSiteUrl, $token ) ) {
			delete_option( self::RETRY_OPTION );

			if ( $this->prober->isSameDatabase( $token ) ) {
				return true;
			}

			$this->resetAliasDomain();

			return true;
		}

		$attempts++;

		if ( $attempts >= self::MAX_ATTEMPTS ) {
			delete_option( self::RETRY_OPTION );
			$this->resetAliasDomain();

			return true;
		}

		$this->scheduleRetry( $token, $attempts );

		return false;
	}

	private function isTooSoonToRetry( $retryState ) {
		return $retryState && ( time() - $retryState['last_attempt'] ) < self::MIN_DELAY_SECONDS;
	}

	private function scheduleRetry( $token, $attempts ) {
		update_option( self::RETRY_OPTION, [
			'token'        => $token,
			'attempts'     => $attempts,
			'last_attempt' => time(),
		], 'no' );
	}

	private function resetAliasDomain() {
		$secondaryDomains = new SecondaryDomains();
		$secondaryDomains->reset();

		AliasDomainResetFlag::set();

		add_action( 'admin_init', [ $this, 'probeAmsToTriggerMigrationBanner' ], 999 );
	}

	public function probeAmsToTriggerMigrationBanner() {
		$this->amsApi->getGlossaryCount();
	}
}

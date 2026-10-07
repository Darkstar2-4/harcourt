<?php

namespace WPML\TM\ATE\ClonedSites\AutoMigration;

use WPML\API\Settings;
use WPML\TM\ATE\API\AmsCredentialsStorage;
use WPML\TM\ATE\API\AmsRequestSigner;
use WPML\TM\ATE\ClonedSites\InProgressJobsCanceller;
use WPML\TM\ATE\ClonedSites\MigrationLogger;
use WPML\TM\ATE\ClonedSites\AliasDomainResetFlag;
use WPML\TM\ATE\ClonedSites\SetupMigration\Resetter\SiteKeyCleaner;
use WPML\TM\ATE\ClonedSites\SetupMigration\Resetter\SiteKeyRegistrar;

use function WPML\Container\make;

class Handler {

	const TRANSIENT_KEY = 'wpml_ate_auto_migration_succeeded';
	const OPTION_MIGRATION_DATA = 'wpml_ate_auto_migration_data';
	const OPTION_MIGRATION_FAILED = 'wpml_ate_auto_migration_failed';

	private $signer;

	private $endpoints;

	private $credentialsStorage;

	private $auth;

	private $siteKeyCleaner;

	private $siteKeyRegistrar;

	private static $processing = false;

	public function __construct(
		AmsRequestSigner $signer,
		\WPML_TM_ATE_AMS_Endpoints $endpoints,
		\WPML_TM_ATE_Authentication $auth,
		AmsCredentialsStorage $credentialsStorage,
		SiteKeyCleaner $siteKeyCleaner,
		SiteKeyRegistrar $siteKeyRegistrar
	) {
		$this->signer             = $signer;
		$this->endpoints          = $endpoints;
		$this->auth               = $auth;
		$this->credentialsStorage = $credentialsStorage;
		$this->siteKeyCleaner     = $siteKeyCleaner;
		$this->siteKeyRegistrar   = $siteKeyRegistrar;
	}

	public function tryMigrate( string $oldUrl = '', string $newUrl = '' ): bool {
		if ( ! $oldUrl || ! $newUrl ) {
			$existing = self::getMigrationData();
			if ( is_array( $existing ) ) {
				$oldUrl = $oldUrl ?: ( $existing['old_url'] ?? '' );
				$newUrl = $newUrl ?: ( $existing['new_url'] ?? '' );
			}
		}

		if ( ! $this->ensureInstallerAvailable() ) {
			return $this->fail( $oldUrl, $newUrl );
		}

		if ( ! \SitePress_Setup::setup_complete() ) {
			return false;
		}

		if ( self::$processing ) {
			return false;
		}

		if ( $this->alreadyMigratedForCurrentUrls( $oldUrl, $newUrl ) ) {
			return true;
		}

		self::$processing = true;

		MigrationLogger::begin();

		try {
			$result = $this->doMigrate( $oldUrl, $newUrl );

			if ( $result ) {
				delete_option( self::OPTION_MIGRATION_FAILED );
				MigrationLogger::siteUnlocked();
			} else {
				MigrationLogger::migrationFailed();
				$this->fail( $oldUrl, $newUrl );
			}

			return $result;
		} finally {
			MigrationLogger::end();
			self::$processing = false;
		}
	}

	private function fail( string $oldUrl = '', string $newUrl = '' ): bool {
		update_option( self::OPTION_MIGRATION_FAILED, true, false );

		if ( $oldUrl || $newUrl ) {
			update_option( self::OPTION_MIGRATION_DATA, [
				'old_url' => $oldUrl,
				'new_url' => $newUrl,
			], false );
		}

		return false;
	}

	private function doMigrate( string $oldUrl, string $newUrl ): bool {
		$body = $this->callCopyWithAttachment();

		if ( ! $body ) {
			return false;
		}

		if ( ! isset( $body['new_shared_key'], $body['new_secret_key'], $body['new_website_uuid'] ) ) {
			MigrationLogger::copyResponseInvalid( $body );
			return false;
		}

		$stored = $this->credentialsStorage->store( $body );
		MigrationLogger::credentialsStored( $stored );

		if ( ! $stored ) {
			return false;
		}

		if ( ! $this->sendConfirmation() ) {
			MigrationLogger::confirmFailed();
			return false;
		}

		$cancelledCount = make( InProgressJobsCanceller::class )->cancel();
		MigrationLogger::jobsCancelled( (int) $cancelledCount );

		$this->handleSiteKey( $body );

		$organizationName      = $body['billing_group_name'] ?? $oldUrl;
		$organizationConnected = (bool) ( $body['organization_connected'] ?? true );

		$aliasDomainReset = AliasDomainResetFlag::isSet();

		update_option( self::OPTION_MIGRATION_DATA, [
			'old_url'                => $oldUrl,
			'new_url'                => $newUrl,
			'organization_name'      => $organizationName,
			'organization_connected' => $organizationConnected,
			'alias_domain_reset'     => $aliasDomainReset,
		], false );

		if ( $oldUrl && $newUrl ) {
			Settings::setAndSave( 'migrated_site', [
				'old_url' => $oldUrl,
				'new_url' => $newUrl,
			] );
		}

		set_transient( self::TRANSIENT_KEY, [
			'old_url' => $oldUrl,
			'new_url' => $newUrl,
		], HOUR_IN_SECONDS );

		do_action( 'wpml_tm_ate_synchronize_translators' );

		return true;
	}

	private function callCopyWithAttachment() {
		$registration_data = get_option( \WPML_TM_ATE_Authentication::AMS_DATA_KEY, [] );

		$url = $this->endpoints->get_ams_copy_attached();

		$params = [
			'shared_key'                  => isset( $registration_data['shared'] ) ? $registration_data['shared'] : '',
			'website_uuid'                => $this->auth->get_site_id(),
			'respect_previous_disconnect' => 'true',
		];

		MigrationLogger::copyRequestSent( $url );

		$response = $this->signer->send( $url, 'POST', $params );

		MigrationLogger::copyResponse( $response );

		if ( is_wp_error( $response ) || ! is_array( $response ) ) {
			return null;
		}

		if (
			! isset( $response['response']['code'] )
			|| $response['response']['code'] !== 200
			|| ! isset( $response['body'] )
		) {
			return null;
		}

		$body = json_decode( $response['body'], true );

		if ( ! is_array( $body ) ) {
			return null;
		}

		return $body;
	}

	private function sendConfirmation(): bool {
		$registration_data = get_option( \WPML_TM_ATE_Authentication::AMS_DATA_KEY, [] );

		MigrationLogger::confirmSent( $this->auth->get_site_id() );

		$response = $this->signer->send(
			$this->endpoints->get_ams_site_confirm(),
			'POST',
			[
				'new_shared_key'   => isset( $registration_data['shared'] ) ? $registration_data['shared'] : '',
				'new_website_uuid' => $this->auth->get_site_id(),
			]
		);

		if ( is_wp_error( $response ) || ! is_array( $response ) ) {
			MigrationLogger::confirmRequestFailed( $response );
			return false;
		}

		if ( ! isset( $response['body'] ) ) {
			MigrationLogger::confirmRequestFailed( $response );
			return false;
		}

		$body = json_decode( $response['body'], true );
		$confirmed = is_array( $body ) && ! empty( $body['confirmed'] );

		MigrationLogger::confirmResponse( $confirmed );

		return $confirmed;
	}

	private function handleSiteKey( array $body ) {
		$siteKey = isset( $body['site_key'] ) ? $body['site_key'] : null;

		if ( ! $siteKey || ! $this->siteKeyRegistrar->register( $siteKey ) ) {
			$this->siteKeyCleaner->unregister();
		}
	}

	private function ensureInstallerAvailable(): bool {
		if ( function_exists( 'OTGS_Installer' ) ) {
			return true;
		}

		if ( function_exists( 'wpml_installer_force_load' ) ) {
			wpml_installer_force_load();
		}

		return function_exists( 'OTGS_Installer' );
	}

	public static function hasMigrated(): bool {
		return (bool) get_transient( self::TRANSIENT_KEY );
	}

	public static function getMigrationData() {
		$data = get_option( self::OPTION_MIGRATION_DATA, null );

		return is_array( $data ) ? $data : null;
	}

	public static function clearMigrationFlag() {
		delete_transient( self::TRANSIENT_KEY );
	}

	public static function clearMigrationData() {
		delete_option( self::OPTION_MIGRATION_DATA );
	}

	public static function setOrganizationConnected( bool $connected ) {
		$data = self::getMigrationData();
		if ( ! is_array( $data ) ) {
			return;
		}
		$data['organization_connected'] = $connected;
		update_option( self::OPTION_MIGRATION_DATA, $data, false );
	}

	public static function hasSitekey(): bool {
		if ( ! function_exists( 'OTGS_Installer' ) ) {
			return false;
		}
		$installer = \OTGS_Installer();
		if ( ! $installer ) {
			return false;
		}
		return (bool) $installer->get_site_key( 'wpml' );
	}

	public static function hasFailed(): bool {
		return (bool) get_option( self::OPTION_MIGRATION_FAILED, false );
	}

	public static function resolveInitialState( $migrationData = null ): string {
		if ( self::hasFailed() ) {
			return 'error';
		}

		if ( $migrationData === null ) {
			$migrationData = self::getMigrationData();
		}

		if ( ! is_array( $migrationData ) || empty( $migrationData ) ) {
			return 'error';
		}

		$connected = isset( $migrationData['organization_connected'] )
			? (bool) $migrationData['organization_connected']
			: true;

		return $connected ? 'success' : 'success-independent';
	}

	public static function clearFailureFlag() {
		delete_option( self::OPTION_MIGRATION_FAILED );
	}

	private function alreadyMigratedForCurrentUrls( string $oldUrl, string $newUrl ): bool {
		$cached = get_transient( self::TRANSIENT_KEY );

		return is_array( $cached )
			&& isset( $cached['old_url'], $cached['new_url'] )
			&& $cached['old_url'] === $oldUrl
			&& $cached['new_url'] === $newUrl;
	}
}

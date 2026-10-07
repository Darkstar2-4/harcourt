<?php

namespace WPML\TM\ATE\ClonedSites\AutoMigration;

use WPML\Core\WP\App\Resources;
use WPML\LIB\WP\User;
use WPML\TM\ATE\ClonedSites\AutoMigration\Endpoints\Connect;
use WPML\TM\ATE\ClonedSites\AutoMigration\Endpoints\Disconnect;
use WPML\TM\ATE\ClonedSites\AutoMigration\Endpoints\Dismiss;
use WPML\TM\ATE\ClonedSites\AutoMigration\Endpoints\Retry;

class Notice implements \IWPML_Backend_Action, \IWPML_DIC_Action {

	const NOTICE_URL_KEY = 'wpml_ate_auto_migration_notice_url';

	public function add_hooks() {
		$renderMountDiv = function () {
			$this->renderMountDiv();
		};

		add_action( 'admin_notices', $renderMountDiv );

		add_filter( 'wpml_tm_dashboard_notices', function ( $notices ) use ( $renderMountDiv ) {
			$notices[] = $renderMountDiv;
			return $notices;
		} );

		add_action( 'admin_enqueue_scripts', [ $this, 'enqueueScripts' ] );
	}

	public function renderMountDiv() {
		if ( ! $this->shouldRender() ) {
			return;
		}

		echo '<div id="wpml-ate-auto-migration-root" class="notice"></div>';
	}

	public function enqueueScripts() {
		if ( ! $this->shouldRender() ) {
			return;
		}

		$migrationData = Handler::getMigrationData();

		$enqueue = Resources::enqueueApp( 'ate-auto-migration' );
		$enqueue( [
			'name' => 'wpml_ate_auto_migration',
			'data' => [
				'initialState'     => Handler::resolveInitialState( $migrationData ),
				'migrationData'    => $migrationData,
				'hasSitekey'       => Handler::hasSitekey(),
				'siteKeyConfig'    => $this->getSiteKeyConfig(),
				'aliasDomainReset' => ! empty( $migrationData['alias_domain_reset'] ),
				'endpoints'        => [
					'retry'      => Retry::class,
					'disconnect' => Disconnect::class,
					'connect'    => Connect::class,
					'dismiss'    => Dismiss::class,
				],
			],
		] );
	}

	private function shouldRender(): bool {
		$this->discardStaleMigrationData();

		if ( ! User::canManageOptions() ) {
			return false;
		}

		return Handler::getMigrationData() || Handler::hasFailed();
	}

	private function discardStaleMigrationData(): void {
		if ( defined( 'ATE_CLONED_SITE_URL' ) || defined( 'ATE_CLONED_DEFAULT_SITE_URL' ) ) {
			return;
		}

		$data = Handler::getMigrationData();

		if ( ! $data || empty( $data['new_url'] ) ) {
			return;
		}

		if ( $this->normalizeUrl( $data['new_url'] ) === $this->normalizeUrl( get_site_url() ) ) {
			return;
		}

		Handler::clearMigrationData();
		Handler::clearFailureFlag();
	}

	private function normalizeUrl( string $url ): string {
		return rtrim( preg_replace( '#^https?://#i', '', $url ), '/' );
	}

	private function getSiteKeyConfig(): array {
		return [
			'nonce'   => wp_create_nonce( 'save_site_key_wpml' ),
			'siteUrl' => get_site_url(),
		];
	}
}

<?php

class WPML_ST_Upgrade {

	const TRANSIENT_UPGRADE_IN_PROGRESS = 'wpml_st_upgrade_in_progress';

	private $sitepress;

	private $command_factory;

	private $upgrade_in_progress;

	public function __construct( SitePress $sitepress, ?WPML_ST_Upgrade_Command_Factory $command_factory = null ) {
		$this->sitepress       = $sitepress;
		$this->command_factory = $command_factory;
	}

	public function run() {
		if ( get_transient( self::TRANSIENT_UPGRADE_IN_PROGRESS ) ) {
			return;
		}

		if ( $this->sitepress->get_wp_api()->is_admin() ) {
			if ( $this->sitepress->get_wp_api()->constant( 'DOING_AJAX' ) ) {
				$this->run_ajax();
			} else {
				$this->run_admin();
			}
		} else {
			$this->run_front_end();
		}

		$this->set_upgrade_completed();
	}

	private function run_admin() {
		$this->maybe_run( 'WPML_ST_Upgrade_Migrate_Originals' );
		$this->maybe_run( 'WPML_ST_Upgrade_Display_Strings_Scan_Notices' );
		$this->maybe_run( 'WPML_ST_Upgrade_DB_String_Packages' );
		$this->maybe_run( 'WPML_ST_Upgrade_MO_Scanning' );
		$this->maybe_run( 'WPML_ST_Upgrade_DB_String_Name_Index' );
		$this->maybe_run( 'WPML_ST_Upgrade_DB_Longtext_String_Value' );
		$this->maybe_run( 'WPML_ST_Upgrade_DB_Strings_Add_Translation_Priority_Field' );
		$this->maybe_run( 'WPML_ST_Upgrade_DB_String_Packages_Word_Count' );
		$this->maybe_run( 'WPML_ST_Upgrade_DB_String_Packages_Translator_Note' );
		$this->maybe_run( '\WPML\ST\Upgrade\Command\RegenerateMoFilesWithStringNames' );
		$this->maybe_run( \WPML\ST\Upgrade\Command\MigrateMultilingualWidgets::class );
		$this->maybe_run( \WPML\ST\Upgrade\Command\UpgradeAutoregisteringStrings::class );
		$this->maybe_run( \WPML\ST\Upgrade\Command\UpgradeWpSettingsStrings::class );
		$this->maybe_run( \WPML\ST\Upgrade\Command\DeleteFileHashingOption::class );
	}

	private function run_ajax() {
		$this->maybe_run_ajax( 'WPML_ST_Upgrade_Migrate_Originals' );

		$this->maybe_run( 'WPML_ST_Upgrade_MO_Scanning' );
		$this->maybe_run( 'WPML_ST_Upgrade_DB_String_Packages_Word_Count' );
		$this->maybe_run( 'WPML_ST_Upgrade_DB_String_Packages_Translator_Note' );
	}

	private function run_front_end() {
		$this->maybe_run( 'WPML_ST_Upgrade_MO_Scanning' );
		$this->maybe_run( 'WPML_ST_Upgrade_DB_String_Packages_Word_Count' );
		$this->maybe_run( 'WPML_ST_Upgrade_DB_String_Packages_Translator_Note' );
	}

	private function maybe_run( $class ) {
		if ( ! $this->has_command_been_executed( $class ) ) {
			$this->set_upgrade_in_progress();
			$upgrade = $this->command_factory->create( $class );
			if ( $upgrade->run() ) {
				$this->mark_command_as_executed( $class );
			}
		}
	}

	private function maybe_run_ajax( $class ) {
		if ( ! $this->has_command_been_executed( $class ) ) {
			$this->run_ajax_command( $class );
		}
	}

	private function run_ajax_command( $class ) {
		if ( $this->nonce_ok( $class ) ) {
			$upgrade = $this->command_factory->create( $class );
			if ( $upgrade->run_ajax() ) {
				$this->mark_command_as_executed( $class );
				$this->sitepress->get_wp_api()->wp_send_json_success( '' );
			}
		}
	}

	private function nonce_ok( $class ) {
		$ok = false;

		$class = strtolower( $class );
		$class = str_replace( '_', '-', $class );
		if ( isset( $_POST['action'] ) && $_POST['action'] === $class ) {
			$nonce = $this->filter_nonce_parameter();
			if ( $this->sitepress->get_wp_api()->wp_verify_nonce( $nonce, $class . '-nonce' ) ) {
				$ok = true;
			}
		}

		return $ok;
	}

	public function has_command_been_executed( $class ) {
		$id       = call_user_func( [ $class, 'get_command_id' ] );
		$settings = $this->sitepress->get_setting( 'st', [] );

		return isset( $settings[ $id . '_has_run' ] );
	}

	public function mark_command_as_executed( $class ) {
		$id                           = call_user_func( [ $class, 'get_command_id' ] );
		$settings                     = $this->sitepress->get_setting( 'st', [] );
		$settings[ $id . '_has_run' ] = true;
		$this->sitepress->set_setting( 'st', $settings, true );
		wp_cache_flush();
	}

	protected function filter_nonce_parameter() {
		return filter_input( INPUT_POST, 'nonce', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
	}

	private function set_upgrade_in_progress() {
		if ( ! $this->upgrade_in_progress ) {
			$this->upgrade_in_progress = true;
			set_transient( self::TRANSIENT_UPGRADE_IN_PROGRESS, true, MINUTE_IN_SECONDS );
		}
	}

	private function set_upgrade_completed() {
		if ( $this->upgrade_in_progress ) {
			$this->upgrade_in_progress = false;
			delete_transient( self::TRANSIENT_UPGRADE_IN_PROGRESS );
		}
	}
}

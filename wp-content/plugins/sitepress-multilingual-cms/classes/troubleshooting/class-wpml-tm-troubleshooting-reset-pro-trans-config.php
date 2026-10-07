<?php

use WPML\API\Sanitize;

class WPML_TM_Troubleshooting_Reset_Pro_Trans_Config extends WPML_TM_AJAX_Factory_Obsolete {

	const SCRIPT_HANDLE = 'wpml_reset_pro_trans_config';

	private $wpdb;

	private $sitepress;
	private $translation_proxy;

	public function __construct( &$sitepress, &$translation_proxy, &$wpml_wp_api, &$wpdb ) {
		parent::__construct( $wpml_wp_api );

		$this->sitepress         = &$sitepress;
		$this->translation_proxy = &$translation_proxy;
		$this->wpdb              = &$wpdb;
		add_action( 'init', array( $this, 'load_action' ) );

		$this->add_ajax_action( 'wp_ajax_wpml_reset_pro_trans_config', array( $this, 'reset_pro_translation_configuration_action' ) );
		$this->init();
	}

	public function load_action() {
		$page           = Sanitize::stringProp( 'page', $_GET );
		$should_proceed = SitePress_Setup::setup_complete()
		                  && ! $this->wpml_wp_api->is_heartbeat()
		                  && ! $this->wpml_wp_api->is_ajax()
		                  && ! $this->wpml_wp_api->is_cron_job()
		                  && $page
		                  && strpos( $page, 'sitepress-multilingual-cms/menu/troubleshooting.php' ) === 0;

		if ( $should_proceed ) {
			$this->add_hooks();
		}
	}

	private function add_hooks() {
		add_action( 'after_setup_complete_troubleshooting_functions', array( $this, 'render_ui' ) );
	}

	public function register_resources() {
		wp_register_script( self::SCRIPT_HANDLE, WPML_TM_URL . '/res/js/reset-pro-trans-config.js', array( 'jquery', 'jquery-ui-dialog' ), ICL_SITEPRESS_SCRIPT_VERSION, true );
	}

	public function enqueue_resources( $hook_suffix ) {
		if ( $this->wpml_wp_api->is_troubleshooting_page() ) {
			$this->register_resources();
			$translation_service_name = $this->translation_proxy->get_current_service_name();
			$strings                  = array(
				'placeHolder'  => 'icl_reset_pro',
				'reset'        => wp_create_nonce( 'reset_pro_translation_configuration' ),
				/* translators: Reset professional translation state confirmation ("%1$s" is the service name) */
				'confirmation' => sprintf( __( 'Are you sure you want to reset the %1$s translation process?', 'wpml-translation-management' ), $translation_service_name ),
				'action'       => self::SCRIPT_HANDLE,
				'nonce'        => wp_create_nonce( self::SCRIPT_HANDLE ),
			);
			wp_localize_script( self::SCRIPT_HANDLE, self::SCRIPT_HANDLE . '_strings', $strings );
			wp_enqueue_script( self::SCRIPT_HANDLE );
		}
	}

	public function render_ui() {
		$clear_ts_factory = new WPML_TM_Troubleshooting_Reset_Pro_Trans_Config_UI_Factory();
		$clear_ts         = $clear_ts_factory->create();
		echo $clear_ts->show();
	}

	public function reset_pro_translation_configuration_action() {
		if ( ! current_user_can( 'wpml_manage_troubleshooting' ) ) {
			return $this->wpml_wp_api->wp_send_json_error( __( "You can't do that!", 'wpml-translation-management' ) );
		}

		$action = Sanitize::stringProp( 'action', $_POST );
		$nonce  = Sanitize::stringProp( 'nonce', $_POST );

		$valid_nonce = $nonce && $action && wp_verify_nonce( $nonce, $action );
		if ( $valid_nonce ) {
			return $this->wpml_wp_api->wp_send_json_success( $this->reset_pro_translation_configuration() );
		} else {
			return $this->wpml_wp_api->wp_send_json_error( __( "You can't do that!", 'wpml-translation-management' ) );
		}
	}

	public function reset_pro_translation_configuration() {
		$translation_service_name = $this->translation_proxy->get_current_service_name();

		$this->sitepress->set_setting( 'content_translation_languages_setup', false );
		$this->sitepress->set_setting( 'content_translation_setup_complete', false );
		$this->sitepress->set_setting( 'content_translation_setup_wizard_step', false );
		$this->sitepress->set_setting( 'translator_choice', false );
		$this->sitepress->set_setting( 'icl_lang_status', false );
		$this->sitepress->set_setting( 'icl_balance', false );
		$this->sitepress->set_setting( 'icl_support_ticket_id', false );
		$this->sitepress->set_setting( 'icl_current_session', false );
		$this->sitepress->set_setting( 'last_get_translator_status_call', false );
		$this->sitepress->set_setting( 'last_icl_reminder_fetch', false );
		$this->sitepress->set_setting( 'icl_account_email', false );
		$this->sitepress->set_setting( 'translators_management_info', false );
		$this->sitepress->set_setting( 'site_id', false );
		$this->sitepress->set_setting( 'access_key', false );
		$this->sitepress->set_setting( 'ts_site_id', false );
		$this->sitepress->set_setting( 'ts_access_key', false );

		if ( class_exists( 'TranslationProxy_Basket' ) ) {
			TranslationProxy_Basket::delete_all_items_from_basket();
		}

		global $wpdb;

		$sql_for_remote_rids = $wpdb->prepare(
			"FROM {$wpdb->prefix}icl_translation_status
            WHERE translation_service != 'local'
                AND translation_service != 0
				AND status IN ( %d, %d )",
			ICL_TM_WAITING_FOR_TRANSLATOR,
			ICL_TM_IN_PROGRESS
		);

		$wpdb->query( "DELETE FROM {$wpdb->prefix}icl_translate_job WHERE rid IN (SELECT rid {$sql_for_remote_rids})" );

		$wpdb->query( "DELETE {$sql_for_remote_rids}" );

		$this->sitepress->set_setting( 'icl_html_status', false );
		$this->sitepress->set_setting( 'language_pairs', false );

		if ( ! $this->translation_proxy->has_preferred_translation_service() ) {
			$this->sitepress->set_setting( 'translation_service', false );
			$this->sitepress->set_setting( 'icl_translation_projects', false );
		}

		$this->sitepress->save_settings();

		$this->wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}icl_core_status" );
		$this->wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}icl_content_status" );
		$this->wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}icl_string_status" );
		$this->wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}icl_node" );
		$this->wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}icl_reminders" );

		if ( $this->translation_proxy->has_preferred_translation_service() && $translation_service_name ) {
			/* translators: Confirmation that translation process was reset ("%1$s" is the service name) */
			$confirm_message = sprintf( __( 'The translation process with %1$s was reset.', 'wpml-translation-management' ), $translation_service_name );
		} elseif ( $translation_service_name ) {
			/* translators: Confirmation that site has been disconnected from translation service ("%1$s" is the service name) */
			$confirm_message = sprintf( __( 'Your site was successfully disconnected from %1$s. Go to the translators tab to connect a new %1$s account or use a different translation service.', 'wpml-translation-management' ), $translation_service_name );
		} else {
			$confirm_message = __( 'PRO translation has been reset.', 'wpml-translation-management' );
		}

		return $confirm_message;
	}
}

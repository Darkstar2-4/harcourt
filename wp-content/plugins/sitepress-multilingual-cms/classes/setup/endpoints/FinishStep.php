<?php

namespace WPML\Setup\Endpoint;

use WPML\AdminLanguageSwitcher\AdminLanguageSwitcher;
use WPML\Ajax\IHandler;
use WPML\API\Settings;
use WPML\Collect\Support\Collection;
use WPML\Core\Component\PostHog\Application\Service\Event\EventInstanceService;
use WPML\Core\LanguageNegotiation;
use WPML\FP\Either;
use WPML\Legacy\SharedKernel\Installer\Application\Query\WpmlActivePluginsQuery;
use WPML\LIB\WP\User;
use WPML\PostHog\Event\CaptureSetupWizardCompletedEvent;
use WPML\PostHog\State\PostHogState;
use WPML\TM\ATE\AutoTranslate\Endpoint\EnableATE;
use WPML\TranslationRoles\Service\AdministratorRoleManager;
use WPML\UrlHandling\WPLoginUrlConverter;
use function WPML\Container\make;
use WPML\FP\Lst;
use WPML\FP\Right;
use WPML\Setup\Option;
use WPML\TM\Menu\TranslationServices\Endpoints\Deactivate;
use WPML\TranslationMode\Endpoint\SetTranslateEverything;

class FinishStep implements IHandler {

	private $administratorRoleManager;

	public function __construct(
		AdministratorRoleManager $administratorRoleManager
	) {
		$this->administratorRoleManager = $administratorRoleManager;
	}

	public function run( Collection $data ) {
		\WPML\Media\Option::prepareSetup();

		$wpmlInstallation = wpml_get_setup_instance();
		$originalLanguage = Option::getOriginalLang();
		$translationLangs = Option::getTranslationLangs();
		$wpmlInstallation->finish_step1( $originalLanguage );
		$wpmlInstallation->finish_step2( Lst::append( $originalLanguage, $translationLangs ) );
		$wpmlInstallation->finish_installation();

		self::enableFooterLanguageSwitcher();

		make( SetTranslateEverything::class )->run(
			wpml_collect( [
				'translateEverything' => false,
				'reviewMode'          => null,
				'onlyNew'             => true
			] )
		);

		$translationMode = Option::getTranslationMode();
		if ( ! Lst::includes( 'users', $translationMode ) ) {
			make( \WPML_Translator_Records::class )->delete_all();
		}

		if ( ! Lst::includes( 'manager', $translationMode ) ) {
			make( \WPML_Translation_Manager_Records::class )->delete_all();
		}


		$this->administratorRoleManager->initializeAllAdministrators();

		if ( Option::isTMAllowed( ) ) {
			if ( ! Lst::includes( 'service', $translationMode ) ) {
				make( Deactivate::class )->run( wpml_collect( [] ) );
			}

			Settings::assoc( 'translation-management', \WPML_TM_Post_Edit_TM_Editor_Mode::TM_KEY_GLOBAL_USE_WPML, 'dashboard' );
		} else {
			Option::setTranslateEverything( false );
		}

		WPLoginUrlConverter::enable( true );
		AdminLanguageSwitcher::enable();

		$aiTranslationData = $data->get( 'ai_translation_data', null );

		$this->captureWizardFinishedEvent([
			'original_language' => $originalLanguage,
			'translation_languages' => $translationLangs,
			'ai_translation_data' => $aiTranslationData,
		]);

		return Right::of( true );
	}


	private function captureWizardFinishedEvent( $eventData ) {

		if ( ! PostHogState::isEnabled() ) {
			return;
		}

		$eventData['translation_mode']            = Option::getTranslationMode();
		$eventData['language_negotiation_mode']   = LanguageNegotiation::getModeAsString();
		$eventData['domains']                     = LanguageNegotiation::getDomains() ?: [];
		$eventData['site_key']                    = function_exists( 'OTGS_Installer' ) && OTGS_Installer() ? (string) OTGS_Installer()->get_site_key( 'wpml' ) : null;
		$eventData['is_predefined_sitekey_saved'] = function_exists( 'OTGS_Installer' )
		                                            && OTGS_Installer()
		                                            && defined( 'OTGS_INSTALLER_SITE_KEY_WPML' )
		                                            && OTGS_INSTALLER_SITE_KEY_WPML
		                                            && OTGS_Installer()->get_site_key( 'wpml' ) === OTGS_INSTALLER_SITE_KEY_WPML;
		$eventData['is_tm_allowed']               = Option::isTMAllowed();
		$eventData['support_step_value']          = class_exists( 'OTGS_Installer_WP_Share_Local_Components_Setting' ) && \OTGS_Installer_WP_Share_Local_Components_Setting::get_setting( 'wpml' );
		$eventData['wpml_active_plugins']         = ( new WpmlActivePluginsQuery() )->getActivePlugins();

		if ( ! empty( $eventData['ai_translation_data'] ) && is_array( $eventData['ai_translation_data'] ) ) {
			$eventData['ai_translation_step_values'] = [
				'product_or_service'  => isset( $eventData['ai_translation_data']['product_or_service'] ) ?
					sanitize_text_field( $eventData['ai_translation_data']['product_or_service'] ) :
					'',
				'website_description' => isset( $eventData['ai_translation_data']['website_description'] ) ?
					sanitize_text_field( $eventData['ai_translation_data']['website_description'] ) :
					'',
				'target_audience'     => isset( $eventData['ai_translation_data']['target_audience'] ) ?
					sanitize_text_field( $eventData['ai_translation_data']['target_audience'] ) :
					'',
			];
		} else {
			$eventData['ai_translation_step_values'] = null;
		}

		unset( $eventData['ai_translation_data'] );

		$event = ( new EventInstanceService() )->getWizardCompletedEvent( $eventData );
		CaptureSetupWizardCompletedEvent::capture( $event );
	}

	private static function enableFooterLanguageSwitcher() {
		\WPML_Config::load_config_run();

		$lsSettings = make( \WPML_LS_Dependencies_Factory::class )->settings();

		$settings = $lsSettings->get_settings();
		$settings['statics']['footer']->set( 'show', true );

		$lsSettings->save_settings( $settings );
	}

}

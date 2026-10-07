<?php

namespace WPML\ST\MO\Hooks;

use WPML\ST\MO\JustInTime\MOFactory;
use WPML\ST\MO\WPLocaleProxy;
use WPML\ST\MO\WPML_Locale;
use WPML\ST\Utils\LanguageResolution;

class LanguageSwitch implements \IWPML_Action {

	private $jit_mo_factory;

	private $language_resolution;

	private static $current_locale;

	private static $globals_cache = [];

	public function __construct(
		LanguageResolution $language_resolution,
		MOFactory $jit_mo_factory
	) {
		$this->language_resolution = $language_resolution;
		$this->jit_mo_factory      = $jit_mo_factory;
	}

	public function add_hooks() {
		add_action( 'wpml_language_has_switched', [ $this, 'languageHasSwitched' ] );
	}

	private function setCurrentLocale( $locale ) {
		self::$current_locale = $locale;
	}

	public function getCurrentLocale() {
		return self::$current_locale;
	}

	public function languageHasSwitched() {
		$this->initCurrentLocale();
		$new_locale = $this->language_resolution->getCurrentLocale();
		$this->switchToLocale( $new_locale );
	}

	public function initCurrentLocale() {
		if ( ! $this->getCurrentLocale() ) {
			add_filter( 'locale', [ $this, 'filterLocale' ], PHP_INT_MAX );
			$this->setCurrentLocale( $this->language_resolution->getCurrentLocale() );
		}
	}

	public function switchToLocale( $new_locale ) {
		if ( $new_locale === $this->getCurrentLocale() ) {
			return;
		}

		$this->updateCurrentGlobalsCache();
		$this->changeWpLocale( $new_locale );
		$this->changeMoObjects( $new_locale );
		$this->setCurrentLocale( $new_locale );
	}

	public static function resetCache( $locale = null ) {
		self::$current_locale = $locale;
		self::$globals_cache = [];
	}

	private function updateCurrentGlobalsCache() {
		$cache = [
			'wp_locale' => isset( $GLOBALS['wp_locale'] ) ? $GLOBALS['wp_locale'] : null,
			'l10n'      => isset( $GLOBALS['l10n'] ) ? (array) $GLOBALS['l10n'] : [],
		];

		self::$globals_cache[ $this->getCurrentLocale() ] = $cache;
	}

	private function changeWpLocale( $new_locale ) {
		if ( isset( self::$globals_cache[ $new_locale ]['wp_locale'] ) ) {
			$GLOBALS['wp_locale'] = self::$globals_cache[ $new_locale ]['wp_locale'];
		} else {
			$GLOBALS['wp_locale'] = new WPML_Locale();
		}
	}

	private function changeMoObjects( $new_locale ) {
		$this->resetTranslationAvailabilityInformation();

		$cachedMoObjects = isset( self::$globals_cache[ $new_locale ]['l10n'] )
			? self::$globals_cache[ $new_locale ]['l10n']
			: [];

		$GLOBALS['l10n'] = $this->jit_mo_factory->get( $new_locale, $this->getUnloadedDomains(), $cachedMoObjects );

		$this->setLocaleInWP65TranslationController( $new_locale );
	}

	private function setLocaleInWP65TranslationController( $new_locale ) {
		if ( class_exists( \WP_Translation_Controller::class ) ) {
			\WP_Translation_Controller::get_instance()->set_locale( $new_locale );
		}
	}

	private function resetTranslationAvailabilityInformation() {
		global $wp_textdomain_registry;
		if ( ! isset( $wp_textdomain_registry ) && function_exists( '_get_path_to_translation' ) ) {
			_get_path_to_translation( '', true );
		}
	}

	public function filterLocale( $locale ) {
		$currentLocale = $this->getCurrentLocale();

		if ( $currentLocale ) {
			return $currentLocale;
		}

		return $locale;
	}

	private function getUnloadedDomains() {
		$unloadedDomains = isset( $GLOBALS['l10n_unloaded'] ) ? array_keys( (array) $GLOBALS['l10n_unloaded'] ) : [];

		if ( class_exists('\WP_Translation_Controller') ) {
			foreach ( $unloadedDomains as $key => $domain ) {
				if ( isset( $GLOBALS['l10n'][ $domain ] ) && ! $GLOBALS['l10n'][ $domain ] instanceof \NOOP_Translations ) {
					unset( $unloadedDomains[ $key ] );
				}
			}
		}
		return $unloadedDomains;
	}
}

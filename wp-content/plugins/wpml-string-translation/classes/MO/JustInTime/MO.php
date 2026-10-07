<?php

namespace WPML\ST\MO\JustInTime;

use NOOP_Translations;
use WPML\LIB\WP\WordPress;
use WPML\ST\MO\Hooks\LoadTranslationFile;
use WPML\ST\MO\LoadedMODictionary;

class MO extends \MO {

	private $loaded_mo_dictionary;

	protected $locale;

	private $domain;

	private $isLoading = false;

	public function __construct(
		LoadedMODictionary $loaded_mo_dictionary,
		$locale,
		$domain
	) {
		$this->loaded_mo_dictionary = $loaded_mo_dictionary;
		$this->locale               = $locale;
		$this->domain               = $domain;
	}

	public function translate( $singular, $context = null ) {
		if ( $this->isLoading ) {
			return $singular;
		}

		$this->load();
		return _x( $singular, $context, $this->domain );
	}

	public function translate_plural( $singular, $plural, $count, $context = null ) {
		if ( $this->isLoading ) {
			return $count > 1 ? $plural : $singular;
		}

		$this->load();
		return _nx( $singular, $plural, $count, $context, $this->domain );
	}

	private function load() {
		if ( $this->isLoaded() ) {
			return true;
		}

		$this->isLoading = true;
		$this->loadTextDomain();

		if ( ! $this->isLoaded() ) {
			$GLOBALS['l10n'][ $this->domain ] = new NOOP_Translations();
		}

		$this->isLoading = false;
	}

	protected function loadTextDomain() {
		$this->loaded_mo_dictionary
			->getFiles( $this->domain, $this->locale )
			->each( function( $mofile ) {
				$defaultTranslationPath =
					LoadTranslationFile::getDefaultWordPressTranslationPath( $this->domain, $this->locale );

				load_textdomain( $this->domain, $mofile, $this->locale );
				if ( $defaultTranslationPath ) {
					load_textdomain( $this->domain, $defaultTranslationPath, $this->locale );
				}
			} );
	}

	private function isLoaded() {
		return isset( $GLOBALS['l10n'][ $this->domain ] )
		       && ! $GLOBALS['l10n'][ $this->domain ] instanceof self;
	}
}

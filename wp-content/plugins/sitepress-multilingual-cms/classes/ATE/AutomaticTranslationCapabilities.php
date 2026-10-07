<?php

namespace WPML\TM\ATE;

use WPML\FP\Fns;
use WPML\FP\Obj;
use WPML\Setup\Option;
use WPML\TM\API\ATE\CachedLanguageMappings;
use function WPML\FP\curryN;

class AutomaticTranslationCapabilities {

	public static function isAvailable() {
		return \WPML_TM_ATE_Status::is_enabled_and_activated();
	}

	public static function isLanguageEligible( $languageCode ) {
		if ( ! self::isAvailable() ) {
			return false;
		}

		return CachedLanguageMappings::isCodeEligibleForAutomaticTranslations( $languageCode );
	}

	public static function withCapabilityInfo( $languages = null, $sourceLang = null ) {
		$fn = curryN( 1, function ( $languages, $sourceLang = null ) {
			if ( ! self::isAvailable() ) {
				return Fns::map(
					Obj::addProp( 'can_be_translated_automatically', Fns::always( false ) ),
					$languages
				);
			}

			return CachedLanguageMappings::withCanBeTranslatedAutomatically( $languages, $sourceLang );
		} );

		return call_user_func_array( $fn, func_get_args() );
	}

	public static function shouldTranslateEverything() {
		return self::isAvailable() && Option::shouldTranslateEverything();
	}

	public static function doesDefaultLanguageSupport() {
		if ( ! self::isAvailable() ) {
			return false;
		}

		return CachedLanguageMappings::doesDefaultLanguageSupportAutomaticTranslations();
	}
}

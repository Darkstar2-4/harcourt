<?php

namespace WPML\PB;

use WPML\FP\Fns;

class ShortCodesInGutenbergBlocks {

	const FORCED_GUTENBERG = 'Forced-Gutenberg';

	public static function recordPackage(
		\WPML_PB_String_Translation_By_Strategy $strategy,
		$strategyKind,
		\WPML_Package $package,
		$language
	) {
		if ( $strategyKind === 'Gutenberg' && $package->kind === 'Page Builder ShortCode Strings' ) {
			$package->kind = self::FORCED_GUTENBERG;
			$strategy->add_package_to_update_list( $package, $language );
		}

	}

	public static function fixupPackage( $package_data ) {
		if ( $package_data['package']->kind === self::FORCED_GUTENBERG ) {
			$package_data['package']->kind = 'Gutenberg';
		}

		return $package_data;
	}

	public static function normalizePackages( array $packagesToUpdate ) {
		if ( count( $packagesToUpdate ) > 1 ) {
			$isForced         = function ( $package ) { return $package['package']->kind !== self::FORCED_GUTENBERG; };
			$packagesToUpdate = array_filter( $packagesToUpdate, $isForced );
		}

		return $packagesToUpdate;
	}
}

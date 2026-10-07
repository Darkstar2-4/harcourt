<?php

namespace WPML\API;

use WPML\Collect\Support\Traits\Macroable;
use function WPML\FP\curryN;

class Version {
	use Macroable;

	public static function init() {

		self::macro( 'firstInstallation', [ '\WPML_Installation', 'getStartVersion' ] );

		self::macro( 'isHigherThanInstallation', curryN( 1, function ( $version ) {
			return version_compare( $version, self::firstInstallation(), '>' );
		} ) );

		self::macro( 'current', function () {
			return defined( 'ICL_SITEPRESS_VERSION' ) ? ICL_SITEPRESS_VERSION : false;
		} );
	}
}

Version::init();
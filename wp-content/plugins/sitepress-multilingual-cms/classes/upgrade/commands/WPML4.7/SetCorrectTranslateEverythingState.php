<?php

namespace WPML\TM\Upgrade\Commands;

use WPML\Settings\PostType\Automatic;
use WPML\Setup\Option;

class SetCorrectTranslateEverythingState implements \IWPML_Upgrade_Command {
	public function run() {
		$optionKey                   = 'WPML(setup)';
		$translateEverythingIsPaused = false;

		$wpmlSetupOptions = get_option( $optionKey );

		if ( ! is_array( $wpmlSetupOptions ) ) {
			return true;
		}

		if ( isset( $wpmlSetupOptions['translate-everything-is-paused'] ) ) {
			$translateEverythingIsPaused = boolval( $wpmlSetupOptions['translate-everything-is-paused'] );
			unset( $wpmlSetupOptions['translate-everything-is-paused'] );
		}

		$tmNotAllowed = isset( $wpmlSetupOptions[ Option::TM_ALLOWED ] ) &&
		                ! $wpmlSetupOptions[ Option::TM_ALLOWED ];

		$isAnyPostTypeDisabledForAutoTranslate = Automatic::isAnyPostTypeDisabledForAutoTranslate();

		// If TM is not allowed (blog licence) Or TranslateEverything is paused we disable TranslateEverything
		if ( $tmNotAllowed || $translateEverythingIsPaused || $isAnyPostTypeDisabledForAutoTranslate ) {
			$wpmlSetupOptions[ Option::TRANSLATE_EVERYTHING ] = false;
		}

		if ( $translateEverythingIsPaused || $isAnyPostTypeDisabledForAutoTranslate ) {
			$wpmlSetupOptions[ Option::HAS_TRANSLATE_EVERYTHING_BEEN_EVER_USED ] = true;
		}


		update_option( $optionKey, $wpmlSetupOptions, true );

		return true;
	}

	public function run_admin() {
		return $this->run();
	}

	public function run_ajax() {
		return null;
	}

	public function run_frontend() {
		return null;
	}

	public function get_results() {
		return true;
	}
}

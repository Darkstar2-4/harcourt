<?php

namespace OTGS\Installer\AdminNotices;

class ScreenConfig extends Config {

	public function isAnyMessageOnPage( array $messages ) {
		$currentScreen = get_current_screen();

		if ( ! $currentScreen instanceof \WP_Screen ) {
			return false;
		}

		return $this->hasItem( $messages, $currentScreen->id, 'screens' );
	}

	public function shouldShowMessage( $repo, $id ) {
		$currentScreen = get_current_screen();

		if ( $currentScreen instanceof \WP_Screen ) {
			if ( isset( $this->config['repo'][ $repo ][ $id ]['screens'] ) ) {
				return in_array( $currentScreen->id, $this->config['repo'][ $repo ][ $id ]['screens'] );
			}
		}

		return false;
	}

}

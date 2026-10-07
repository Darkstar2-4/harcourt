<?php

abstract class WPML_Current_Screen_Loader_Factory implements IWPML_Backend_Action_Loader, IWPML_Deferred_Action_Loader {

	public function get_load_action() {
		return 'current_screen';
	}

	abstract protected function get_screen_regex();

	abstract protected function create_hooks();

	public function create() {
		if ( $this->is_on_matching_screen() ) {
			return $this->create_hooks();
		}

		return null;
	}

	private function is_on_matching_screen() {
		$current_screen = get_current_screen();

		foreach ( array( 'id', 'base' ) as $property ) {
			if ( isset( $current_screen->{$property} )
				 && preg_match( $this->get_screen_regex(), $current_screen->{$property} )
			) {
				return true;
			}
		}

		return false;
	}
}

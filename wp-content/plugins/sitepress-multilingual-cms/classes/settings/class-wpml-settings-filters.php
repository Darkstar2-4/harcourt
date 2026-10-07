<?php

class WPML_Settings_Filters {
	function get_translatable_documents( array $types, array $read_only_cpt_settings, array $cpt_unlocked_options ) {
		global $wp_post_types;
		foreach ( $read_only_cpt_settings as $cp => $translate ) {
			if ( $this->is_cpt_unlocked( $cpt_unlocked_options, $cp ) ) {
				continue;
			}

			if ( $translate && ! isset( $types[ $cp ] ) && isset( $wp_post_types[ $cp ] ) ) {
				$types[ $cp ] = $wp_post_types[ $cp ];
			} elseif ( ! $translate && isset( $types[ $cp ] ) ) {
				unset( $types[ $cp ] );
			}
		}

		return $types;
	}

	private function is_cpt_unlocked( array $cpt_unlocked_options, $cp ) {
		return isset( $cpt_unlocked_options[ $cp ] ) && (bool) $cpt_unlocked_options[ $cp ];
	}
}

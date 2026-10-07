<?php

namespace WPML\TM\ATE\ClonedSites;

class AliasDomainCheckHandler implements \IWPML_Frontend_Action, \IWPML_Backend_Action, \IWPML_DIC_Action {

	const GET_PARAM     = 'wpml_alias_domain_check';
	const OPTION_KEY    = 'wpml_alias_domain_check_token';
	const RESPONSE_BODY = 'wpml-alias-domain-check-ok';

	public function add_hooks() {
		if ( isset( $_GET[ self::GET_PARAM ] ) ) {
			add_action( 'init', [ $this, 'handleCheck' ], 1 );
		}
	}

	public function handleCheck() {
		$token = sanitize_text_field( $_GET[ self::GET_PARAM ] );
		if ( $token ) {
			update_option( self::OPTION_KEY, $token, 'no' );
		}
		wp_die( self::RESPONSE_BODY, '', [ 'response' => 200 ] );
	}

	public static function getAndDeleteToken() {
		$token = get_option( self::OPTION_KEY, '' );
		delete_option( self::OPTION_KEY );

		return $token;
	}
}

<?php

namespace WPML\Request;

use WPML\LIB\WP\User;

class Hooks implements \IWPML_Backend_Action, \IWPML_DIC_Action {
	public function add_hooks() {
		add_filter( 'woocommerce_prevent_admin_access', [ $this, 'checkUserAdminAccess' ] );
	}

	public function checkUserAdminAccess( $preventAccess ) {
		$hasTranslationCap = (
			current_user_can( User::CAP_MANAGE_TRANSLATIONS ) ||
			current_user_can( User::CAP_TRANSLATE )
		);

		$hasOnlyRead = current_user_can( 'read' )
			&& ! current_user_can( 'edit_posts' )
			&& ! current_user_can( 'manage_woocommerce' )
			&& ! current_user_can( 'view_admin_dashboard' );

		if ( $hasTranslationCap && $hasOnlyRead ) {
			return false;
		}

		return $preventAccess;
	}
}

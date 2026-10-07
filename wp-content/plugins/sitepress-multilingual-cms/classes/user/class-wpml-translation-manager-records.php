<?php

use WPML\FP\Relation;

class WPML_Translation_Manager_Records extends WPML_Translation_Roles_Records {

	protected function prepare_hooks() {
		add_action( 'wpml_tm_ate_synchronize_managers', [ $this, 'on_translator_save' ], -10 );
	}

	protected function get_capability() {
		return \WPML\LIB\WP\User::CAP_MANAGE_TRANSLATIONS;
	}

	protected function get_required_wp_roles() {

		return wpml_collect( $this->wp_roles->role_objects )
			->filter( [ $this, 'is_required_role' ] )
			->keys()
			->reject( Relation::equals( 'administrator' ) )
			->all();
	}

	public function is_required_role( WP_Role $role ) {
		return array_key_exists( 'edit_private_posts', $role->capabilities );
	}

}

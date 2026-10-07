<?php

class WPML_TM_Restore_Skipped_Migration implements IWPML_Action {
	private $migration_state;

	public function __construct( WPML_TM_Jobs_Migration_State $migration_state ) {
		$this->migration_state = $migration_state;
	}


	public function add_hooks() {
		add_action( 'wpml_tm_translation_service_authorized', array( $this, 'restore' ) );
	}

	public function restore() {
		if ( $this->migration_state->is_skipped() ) {
			$this->migration_state->skip_migration( false );
		}
	}
}

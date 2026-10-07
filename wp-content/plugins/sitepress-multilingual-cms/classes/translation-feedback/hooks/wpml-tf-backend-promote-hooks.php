<?php

use WPML\FP\Obj;

class WPML_TF_Backend_Promote_Hooks implements IWPML_Action {
	private $promote_notices;

	private $translation_service;

	private $is_setup_complete;

	public function __construct(
		WPML_TF_Promote_Notices $promote_notices,
		$is_setup_complete,
		WPML_TF_Translation_Service $translation_service
	) {
		$this->promote_notices     = $promote_notices;
		$this->is_setup_complete   = $is_setup_complete;
		$this->translation_service = $translation_service;
	}

	public function add_hooks() {
		if ( $this->translation_service->allows_translation_feedback() ) {
			if ( $this->is_setup_complete && get_option( WPML_Installation::WPML_START_VERSION_KEY )	) {
				add_action(
					'wpml_pro_translation_completed',
					[ $this, 'add_notice_for_manager_on_job_completed' ],
					10, 3
				);
			}
		}
	}

	public function add_notice_for_manager_on_job_completed( $new_post_id, $fields, $job ) {
		if ( Obj::prop( 'translation_service', $job ) !== 'local' ) {
			$this->promote_notices->show_notice_for_new_site( (int) $job->manager_id );
		}
	}
}

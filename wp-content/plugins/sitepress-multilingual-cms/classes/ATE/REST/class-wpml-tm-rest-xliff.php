<?php

class WPML_TM_REST_XLIFF extends WPML_TM_ATE_Required_Rest_Base {
	const CAPABILITY = 'translate';

	function add_hooks() {
		$this->register_routes();
	}

	function register_routes() {
		parent::register_route(
			'/xliff/fetch/(?P<jobId>\d+)',
			array(
				'methods'  => 'GET',
				'callback' => array( $this, 'fetch_xliff' ),
			)
		);
	}

	public function fetch_xliff( WP_REST_Request $request ) {
		$job_factory = wpml_tm_load_job_factory();
		$job_id      = (int) $request->get_param( 'jobId' );
		$job         = $job_factory->get_translation_job( $job_id, false, 1, true );

		if ( $job && ( current_user_can( 'manage_translations' ) || $job->user_can_translate( wp_get_current_user() ) ) ) {
			$writer = new WPML_TM_Xliff_Writer( $job_factory );

			return array(
				'content'    => base64_encode( $writer->generate_job_xliff( $job_id ) ),
				'sourceLang' => $job->get_source_language_code(),
				'targetLang' => $job->get_language_code(),
			);
		}

		return new WP_Error(
			'wpml_tm_xliff_forbidden',
			__( 'You are not allowed to access this translation job.', 'sitepress' ),
			array( 'status' => 403 )
		);
	}

	function get_allowed_capabilities( WP_REST_Request $request ) {
		return self::CAPABILITY;
	}
}

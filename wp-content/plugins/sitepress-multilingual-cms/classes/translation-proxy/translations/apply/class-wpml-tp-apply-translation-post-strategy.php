<?php

class WPML_TP_Apply_Translation_Post_Strategy implements WPML_TP_Apply_Translation_Strategy {
	private $jobs_api;

	private $wpdb;

	public function __construct( WPML_TP_Jobs_API $jobs_api ) {
		$this->jobs_api = $jobs_api;

		global $wpdb;
		$this->wpdb = $wpdb;
	}

	public function apply( WPML_TM_Job_Entity $job, WPML_TP_Translation_Collection $translations ) {
		if ( ! $job instanceof WPML_TM_Post_Job_Entity ) {
			throw new InvalidArgumentException( 'A job must have post type' );
		}

		kses_remove_filters();
		wpml_tm_save_data( $this->build_data( $job, $translations ) );
		kses_init();

		$this->jobs_api->update_job_state( $job, 'delivered' );
	}

	private function build_data( WPML_TM_Post_Job_Entity $job, WPML_TP_Translation_Collection $translations ) {
		$data = array(
			'job_id'   => $job->get_translate_job_id(),
			'fields'   => array(),
			'complete' => 1
		);

		foreach ( $translations as $translation ) {
			foreach ( $job->get_elements() as $element ) {
				if ( $element->get_type() === $translation->get_field() ) {
					$data['fields'][ $element->get_type() ] = array(
						'data'       => $translation->get_target(),
						'finished'   => 1,
						'tid'        => $element->get_id(),
						'field_type' => $element->get_type(),
						'format'     => $element->get_format()
					);
				}
			}
		}

		return $data;
	}
}
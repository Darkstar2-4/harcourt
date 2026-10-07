<?php

class WPML_TP_Apply_Translation_Strategies {
	private $post_strategy;

	private $wpdb;

	public function __construct( wpdb $wpdb ) {
		$this->wpdb = $wpdb;
	}

	public function get( WPML_TM_Job_Entity $job ) {
		switch ( $job->get_type() ) {
			case WPML_TM_Job_Entity::POST_TYPE:
			case WPML_TM_Job_Entity::PACKAGE_TYPE:
			case WPML_TM_Job_Entity::STRING_BATCH:
				return $this->get_post_strategy();
			default:
				throw new InvalidArgumentException( 'Job type: ' . $job->get_type() . ' is not supported' );
		}
	}

	private function get_post_strategy() {
		if ( ! $this->post_strategy ) {
			$this->post_strategy = new WPML_TP_Apply_Translation_Post_Strategy( wpml_tm_get_tp_jobs_api() );
		}

		return $this->post_strategy;
	}
}

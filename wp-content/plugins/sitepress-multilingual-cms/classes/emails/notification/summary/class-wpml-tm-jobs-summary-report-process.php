<?php

class WPML_TM_Jobs_Summary_Report_Process {

	private $view;

	private $report_model;

	private $jobs;

	public function __construct(
		WPML_TM_Jobs_Summary_Report_View $view,
		WPML_TM_Jobs_Summary_Report_Model $report_model,
		array $jobs
	) {
		$this->view         = $view;
		$this->report_model = $report_model;
		$this->jobs         = $jobs;
	}

	public function send() {
		foreach ( $this->jobs as $manager_id => $jobs ) {
			if ( array_key_exists( WPML_TM_Jobs_Summary::JOBS_COMPLETED_KEY, $jobs ) ) {
				$this->view
					->set_jobs( $jobs )
					->set_manager_id( $manager_id )
					->set_summary_text( $this->report_model->get_summary_text() );

				$this->send_email( $manager_id );
			}
		}
	}

	private function send_email( $manager_id ) {
		$to      = get_userdata( $manager_id )->user_email;
		$subject = sprintf( $this->report_model->get_subject(), get_bloginfo( 'name' ), date( 'd/F/Y', time() ) );
		$message = $this->view->get_report_content();
		$headers = array(
			'MIME-Version: 1.0',
			'Content-type: text/html; charset=UTF-8',
		);

		WPML_Mail_Sender::send( $to, $subject, $message, $headers, array(), 'jobs-summary-report' );
	}
}
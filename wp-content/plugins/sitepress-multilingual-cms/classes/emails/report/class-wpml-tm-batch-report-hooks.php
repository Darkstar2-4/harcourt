<?php

class WPML_TM_Batch_Report_Hooks {

	private $batch_report;

	private $email_process;

	public function __construct(
		WPML_TM_Batch_Report $batch_report,
		WPML_TM_Batch_Report_Email_Process $email_process
	) {
		$this->batch_report  = $batch_report;
		$this->email_process = $email_process;
	}

	public function add_hooks() {
		add_action( 'wpml_tm_assign_job_notification', array( $this, 'set_job' ) );
		add_action( 'wpml_tm_new_job_notification', array( $this, 'set_job' ) );
		add_action( 'wpml_tm_assign_job_notification_with_delay', array( $this, 'set_job_with_delay' ) );
		add_action( 'wpml_tm_new_job_notification_with_delay', array( $this, 'set_job_with_delay' ) );
		add_action( 'wpml_tm_local_string_sent', array( $this, 'set_job' ) );
		add_action( 'wpml_tm_jobs_notification', array( $this->email_process, 'process_emails' ) );
	}

	public function set_job( $job ) {
		if ( $job instanceof WPML_Translation_Job ) {
			$this->batch_report->set_job( $job );
		}
	}

	public function set_job_with_delay( $job ) {
		if ( $job instanceof WPML_Translation_Job ) {
			$this->batch_report->set_job_with_delay( $job );
		}
	}
}

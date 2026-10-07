<?php

class WPML_TM_Old_Jobs_Editor {

	const OPTION_NAME = 'wpml-old-jobs-editor';

	private $wpdb;

	private $job_factory;

	public function __construct( WPML_Translation_Job_Factory $job_factory ) {
		global $wpdb;
		$this->wpdb = $wpdb;

		$this->job_factory = $job_factory;
	}


	public function get( $job_id ) {
		$current_editor = $this->get_current_editor( $job_id );

		if ( WPML_TM_Editors::NONE === $current_editor || WPML_TM_Editors::ATE === $current_editor ) {
			return $current_editor;
		} else {
			return get_option( self::OPTION_NAME, null );
		}
	}

	public function shouldStickToWPMLEditor( $job_id, $previousJob = false ) {
		$sql = "
			SELECT job.editor
			FROM {$this->wpdb->prefix}icl_translate_job job
			WHERE job.job_id < %d AND job.rid = (
				SELECT rid FROM {$this->wpdb->prefix}icl_translate_job WHERE job_id = %s
			)			
			ORDER BY job.job_id DESC
		";

		$previousJobEditor = $previousJob ? $previousJob->editor : $this->wpdb->get_var( $this->wpdb->prepare( $sql, $job_id, $job_id ) );

		return $previousJobEditor === WPML_TM_Editors::WPML && get_option( self::OPTION_NAME, null ) === WPML_TM_Editors::WPML;
	}

	public function editorForTranslationsPreviouslyCreatedUsingCTE(  ) {
		return get_option( self::OPTION_NAME, WPML_TM_Editors::WPML );
	}

	public function set( $job_id, $editor ) {
		$data = [ 'editor' => $editor ];
		if ( $editor !== WPML_TM_Editors::ATE ) {
			$data['editor_job_id'] = null;
		}

		$this->job_factory->update_job_data( $job_id, $data );
	}


	public function get_current_editor( $job_id ) {
		$sql = "SELECT editor FROM {$this->wpdb->prefix}icl_translate_job WHERE job_id = %d";

		return $this->wpdb->get_var( $this->wpdb->prepare( $sql, $job_id ) );
	}
}

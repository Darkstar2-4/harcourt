<?php

class WPML_TM_ATE_Models_Job_Create {
	public $id;
	public $deadline;
	public $file;
	public $notify_enabled;
	public $notify_url;
	public $source_id;
	public $element_id;
	public $permalink;
	public $site_identifier;
	public $source_language;
	public $target_language;
	public $ate_ams_console_url;
	public $existing_ate_id;

	public $wpml_words_to_translate_count;
	public $wpml_automatic_translation_costs;
	public $ate_previous_job_ids;

	public $apply_memory;

	public $job_sender;

	public $tier;

	public $rank;

	public function __construct( array $args = array() ) {
		foreach ( $args as $key => $value ) {
			$this->$key = $value;
		}
		if ( ! $this->file ) {
			$this->file = new WPML_TM_ATE_Models_Job_File();
		}
		if ( ! $this->source_language ) {
			$this->source_language = new WPML_TM_ATE_Models_Language();
		}
		if ( ! $this->target_language ) {
			$this->target_language = new WPML_TM_ATE_Models_Language();
		}

		$this->ate_ams_console_url = wpml_tm_get_ams_ate_console_url();
	}
}

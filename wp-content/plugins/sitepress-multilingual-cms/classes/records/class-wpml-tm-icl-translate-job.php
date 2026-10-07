<?php

class WPML_TM_ICL_Translate_Job {

	private $table  = 'icl_translate_job';
	private $job_id = 0;
	private $tm_records;

	private $rid;
	private $editor;
	private $completed_date;
	private $translated;

	public function __construct( WPML_TM_Records $tm_records, $job_id ) {
		$this->tm_records = $tm_records;

		$job_id = (int) $job_id;
		if ( $job_id > 0 ) {
			$this->job_id = $job_id;
		} else {
			throw new InvalidArgumentException( 'Invalid Job ID: ' . $job_id );
		}
	}

	public function translator_id() {

		return $this->tm_records->icl_translation_status_by_rid( $this->rid() )
								->translator_id();
	}

	public function service() {

		return $this->tm_records->icl_translation_status_by_rid( $this->rid() )
								->service();
	}

	public function update( $args ) {
		$wpdb = $this->tm_records->wpdb();

		$wpdb->update(
			$wpdb->prefix . $this->table,
			$args,
			array( 'job_id' => $this->job_id )
		);

		return $this;
	}

	public function complete() {
		$completed_date = $this->completed_date();
		if ( $this->translated() && $completed_date ) {
			return;
		}

		$completed_date = $completed_date
			? $completed_date
			: date( 'Y-m-d H:i:s' );

		$wpdb = $this->tm_records->wpdb();

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}{$this->table}
				SET completed_date = %s,
					translated = 1
				WHERE job_id = %d",
				$completed_date,
				$this->job_id
			)
		);

		$this->completed_date = $completed_date;
		$this->translated     = 1;
	}

	public function is_open() {
		$wpdb = $this->tm_records->wpdb();

		return $this->job_id === (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT MAX(job_id)
				 FROM {$wpdb->prefix}{$this->table}
				 WHERE rid = %d",
				$this->rid()
			)
		);
	}

	public function rid() {
		if ( null === $this->rid ) {
			$this->load_fields();
		}

		return $this->rid;
	}

	public function editor() {
		if ( null === $this->editor ) {
			$this->load_fields();
		}

		return $this->editor;
	}

	public function completed_date() {
		if ( null === $this->completed_date ) {
			$this->load_fields();
		}

		return $this->completed_date;
	}

	public function translated() {
		if ( null === $this->translated ) {
			$this->load_fields();
		}

		return $this->translated;
	}

	private function load_fields() {
		$wpdb = $this->tm_records->wpdb();

		$fields = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT `rid`, `editor`, `translated`, `completed_date`
				FROM {$wpdb->prefix}{$this->table}
				WHERE job_id = %d LIMIT 1",
				$this->job_id
			)
		);

		$this->rid            = $fields->rid;
		$this->editor         = $fields->editor;
		$this->translated     = $fields->translated;
		$this->completed_date = $fields->completed_date;
	}
}

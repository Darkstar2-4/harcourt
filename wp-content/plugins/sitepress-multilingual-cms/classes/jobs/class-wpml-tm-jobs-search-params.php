<?php

class WPML_TM_Jobs_Search_Params {

	const SCOPE_REMOTE = 'remote';
	const SCOPE_LOCAL  = 'local';
	const SCOPE_ALL    = 'all';
	const SCOPE_ATE    = 'ate';

	private static $scopes = array(
		self::SCOPE_LOCAL,
		self::SCOPE_REMOTE,
		self::SCOPE_ALL,
		self::SCOPE_ATE,
	);

	private $status = array();

	private $needs_update;

	private $scope = self::SCOPE_ALL;

	private $job_types = array();

	private $local_job_ids;

	private $limit;

	private $offset;

	private $id;

	private $ids;

	private $title;

	private $batch_name;

	private $source_language;

	private $target_language;

	private $tp_id = '';

	private $sorting = array();

	private $translated_by;

	private $deadline;

	private $sent;

	private $completed_date;

	private $original_element_id;

	private $needs_review = null;

	private $exclude_hidden_jobs = true;

	private $max_ate_retries;

	private $exclude_manual = false;

	private $exclude_longstanding = false;

	private $exclude_cancelled = false;

	private $element_type;

	private $columns_to_select = null;

	private $custom_where_conditions = [];

	public function __construct( array $params = array() ) {
		if ( array_key_exists( 'limit', $params ) ) {
			$this->set_limit( $params['limit'] );
			if ( array_key_exists( 'offset', $params ) ) {
				$this->set_offset( $params['offset'] );
			}
		}

		$fields = array(
			'status',
			'scope',
			'job_types',
			'local_job_id',
			'id',
			'ids',
			'title',
			'batch_name',
			'source_language',
			'target_language',
			'sorting',
			'tp_id',
			'translated_by',
			'deadline',
			'completed_date',
			'sent',
			'original_element_id',
			'exclude_manual',
			'exclude_longstanding',
		);
		foreach ( $fields as $field ) {
			if ( array_key_exists( $field, $params ) ) {
				$this->{'set_' . $field}( $params[ $field ] );
			}
		}
	}

	public function get_status() {
		return $this->status;
	}

	public function set_status( array $status ) {
		$this->status = array_map( 'intval', array_values( array_filter( $status, 'is_numeric' ) ) );

		return $this;
	}

	public function get_scope() {
		return $this->scope;
	}

	public function get_tp_id() {
		return $this->tp_id;
	}

	public function set_scope( $scope ) {
		if ( ! $this->is_valid_scope( $scope ) ) {
			throw new InvalidArgumentException(
				'Invalid scope. Accepted values: ' . implode( ', ', self::$scopes )
			);
		}

		$this->scope = $scope;

		return $this;
	}

	public function get_job_types() {
		return $this->job_types;
	}

	public function set_tp_id( $tp_id ) {
		$this->tp_id = is_array( $tp_id ) ? $tp_id : array( $tp_id );

		return $this;
	}

	public function set_job_types( $job_types ) {
		$correct_types = [
			WPML_TM_Job_Entity::POST_TYPE,
			WPML_TM_Job_Entity::PACKAGE_TYPE,
			WPML_TM_Job_Entity::STRING_TYPE,
			WPML_TM_Job_Entity::STRING_BATCH,
		];

		if ( ! is_array( $job_types ) ) {
			$job_types = array( $job_types );
		}

		foreach ( $job_types as $job_type ) {
			if ( ! in_array( $job_type, $correct_types, true ) ) {
				throw new InvalidArgumentException( 'Invalid job type' );
			}
			$this->job_types[] = $job_type;
		}

		return $this;
	}

	public function get_first_local_job_id() {
		return ! empty( $this->local_job_ids ) ? current( $this->local_job_ids ) : null;
	}

	public function get_local_job_ids() {
		return $this->local_job_ids;
	}

	public function set_local_job_id( $local_job_id ) {
		$this->local_job_ids[] = (int) $local_job_id;

		return $this;
	}

	public function set_local_job_ids( array $local_job_ids ) {
		$this->local_job_ids = array_map( 'intval', $local_job_ids );

		return $this;
	}

	public function get_limit() {
		return $this->limit;
	}

	public function set_limit( $limit ) {
		$this->limit = $limit;

		return $this;
	}

	public function get_offset() {
		return $this->offset;
	}

	public function set_offset( $offset ) {
		$this->offset = $offset;

		return $this;
	}

	public function get_id() {
		return $this->id;
	}

	public function set_id( $id ) {
		$this->id = $id;

		return $this;
	}

	public function get_ids() {
		return $this->ids;
	}

	public function set_ids( array $ids ) {
		$this->ids = array_map( 'intval', $ids );

		return $this;
	}

	public function get_title() {
		return $this->title;
	}

	public function set_title( $title ) {
		$this->title = is_array( $title ) ? $title : array( $title );

		return $this;
	}

	public function get_batch_name() {
		return $this->batch_name;
	}

	public function set_batch_name( $batch_name ) {
		$this->batch_name = $batch_name;
	}


	public function get_source_language() {
		return $this->source_language;
	}

	public function set_source_language( $source_language ) {
		$this->source_language = $source_language;

		return $this;
	}

	public function get_target_language() {
		return $this->target_language;
	}

	public function set_target_language( $target_language ) {
		$this->target_language = is_array( $target_language ) ? $target_language : array( $target_language );

		return $this;
	}

	public function get_sorting() {
		return $this->sorting;
	}

	public function set_sorting( array $sorting ) {
		$this->sorting = array();

		foreach ( $sorting as $sorting_param ) {
			if ( $sorting_param instanceof WPML_TM_Jobs_Sorting_Param ) {
				$this->sorting[] = $sorting_param;
			}
		}

		return $this;
	}

	public function get_translated_by() {
		return $this->translated_by;
	}

	public function set_translated_by( $translated_by ) {
		$this->translated_by = (int) $translated_by;

		return $this;
	}

	public function get_deadline() {
		return $this->deadline;
	}

	public function set_deadline( WPML_TM_Jobs_Date_Range $deadline ) {
		$this->deadline = $deadline;

		return $this;
	}

	public function get_sent() {
		return $this->sent;
	}

	public function get_completed_date() {
		return $this->completed_date;
	}

	public function get_original_element_id() {
		return $this->original_element_id;
	}

	public function set_sent( WPML_TM_Jobs_Date_Range $sent ) {
		$this->sent = $sent;

		return $this;
	}

	public function set_completed_date( WPML_TM_Jobs_Date_Range $completed_date ) {
		$this->completed_date = $completed_date;

		return $this;
	}

	public function set_original_element_id( $original_element_id ) {
		$this->original_element_id = $original_element_id;

		return $this;
	}

	public function get_needs_update() {
		return $this->needs_update;
	}

	public function set_needs_update( ?WPML_TM_Jobs_Needs_Update_Param $needs_update = null ) {
		$this->needs_update = $needs_update;

		return $this;
	}

	public function needs_review() {
		return $this->needs_review;
	}

	public function exclude_hidden_jobs() {
		return $this->exclude_hidden_jobs;
	}

	public function set_max_ate_retries( $max_ate_retries ) {
		$this->max_ate_retries = $max_ate_retries;

		return $this;
	}

	public function get_max_ate_retries() {
		return $this->max_ate_retries;
	}

	public function set_needs_review( $needs_review = true ) {
		$this->needs_review = $needs_review;

		return $this;
	}

	public function set_exclude_hidden_jobs( $exclude_hidden_jobs ) {
		$this->exclude_hidden_jobs = $exclude_hidden_jobs;

		return $this;
	}

	public static function is_valid_scope( $value ) {
		return in_array(
			$value,
			self::$scopes,
			true
		);
	}

	public function set_exclude_manual( $excludeManual ) {
		$this->exclude_manual = $excludeManual;
	}

	public function should_exclude_manual() {
		return $this->exclude_manual;
	}

	public function set_exclude_longstanding( $excludeLongstanding ) {
		$this->exclude_longstanding = $excludeLongstanding;
	}

	public function should_exclude_longstanding() {
		return $this->exclude_longstanding;
	}

	public function get_columns_to_select() {
		return $this->columns_to_select;
	}

	public function set_columns_to_select( $columns_to_select ) {
		$this->columns_to_select = $columns_to_select;

		return $this;
	}

	public function get_element_type() {
		return $this->element_type;
	}

	public function set_element_type( $element_type ) {
		$this->element_type = $element_type;

		return $this;
	}

	public function should_exclude_cancelled() {
		return $this->exclude_cancelled;
	}

	public function set_exclude_cancelled( $exclude_cancelled = true ) {
		$this->exclude_cancelled = $exclude_cancelled;

		return $this;
	}

	public function get_custom_where_conditions() {
		return $this->custom_where_conditions;
	}

	public function set_custom_where_conditions( $custom_where_conditions ) {
		$this->custom_where_conditions = $custom_where_conditions;

		return $this;
	}
}

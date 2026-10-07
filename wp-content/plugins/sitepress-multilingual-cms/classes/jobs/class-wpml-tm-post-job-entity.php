<?php

class WPML_TM_Post_Job_Entity extends WPML_TM_Job_Entity {
	private $elements;

	private $translate_job_id;

	private $editor;

	private $editor_job_id;

	private $completed_date;

	private $automatic;

	private $review_status = null;

	private $trid;

	private $element_type;

	private $element_id;

	private $element_type_prefix;

	private $job_title;

	public function __construct( $id, $type, $tp_id, $batch, $status, $elements ) {
		parent::__construct( $id, $type, $tp_id, $batch, $status );

		if ( is_callable( $elements ) ) {
			$this->elements = $elements;
		} elseif ( is_array( $elements ) ) {
			foreach ( $elements as $element ) {
				if ( $element instanceof WPML_TM_Job_Element_Entity ) {
					$this->elements[] = $element;
				}
			}
		}
	}

	public function get_elements() {
		if ( is_callable( $this->elements ) ) {
			return call_user_func( $this->elements, $this );
		} elseif ( is_array( $this->elements ) ) {
			return $this->elements;
		} else {
			return array();
		}
	}

	public function get_translate_job_id() {
		return $this->translate_job_id;
	}

	public function set_translate_job_id( $translate_job_id ) {
		$this->translate_job_id = (int) $translate_job_id;
	}

	public function get_editor() {
		return $this->editor;
	}

	public function set_editor( $editor ) {
		$this->editor = (string) $editor;
	}

	public function get_editor_job_id() {
		return $this->editor_job_id;
	}

	public function set_editor_job_id( $editor_job_id ) {
		$this->editor_job_id = (int) $editor_job_id;
	}

	public function is_ate_job() {
		return 'local' === $this->get_translation_service() && $this->is_ate_editor();
	}

	public function is_ate_editor() {
		return WPML_TM_Editors::ATE === $this->get_editor();
	}

	public function get_completed_date() {
		return $this->completed_date;
	}

	public function set_completed_date( ?DateTime $completed_date = null ) {
		$this->completed_date = $completed_date;
	}

	public function is_automatic() {
		return $this->automatic;
	}

	public function set_automatic( $automatic ) {
		$this->automatic = (bool) $automatic;
	}

	public function get_review_status() {
		return $this->review_status;
	}

	public function set_review_status( $review_status ) {
		$this->review_status = $review_status;
	}

	public function get_trid() {
		return $this->trid;
	}

	public function set_trid( $trid ) {
		$this->trid = $trid;
	}

	public function get_element_type() {
		return $this->element_type;
	}

	public function set_element_type( $element_type ) {
		$this->element_type = $element_type;
	}

	public function get_element_id() {
		return $this->element_id;
	}

	public function set_element_id( $element_id ) {
		$this->element_id = $element_id;
	}

	public function get_element_type_prefix() {
		return $this->element_type_prefix;
	}

	public function set_element_type_prefix( $element_type ) {
		$this->element_type_prefix = explode( '_', $element_type )[0];
	}

	public function get_job_title() {
		return $this->job_title;
	}

	public function set_job_title( $job_title ) {
		$this->job_title = $job_title;
	}
}

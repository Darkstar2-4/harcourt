<?php

class WPML_TM_Job_Element_Entity {
	private $id;

	private $content_id;

	private $timestamp;

	private $type;

	private $format;

	private $translatable;

	private $data;

	private $data_translated;

	private $finished;

	public function __construct(
		$id,
		$content_id,
		$timestamp,
		$type,
		$format,
		$is_translatable,
		$data,
		$data_translated,
		$finished
	) {
		$this->id              = (int) $id;
		$this->content_id      = (int) $content_id;
		$this->timestamp       = (int) $timestamp;
		$this->type            = (string) $type;
		$this->format          = (string) $format;
		$this->translatable    = (bool) $is_translatable;
		$this->data            = (string) $data;
		$this->data_translated = (bool) $data_translated;
		$this->finished        = (bool) $finished;
	}

	public function get_id() {
		return $this->id;
	}

	public function get_content_id() {
		return $this->content_id;
	}

	public function get_timestamp() {
		return $this->timestamp;
	}

	public function get_type() {
		return $this->type;
	}

	public function get_format() {
		return $this->format;
	}

	public function is_translatable() {
		return $this->translatable;
	}

	public function get_data() {
		return $this->data;
	}

	public function get_data_translated() {
		return $this->data_translated;
	}

	public function is_finished() {
		return $this->finished;
	}
}

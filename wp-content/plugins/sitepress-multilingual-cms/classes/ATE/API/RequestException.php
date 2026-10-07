<?php

namespace WPML\TM\ATE\API;

class RequestException extends \Exception {
	protected $type;

	protected $data;

	protected $avoidLogDuplication;

	public function __construct( $message, $type, $data = null, $code = 0, $avoidLogDuplication = false ) {
		$code = 0 === $code ? (int) $type : $code;

		$this->avoidLogDuplication = $avoidLogDuplication;

		parent::__construct( $message, $code );

		$this->type = (string) $type;
		$this->data = $data;
	}

	public function getType() {
		return $this->type;
	}

	public function getData() {
		return $this->data;
	}

	public function shouldAvoidLogDuplication() {
		return $this->avoidLogDuplication;
	}
}

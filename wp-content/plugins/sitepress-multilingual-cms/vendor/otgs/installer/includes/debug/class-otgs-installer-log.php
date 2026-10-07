<?php

class OTGS_Installer_Log {

	private $time;
	private $request_url;
	private $request_args;
	private $response;
	private $component;

	public function get_time() {
		return $this->time;
	}

	public function set_time( $time ) {
		$this->time = $time;
		return $this;
	}

	public function get_request_url() {
		return $this->request_url;
	}

	public function set_request_url( $request_url ) {
		$this->request_url = $request_url;
		return $this;
	}

	public function get_request_args() {
		return $this->request_args;
	}

	public function set_request_args( $request_args ) {
		$this->request_args = $request_args;
		return $this;
	}

	public function get_response() {
		return $this->response;
	}

	public function set_response( $response ) {
		$this->response = $response;
		return $this;
	}

	public function get_component() {
		return $this->component;
	}

	public function set_component( $component ) {
		$this->component = $component;
		return $this;
	}
}
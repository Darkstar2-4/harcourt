<?php

abstract class WPML_TP_Abstract_API {

	protected $tp_client;

	protected $exception;

	protected $error_message;

	public function __construct( WPML_TP_Client $tp_client ) {
		$this->tp_client = $tp_client;
	}

	abstract protected function get_endpoint_uri();

	abstract protected function is_authenticated();

	protected function get( array $params = array() ) {
		return $this->remote_call( $params, 'GET' );
	}

	protected function post( array $params = array() ) {
		return $this->remote_call( $params, 'POST' );
	}

	protected function put( array $params = array() ) {
	}

	protected function delete( array $params = array() ) {
	}

	private function remote_call( array $params, $method ) {
		$response = false;

		try {
			$params   = $this->pre_process_params( $params );
			$response = TranslationProxy_Api::proxy_request( $this->get_endpoint_uri(), $params, $method );
		} catch ( Exception $e ) {
			$this->exception = $e;
		}

		return $response;
	}

	private function pre_process_params( array $params ) {
		if ( $this->is_authenticated() ) {
			$params['accesskey'] = $this->tp_client->get_project()->get_access_key();
		}

		return $params;
	}

	protected function get_original_file_id( $job_id, $document_source_id ) {
		return $job_id . '-' . md5( $job_id . $document_source_id );
	}

	public function get_exception() {
		return $this->exception;
	}

	public function get_error_message() {
		return $this->exception->getMessage();
	}
}

<?php

namespace WPML\TM\ATE\Sitekey;

class UnassignSitekeyResult {

	const STATUS_SUCCESS = 'success';
	const STATUS_RETRYABLE_ERROR = 'retryable_error';
	const STATUS_NON_RETRYABLE_ERROR = 'non_retryable_error';

	private $status;

	private $error;

	public function __construct( $status, $error = null ) {
		$this->status = $status;
		$this->error  = $error;
	}

	public static function fromApiResponse( $result ) {
		if ( is_wp_error( $result ) ) {
			$errorCode = $result->get_error_code();

			if ( is_int( $errorCode ) ) {
				return new self( self::STATUS_NON_RETRYABLE_ERROR, $result->get_error_message() );
			}

			return new self( self::STATUS_RETRYABLE_ERROR, $result->get_error_message() );
		}

		if ( is_array( $result ) && isset( $result['unassigned_key'] ) ) {
			return new self( self::STATUS_SUCCESS );
		}

		return new self( self::STATUS_RETRYABLE_ERROR, 'Unexpected API response' );
	}

	public function isSuccess(): bool {
		return $this->status === self::STATUS_SUCCESS;
	}

	public function isRetryable(): bool {
		return $this->status === self::STATUS_RETRYABLE_ERROR;
	}

	public function getError() {
		return $this->error;
	}
}

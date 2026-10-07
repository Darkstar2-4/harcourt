<?php

use WPML\API\Sanitize;

class WPML_TF_TP_Responses {

	const FEEDBACK_FORWARD_MANUAL  = 'manual';
	const FEEDBACK_FORWARD_EMAIL   = 'email';
	const FEEDBACK_FORWARD_API     = 'api';
	const FEEDBACK_TP_URL_ENDPOINT = '/feedbacks/{feedback_id}/external';

	private $rating_id = '';

	private $feedback_id;

	private $feedback_forward_method;

	public function __construct( array $args = array() ) {
		if( isset( $args['tp_rating_id'] ) ) {
			$this->set_rating_id( $args['tp_rating_id'] );
		}

		if( isset( $args['tp_feedback_id'] ) ) {
			$this->set_feedback_id( $args['tp_feedback_id'] );
		}

		if( isset( $args['feedback_forward_method'] ) ) {
			$this->set_feedback_forward_method( $args['feedback_forward_method'] );
		}
	}

	public function set_rating_id( $rating_id ) {
		if ( is_numeric( $rating_id ) ) {
			$rating_id = (int) $rating_id;
		}

		$this->rating_id = $rating_id;
	}

	public function get_rating_id() {
		return $this->rating_id;
	}

	public function set_feedback_id( $feedback_id ) {
		$this->feedback_id = (int) $feedback_id;
	}

	public function get_feedback_id() {
		return $this->feedback_id;
	}

	public function set_feedback_forward_method( $method ) {
		$this->feedback_forward_method = Sanitize::string( $method );
	}

	public function get_feedback_forward_method() {
		return $this->feedback_forward_method;
	}

	public function is_manual_feedback() {
		return self::FEEDBACK_FORWARD_MANUAL === $this->feedback_forward_method;
	}

	public function is_email_feedback() {
		return self::FEEDBACK_FORWARD_EMAIL === $this->feedback_forward_method;
	}

	public function is_api_feedback() {
		return self::FEEDBACK_FORWARD_API === $this->feedback_forward_method;
	}

	public function get_feedback_tp_url() {
		$url = null;

		if ( $this->is_api_feedback() && defined( 'OTG_TRANSLATION_PROXY_URL' ) ) {
			$url = OTG_TRANSLATION_PROXY_URL . self::FEEDBACK_TP_URL_ENDPOINT;
			$url = preg_replace( '/{feedback_id}/', (string) $this->get_feedback_id(), $url );
		}

		return $url;
	}

	public function get_strings() {
		return array(
			'display_for_manual' => __( '%1s cannot receive feedback about the translation automatically. Please log-in to %1s website and report these issues manually.', 'sitepress' ),
			'display_for_email'  => __( 'An email has been sent to %s to report the issue. Please check your email for a feedback from their part.', 'sitepress' ),
			'display_for_api'    => __( 'Issue tracking in %s', 'sitepress' ),
		);
	}
}

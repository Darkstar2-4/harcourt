<?php

namespace WPML\PostHog\Event;

class SendDataToPostHog {

	public function __construct() {
		add_action( 'wp_ajax_wpml_posthog_capture_data_action', [ $this, 'handle_request' ] );
	}

	public function handle_request() {
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( $_POST['nonce'] ), 'wpml_posthog_capture_data_nonce' ) ) {
			wp_send_json_error( 'Nonce check failed' );
			wp_die();
		}

		if ( ! isset( $_POST['data'] ) ) {
			wp_send_json_error( 'No data received' );
			wp_die();
		}

		$data_json = sanitize_text_field( wp_unslash( $_POST['data'] ) );
		$data      = json_decode( $data_json, true );

		if ( ! is_array( $data )
		     || ! isset( $data['eventName'] )
		     || empty( $data['captureData'] )
		) {
			wp_send_json_error( 'Invalid data format' );
			wp_die();
		}

		$eventName  = $data['eventName'];
		$eventProps = [];
		if ( is_array( $data['captureData'] ) ) {
			$eventProps = $data['captureData'];
		}

		$event = new \WPML\Core\Component\PostHog\Domain\Event\Custom\Event( $eventName, $eventProps );

		\WPML\PostHog\Event\CaptureEvent::capture( $event );

		wp_die();
	}
}

<?php

class WPML_TF_Frontend_AJAX_Hooks implements IWPML_Action {

	private $feedback_storage;

	private $document_information;

	private $post_rating_metrics;

	private $tp_client_factory;

	private $tp_client;

	private $post_data;

	public function __construct(
		WPML_TF_Data_Object_Storage $feedback_storage,
		WPML_TF_Document_Information $document_information,
		WPML_TF_Post_Rating_Metrics $post_rating_metrics,
		?WPML_TP_Client_Factory $tp_client_factory = null,
		?array $post_data = null
	) {
		$this->feedback_storage     = $feedback_storage;
		$this->document_information = $document_information;
		$this->post_rating_metrics  = $post_rating_metrics;
		$this->tp_client_factory    = $tp_client_factory;
		$this->post_data            = $post_data;
	}

	public function add_hooks() {
		add_action( 'wp_ajax_nopriv_' . WPML_TF_Frontend_AJAX_Hooks_Factory::AJAX_ACTION, array( $this, 'save_feedback_callback' ) );
		add_action( 'wp_ajax_' . WPML_TF_Frontend_AJAX_Hooks_Factory::AJAX_ACTION, array( $this, 'save_feedback_callback' ) );
	}

	public function save_feedback_callback() {
		$feedback  = null;
		$is_create = false;

		if ( isset( $this->post_data['feedback_id'] ) ) {
			$feedback = $this->update_feedback( $this->post_data['feedback_id'] );
		} elseif ( isset( $this->post_data['rating'], $this->post_data['document_id'], $this->post_data['document_type'] ) ) {
			$feedback  = $this->create_feedback();
			$is_create = true;
		}

		if ( $feedback ) {
			$feedback_id = $this->feedback_storage->persist( $feedback );
			$this->post_rating_metrics->refresh( $feedback->get_document_id() );

			$response = array( 'feedback_id' => $feedback_id );
			if ( $is_create && ! is_wp_error( $feedback_id ) ) {
				$token                       = wp_generate_password( 20, false );
				$response['ownership_token'] = $token;
				update_post_meta( $feedback_id, '_wpml_tf_owner_token', $token );
			}
			wp_send_json_success( $response );
		} else {
			wp_send_json_error( esc_html__( 'Invalid request!', 'sitepress' ) );
		}
	}

	private function update_feedback( $feedback_id ) {
		$feedback_id = (int) $feedback_id;
		$feedback    = $this->feedback_storage->get( $feedback_id );

		if ( ! $feedback ) {
			return false;
		}

		if ( empty( $this->post_data['ownership_token'] ) ) {
			return false;
		}

		$stored_token = get_post_meta( $feedback_id, '_wpml_tf_owner_token', true );
		if ( ! hash_equals( $stored_token, $this->post_data['ownership_token'] ) ) {
			return false;
		}

		if ( isset( $this->post_data['content'] ) ) {
			$feedback->set_content( $this->post_data['content'] );
		}

		if ( isset( $this->post_data['rating'] ) ) {
			$feedback->set_rating( $this->post_data['rating'] );
		}

		$feedback->set_status( $this->get_filtered_status() );

		return $feedback;
	}

	private function create_feedback() {
		$this->document_information->init( $this->post_data['document_id'], $this->post_data['document_type'] );

		$args = array(
			'rating'        => $this->post_data['rating'],
			'status'        => $this->get_filtered_status(),
			'document_id'   => $this->post_data['document_id'],
			'document_type' => $this->post_data['document_type'],
			'language_from' => $this->document_information->get_source_language(),
			'language_to'   => $this->document_information->get_language(),
			'job_id'        => $this->document_information->get_job_id(),
		);

		if ( $this->document_information->is_local_translation( $args['job_id'] ) ) {
			$args['tp_rating_id'] = 0;
		} elseif ( $this->get_tp_client() ) {
			$active_service = $this->get_tp_client()->services()->get_active();

			if ( isset( $active_service->feedback_forward_method ) ) {
				$args['feedback_forward_method'] = $active_service->feedback_forward_method;
			}
		}

		return new WPML_TF_Feedback( $args );
	}

	private function get_filtered_status() {
		$rating = isset( $this->post_data['rating'] ) ? $this->post_data['rating'] : null;
		$status = 'pending';

		if ( 3 < (int) $rating || empty( $this->post_data['content'] ) ) {
			$status = 'rating_only';
		}

		return $status;
	}

	private function get_tp_client() {
		if ( $this->tp_client_factory && ! $this->tp_client ) {
			$this->tp_client = $this->tp_client_factory->create();
		}

		return $this->tp_client;
	}
}

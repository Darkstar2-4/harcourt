<?php

class WPML_TF_Feedback implements IWPML_TF_Data_Object {

	private $id;

	private $date_created;

	private $status;

	private $rating;

	private $content;

	private $document_id;

	private $document_type;

	private $language_from;

	private $language_to;

	private $job_id;

	private $reviewer;

	private $messages;

	private $document_information;

	private $tp_responses;

	public function __construct(
		$data = array(),
		?WPML_TF_Backend_Document_Information $document_information = null
	) {
		$this->id            = array_key_exists( 'id', $data ) ? (int) $data['id'] : null;
		$this->date_created  = array_key_exists( 'date_created', $data )
			? sanitize_text_field( $data['date_created'] ) : null;
		$this->rating        = array_key_exists( 'rating', $data ) ? (int) $data['rating'] : null;
		$this->content       = array_key_exists( 'content', $data )
			? sanitize_text_field( $data['content'] ) : '';
		$this->document_id   = array_key_exists( 'document_id', $data ) ? (int) $data['document_id'] : null;
		$this->document_type = array_key_exists( 'document_type', $data )
			? sanitize_text_field( $data['document_type'] ) : null;
		$this->language_from = array_key_exists( 'language_from', $data )
			? sanitize_text_field( $data['language_from'] ) : null;
		$this->language_to   = array_key_exists( 'language_to', $data )
			? sanitize_text_field( $data['language_to'] ) : null;
		$this->job_id        = array_key_exists( 'job_id', $data ) ? (int) $data['job_id'] : null;
		$this->messages      = array_key_exists( 'messages', $data ) && $data['messages'] instanceof WPML_TF_Collection
			? $data['messages'] : new WPML_TF_Message_Collection();

		if ( array_key_exists( 'reviewer_id', $data ) ) {
			$this->set_reviewer( $data['reviewer_id'] );
		}

		$this->status = array_key_exists( 'status', $data )
			? new WPML_TF_Feedback_Status( $data['status'] ) : new WPML_TF_Feedback_Status( 'pending' );

		$this->set_tp_responses( new WPML_TF_TP_Responses( $data ) );

		if ( $document_information ) {
			$this->set_document_information( $document_information );
		}
	}

	public function get_id() {
		return $this->id;
	}

	public function get_feedback_id() {
		return $this->id;
	}

	public function add_message( WPML_TF_Message $message ) {
		$this->messages->add( $message );
	}

	public function get_date_created() {
		return $this->date_created;
	}

	public function get_status() {
		return $this->status->get_value();
	}

	public function set_status( $status ) {
		$this->status->set_value( $status );
	}

	public function get_rating() {
		return $this->rating;
	}

	public function set_rating( $rating ) {
		$this->rating = (int) $rating;
	}

	public function get_content() {
		return $this->content;
	}

	public function set_content( $content ) {
		$this->content = sanitize_text_field( $content );
	}

	public function get_document_id() {
		return $this->document_id;
	}

	public function get_document_type() {
		return $this->document_type;
	}

	public function get_language_from() {
		return $this->language_from;
	}

	public function get_language_to() {
		return $this->language_to;
	}

	public function get_job_id() {
		return $this->job_id;
	}

	public function get_reviewer() {
		if ( ! isset( $this->reviewer ) ) {
			$this->set_reviewer( 0 );
		}

		return $this->reviewer;
	}

	public function set_reviewer( $reviewer_id ) {
		$this->reviewer = new WPML_TF_Feedback_Reviewer( $reviewer_id );
	}

	public function get_messages() {
		return $this->messages;
	}

	public function set_tp_responses( WPML_TF_TP_Responses $tp_responses ) {
		$this->tp_responses = $tp_responses;
	}

	public function get_tp_responses() {
		return $this->tp_responses;
	}

	public function get_text_status() {
		return $this->status->get_display_text();
	}

	public function get_next_status() {
		return $this->status->get_next_status();
	}

	public function is_pending() {
		return $this->status->is_pending();
	}

	public function get_document_flag_url() {
		return $this->document_information->get_flag_url( $this->get_language_to() );
	}

	public function get_source_document_flag_url() {
		return $this->document_information->get_flag_url( $this->get_language_from() );
	}

	public function is_local_translation() {
		return $this->document_information->is_local_translation( $this->get_job_id() );
	}

	public function get_translator_name() {
		return $this->document_information->get_translator_name( $this->get_job_id() );
	}

	public function get_available_translators() {
		return $this->document_information->get_available_translators( $this->language_from, $this->language_to );
	}

	public function set_document_information( WPML_TF_Backend_Document_Information $document_information ) {
		$this->document_information = $document_information;
		$this->document_information->init( $this->get_document_id(), $this->get_document_type() );
	}

	public function get_document_information() {
		return $this->document_information;
	}
}

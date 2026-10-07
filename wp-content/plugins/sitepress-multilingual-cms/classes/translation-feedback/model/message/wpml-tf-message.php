<?php

class WPML_TF_Message implements IWPML_TF_Data_Object {

	private $id;

	private $feedback_id;

	private $date_created;

	private $content;

	private $author_id;

	public function __construct( $data = array() ) {
		$this->id           = array_key_exists( 'id', $data ) ? (int) $data['id'] : null;
		$this->feedback_id  = array_key_exists( 'feedback_id', $data ) ? (int) $data['feedback_id'] : null;
		$this->date_created = array_key_exists( 'date_created', $data )
			? sanitize_text_field( $data['date_created'] ) : null;
		$this->content      = array_key_exists( 'content', $data )
			? sanitize_text_field( $data['content'] ) : null;
		$this->author_id    = array_key_exists( 'author_id', $data )
			? (int) $data['author_id'] : null;
	}

	public function get_id() {
		return $this->id;
	}

	public function get_feedback_id() {
		return $this->feedback_id;
	}

	public function add_message( WPML_TF_Message $message ) {
		return;
	}

	public function get_date_created() {
		return $this->date_created;
	}

	public function get_content() {
		return $this->content;
	}

	public function get_author_id() {
		return $this->author_id;
	}

	public function get_author_display_label() {
		$label = __( 'Translator', 'sitepress' );

		if ( user_can( $this->get_author_id(), 'manage_options' ) ) {
			$label = __( 'Admin', 'sitepress' );
		}

		return $label;
	}

	public function author_is_current_user() {
		$current_user = wp_get_current_user();
		return $current_user->ID === $this->author_id;
	}
}

<?php

interface IWPML_TF_Data_Object {

	public function get_id();

	public function get_feedback_id();

	public function add_message( WPML_TF_Message $message );
}
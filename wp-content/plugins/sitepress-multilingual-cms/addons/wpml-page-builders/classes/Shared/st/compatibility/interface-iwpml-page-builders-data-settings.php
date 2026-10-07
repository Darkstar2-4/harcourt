<?php

interface IWPML_Page_Builders_Data_Settings {

	public function get_meta_field();

	public function get_node_id_field();

	public function get_fields_to_copy();

	public function get_fields_to_save();

	public function convert_data_to_array( $data );

	public function prepare_data_for_saving( array $data );

	public function get_pb_name();

	public function add_hooks();

	public function is_handling_post( $postId );
}

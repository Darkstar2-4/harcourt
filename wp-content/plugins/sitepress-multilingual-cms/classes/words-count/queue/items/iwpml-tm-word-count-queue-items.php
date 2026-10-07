<?php

interface IWPML_TM_Word_Count_Queue_Items {

	public function get_next();

	public function remove( $id, $type );

	public function is_completed();

	public function save();

}

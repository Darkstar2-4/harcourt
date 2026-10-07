<?php

class WPML_TM_Translatable_Element_Provider {

	private $word_count_records;

	private $single_process;

	private $st_package_factory;

	public function __construct(
		WPML_TM_Word_Count_Records $word_count_records,
		WPML_TM_Word_Count_Single_Process $single_process,
		?WPML_ST_Package_Factory $st_package_factory = null
	) {
		$this->word_count_records = $word_count_records;
		$this->single_process     = $single_process;
		$this->st_package_factory = $st_package_factory;
	}

	public function get_from_job( WPML_TM_Job_Entity $job ) {
		$id = $job->get_original_element_id();

		switch ( $job->get_type() ) {
			case WPML_TM_Job_Entity::POST_TYPE:
				return $this->get_post( $id );

			case WPML_TM_Job_Entity::STRING_TYPE:
				return $this->get_string( $id );

			case WPML_TM_Job_Entity::PACKAGE_TYPE:
				return $this->get_package( $id );
		}

		return null;
	}

	public function get_from_type( $type, $id ) {
		switch ( $type ) {
			case 'post':
				return $this->get_post( $id );

			case 'string':
				return $this->get_string( $id );

			case 'package':
				return $this->get_package( $id );
		}

		return null;
	}

	private function get_post( $id ) {
		return new WPML_TM_Post( $id, $this->word_count_records, $this->single_process );
	}

	private function get_string( $id ) {
		return new WPML_TM_String( $id, $this->word_count_records, $this->single_process );
	}

	private function get_package( $id ) {
		return new WPML_TM_Package_Element( $id, $this->word_count_records, $this->single_process, $this->st_package_factory );
	}
}

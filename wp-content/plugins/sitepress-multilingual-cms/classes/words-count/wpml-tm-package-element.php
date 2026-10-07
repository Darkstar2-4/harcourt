<?php

class WPML_TM_Package_Element extends WPML_TM_Translatable_Element {

	private $st_package_factory;

	private $st_package;

	public function __construct(
		$id,
		WPML_TM_Word_Count_Records $word_count_records,
		WPML_TM_Word_Count_Single_Process $single_process,
		?WPML_ST_Package_Factory $st_package_factory = null
	) {
		$this->st_package_factory = $st_package_factory;
		parent::__construct( $id, $word_count_records, $single_process );

	}

	protected function init( $id ) {
		if ( $this->st_package_factory ) {
			$this->st_package = $this->st_package_factory->create( $id );
		}
	}

	protected function get_type() {
		return 'package';
	}

	protected function get_total_words() {
		return $this->word_count_records->get_package_word_count( $this->id )->get_total_words();
	}

	public function get_type_name( $label = null ) {
		if ( $this->st_package ) {
			return $this->st_package->kind;
		}

		return __( 'Unknown string Package', 'wpml-translation-management' );
	}
}

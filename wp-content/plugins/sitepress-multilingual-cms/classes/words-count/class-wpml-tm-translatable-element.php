<?php
abstract class WPML_TM_Translatable_Element {

	protected $word_count_records;

	protected $single_process;

	protected $id;

	public function __construct(
		$id,
		WPML_TM_Word_Count_Records $word_count_records,
		WPML_TM_Word_Count_Single_Process $single_process
	) {
		$this->word_count_records = $word_count_records;
		$this->single_process     = $single_process;
		$this->set_id( $id );
	}

	public function set_id( $id ) {
		if ( ! $id ) {
			return;
		}

		$this->id = $id;
		$this->init( $id );
	}

	abstract protected function init( $id );

	abstract public function get_type_name( $label = null );

	abstract protected function get_type();

	abstract protected function get_total_words();

	public function get_words_count() {
		$total_words = $this->get_total_words();

		if ( $total_words ) {
			return $total_words;
		}

		$this->single_process->process( $this->get_type(), $this->id );
		return $this->get_total_words();
	}
}

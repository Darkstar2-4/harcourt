<?php

class WPML_TM_Count implements IWPML_TM_Count {

	private $total = 0;

	private $to_translate;

	public function __construct( $json_data = null ) {
		if ( $json_data ) {
			$this->set_properties_from_json( $json_data );
		}
	}

	public function set_properties_from_json( $json_data ) {
		$data = json_decode( $json_data, true );

		if ( isset( $data['total'] ) ) {
			$this->total = (int) $data['total'];
		}

		if ( isset( $data['to_translate'] ) ) {
			$this->to_translate = $data['to_translate'];
		}
	}

	public function get_total_words() {
		return $this->total;
	}

	public function set_total_words( $total ) {
		$this->total = $total;
	}

	public function get_words_to_translate( $lang ) {
		if ( isset( $this->to_translate[ $lang ] ) ) {
			return (int) $this->to_translate[ $lang ];
		}

		return null;
	}

	public function to_string() {
		return json_encode(
			array(
				'total'        => $this->total,
				'to_translate' => $this->to_translate,
			)
		);
	}

	public function set_words_to_translate( $lang, $quantity ) {
		$this->to_translate[ $lang ] = $quantity;
	}
}

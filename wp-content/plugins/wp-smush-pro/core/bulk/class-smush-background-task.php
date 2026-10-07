<?php

namespace Smush\Core\Bulk;

class Smush_Background_Task implements \Serializable, \JsonSerializable {
	private static $task_type_smush = 'SMUSH';
	private static $task_type_resmush = 'RESMUSH';
	private static $task_type_error = 'ERROR';

	private $type;

	private $image_id;

	public function __construct( $type, $image_id ) {
		$this->type     = $type;
		$this->image_id = $image_id;
	}

	public function is_valid() {
		return $this->is_type_valid( $this->type )
		       && $this->is_image_id_valid( $this->image_id );
	}

	private function is_type_valid( $type ) {
		$valid_types = array( self::$task_type_smush, self::$task_type_resmush );

		return in_array( $type, $valid_types );
	}

	private function is_image_id_valid( $image_id ) {
		return intval( $image_id ) > 0;
	}

	/**
	 * @return mixed
	 */
	public function get_type() {
		return $this->type;
	}

	/**
	 * @param mixed $type
	 */
	public function set_type( $type ) {
		$this->type = $type;
	}

	/**
	 * @return mixed
	 */
	public function get_image_id() {
		return $this->image_id;
	}

	/**
	 * @param mixed $image_id
	 */
	public function set_image_id( $image_id ) {
		$this->image_id = $image_id;
	}

	private static function get( $array, $key ) {
		return empty( $array[ $key ] ) ? null : $array[ $key ];
	}

	public function serialize() {
		return json_encode( $this->__serialize() );
	}

	public function unserialize( $data ) {
		$this->__unserialize( json_decode( $data, true ) );
	}

	public function __unserialize( $data ) {
		$type = self::get( $data, 'type' );
		$type = $this->is_type_valid( $type ) ? $type : '';
		$this->set_type( $type );

		$image_id = self::get( $data, 'image_id' );
		$image_id = $this->is_image_id_valid( $image_id ) ? $image_id : 0;
		$this->set_image_id( $image_id );
	}

	public function __serialize() {
		return array(
			'type'     => $this->type,
			'image_id' => $this->image_id,
		);
	}

	public function __toString() {
		return json_encode( $this->__serialize() );
	}

	/**
	 * Get task_type_error.
	 *
	 * @return string
	 */
	public static function get_task_type_error() {
		return self::$task_type_error;
	}


	/**
	 * Get task_type_resmush.
	 *
	 * @return string
	 */
	public static function get_task_type_resmush() {
		return self::$task_type_resmush;
	}


	/**
	 * Get task_type_smush.
	 *
	 * @return string
	 */
	public static function get_task_type_smush() {
		return self::$task_type_smush;
	}

	/**
	 * @smush-keep-signature
	 *
	 * @return array
	 */
	public function jsonSerialize(): array {
		return $this->__serialize();
	}

	/**
	 * Create instance from JSON string or decoded array.
	 *
	 * @param string|array $data JSON string or associative array.
	 *
	 * @return self
	 */
	public static function from_json( $data ) {
		if ( is_string( $data ) ) {
			$data = json_decode( $data, true );
		}
		$instance = new self( '', 0 );
		$instance->__unserialize( (array) $data );

		return $instance;
	}
}
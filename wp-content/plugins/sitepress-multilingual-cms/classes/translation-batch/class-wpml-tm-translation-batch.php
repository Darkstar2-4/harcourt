<?php

use WPML\FP\Lst;

class WPML_TM_Translation_Batch {

	const HANDLE_EXISTING_LEAVE = 'leave';
	const HANDLE_EXISTING_OVERRIDE = 'override';

	private $elements;

	private $basket_name;

	private $translators;

	private $deadline;

	private $translationMode = null;

	private $tpBatchInfo;

	private $howToHandleExisting = self::HANDLE_EXISTING_LEAVE;

	public function __construct( array $elements, $basket_name, array $translators, ?DateTime $deadline = null, $tpBatchInfo = null ) {
		if ( empty( $elements ) ) {
			throw new InvalidArgumentException( 'Batch elements cannot be empty' );
		}

		if ( empty( $basket_name ) ) {
			throw new InvalidArgumentException( 'Basket name cannot be empty' );
		}

		if ( empty( $translators ) ) {
			throw new InvalidArgumentException( 'Translators array cannot be empty' );
		}

		$this->elements    = $elements;
		$this->basket_name = (string) $basket_name;
		$this->translators = $translators;
		$this->deadline    = $deadline;
		$this->tpBatchInfo = $tpBatchInfo;
	}

	public function get_elements() {
		return $this->elements;
	}

	public function add_element( WPML_TM_Translation_Batch_Element $element ) {
		$this->elements[] = $element;
	}

	public function get_elements_by_type( $type ) {
		$result = array();
		foreach ( $this->get_elements() as $element ) {
			if ( $element->get_element_type() === $type ) {
				$result[] = $element;
			}
		}

		return $result;
	}

	public function get_basket_name() {
		return $this->basket_name;
	}

	public function get_translators() {
		return $this->translators;
	}

	public function get_translator( $lang ) {
		return $this->translators[ $lang ];
	}

	public function get_deadline() {
		return $this->deadline;
	}

	public function getTpBatchInfo() {
		return $this->tpBatchInfo;
	}

	public function get_target_languages() {
		$result = array();
		foreach ( $this->get_elements() as $element ) {
			$result[] = array_keys( $element->get_target_langs() );
		}

		return array_values( array_unique( call_user_func_array( 'array_merge', $result ) ) );
	}

	public function get_remote_target_languages() {
		return array_values(
			array_filter(
				$this->get_target_languages(),
				array(
					$this,
					'is_remote_target_language',
				)
			)
		);
	}

	private function is_remote_target_language( $lang ) {
		return isset( $this->translators[ $lang ] ) && ! is_numeric( $this->translators[ $lang ] );
	}

	public function get_batch_options() {
		return array(
			'basket_name'   => $this->get_basket_name(),
			'deadline_date' => $this->get_deadline() ? $this->get_deadline()->format( 'Y-m-d' ) : '',
		);
	}

	public function getTranslationMode() {
		return $this->translationMode;
	}

	public function setTranslationMode( $translationMode ) {
		$this->translationMode = Lst::includes( $translationMode, [ 'auto', 'manual' ] ) ? $translationMode : null;
	}

	public function getHowToHandleExisting() {
		return $this->howToHandleExisting;
	}

	public function setHowToHandleExisting( $howToHandleExisting ) {
		$this->howToHandleExisting = $howToHandleExisting;
	}

	public function toArray() {
		$elements = [];
		foreach ( $this->elements as $element ) {
			$elements[] = [
				'element_id'            => $element->get_element_id(),
				'element_type'          => $element->get_element_type(),
				'source_lang'           => $element->get_source_lang(),
				'target_langs'          => $element->get_target_langs(),
				'media_to_translations' => $element->get_media_to_translations(),
			];
		}

		return array(
			'elements'               => $elements,
			'basket_name'            => $this->basket_name,
			'translators'            => $this->translators,
			'deadline'               => $this->deadline ? $this->deadline->format( 'Y-m-d H:i:s' ) : null,
			'translation_mode'       => $this->translationMode,
			'tp_batch_info'          => $this->tpBatchInfo,
			'how_to_handle_existing' => $this->howToHandleExisting,
		);
	}
}

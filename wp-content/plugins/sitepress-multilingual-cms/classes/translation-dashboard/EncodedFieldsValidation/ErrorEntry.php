<?php

namespace WPML\TM\TranslationDashboard\EncodedFieldsValidation;

class ErrorEntry {
	public $elementId;

	public $elementTitle;

	public $fields;

	public function __construct( $elementId, $elementTitle, $fields ) {
		$this->elementId    = (int) $elementId;
		$this->elementTitle = $elementTitle;
		$this->fields       = $fields;
	}
}

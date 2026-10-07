<?php

namespace WPML\PB\Gutenberg\StringsInBlock\DOMHandler;

class StandardBlock extends DOMHandle {

	protected function getInnerHTMLFromChildNodes( \DOMNode $element, $context ) {
		$innerHTML = "";
		$children  = $element->childNodes;

		foreach ( $children as $child ) {
			$innerHTML .= $this->getAsHTML5( $child );
		}

		return $innerHTML;
	}

	protected function appendExtraChildNodes( \DOMNode $clone, \DOMNode $element ) {

	}
}

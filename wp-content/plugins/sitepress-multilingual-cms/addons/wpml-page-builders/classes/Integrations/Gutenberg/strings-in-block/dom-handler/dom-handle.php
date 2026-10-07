<?php

namespace WPML\PB\Gutenberg\StringsInBlock\DOMHandler;

use WPML\PB\Gutenberg\StringsInBlock\Base;
use function WPML\FP\pipe;

abstract class DOMHandle {

	const INNER_HTML_PARTIAL = 'partial';
	const INNER_HTML_FULL    = 'full';

	public function getDomxpath( $html ) {
		$dom = $this->getDom( $html );

		return new \DOMXPath( $dom );
	}

	public function getDom( $html ) {
		$dom = new \DOMDocument();
		\libxml_use_internal_errors( true );
		$html = mb_encode_numericentity( $html, [ 0x80, 0x1FFFFF, 0, 0x1FFFFF ], 'UTF-8' );
		$dom->loadHTML( '<div>' . $html . '</div>' );
		\libxml_clear_errors();

		$dom->removeChild( $dom->doctype );

		$dom->replaceChild( $dom->firstChild->firstChild->firstChild, $dom->firstChild );
		return $dom;
	}

	public function applyStringTranslations( \WP_Block_Parser_Block $block, \DOMNode $element, $translation, $originalValue = null ) {
		if ( empty( $block->innerContent ) || empty( $element->nodeValue ) ) {
			return $block;
		}

		if ( $element instanceof \DOMAttr ) {
			$search_value = preg_quote( esc_attr( $element->nodeValue ), '/' );
			$search       = '/(")(' . $search_value . ')(")/';
			$translation  = esc_attr( $translation );
		} else {
			$replace_full_html_node_content = $element->childNodes->length > 0 && $originalValue;

			$search_value = preg_quote( $replace_full_html_node_content ? $originalValue : $element->nodeValue, '/' );
			$search_value = str_replace( [ preg_quote( '<br>', '/' ), preg_quote( '<br/>', '/' ) ], '<br\/?>', $search_value );
			$search       = '/(>)(' . $search_value . ')(<)/';
		}

		foreach ( $block->innerContent as &$inner_content ) {
			if ( $inner_content ) {
				$inner_content = preg_replace( $search, '${1}' . $translation . '${3}', $inner_content );
			}
		}

		return $block;
	}

	protected function getInnerHTML( \DOMNode $element, $context ) {
		$innerHTML = $element instanceof \DOMText
			? $element->nodeValue
			: $this->getInnerHTMLFromChildNodes( $element, $context );

		$type = Base::get_string_type( $innerHTML );

		if ( 'VISUAL' !== $type ) {
			$innerHTML = html_entity_decode( $innerHTML );
		}

		$removeCdata = pipe(
			[ $this, 'removeCdataFromStyleTag' ],
			[ $this, 'removeCdataFromScriptTag' ]
		);

		return [ $removeCdata( $innerHTML ), $type ];
	}

	abstract protected function getInnerHTMLFromChildNodes( \DOMNode $element, $context );

	public function getPartialInnerHTML( \DOMNode $element ) {
		return $this->getInnerHTML( $element, self::INNER_HTML_PARTIAL );
	}

	public function getFullInnerHTML( \DOMNode $element ) {
		return $this->getInnerHTML( $element, self::INNER_HTML_FULL );
	}

	public function setElementValue( \DOMNode $element, $value ) {
		if ( $element instanceof \DOMAttr ) {
			$element->parentNode->setAttribute( $element->name, $value );
		} elseif ( $element instanceof \DOMText ) {
			$clone            = $this->cloneNodeWithoutChildren( $element );
			$clone->nodeValue = $value;
			$element->parentNode->replaceChild( $clone, $element );
		} else {
			$clone    = $this->cloneNodeWithoutChildren( $element );
			$fragment = $this->getDom( $value )->firstChild;
			foreach ( $fragment->childNodes as $child ) {
				$clone->appendChild( $element->ownerDocument->importNode( $child, true ) );
			}

			$this->appendExtraChildNodes( $clone, $element );

			$element->parentNode->replaceChild( $clone, $element );
		}
	}

	abstract protected function appendExtraChildNodes( \DOMNode $clone, \DOMNode $element );

	private function cloneNodeWithoutChildren( \DOMNode $element ) {
		return $element->cloneNode( false );
	}

	protected function getAsHTML5( \DOMNode $element ) {
		return str_replace( '--/>', '-->', strtr(
			$element->ownerDocument->saveXML( $element, LIBXML_NOEMPTYTAG ),
			[
				'></area>'   => '/>',
				'></base>'   => '/>',
				'></br>'     => '/>',
				'></col>'    => '/>',
				'></embed>'  => '/>',
				'></hr>'     => '/>',
				'></img>'    => '/>',
				'></input>'  => '/>',
				'></link>'   => '/>',
				'></meta>'   => '/>',
				'></param>'  => '/>',
				'></source>' => '/>',
				'></track>'  => '/>',
				'></wbr>'    => '/>',
			]
		) );
	}

	public static function removeCdataFromStyleTag( $innerHTML ) {
		return preg_replace( '/<style(.*?)><!\\[CDATA\\[(.*?)\\]\\]><\\/style>/s', '<style$1>$2</style>', $innerHTML );
	}

	public static function removeCdataFromScriptTag( $innerHTML ) {
		return preg_replace( '/<script(.*?)><!\\[CDATA\\[(.*?)\\]\\]><\\/script>/s', '<script$1>$2</script>', $innerHTML );
	}

}

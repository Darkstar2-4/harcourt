<?php

namespace WPML\PB\Elementor\V4\Component;

use WPML\FP\Obj;

class Overrides implements \IWPML_Page_Builders_Module {

	const ITEMS_FIELD                 = 'component_instance>value>overrides>value';
	const TYPE_KEY                    = \WPML\PB\Elementor\V4\Hooks::TYPE_KEY;
	const OVERRIDE_KEY_PATH           = [ 'value', 'override_key' ];
	const OVERRIDE_STRING_VALUE_PATH  = [ 'value', 'override_value', 'value' ];
	const OVERRIDE_HTML_V3_VALUE_PATH = [ 'value', 'override_value', 'value', 'content', 'value' ];
	const OVERRIDE_LINK_VALUE_PATH    = [ 'value', 'override_value', 'value', 'destination', 'value' ];
	const OVERRIDE_VALUE_TYPE_PATH    = [ 'value', 'override_value', self::TYPE_KEY ];
	const OVERRIDE_VALUE_FIELD_NAME   = 'override_value';
	const OVERRIDE_VALUE_TYPE_STRING  = 'string';
	const OVERRIDE_VALUE_TYPE_HTML_V3 = 'html-v3';
	const OVERRIDE_VALUE_TYPE_LINK    = 'link';
	const EDITOR_TYPE_LINE            = 'LINE';
	const EDITOR_TYPE_LINK            = \WPML_TM_Page_Builders::FIELD_STYLE_LINK;

	public function get( $node_id, $element, $strings ) {
		foreach ( $this->get_items( $element ) as $item ) {
			$value = $this->get_string_value( $item );

			if ( ! is_string( $value ) ) {
				continue;
			}

			$strings[] = new \WPML_PB_String(
				$value,
				$this->get_string_name( $node_id, $item, $element ),
				__( 'Component: Property override', 'sitepress' ),
				$this->get_editor_type( $item )
			);
		}

		return $strings;
	}

	public function update( $node_id, $element, \WPML_PB_String $pbString ) {
		foreach ( $this->get_items( $element ) as $key => $item ) {
			if ( $this->get_string_name( $node_id, $item, $element ) !== $pbString->get_name() ) {
				continue;
			}

			if ( $this->isStringOverride( $item ) ) {
				$item = Obj::assocPath( self::OVERRIDE_STRING_VALUE_PATH, $pbString->get_value(), $item );
			} elseif ( $this->isHtmlOverride( $item ) ) {
				$item = Obj::assocPath( self::OVERRIDE_HTML_V3_VALUE_PATH, $pbString->get_value(), $item );
			} elseif ( $this->isLinkOverride( $item ) ) {
				$item = Obj::assocPath( self::OVERRIDE_LINK_VALUE_PATH, $pbString->get_value(), $item );
			}

			return [ $key, $item ];
		}

		return [ null, null ];
	}

	public function get_items_field() {
		return self::ITEMS_FIELD;
	}

	private function get_items( $element ) {
		return $element['settings']['component_instance']['value']['overrides']['value'] ?? [];
	}

	private function isStringOverride( $item ) {
		return self::OVERRIDE_VALUE_TYPE_STRING === Obj::path( self::OVERRIDE_VALUE_TYPE_PATH, $item )
			&& is_string( Obj::path( self::OVERRIDE_STRING_VALUE_PATH, $item ) );
	}

	private function isHtmlOverride( $item ) {
		return self::OVERRIDE_VALUE_TYPE_HTML_V3 === Obj::path( self::OVERRIDE_VALUE_TYPE_PATH, $item )
			&& is_string( Obj::path( self::OVERRIDE_HTML_V3_VALUE_PATH, $item ) );
	}

	private function isLinkOverride( $item ) {
		return self::OVERRIDE_VALUE_TYPE_LINK === Obj::path( self::OVERRIDE_VALUE_TYPE_PATH, $item )
			&& is_string( Obj::path( self::OVERRIDE_LINK_VALUE_PATH, $item ) );
	}

	private function get_string_value( $item ) {
		if ( $this->isStringOverride( $item ) ) {
			return Obj::path( self::OVERRIDE_STRING_VALUE_PATH, $item );
		}

		if ( $this->isHtmlOverride( $item ) ) {
			return Obj::path( self::OVERRIDE_HTML_V3_VALUE_PATH, $item );
		}

		if ( $this->isLinkOverride( $item ) ) {
			return Obj::path( self::OVERRIDE_LINK_VALUE_PATH, $item );
		}

		return null;
	}

	private function get_editor_type( $item ) {
		return $this->isLinkOverride( $item ) ? self::EDITOR_TYPE_LINK : self::EDITOR_TYPE_LINE;
	}

	private function get_string_name( $nodeId, array $item, array $element ) {
		$widgetType  = $element['widgetType'] ?? null;
		$overrideKey = Obj::path( self::OVERRIDE_KEY_PATH, $item );
		$name        = $widgetType . '-' . self::OVERRIDE_VALUE_FIELD_NAME . '-' . $nodeId . '-' . $overrideKey;

		return apply_filters(
			'wpml_pb_elementor_register_string_name_' . $widgetType,
			$name,
			[
				'nodeId'  => $nodeId,
				'item'    => $item,
				'element' => $element,
				'field'   => self::OVERRIDE_VALUE_FIELD_NAME,
				'key'     => '',
			]
		);
	}
}

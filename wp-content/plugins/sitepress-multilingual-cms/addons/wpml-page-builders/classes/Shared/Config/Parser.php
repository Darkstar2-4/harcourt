<?php

namespace WPML\PB\Config;

use WPML\FP\Fns;
use WPML\FP\Lst;
use WPML\FP\Maybe;
use WPML\FP\Obj;
use WPML\PB\ConvertIds\Helper;

class Parser {

	const EXCLUDE_TYPES = [
		Helper::TYPE_POST_IDS,
		Helper::TYPE_TAXONOMY_IDS,
	];

	private $configRoot;

	private $defaultConditionKey;

	public function __construct( $configRoot, $defaultConditionKey ) {
		$this->configRoot          = $configRoot;
		$this->defaultConditionKey = $defaultConditionKey;
	}

	public function extract( array $allConfig ) {
		$pbConfig   = [];
		$allWidgets = Obj::pathOr( [], [ 'wpml-config', $this->configRoot, 'widget' ], $allConfig );

		foreach ( $allWidgets as $widget ) {
			$widgetName = Obj::path( ['attr', 'name'], $widget );

			$pbConfig[ $widgetName ] = [
				'conditions' => $this->parseConditions( $widget, $widgetName ),
				'fields'     => $this->parseFields(  Obj::pathOr( [], ['fields', 'field'], $widget ) ),
			];

			$fieldsInItems = Obj::prop( 'fields-in-item', $widget );

			if ( $fieldsInItems ) {
				$pbConfig[ $widgetName ]['fields_in_item'] = [];
				$fieldsInItems                             = $this->normalize( $fieldsInItems );

				foreach ( $fieldsInItems as $fieldsInItem ) {
					$itemOf                                               = Obj::path( [ 'attr', 'items_of' ], $fieldsInItem );
					$pbConfig[ $widgetName ]['fields_in_item'][ $itemOf ] = $this->parseFields( Obj::propOr( [], 'field', $fieldsInItem ) );
				}
			}

			$integrationClasses = $this->parseIntegrationClasses( $widget );

			if ( $integrationClasses ) {
				$pbConfig[ $widgetName ]['integration-class'] = $integrationClasses;
			}
		}

		return $pbConfig;
	}

	private function parseConditions( array $widget, $widgetName ) {
		$makePair = function( $condition ) {
			return [ Obj::pathOr( $this->defaultConditionKey, ['attr', 'key'], $condition ), $condition['value'] ];
		};

		return Maybe::fromNullable( Obj::path( ['conditions', 'condition'], $widget ) )
			->map( [ $this, 'normalize' ] )
			->map( Fns::map( $makePair ) )
			->map( Lst::fromPairs() )
			->getOrElse( [ $this->defaultConditionKey => $widgetName ] );
	}

	private function parseFields( array $rawFields ) {
		$parsedFields = [];

		foreach ( $this->normalize( $rawFields ) as $field ) {
			if ( in_array( Obj::path( [ 'attr', 'type' ], $field ), self::EXCLUDE_TYPES, true ) ) {
				continue;
			}

			$key     = Obj::path( [ 'attr', 'key_of' ], $field );
			$fieldId = Obj::path( [ 'attr', 'field_id' ], $field );

			$parsedField = [
				'field'       => $field['value'],
				'type'        => Obj::pathOr( $field['value'], [ 'attr', 'type' ], $field ),
				'editor_type' => Obj::pathOr( 'LINE', [ 'attr', 'editor_type' ], $field ),
			];

			if ( $fieldId ) {
				$parsedField['field_id'] = $fieldId;
			}

			if ( $key ) {
				$parsedFields[ $key ] = $parsedField;
			} else {
				$parsedFields[] = $parsedField;
			}
		}

		return $parsedFields;
	}

	private function parseIntegrationClasses( array $widget ) {
		return Maybe::fromNullable( Obj::path( [ 'integration-classes', 'integration-class' ], $widget ) )
			->map( [ $this, 'normalize' ] )
			->map( Lst::pluck( 'value' ) )
			->getOrElse( [] );
	}

	public function normalize( array $partialConfig ) {
		$isAssocArray = count( array_filter( array_keys( $partialConfig ), 'is_string' ) ) > 0;

		return $isAssocArray ? [ $partialConfig ] : $partialConfig;
	}
}

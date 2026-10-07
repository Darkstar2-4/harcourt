<?php

namespace WPML\PB\Gutenberg;

use WPML\FP\Obj;

class XPath {

	public static function normalize( $data ) {
		if ( isset( $data['attr'] ) ) {
			$data['value'] = array_merge( [ 'value' => $data['value'] ], $data['attr'] );
			if ( isset( $data['value']['type'] ) ) {
				$data = Obj::over( Obj::lensPath( [ 'value', 'type' ] ), 'strtoupper', $data );
			}

			unset( $data['attr'] );
		}

		return $data;
	}

	public static function parse( $query ) {
		if ( is_array( $query ) ) {
			return [
				$query['value'],
				isset( $query['type'] ) ? $query['type'] : '',
				isset( $query['label'] ) ? $query['label'] : '',
			];
		}

		return [ $query, '', '' ];
	}

}

<?php

namespace WPML\Compatibility\Divi\V5;

use WPML\FP\Obj;
use WPML\LIB\WP\Hooks;
use WPML\FP\Str;

use function WPML\FP\spreadArgs;

class DynamicContent implements \IWPML_Frontend_Action, \IWPML_Backend_Action {

	const WRAPPER_KEYS = [ 'before', 'after', 'custom_text' ];

	const VARIABLE = '$variable';

	const VARIABLE_PATTERN = '/\\' . self::VARIABLE . '\((.*?)\)\$/s';

	public function add_hooks() {
		Hooks::onFilter( 'wpml_found_strings_in_block', 10, 2 )
			->then( spreadArgs( [ $this, 'replaceDynamicStringWithWrappers' ] ) );

		Hooks::onFilter( 'wpml_update_strings_in_block', 10, 3 )
			->then( spreadArgs( [ $this, 'updateStringsInBlock' ] ) );
	}

	private function parseDynamicPayload( $content ) {
		$matches = Str::match( self::VARIABLE_PATTERN, $content );
		if ( ! $matches || ! isset( $matches[1] ) ) {
			return null;
		}

		$jsonData = $matches[1];
		return json_decode( $jsonData, true );
	}

	private function getWrapperStringName( $key, $value ) {
		return md5( $key . $value );
	}

	public function replaceDynamicStringWithWrappers( array $strings, \WP_Block_Parser_Block $block ) {
		if ( ! $this->isDiviBlock( $block ) ) {
			return $strings;
		}

		$foundStrings = [];

		foreach ( $strings as $string ) {
			$payload = $this->parseDynamicPayload( Obj::prop( 'value', $string ) );
			if ( ! is_array( $payload ) ) {
				$foundStrings[] = $string;
				continue;
			}

			$settings = $payload['value']['settings'] ?? [];
			foreach ( self::WRAPPER_KEYS as $key ) {
				$value = Obj::prop( $key, $settings );
				if ( $value && trim( $value ) ) {
					$foundStrings[] = (object) [
						'id'    => $this->getWrapperStringName( $key, $value ),
						'name'  => Obj::prop( 'name', $string ) . ': ' . $key,
						'value' => $value,
						'type'  => 'LINE',
					];
				}
			}
		}

		return $foundStrings;
	}

	public function updateStringsInBlock( \WP_Block_Parser_Block $block, array $stringTranslations, $lang ) {
		if ( ! $this->isDiviBlock( $block ) ) {
			return $block;
		}

		if ( ! empty( $block->attrs ) ) {
			$block->attrs = $this->updateBlockAttrs( $block->attrs, $stringTranslations, $lang );
		}

		return $block;
	}

	private function updateBlockAttrs( array $attrs, array $stringTranslations, $lang ) {
		foreach ( $attrs as $key => $value ) {
			if ( is_array( $value ) ) {
				$attrs[ $key ] = $this->updateBlockAttrs( $value, $stringTranslations, $lang );
			} elseif ( is_string( $value ) ) {
				$attrs[ $key ] = $this->updateDynamicVariableInString( $value, $stringTranslations, $lang );
			}
		}

		return $attrs;
	}

	private function updateDynamicVariableInString( $value, array $stringTranslations, $lang ) {
		$payload = $this->parseDynamicPayload( $value );
		if ( ! is_array( $payload ) ) {
			return $value;
		}

		$settings = $payload['value']['settings'] ?? [];
		if ( empty( $settings ) ) {
			return $value;
		}

		foreach ( self::WRAPPER_KEYS as $key ) {
			$originalValue = $settings[ $key ] ?? '';
			if ( '' === $originalValue ) {
				continue;
			}

			$stringName = $this->getWrapperStringName( $key, $originalValue );
			if ( isset( $stringTranslations[ $stringName ][ $lang ]['value'] ) ) {
				$settings[ $key ] = $stringTranslations[ $stringName ][ $lang ]['value'];
			}
		}

		$payload['value']['settings'] = $settings;

		return self::VARIABLE . '(' . wp_json_encode( $payload ) . ')$';
	}

	private function isDiviBlock( \WP_Block_Parser_Block $block ) {
		return (bool) Str::startsWith( 'divi/', $block->blockName );
	}
}

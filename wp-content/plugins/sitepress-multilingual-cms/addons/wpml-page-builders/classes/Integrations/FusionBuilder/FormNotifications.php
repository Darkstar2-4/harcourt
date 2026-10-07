<?php

namespace WPML\Compatibility\FusionBuilder;

use WPML\LIB\WP\Hooks;
use function WPML\FP\spreadArgs;

class FormNotifications implements \IWPML_Backend_Action, \IWPML_Frontend_Action {

	const CUSTOM_FIELD_KEY  = '_fusion';
	const NOTIFICATIONS_KEY = 'notifications';

	public function add_hooks() {
		Hooks::onFilter( 'wpml_config_array' )
			->then( spreadArgs( [ $this, 'addConfigArray' ] ) );

		Hooks::onFilter( 'wpml_decode_custom_field', 10, 2 )
			->then( spreadArgs( [ $this, 'decodeNestedSerializedData' ] ) );

		Hooks::onFilter( 'wpml_encode_custom_field', 10, 2 )
			->then( spreadArgs( [ $this, 'encodeNestedSerializedData' ] ) );
	}

	public function addConfigArray( $config ) {
		if ( isset( $config['wpml-config']['custom-fields-texts']['key'] ) ) {
			foreach ( $config['wpml-config']['custom-fields-texts']['key'] as &$customField ) {
				if ( isset( $customField['attr']['name'] ) && self::CUSTOM_FIELD_KEY === $customField['attr']['name'] ) {
					$customField['key'][] = [
						'attr' => [ 'name' => self::NOTIFICATIONS_KEY ],
						'key'  => [
							[
								'attr' => [ 'name' => '*' ],
								'key'  => [
									[
										'value' => '',
										'attr'  => [ 'name' => 'label' ],
									],
									[
										'value' => '',
										'attr'  => [ 'name' => 'email_subject' ],
									],
									[
										'value' => '',
										'attr'  => [ 'name' => 'email_from' ],
									],
									[
										'value' => '',
										'attr'  => [ 'name' => 'email_from_id' ],
									],
									[
										'value' => '',
										'attr'  => [ 'name' => 'email_reply_to' ],
									],
									[
										'value' => '',
										'attr'  => [ 'name' => 'email_message' ],
									],
								],
							],
						],
					];
					break;
				}
			}
		}

		return $config;
	}

	public function decodeNestedSerializedData( $fieldValue, $key ) {
		if ( self::CUSTOM_FIELD_KEY !== $key || ! is_array( $fieldValue ) ) {
			return $fieldValue;
		}

		if ( isset( $fieldValue[ self::NOTIFICATIONS_KEY ] ) && is_string( $fieldValue[ self::NOTIFICATIONS_KEY ] ) ) {
			$decoded = json_decode( $fieldValue[ self::NOTIFICATIONS_KEY ], true );
			if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) {
				$fieldValue[ self::NOTIFICATIONS_KEY ] = $decoded;
			}
		}

		return $fieldValue;
	}

	public function encodeNestedSerializedData( $fieldValue, $key ) {
		if ( self::CUSTOM_FIELD_KEY !== $key || ! is_array( $fieldValue ) ) {
			return $fieldValue;
		}

		if ( isset( $fieldValue[ self::NOTIFICATIONS_KEY ] ) && is_array( $fieldValue[ self::NOTIFICATIONS_KEY ] ) ) {
			$fieldValue[ self::NOTIFICATIONS_KEY ] = wp_json_encode( $fieldValue[ self::NOTIFICATIONS_KEY ] );
		}

		return $fieldValue;
	}
}

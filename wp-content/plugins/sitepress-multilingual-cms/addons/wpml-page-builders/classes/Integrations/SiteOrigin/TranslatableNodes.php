<?php

namespace WPML\PB\SiteOrigin;

use WPML\FP\Obj;
use WPML\PB\SiteOrigin\Modules\ModuleWithItemsFromConfig;

class TranslatableNodes implements \IWPML_Page_Builders_Translatable_Nodes {

	const SETTINGS_FIELD = 'panels_info';
	const CHILDREN_FIELD = 'panels_data';

	const WRAPPING_MODULES = [
		'SiteOrigin_Panels_Widgets_Layout',
	];

	private $translatableNodes;

	public function get( $node_id, $settings ) {
		$strings = [];

		foreach ( $this->getTranslatableNodes() as $node_data ) {
			if ( $this->conditions_ok( $node_data, $settings ) ) {
				foreach ( $node_data['fields'] as $field ) {
					$field_key       = $field['field'];
					$pathInFlatField = self::get_partial_path( $field_key );
					$string_value    = Obj::path( $pathInFlatField, $settings );

					if ( $string_value ) {

						$string = new \WPML_PB_String(
							$string_value,
							$this->get_string_name( $node_id, $field, $settings ),
							$field['type'],
							$field['editor_type'],
							$this->get_wrap_tag( $settings )
						);

						$strings[] = $string;
					}
				}

				foreach ( $this->get_integration_instances( $node_data ) as $node ) {
					$strings = $node->get( $node_id, $settings, $strings );
				}
			}
		}

		return $strings;
	}

	public function update( $node_id, $settings, \WPML_PB_String $pbString ) {
		foreach ( $this->getTranslatableNodes() as $node_data ) {
			if ( $this->conditions_ok( $node_data, $settings ) ) {
				foreach ( $node_data['fields'] as $field ) {
					$field_key = $field['field'];
					if ( $this->get_string_name( $node_id, $field, $settings ) === $pbString->get_name() ) {
						$pathInFlatField   = self::get_partial_path( $field_key );
						$stringInFlatField = Obj::path( $pathInFlatField, $settings );

						if ( is_string( $stringInFlatField ) ) {
							$settings = Obj::assocPath( $pathInFlatField, $pbString->get_value(), $settings );
						}
					}
				}

				foreach ( $this->get_integration_instances( $node_data ) as $node ) {
					list( $key, $item ) = $node->update( $node_id, $settings, $pbString );
					if ( $item ) {
						if ( strpos( $key, '>' ) ) {
							$pathInFlatField = $node->get_field_path( $key );
						} else {
							$pathInFlatField   = self::get_partial_path( $node->get_items_field() );
							$pathInFlatField[] = $key;
						}
						$settings = Obj::assocPath( $pathInFlatField, $item, $settings );
					}
				}
			}
		}

		return $settings;
	}

	private static function get_partial_path( $field ) {
		return explode( '>', $field );
	}

	private function get_integration_instances( array $node_data ) {
		$instances = [];

		if ( isset( $node_data['fields_in_item'] ) ) {
			foreach ( $node_data['fields_in_item'] as $item_of => $config ) {
				$instances[] = new ModuleWithItemsFromConfig( $item_of, $config );
			}
		}

		return $instances;
	}

	public function get_string_name( $node_id, $field, $settings ) {
		return $node_id . '-' . $settings[ self::SETTINGS_FIELD ]['id'] . '-' . $field['field'];
	}

	private function get_wrap_tag( $settings ) {
		return '';
	}

	private function conditions_ok( $node_data, $settings ) {
		$conditions_meet = true;
		foreach ( $node_data['conditions'] as $field_value ) {
			if ( $settings[ self::SETTINGS_FIELD ]['class'] !== $field_value ) {
				$conditions_meet = false;
				break;
			}
		}

		return $conditions_meet;
	}

	private function getTranslatableNodes() {
		if ( null === $this->translatableNodes ) {
			$this->translatableNodes = $this->initialize_nodes_to_translate();
		}

		return $this->translatableNodes;
	}

	public function initialize_nodes_to_translate() {
		return apply_filters( 'wpml_siteorigin_modules_to_translate', [] );
	}

	public static function isWrappingModule( $module ) {
		return isset( $module[ self::CHILDREN_FIELD ] ) &&
			in_array( Obj::path( [ self::SETTINGS_FIELD, 'class' ], $module ), self::WRAPPING_MODULES, true );
	}
}

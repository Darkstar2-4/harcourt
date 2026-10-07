<?php

namespace WPML\PB\BeaverBuilder\BeaverThemer;

use WPML\Convert\Ids;
use WPML\FP\Obj;
use WPML\LIB\WP\Hooks;
use function WPML\FP\spreadArgs;

class LocationHooks implements \IWPML_Backend_Action {

	const LAYOUT_CPT = 'fl-theme-layout';

	const LOCATIONS_RULES_KEY = '_fl_theme_builder_locations';

	const EXCLUSIONS_RULES_KEY = '_fl_theme_builder_exclusions';

	const BUILDER_DATA_KEY = \WPML_Beaver_Builder_Data_Settings::META_FIELD_KEY;

	public function add_hooks() {
		Hooks::onFilter( 'wpml_pb_copy_meta_field', 10, 4 )
			->then( spreadArgs( [ $this, 'translateLocationRulesMeta' ] ) );

		Hooks::onAction( 'wpml_pro_translation_completed', 10, 3 )
			->then( spreadArgs( [ $this, 'translateConditionalLogic' ] ) );
	}

	public function translateLocationRulesMeta( $copiedValue, $translatedPostId, $originalPostId, $metaKey ) {
		if ( in_array( $metaKey, [ self::LOCATIONS_RULES_KEY, self::EXCLUSIONS_RULES_KEY ], true ) ) {
			$targetLang = self::getLayoutLanguage( $translatedPostId );

			foreach ( $copiedValue as &$rule ) {
				$rule = $this->translateRule( $rule, $targetLang );
			}
		}

		return $copiedValue;
	}

	public function translateConditionalLogic( $newPostId, $fields, $job ) {
		if ( get_post_type( $newPostId ) !== self::LAYOUT_CPT ) {
			return;
		}

		$this->processBuilderDataConditionalLogic( $newPostId, Obj::prop( 'language_code', $job ) );
	}

	private function processBuilderDataConditionalLogic( $postId, $lang ) {
		$builderData = get_post_meta( $postId, self::BUILDER_DATA_KEY, true );
		if ( ! $builderData || ! is_array( $builderData ) ) {
			return;
		}

		$original = wp_json_encode( $builderData );

		foreach ( $builderData as &$module ) {
			if ( ! is_object( $module ) || ! isset( $module->settings->visibility_logic ) ) {
				continue;
			}

			$module->settings->visibility_logic = $this->translateVisibilityLogic( $module->settings->visibility_logic, $lang );
		}

		if ( wp_json_encode( $builderData ) !== $original ) {
			update_post_meta( $postId, self::BUILDER_DATA_KEY, $builderData );
		}
	}

	private function translateVisibilityLogic( $visibilityLogic, $lang ) {
		foreach ( (array) $visibilityLogic as &$logicGroup ) {
			foreach ( (array) $logicGroup as &$rule ) {
				if ( is_object( $rule ) ) {
					$rule = $this->translateConditionalRule( $rule, $lang );
				}
			}
		}

		return $visibilityLogic;
	}

	private function translateConditionalRule( $rule, $lang ) {
		if ( isset( $rule->type ) && 'wordpress/archive-term' === $rule->type && isset( $rule->term, $rule->taxonomy ) ) {
			$rule->term = self::translateElement( $rule->term, $rule->taxonomy, $lang );
		} elseif ( isset( $rule->type ) && 'wordpress/post' === $rule->type && isset( $rule->post ) ) {
			$post_type = get_post_type( $rule->post );
			if ( $post_type ) {
				$rule->post = self::translateElement( $rule->post, $post_type, $lang );
			}
		}

		return $rule;
	}

	private function translateRule( $rule, $targetLangCode ) {
		$parts = explode( ':', $rule );

		if ( 3 === count( $parts ) ) {
			$rule = implode( ':', [ $parts[0], $parts[1], self::translateElement( $parts[2], $parts[1], $targetLangCode ) ] );
		} elseif ( 5 === count( $parts ) ) {
			$rule = implode( ':', [ $parts[0], $parts[1], $parts[2], $parts[3], self::translateElement( $parts[4], $parts[3], $targetLangCode ) ] );
		}

		return $rule;
	}

	private static function getLayoutLanguage( $translatedPostId ) {
		return apply_filters(
			'wpml_element_language_code',
			null,
			[
				'element_id'   => $translatedPostId,
				'element_type' => self::LAYOUT_CPT,
			]
		);
	}

	private static function translateElement( $elementId, $elementType, $targetLangCode ) {
		return Ids::convert( $elementId, $elementType, true, $targetLangCode );
	}
}

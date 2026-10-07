<?php

use WPML\PB\TranslationJob\Groups;

class WPML_PB_String_Registration {

	private $strategy;
	private $string_factory;
	private $package_factory;
	private $translate_link_targets;

	private $set_link_translations;

	private $migration_mode;

	public function __construct(
		IWPML_PB_Strategy $strategy,
		WPML_ST_String_Factory $string_factory,
		WPML_ST_Package_Factory $package_factory,
		WPML_Translate_Link_Targets $translate_link_targets,
		callable $set_link_translations,
		$migration_mode = false
	) {
		$this->strategy               = $strategy;
		$this->string_factory         = $string_factory;
		$this->package_factory        = $package_factory;
		$this->translate_link_targets = $translate_link_targets;
		$this->set_link_translations  = $set_link_translations;
		$this->migration_mode         = $migration_mode;
	}

	public function get_string_id_from_package( $post_id, $content, $name = '' ) {
		$package_data = $this->strategy->get_package_key( $post_id );
		$package      = $this->package_factory->create( $package_data );
		$string_name  = $name ? $name : md5( $content );
		$string_name  = $package->sanitize_string_name( $string_name );
		$string_value = $content;

		return apply_filters( 'wpml_string_id_from_package', null, $package, $string_name, $string_value );
	}

	public function get_string_title( $string_id ) {
		return apply_filters( 'wpml_string_title_from_id', null, $string_id );
	}

	public function register_string(
		$post_id,
		$content = '',
		$type = 'LINE',
		$title = '',
		$name = '',
		$location = 0,
		$wrap_tag = '',
		$groupSequence = null
	) {

		$string_id = 0;

		if ( is_string( $content ) && trim( $content ) ) {

			$string_name = $name ? $name : md5( $content );

			if ( $this->migration_mode ) {

				$string_id = $this->get_string_id_from_package( $post_id, $content, $string_name );
				$this->update_string_data( $string_id, $location, $wrap_tag );

			} else {

				$string_value = $content;
				$package      = $this->strategy->get_package_key( $post_id );
				$string_title = $title ? $title : $string_value;

				if ( Groups::isGroupLabel( $string_title ) ) {
					list( $groups, $label ) = Groups::parseGroupLabel( $string_title );

					$string_title = Groups::buildGroupLabel( $groups, $label, $groupSequence );
				}

				do_action( 'wpml_register_string', $string_value, $string_name, $package, $string_title, $type );

				$string_id = $this->get_string_id_from_package( $post_id, $content, $string_name );
				$this->update_string_data( $string_id, $location, $wrap_tag );

				if ( 'LINK' === $type ) {
					call_user_func( $this->set_link_translations, $string_id );
				}
			}
		}

		return $string_id;
	}

	private function update_string_data( $string_id, $location, $wrap_tag ) {
		$string = $this->string_factory->find_by_id( $string_id );
		$string->set_location( $location );
		$string->set_wrap_tag( $wrap_tag );
	}
}

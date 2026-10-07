<?php

class WPML_Translation_Element_Factory {
	const ELEMENT_TYPE_POST = 'Post';
	const ELEMENT_TYPE_TERM = 'Term';
	const ELEMENT_TYPE_MENU = 'Menu';

	private $sitepress;

	private $wpml_cache;

	public function __construct( SitePress $sitepress, ?WPML_WP_Cache $wpml_cache = null ) {
		$this->sitepress  = $sitepress;
		$this->wpml_cache = $wpml_cache;
	}

	public function create( $id, $type ) {
		$fn = 'create_' . $type;
		if ( method_exists( $this, $fn ) ) {
			return $this->$fn( $id );
		}

		throw new InvalidArgumentException( 'Element type: ' . $type . ' does not exist.' );
	}


	public function create_post( $id ) {
		return new WPML_Post_Element( $id, $this->sitepress, $this->wpml_cache );
	}

	public function create_term( $id ) {
		return new WPML_Term_Element( $id, $this->sitepress, '', $this->wpml_cache );
	}

	public function create_menu( $id ) {
		return new WPML_Menu_Element( $id, $this->sitepress, $this->wpml_cache );
	}

	public function create_package( $id, $kind ) {
		return new WPML_Package_Element( $id, $this->sitepress, $kind, $this->wpml_cache );
	}

	public function create_wpml_package( $package ) {
		return new WPML_Package( $package );
	}
}

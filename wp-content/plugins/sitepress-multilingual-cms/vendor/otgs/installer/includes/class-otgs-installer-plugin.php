<?php

class OTGS_Installer_Plugin {

	private $name;
	private $slug;
	private $description;
	private $changelog;
	private $version;
	private $date;
	private $url;
	private $free_on_wporg;
	private $fallback_on_wporg;
	private $basename;
	private $external_repo;
	private $is_lite;
	private $repo;
	private $id;
	private $installed_version;
	private $channel;
	private $tested;

	public function __construct( array $params = array() ) {
		foreach ( get_object_vars( $this ) as $property => $value ) {
			if ( array_key_exists( $property, $params ) ) {
				$this->$property = $params[ $property ];
			}
		}
	}

	public function get_name() {
		return $this->name;
	}

	public function get_slug() {
		return $this->slug;
	}

	public function get_description() {
		return $this->description;
	}

	public function get_changelog() {
		return $this->changelog;
	}

	public function get_version() {
		return $this->version;
	}

	public function get_date() {
		return $this->date;
	}

	public function get_url() {
		return $this->url;
	}

	public function get_repo() {
		return $this->repo;
	}

	public function is_free_on_wporg() {
		return (bool) $this->free_on_wporg;
	}

	public function has_fallback_on_wporg() {
		return (bool) $this->fallback_on_wporg;
	}

	public function get_basename() {
		return $this->basename;
	}

	public function get_external_repo() {
		return $this->external_repo;
	}

	public function get_id() {
		return $this->id;
	}

	public function get_installed_version() {
		return $this->installed_version;
	}

	public function get_channel() {
		return $this->channel;
	}

	public function get_tested() {
		return $this->tested;
	}

	public function is_lite() {
		return $this->is_lite;
	}
}
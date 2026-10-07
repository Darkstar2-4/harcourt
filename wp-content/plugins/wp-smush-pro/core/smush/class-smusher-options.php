<?php

namespace Smush\Core\Smush;

class Smusher_Options {

	private $lossy_level;
	private $strip_exif;
	private $api_key;
	private $api_url;
	private $streaming_enabled;
	private $extra_headers;
	private $parallel_optimization_enabled;
	private $protocol;
	private $max_size;
	/**
	 * @var callable|null
	 */
	private $on_disable_streaming;
	/**
	 * @var callable|null
	 */
	private $on_switch_to_http;

	public function set_on_disable_streaming( $callback ) {
		$this->on_disable_streaming = $callback;

		return $this;
	}

	public function set_on_switch_to_http( $callback ) {
		$this->on_switch_to_http = $callback;

		return $this;
	}

	public function disable_streaming() {
		if ( is_callable( $this->on_disable_streaming ) ) {
			( $this->on_disable_streaming )();
		}
	}

	public function switch_to_http() {
		if ( is_callable( $this->on_switch_to_http ) ) {
			( $this->on_switch_to_http )();
		}
	}

	public function get_lossy_level() {
		return $this->lossy_level;
	}

	public function set_lossy_level( $lossy_level ) {
		$this->lossy_level = $lossy_level;

		return $this;
	}

	public function strip_exif() {
		return $this->strip_exif;
	}

	public function set_strip_exif( $strip_exif ) {
		$this->strip_exif = $strip_exif;

		return $this;
	}

	public function get_api_key() {
		return $this->api_key;
	}

	public function set_api_key( $api_key ) {
		$this->api_key = $api_key;

		return $this;
	}

	public function get_api_url() {
		return $this->api_url;
	}

	public function set_api_url( $api_url ) {
		$this->api_url = $api_url;

		return $this;
	}

	public function is_streaming_enabled() {
		return $this->streaming_enabled;
	}

	public function set_streaming_enabled( $enabled ) {
		$this->streaming_enabled = $enabled;

		return $this;
	}

	public function get_extra_headers() {
		return $this->extra_headers;
	}

	public function set_extra_headers( $extra_headers ) {
		$this->extra_headers = $extra_headers;

		return $this;
	}

	public function is_parallel_optimization_enabled() {
		return $this->parallel_optimization_enabled;
	}

	public function set_parallel_optimization_enabled( $enabled ) {
		$this->parallel_optimization_enabled = $enabled;

		return $this;
	}

	public function get_protocol() {
		return $this->protocol;
	}

	public function set_protocol( $protocol ) {
		$this->protocol = $protocol;

		return $this;
	}

	public function get_max_size() {
		return $this->max_size;
	}

	public function set_max_size( $max_size ) {
		$this->max_size = $max_size;

		return $this;
	}
}
<?php

class WPML_LS_Template extends WPML_Templates_Factory {

	const FILENAME = 'template.twig';

	private $template;

	private $model;

	private $prefix = 'wpml-ls-';

	public function __construct( $template_data, $template_model = array() ) {
		$this->template        = $this->format_data($template_data);
		$this->template['js']  = self::remove_non_minified_duplicates( $this->template['js'] );
		$this->template['css'] = self::remove_non_minified_duplicates( $this->template['css'] );

		if ( array_key_exists( 'template_string', $this->template ) ) {
			$this->template_string = $this->template['template_string'];
		}

		$this->model = $template_model;
		parent::__construct();
	}

	private function format_data( $template_data ) {
		foreach ( array( 'path', 'js', 'css' ) as $k ) {
			$template_data[ $k ] = isset( $template_data[ $k ] ) ? $template_data[ $k ] : array();
			$template_data[ $k ] = is_array( $template_data[ $k ] ) ? $template_data[ $k ] : array( $template_data[ $k ] );
		}

		return $template_data;
	}

	public function set_model( $model ) {
		$this->model = is_array( $model ) ? $model : array( $model );
	}

	public function get_html( $sandbox = false ) {
		$ret = '';
		if ( $this->template_paths || $this->template_string ) {
			if ( $sandbox ) {
				$ret = parent::get_sandbox_view( null, null );
			} else {
				$ret = parent::get_view( null, null );
			}
		}
		return $ret;
	}

	public function get_styles( $with_version = false ) {
		$styles = $with_version
			? array_map( array( $this, 'add_resource_version' ), $this->template['css'] )
			: $this->template['css'];

		return array_values( $styles );
	}

	public function has_styles() {
		return ! empty( $this->template['css'] );
	}

	public function get_scripts( $with_version = false ) {
		$scripts = $with_version
			? array_map( array( $this, 'add_resource_version' ), $this->template['js'] )
			: $this->template['js'];

		return array_values( $scripts );
	}

	private function add_resource_version( $url ) {
		return $url . '?ver=' . $this->get_version();
	}

	public function get_resource_handler( $index ) {
		$slug   = isset( $this->template['slug'] ) ? $this->template['slug'] : '';
		$prefix = $this->is_core() ? '' : $this->prefix;
		return $prefix . $slug . '-' . $index;
	}

	public function get_inline_style_handler() {
		$count = count( $this->template['css'] );

		return $count > 0 ? $this->get_resource_handler( $count - 1 ) : null;
	}

	public function get_version() {
		return $this->template['version'];
	}

	protected function init_template_base_dir() {
		$this->template_paths = $this->template['path'];
	}

	public function get_template() {
		$template = self::FILENAME;

		if ( isset( $this->template_string ) ) {
			$template = $this->template_string;
		} elseif ( array_key_exists( 'filename', $this->template ) ) {
			$template = $this->template['filename'];
		}

		return $template;
	}

	public function get_model() {
		return $this->model;
	}

	public function get_template_data() {
		return $this->template;
	}

	public function set_template_data( $template ) {
		$this->template = $template;
	}

	public function is_core() {
		return isset( $this->template['is_core'] ) ? (bool) $this->template['is_core'] : false;
	}

	public function supported_slot_types() {
		return isset( $this->template['for'] ) ? $this->template['for'] : array();
	}

	public function force_settings() {
		return isset( $this->template['force_settings'] ) ? $this->template['force_settings'] : array();
	}

	public function is_path_valid() {
		$valid                = true;
		$this->template_paths = apply_filters( 'wpml_ls_template_paths', $this->template_paths );

		foreach ( $this->template_paths as $path ) {
			if ( ! file_exists( $path ) ) {
				$valid = false;
				break;
			}
		}
		return $valid;
	}

	public function set_template_string( $template_string ) {
		if ( method_exists( $this, 'is_string_template' ) ) {
			$this->template_string = $template_string;
		}
	}



    public static function remove_non_minified_duplicates( array $assets ) {
        $hasMinifiedVersion = function( $url ) use ( $assets ) {
            $extension = pathinfo( $url, PATHINFO_EXTENSION );
            $basename  = pathinfo( $url, PATHINFO_BASENAME );
            $filename  = pathinfo( $url, PATHINFO_FILENAME );

            $url_start     = substr( $url, 0, strlen( $url ) - strlen( $basename ) );
            $minified_file = $filename . '.min.' . $extension;
            if ( in_array( $url_start . $minified_file, $assets, true ) ) {
                return true;
            }

            return false;
        };

        return wpml_collect( $assets )
            ->reject( $hasMinifiedVersion )
            ->toArray();
    }
}

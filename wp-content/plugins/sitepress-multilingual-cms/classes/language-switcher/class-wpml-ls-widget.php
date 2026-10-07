<?php

use WPML\FP\Obj;

class WPML_LS_Widget extends WP_Widget {

	const SLUG        = 'icl_lang_sel_widget';
	const ANCHOR_BASE = '#sidebars/';

	public function __construct() {
		parent::__construct(
			self::SLUG,
			__( 'Language Switcher', 'sitepress' ),
			array(
				'description' => __( 'Language Switcher', 'sitepress' ),
			)
		);

		add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts_action' ) );
	}

	public static function register() {
		register_widget( __CLASS__ );
	}

	public function admin_enqueue_scripts_action( $hook ) {
		global $sitepress;

		if ( 'widgets.php' === $hook ) {
			$suffix = $sitepress->get_wp_api()->constant( 'SCRIPT_DEBUG' ) ? '' : '.min';
			wp_enqueue_script( 'wpml-widgets', ICL_PLUGIN_URL . '/res/js/widgets' . $suffix . '.js', array( 'jquery' ) );
		}
	}

	public function widget( $args, $instance ) {
		global $wpml_language_switcher;

		$sidebar = isset( $args['id'] ) ? $args['id'] : '';

		$slot = $wpml_language_switcher->get_slot( 'sidebars', $sidebar );

		if ( ! $slot instanceof WPML_LS_Sidebar_Slot ) {
			$instanceSlot = Obj::prop( 'slot', $instance );
			$slot         = $instanceSlot instanceof WPML_LS_Sidebar_Slot ? $instanceSlot : $slot;
		}

		$ret  = $wpml_language_switcher->render( $slot );

		if ( $ret ) {

			if ( $slot->get( 'widget_title' ) ) {
				remove_filter( 'widget_title', 'icl_sw_filters_widget_title', 0 );

				$ret = $args['before_title'] . apply_filters( 'widget_title', $slot->get( 'widget_title' ) )
					   . $args['after_title'] . $ret;

				if ( function_exists( 'icl_sw_filters_widget_title' ) ) {
					add_filter( 'widget_title', 'icl_sw_filters_widget_title', 0 );
				}
			}

			echo $args['before_widget'] . $ret . $args['after_widget'];
		}
	}

	public function form( $instance ) {
		global $wpml_language_switcher;

		$slug = isset( $instance['slot'] ) ? $instance['slot']->slug() : '';
		echo $wpml_language_switcher->get_button_to_edit_slot( 'sidebars', $slug );
	}

	public function update( $new_instance, $old_instance ) {
		if ( ! $new_instance && ! $old_instance ) {
			$slot_factory      = new WPML_LS_Slot_Factory();
			$args              = $slot_factory->get_default_slot_arguments( 'sidebars' );
			$args['slot_slug'] = isset( $_POST['sidebar'] ) ? $_POST['sidebar'] : '';

			$new_instance = array(
				'slot' => $slot_factory->get_slot( $args ),
			);
		}

		return $new_instance;
	}

	public function create_new_instance( WPML_LS_Slot $slot ) {
		require_once ABSPATH . '/wp-admin/includes/widgets.php';
		$number = next_widget_id_number( $this->id_base );
		$this->_set( $number );
		$this->_register_one( $number );
		$all_instances            = $this->get_settings();
		$all_instances[ $number ] = $this->get_instance_options_from_slot( $slot );
		$this->save_settings( $all_instances );
		return $this->id;
	}

	public function update_instance( WPML_LS_Slot $slot, $widget_id = null ) {
		$number                   = isset( $widget_id ) ? $this->get_number_from_widget_id( $widget_id ) : $this->number;
		$all_instances            = $this->get_settings();
		$all_instances[ $number ] = $this->get_instance_options_from_slot( $slot );
		$this->save_settings( $all_instances );
	}

	public function delete_instance( $widget_id = null ) {
		$number        = isset( $widget_id ) ? $this->get_number_from_widget_id( $widget_id ) : $this->number;
		$all_instances = $this->get_settings();
		unset( $all_instances[ $number ] );
		$this->save_settings( $all_instances );
	}

	public function get_number_from_widget_id( $widget_id ) {
		return (int) preg_replace( '/^' . self::SLUG . '-/', '', (string) $widget_id, 1 );
	}

	private function get_instance_options_from_slot( WPML_LS_Slot $slot ) {
		return array( 'slot' => $slot );
	}

	public function get_settings_page_url( $slug ) {
		return admin_url( 'admin.php?page=' . WPML_LS_Admin_UI::get_page_hook() . self::ANCHOR_BASE . $slug );
	}
}

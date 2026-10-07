<?php

class WPML_LS_Render extends WPML_SP_User {

	const THE_CONTENT_FILTER_PRIORITY = 100;

	private $current_template;

	private $templates;

	private $settings;

	private $model_build;

	private $inline_styles;

	private $assets;

	private $wpml_ls_exclude_in_menu;

	private $has_hierarchical_menu = false;

	public function __construct( $templates, $settings, $model_build, $inline_styles, $sitepress ) {
		$this->templates     = $templates;
		$this->settings      = $settings;
		$this->model_build   = $model_build;
		$this->inline_styles = $inline_styles;
		$this->assets        = new WPML_LS_Assets( $this->templates, $this->settings );
		parent::__construct( $sitepress );
	}

	public function init_hooks() {
		if ( $this->sitepress->get_setting( 'setup_complete' ) ) {

			add_filter( 'wp_get_nav_menu_items', array( $this, 'wp_get_nav_menu_items_filter' ), 10, 2 );
			add_filter( 'wp_setup_nav_menu_item', array( $this, 'maybe_repair_menu_item' ), PHP_INT_MAX );
			add_filter( 'nav_menu_link_attributes', array( $this, 'add_menu_link_accessibility_attributes' ), 10, 2 );
			add_action( 'wp_footer', array( $this, 'add_menu_accessibility_script' ), 999 );

			add_filter( 'the_content', array( $this, 'the_content_filter' ), self::THE_CONTENT_FILTER_PRIORITY );
			if ( ! $this->is_widgets_page() ) {
				add_action( 'wp_footer', array( $this, 'wp_footer_action' ), 19 );
			}

			$this->assets->init_hooks();
		}
	}

	public function render( $slot ) {
		$html  = '';
		$model = array();

		if ( ! $this->is_hidden( $slot ) ) {

			$this->assets->maybe_late_enqueue_template( $slot->template() );
			$this->current_template = clone $this->templates->get_template( $slot->template() );

			$sandbox = false;
			if ( $slot->template_string() ) {
				$this->current_template->set_template_string( $slot->template_string() );
				$sandbox = true;
			}

			$model = $this->model_build->get( $slot, $this->current_template->get_template_data() );

			if ( $model['languages'] ) {
				$this->current_template->set_model( $model );
				$html = $this->current_template->get_html( $sandbox );
			}
		}

		return $this->filter_html( $html, $model, $slot );
	}

	private function filter_html( $html, $model, $slot ) {
		return apply_filters( 'wpml_ls_html', $html, $model, $slot );
	}

	public function get_preview( $slot ) {
		$ret = [];

		if ( $slot->is_menu() ) {
			$ret['html'] = $this->render_menu_preview( $slot );
		} elseif ( $slot->is_post_translations() ) {
			$ret['html'] = $this->post_translations_label( $slot );
		} else {
			$ret['html'] = $this->render( $slot );
		}

		$ret['css']    = $this->current_template->get_styles( true );
		$ret['js']     = $this->current_template->get_scripts( true );
		$ret['styles'] = $this->inline_styles->get_slots_inline_styles( [ $slot ] );

		return $ret;
	}

	public function wp_get_nav_menu_items_filter( $items, $menu ) {
		if ( $this->should_not_alter_menu() ) {
			return $items;
		}

		if ( $this->is_for_menu_panel_in_customizer() ) {
			return $items;
		}

		$slot = $this->settings->get_menu_settings_from_id( $menu->term_id );

		if ( $slot->is_enabled() && ! $this->is_hidden( $slot ) ) {
			$is_before  = 'before' === $slot->get( 'position_in_menu' );
			$lang_items = $this->get_menu_items( $slot );

			if ( $items && $lang_items ) {
				$items = $this->merge_menu_items( $items, $lang_items, $is_before );
			} elseif ( ! $items && $lang_items ) {
				$items = $lang_items;
			}
		}

		return $items;
	}

	private function is_for_menu_panel_in_customizer() {
		return is_customize_preview() && ! did_action( 'template_redirect' );
	}

	private function should_not_alter_menu() {
		if ( null === $this->wpml_ls_exclude_in_menu ) {
			$this->wpml_ls_exclude_in_menu = apply_filters( 'wpml_ls_exclude_in_menu', true );
		}

		return is_admin() && $this->wpml_ls_exclude_in_menu;
	}


	private function merge_menu_items( $items, $lang_items, $is_before ) {
		if ( $is_before ) {
			$items_to_prepend = $lang_items;
			$items_to_append  = $items;
		} else {
			$items_to_prepend = $items;
			$items_to_append  = $lang_items;
		}

		$menu_orders = wp_list_pluck( $items_to_prepend, 'menu_order' );
		$offset      = max( $menu_orders );

		foreach ( $items_to_append as $item ) {
			$item->menu_order = $item->menu_order + $offset;
		}

		return array_merge( $items_to_prepend, $items_to_append );
	}

	private function get_menu_items( $slot ) {
		$lang_items = array();
		$model      = $this->model_build->get( $slot );

		if ( isset( $model['languages'] ) ) {
			if ( $slot->get( 'is_hierarchical' ) ) {
				$this->has_hierarchical_menu = true;
			}

			$this->current_template = $this->templates->get_template( $slot->template() );
			$menu_order             = 1;

			foreach ( $model['languages'] as $language_model ) {
				$this->current_template->set_model( $language_model );
				$item_content             = $this->filter_html( $this->current_template->get_html(), $language_model, $slot );
				$ls_menu_item             = new WPML_LS_Menu_Item( $language_model, $item_content );
				$ls_menu_item->menu_order = $menu_order;
				$menu_order++;

				$lang_items[] = $ls_menu_item;
			}
		}

		return $lang_items;
	}

	public function maybe_repair_menu_item( $item ) {
		if ( $this->should_not_alter_menu() ) {
			return $item;
		}

		if ( ! $item instanceof WPML_LS_Menu_Item ) {
			return $item;
		}

		if ( ! $item->db_id ) {
			$item->db_id = $item->ID;
		}

		return $item;
	}

	private function render_menu_preview( $slot ) {
		$items    = $this->get_menu_items( $slot );
		$class    = $slot->get( 'is_hierarchical' ) ? 'wpml-ls-menu-hierarchical-preview' : 'wpml-ls-menu-flat-preview';
		$defaults = array(
			'before'         => '',
			'after'          => '',
			'link_before'    => '',
			'link_after'     => '',
			'theme_location' => '',
		);
		$defaults = (object) $defaults;
		$output   = walk_nav_menu_tree( $items, 2, $defaults );

		$dummy_item = esc_html__( 'menu items', 'sitepress' );

		if ( $slot->get( 'position_in_menu' ) === 'before' ) {
			$output .= '<li class="dummy-menu-item"><a href="#">' . $dummy_item . '...</a></li>';
		} else {
			$output = '<li class="dummy-menu-item"><a href="#">...' . $dummy_item . '</a></li>' . $output;
		}

		return '<div><ul class="wpml-ls-menu-preview ' . $class . '">' . $output . '</ul></div>';
	}

	private function is_hidden( $slot ) {
		if ( ! function_exists( 'wpml_home_url_ls_hide_check' ) ) {
			require WPML_PLUGIN_PATH . '/inc/post-translation/wpml-root-page-actions.class.php';
		}

		return wpml_home_url_ls_hide_check() && ! $slot->is_shortcode_actions();
	}

	public function is_widgets_page() {
		global $pagenow;

		return is_admin() && 'widgets.php' === $pagenow;
	}

	public function the_content_filter( $content ) {
		$post_translations = '';
		$slot              = $this->settings->get_slot( 'statics', 'post_translations' );

		if ( $slot->is_enabled() && $this->sitepress->get_wp_api()->is_singular() ) {
			$post_translations = $this->post_translations_label( $slot );
		}

		if ( $post_translations ) {
			if ( $slot->get( 'display_before_content' ) ) {
				$content = $post_translations . $content;
			}

			if ( $slot->get( 'display_after_content' ) ) {
				$content = $content . $post_translations;
			}
		}

		return $content;
	}

	public function post_translations_label( $slot ) {
		$css_classes = $this->model_build->get_slot_css_classes( $slot );
		$html        = $this->render( $slot );
		if ( $html ) {
			$html = sprintf( $slot->get( 'availability_text' ), $html );
			$html = '<p class="' . $css_classes . '">' . $html . '</p>';

			$html = apply_filters( 'icl_post_alternative_languages', $html );

			$html = apply_filters( 'wpml_ls_post_alternative_languages', $html );
		}

		return $html;
	}

	public function wp_footer_action() {
		$slot = $this->settings->get_slot( 'statics', 'footer' );
		if ( $slot->is_enabled() ) {
			echo $this->render( $slot );
		}
	}

	public function add_menu_link_accessibility_attributes( $atts, $item ) {
		if ( $item instanceof WPML_LS_Menu_Item ) {
			if ( isset( $item->aria_label ) ) {
				$atts['aria-label'] = $item->aria_label;
			}

			if ( isset( $item->link_role ) && ! empty( $item->link_role ) ) {
				$atts['role'] = $item->link_role;
			}

			if ( isset( $item->aria_expanded ) && ! empty( $item->aria_expanded ) ) {
				$atts['aria-expanded'] = $item->aria_expanded;
			}

			if ( isset( $item->aria_controls ) && ! empty( $item->aria_controls ) ) {
				$atts['aria-controls'] = $item->aria_controls;
			}

			if ( isset( $item->classes ) && is_array( $item->classes ) ) {
				foreach ( $item->classes as $class ) {
					if ( strpos( $class, 'wpml-ls-current-language' ) !== false ) {
						$atts['aria-current'] = 'page';
						break;
					}
				}
			}
		}

		return $atts;
	}

	public function add_menu_accessibility_script() {
 		if ( ! $this->has_hierarchical_menu_items() ) {
			return;
		}
 		?>
		<script>
		(function() {
			'use strict';
			var parentSelector = '.menu-item.wpml-ls-current-language.menu-item-has-children';
			
			function toggleSubmenu(link, forceClose) {
				var isExpanded = link.getAttribute('aria-expanded') === 'true';
				if (forceClose || isExpanded) {
					link.setAttribute('aria-expanded', 'false');
				} else {
					link.setAttribute('aria-expanded', 'true');
				}
			}
			
			function handleKeydown(e) {
				var key = e.key || e.keyCode;
				if (key === 'Enter' || key === ' ' || key === 13 || key === 32) {
					e.preventDefault();
					toggleSubmenu(this);
				} else if (key === 'Escape' || key === 'Esc' || key === 27) {
					if (this.getAttribute('aria-expanded') === 'true') {
						e.preventDefault();
						toggleSubmenu(this, true);
					}
				}
			}
			
			function handleBlur(e) {
				var link = this;
				var parent = link.closest(parentSelector);
				if (!parent) return;
				
				setTimeout(function() {
					if (!parent.contains(document.activeElement)) {
						toggleSubmenu(link, true);
					}
				}, 100);
			}
			
			function init() {
				var parents = document.querySelectorAll(parentSelector);
				for (var i = 0; i < parents.length; i++) {
					var link = parents[i].querySelector('a[aria-expanded]');
					if (link) {
						link.addEventListener('keydown', handleKeydown);
						link.addEventListener('blur', handleBlur);
					}
				}
			}
			
			if (document.readyState === 'loading') {
				document.addEventListener('DOMContentLoaded', init);
			} else {
				init();
			}
		})();
		</script>
		<?php
	}

	private function has_hierarchical_menu_items() {
		return $this->has_hierarchical_menu;
	}
}
<?php

use WPML\API\Sanitize;
use WPML\Core\Twig_Environment;
use WPML\Core\Twig_Loader_Filesystem;
use WPML\Core\Twig_Loader_String;
use WPML\Core\Twig_LoaderInterface;
use function WPML\Container\make;

class WPML_WP_API extends WPML_PHP_Functions {
	public function get_file_mime_type( $file, $filename ) {

		$mime_type = false;
		if ( file_exists( $file ) ) {
			$file_info = wp_check_filetype_and_ext( $file, $filename );
			$mime_type = $file_info['type'];
		}

		return $mime_type;
	}

	public function get_option( $option, $default = false ) {

		return get_option( $option, $default );
	}

	public function is_url( $value ) {
		$regex  = '((https?|ftp)\:\/\/)?';
		$regex .= '([a-z0-9+!*(),;?&=$_.-]+(\:[a-z0-9+!*(),;?&=$_.-]+)?@)?';
		$regex .= '([a-z0-9-.]*)\.([a-z]{2,3})';
		$regex .= '(\:[0-9]{2,5})?';
		$regex .= '(\/([a-z0-9+$_-]\.?)+)*\/?';
		$regex .= '(\?[a-z+&$_.-][a-z0-9;:@&%=+\/$_.-]*)?';
		$regex .= '(#[a-z_.-][a-z0-9+$_.-]*)?';

		return preg_match( "/^$regex$/", $value );
	}

	public function get_transient( $transient ) {
		return get_transient( $transient );
	}

	public function set_transient( $transient, $value, $expiration = 0 ) {
		set_transient( $transient, $value, $expiration );
	}

	public function update_option( $option, $value, $autoload = null ) {
		return update_option( $option, $value, $autoload );
	}

	public function get_post_status( $ID = '' ) {
		$ID = is_string( $ID ) ? (int) $ID : $ID;
		return get_post_status( $ID );
	}

	public function get_term_link( $term, $taxonomy = '' ) {

		return get_term_link( $term, $taxonomy );
	}

	public function get_term_by( $field, $value, $taxonomy = '', $output = OBJECT, $filter = 'raw' ) {
		return get_term_by( $field, $value, $taxonomy, $output, $filter );
	}

	public function add_submenu_page( $parent_slug, $page_title, $menu_title, $capability, $menu_slug, $function = '' ) {
		return add_submenu_page( $parent_slug, $page_title, $menu_title, $capability, $menu_slug, $function );
	}

	public function add_menu_page( $page_title, $menu_title, $capability, $menu_slug, $function = '', $icon_url = '', $position = null ) {
		return add_menu_page( $page_title, $menu_title, $capability, $menu_slug, $function, $icon_url, $position );
	}

	public function get_post_type_archive_link( $post_type ) {

		return get_post_type_archive_link( $post_type );
	}

	public function get_edit_post_link( $id = 0, $context = 'display' ) {

		return get_edit_post_link( $id, $context );
	}

	public function get_the_title( $post ) {

		return get_the_title( $post );
	}

	public function get_day_link( $year, $month, $day ) {

		return get_day_link( $year, $month, $day );
	}

	public function get_month_link( $year, $month ) {

		return get_month_link( $year, $month );
	}

	public function get_year_link( $year ) {

		return get_year_link( $year );
	}

	public function get_author_posts_url( $author_id, $author_nicename = '' ) {

		return get_author_posts_url( $author_id, $author_nicename );
	}

	public function current_user_can( $capability ) {
		return WPML\LIB\WP\User::currentUserCan( $capability );
	}

	public function get_user_meta( $user_id, $key = '', $single = false ) {

		return get_user_meta( $user_id, $key, $single );
	}

	public function get_post_type( $post = null ) {

		return get_post_type( $post );
	}

	public function is_archive() {
		return is_archive();
	}

	public function is_front_page() {
		return is_front_page();
	}

	public function is_home() {
		return is_home();
	}

	public function is_page( $page = '' ) {
		return is_page( $page );
	}

	public function is_paged() {
		return is_paged();
	}

	public function is_single( $post = '' ) {
		return is_single( $post );
	}

	public function is_singular( $post_types = '' ) {
		return is_singular( $post_types );
	}

	public function user_can( $user, $capability ) {
		return WPML\LIB\WP\User::userCan( $user, $capability );
	}

	public function add_filter( $tag, $function_to_add, $priority = 10, $accepted_args = 1 ) {

		return add_filter( $tag, $function_to_add, $priority, $accepted_args );
	}

	public function remove_filter( $tag, $function_to_remove, $priority = 10 ) {

		return remove_filter( $tag, $function_to_remove, $priority );
	}

	public function current_filter() {
		return current_filter();
	}

	public function get_tm_url( $tab = null, $hash = null ) {
		$tm_url = menu_page_url( $this->constant( 'WPML_TM_FOLDER' ) . '/menu/main.php', false );

		$query_vars = array();
		if ( $tab ) {
			$query_vars['sm'] = $tab;
		}

		$tm_url = add_query_arg( $query_vars, $tm_url );

		if ( $hash ) {
			if ( strpos( $hash, '#' ) !== 0 ) {
				$hash = '#' . $hash;
			}
			$tm_url .= $hash;
		}

		return $tm_url;
	}

	public function is_admin() {

		return is_admin();
	}

	public function is_jobs_tab() {
		return $this->is_tm_page( 'jobs' );
	}

	public function is_tm_page( $tab = null, $page_type = 'management' ) {
		if ( 'settings' === $page_type ) {
			$page_suffix = '/menu/settings';
			$default_tab = 'mcsetup';
		} else {
			$page_suffix = '/menu/main.php';
			$default_tab = 'dashboard';
		}

		$result = is_admin()
				  && isset( $_GET['page'] )
				  && $_GET['page'] == $this->constant( 'WPML_TM_FOLDER' ) . $page_suffix;

		if ( $tab ) {
			if ( $tab == $default_tab && ! isset( $_GET['sm'] ) ) {
				$result = $result && true;
			} else {
				$result = $result && isset( $_GET['sm'] ) && $_GET['sm'] == $tab;
			}
		}

		return $result;
	}

	public function is_translation_queue_page() {
		return is_admin() && isset( $_GET['page'] ) && $this->constant( 'WPML_TM_FOLDER' ) . '/menu/translations-queue.php' == $_GET['page'];
	}

	public function is_string_translation_page() {
		return is_admin() && isset( $_GET['page'] ) && $this->constant( 'WPML_ST_FOLDER' ) . '/menu/string-translation.php' == $_GET['page'];
	}

	public function is_support_page() {
		return $this->is_core_page( 'support.php' );
	}

	public function is_troubleshooting_page() {
		return $this->is_core_page( 'troubleshooting.php' );
	}

	public function is_core_page( $page = '' ) {
		$result = is_admin()
				  && isset( $_GET['page'] )
				  && stripos( $_GET['page'], $this->constant( 'ICL_PLUGIN_FOLDER' ) . '/menu/' . $page ) !== false;
		return $result;
	}

	public function is_back_end() {
		return is_admin() && ! $this->is_ajax() && ! $this->is_cron_job();
	}

	public function is_front_end() {
		return ! is_admin() &&
		       ! $this->is_ajax() &&
		       ! $this->is_cron_job() &&
		       ! wpml_is_rest_request();
	}

	public function is_ajax() {

		$result = defined( 'DOING_AJAX' ) && DOING_AJAX;

		if ( $this->function_exists( 'wpml_is_ajax' ) ) {
			$result = $result || wpml_is_ajax();
		}

		return $result;
	}

	public function is_cron_job() {
		return defined( 'DOING_CRON' ) && DOING_CRON;
	}

	public function is_heartbeat() {
		$action = Sanitize::stringProp( 'action', $_POST );

		return 'heartbeat' === $action;
	}

	public function is_post_edit_page() {
		global $pagenow;

		return 'post.php' === $pagenow && isset( $_GET['action'], $_GET['post'] )
		       && 'edit' === Sanitize::stringProp( 'action', $_GET );
	}

	public function is_new_post_page() {
		global $pagenow;

		return 'post-new.php' === $pagenow;
	}

	public function is_term_edit_page() {
		global $pagenow;

		return 'term.php' === $pagenow || ( 'edit-tags.php' === $pagenow && isset( $_GET['action'] ) && 'edit' === filter_var( $_GET['action'] ) );
	}

	public function is_customize_page() {
		global $pagenow;

		return 'customize.php' === $pagenow;
	}

	public function is_comments_post_page() {
		global $pagenow;

		return 'wp-comments-post.php' === $pagenow;
	}

	public function is_plugins_page() {
		global $pagenow;

		return 'plugins.php' === $pagenow;
	}

	public function is_themes_page() {
		global $pagenow;

		return 'themes.php' === $pagenow;
	}

	public function is_feed( $feeds = '' ) {
		global $wp_query;

		return isset( $wp_query ) && is_feed( $feeds );
	}

	public function wp_update_term_count( $terms, $taxonomy, $do_deferred = false ) {

		return wp_update_term_count( $terms, $taxonomy, $do_deferred );
	}

	public function get_taxonomy( $taxonomy ) {

		return get_taxonomy( $taxonomy );
	}

	public function wp_set_object_terms( $object_id, $terms, $taxonomy, $append = false ) {

		return wp_set_object_terms( $object_id, $terms, $taxonomy, $append );
	}

	public function get_post_types( $args = array(), $output = 'names', $operator = 'and' ) {

		return get_post_types( $args, $output, $operator );
	}

	public function wp_send_json( $response ) {
		wp_send_json( $response );

		return $response;
	}

	public function wp_send_json_success( $data = null ) {
		wp_send_json_success( $data );

		return $data;
	}

	public function wp_send_json_error( $data = null ) {
		wp_send_json_error( $data );

		return $data;
	}

	public function get_current_user_id() {

		return get_current_user_id();
	}

	public function get_post( $post = null, $output = OBJECT, $filter = 'raw' ) {

		return get_post( $post, $output, $filter );
	}

	public function get_post_meta( $post_id, $key = '', $single = false ) {

		return get_post_meta( $post_id, $key, $single );
	}

	public function update_post_meta(
		$post_id,
		$key,
		$value,
		$prev_value = ''
	) {

		return update_post_meta( $post_id, $key, $value, $prev_value );
	}

	public function add_post_meta( $post_id, $meta_key, $meta_value, $unique = false ) {
		return add_post_meta( $post_id, $meta_key, $meta_value, $unique );
	}

	public function delete_post_meta( $post_id, $meta_key, $meta_value = '' ) {
		return delete_post_meta( $post_id, $meta_key, $meta_value );
	}

	public function get_term_meta( $term_id, $key = '', $single = false ) {

		return get_term_meta( $term_id, $key, $single );
	}

	public function get_permalink( $id = 0, $leavename = false ) {

		return get_permalink( $id, $leavename );
	}

	public function wp_mail( $to, $subject, $message, $headers = '', $attachments = array() ) {

		return wp_mail( $to, $subject, $message, $headers, $attachments );
	}

	public function get_post_custom( $post_id = 0 ) {

		return get_post_custom( $post_id );
	}

	public function is_dashboard_tab() {
		return $this->is_tm_page( 'dashboard' );
	}

	public function wp_safe_redirect( $redir_target, $status = 302 ) {
		if ( wp_safe_redirect( $redir_target, $status, 'WPML' ) ) {
			exit;
		}
	}

	public function load_textdomain( $domain, $mofile ) {

		return load_textdomain( $domain, $mofile );
	}

	public function get_home_url(
		$blog_id = null,
		$path = '',
		$scheme = null
	) {

		return get_home_url( $blog_id, $path, $scheme );
	}

	public function get_site_url(
		$blog_id = null,
		$path = '',
		$scheme = null
	) {

		return get_site_url( $blog_id, $path, $scheme );
	}

	public function is_multisite() {

		return is_multisite();
	}

	public function is_main_site( $site_id = null ) {
		return is_main_site( $site_id );
	}

	public function ms_is_switched() {

		return ms_is_switched();
	}

	public function get_current_blog_id() {

		return get_current_blog_id();
	}

	public function wp_get_post_terms(
		$post_id = 0,
		$taxonomy = 'post_tag',
		$args = array()
	) {

		return wp_get_post_terms( $post_id, $taxonomy, $args );
	}

	public function get_taxonomies(
		$args = array(),
		$output = 'names',
		$operator = 'and'
	) {

		return get_taxonomies( $args, $output, $operator );
	}

	public function wp_get_theme( $stylesheet = null, $theme_root = null ) {

		return wp_get_theme( $stylesheet, $theme_root );
	}

	public function get_theme_name() {

		return wp_get_theme()->get( 'Name' );
	}

	public function get_theme_parent_name() {

		return wp_get_theme()->parent_theme;
	}

	public function get_theme_URI() {

		return wp_get_theme()->get( 'URI' );
	}

	public function get_theme_author() {

		return wp_get_theme()->get( 'Author' );
	}

	public function get_theme_authorURI() {

		return wp_get_theme()->get( 'AuthorURI' );
	}

	public function get_theme_template() {

		return wp_get_theme()->get( 'Template' );
	}

	public function get_theme_version() {

		return wp_get_theme()->get( 'Version' );
	}

	public function get_theme_textdomain() {

		return wp_get_theme()->get( 'TextDomain' );
	}

	public function get_theme_domainpath() {

		return wp_get_theme()->get( 'DomainPath' );
	}

	public function get_plugins() {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		return get_plugins();
	}

	public function get_post_custom_keys( $post_id ) {

		return get_post_custom_keys( $post_id );
	}

	public function get_bloginfo( $show = '', $filter = 'raw' ) {

		return get_bloginfo( $show, $filter );
	}

	public function version_compare_naked( $version1, $version2, $operator = null ) {
		return $this->version_compare( $this->get_naked_version( $version1 ), $this->get_naked_version( $version2 ), $operator );
	}

	public function get_naked_version( $version ) {

		$elements = explode( '.', str_replace( '..', '.', preg_replace( '/([^0-9\.]+)/', '.$1.', str_replace( array( '-', '_', '+' ), '.', trim( $version ) ) ) ) );

		$naked_elements = array( '0', '0', '0' );

		$elements_count = 0;
		foreach ( $elements as $element ) {
			if ( $elements_count === 3 || ! is_numeric( $element ) ) {
				break;
			}
			$naked_elements[ $elements_count ] = $element;
			$elements_count ++;
		}

		return implode( $naked_elements );
	}

	public function has_filter( $tag, $function_to_check = false ) {
		return has_filter( $tag, $function_to_check );
	}

	public function add_action( $tag, $function_to_add, $priority = 10, $accepted_args = 1 ) {
		return add_action( $tag, $function_to_add, $priority, $accepted_args );
	}

	public function get_current_screen() {
		return get_current_screen();
	}

	public function get_query_var( $var, $default = '' ) {
		return get_query_var( $var, $default );
	}

	public function get_queried_object() {
		return get_queried_object();
	}

	public function get_raw_post_data() {
		$raw_post_data = @file_get_contents( 'php://input' );
		if ( ! $raw_post_data && array_key_exists( 'HTTP_RAW_POST_DATA', $GLOBALS ) ) {
			$raw_post_data = $GLOBALS['HTTP_RAW_POST_DATA'];
		}

		return $raw_post_data;
	}

	public function wp_verify_nonce( $nonce, $action = -1 ) {
		return wp_verify_nonce( $nonce, $action );
	}

	public function did_action( $action ) {
		return did_action( $action );
	}

	public function current_action() {
		return current_action();
	}

	public function get_wp_post_types_global() {
		global $wp_post_types;
		return $wp_post_types;
	}

	public function get_wp_xmlrpc_server() {
		global $wp_xmlrpc_server;

		return $wp_xmlrpc_server;
	}

	public function get_wp_taxonomies() {
		global $wp_taxonomies;

		return $wp_taxonomies;
	}

	public function get_category_link( $category_id ) {
		return get_category_link( $category_id );
	}

	public function is_wp_error( $thing ) {
		return is_wp_error( $thing );
	}

	public function get_backtrace( $limit = 0, $provide_object = false, $ignore_args = true ) {
		$options = false;

		if ( version_compare( $this->phpversion(), '5.3.6' ) < 0 ) {
			$options = $provide_object;
		} else {
			if ( $provide_object ) {
				$options |= DEBUG_BACKTRACE_PROVIDE_OBJECT;
			}
			if ( $ignore_args ) {
				$options |= DEBUG_BACKTRACE_IGNORE_ARGS;
			}
		}

		if ( version_compare( $this->phpversion(), '5.4.0' ) >= 0 ) {
			$debug_backtrace = debug_backtrace( $options, $limit );
		} elseif ( version_compare( $this->phpversion(), '5.2.4' ) >= 0 ) {
			$debug_backtrace = debug_backtrace();
		} else {
			$debug_backtrace = debug_backtrace( $options );
		}

		if ( $debug_backtrace ) {
			array_shift( $debug_backtrace );
		}
		return $debug_backtrace;
	}

	public function get_wp_filesystem_direct() {
		global $wp_filesystem;

		require_once ABSPATH . 'wp-admin/includes/file.php';

		if ( ! $wp_filesystem ) {
			WP_Filesystem();
		}

		require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';

		return new WP_Filesystem_Direct( null );
	}

	public function get_admin_notices() {
		global $wpml_admin_notices, $sitepress;

		if ( ! $wpml_admin_notices ) {
			$wpml_admin_notices = new WPML_Notices( new WPML_Notice_Render(), $sitepress );
			$wpml_admin_notices->init_hooks();
		}

		return $wpml_admin_notices;
	}

	public function get_twig_environment( $loader, $environment_args ) {
		return new Twig_Environment( $loader, $environment_args );
	}

	public function get_twig_loader_filesystem( $template_paths ) {
		return new Twig_Loader_Filesystem( $template_paths );
	}

	public function get_twig_loader_string() {
		return new Twig_Loader_String();
	}

	public function is_a_REST_request() {
		return defined( 'REST_REQUEST' ) && REST_REQUEST;
	}
}

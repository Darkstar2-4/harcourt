<?php

class WPML_Pre_Option_Page extends WPML_WPDB_And_SP_User {

	const CACHE_GROUP = 'wpml_pre_option_page';
	private static $hooks_added = false;

	private static function add_cache_clearing_hooks() {
		if ( self::$hooks_added ) {
			return;
		}
		self::$hooks_added = true;
		add_action( 'update_option_page_on_front', [ self::class, 'clear_page_on_front_cache' ], 10, 0 );
		add_action( 'update_option_wp_page_for_privacy_policy', [ self::class, 'clear_privacy_policy_cache' ] );
	}

	private $switched;
	private $lang;

	public function __construct( &$wpdb, &$sitepress, $switched, $lang ) {
		parent::__construct( $wpdb, $sitepress );

		$this->switched = $switched;
		$this->lang     = $lang;

		self::add_cache_clearing_hooks();
	}

	public function get( $type, $from_language = null ) {

		$cache_key   = $type;
		$cache_found = false;

		$cache       = new WPML_WP_Cache( self::CACHE_GROUP );
		$results     = $cache->get( $cache_key, $cache_found );

		if ( ( ( ! $cache_found || ! isset ( $results[ $type ] ) ) && ! $this->switched )
		     || ( $this->switched && $this->sitepress->get_setting( 'setup_complete' ) )
		) {
			$results = [];
			$results[ $type ] = [];
			$values = $this->wpdb->get_results(
				$this->wpdb->prepare(
					"	SELECT element_id, language_code
						FROM {$this->wpdb->prefix}icl_translations
						WHERE trid =
							(SELECT trid
							 FROM {$this->wpdb->prefix}icl_translations
							 WHERE element_type = 'post_page'
							 AND element_id = (SELECT option_value
											   FROM {$this->wpdb->options}
											   WHERE option_name=%s
											   LIMIT 1))
						",
					$type
				)
			);

			if ( is_array( $values ) && count( $values ) ) {
				foreach ( $values as $lang_result ) {
					$results[ $type ] [ $lang_result->language_code ] = $lang_result->element_id;
				}
			}

			$cache->set( $cache_key, $results );
		}

		$target_language = $from_language ? $from_language : $this->lang;

		return isset( $results[ $type ][ $target_language ] ) ? $results[ $type ][ $target_language ] : false;
	}


	public static function clear_cache() {
		$cache = new WPML_WP_Cache( self::CACHE_GROUP );
		$cache->flush_group_cache();
	}
	public static function clear_page_on_front_cache() {
		self::clear_cache_key( 'page_on_front' );
	}

	public static function clear_privacy_policy_cache() {
		self::clear_cache_key( 'wp_page_for_privacy_policy' );
	}

	public static function maybe_clear_privacy_policy_cache( $page_id, $post_type ) {
		if ( 'page' !== $post_type ) {
			return;
		}

		$policy_page_id = (int) get_option( 'wp_page_for_privacy_policy' );
		
		if ( $policy_page_id === $page_id ) {
			self::clear_privacy_policy_cache();
		}
	}

	public static function clear_cache_key( $cache_key ) {
		wp_cache_delete( $cache_key, self::CACHE_GROUP );
	}

	function fix_trashed_front_or_posts_page_settings( $post_id ) {
		if ( 'page' !== get_post_type( $post_id ) ) {
			return;
		}

		$post_id = (int) $post_id;
		$page_on_front_current  = (int) $this->get( 'page_on_front' );
		$page_for_posts_current = (int) $this->get( 'page_for_posts' );

		$page_on_front_default  = (int) $this->get( 'page_on_front', $this->sitepress->get_default_language() );
		$page_for_posts_default = (int) $this->get( 'page_for_posts', $this->sitepress->get_default_language() );

		if ( $page_on_front_current === $post_id && $page_on_front_current !== $page_on_front_default ) {
			remove_filter( 'pre_option_page_on_front', array( $this->sitepress, 'pre_option_page_on_front' ) );
			update_option( 'page_on_front', $page_on_front_default );
			add_filter( 'pre_option_page_on_front', array( $this->sitepress, 'pre_option_page_on_front' ) );
		}
		if ( $page_for_posts_current === $post_id && $page_for_posts_current !== $page_for_posts_default ) {
			remove_filter( 'pre_option_page_for_posts', array( $this->sitepress, 'pre_option_page_for_posts' ) );
			update_option( 'page_for_posts', $page_for_posts_default );
			add_filter( 'pre_option_page_for_posts', array( $this->sitepress, 'pre_option_page_for_posts' ) );
		}
	}

}

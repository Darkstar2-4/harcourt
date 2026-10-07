<?php

use WPML\Core\WP\App\Resources;
use WPML\LIB\WP\User;

class WPML_Notices {

	const NOTICES_OPTION_KEY   = 'wpml_notices';
	const DISMISSED_OPTION_KEY = '_wpml_dismissed_notices';
	const USER_DISMISSED_KEY   = '_wpml_user_dismissed_notices';
	const NONCE_NAME           = 'wpml-notices';
	const DEFAULT_GROUP        = 'default';

	private $notice_render;

	private $notices;

	private $notices_to_remove = [];

	private $dismissed;

	private $user_dismissed;

	private $original_notices_md5;

	private $notice_key;

	private $sitepress;

	private $notices_to_add = [];

	public function __construct( WPML_Notice_Render $notice_render, \SitePress $sitepress ) {
		$this->notice_render = $notice_render;
		$this->sitepress     = $sitepress;
		$this->notice_key    = self::NOTICES_OPTION_KEY;

		$this->add_hooks();
	}

	private function add_hooks() {
		add_action( 'init', [ $this, 'add_remove_pending_notices' ] );
	}

	public function add_remove_pending_notices() {
		if ( $this->notices_to_add ) {
			foreach ( $this->notices_to_add as $key => $args ) {
				$this->add_notice( ...$args );
				unset( $this->notices_to_add[ $key ] );
			}
		}

		$this->remove_notices();
	}

	public function init_notices() {
		if ( null !== $this->notices ) {
			return;
		}

		if ( ! did_action( 'init' ) ) {
			$wp_api = $this->sitepress->get_wp_api();

			if ( $wp_api->constant( 'WP_DEBUG' ) ) {
				$wp_api->error_log( 'Deprecated: WPML_Notices::init_notices() should not be called before the init hook in ' . $this->get_caller_from_backtrace() );
			}

			$this->notices              = [];
			$this->original_notices_md5 = '';
		}

		$current_language = did_action( 'wpml_loaded' )
			? $this->sitepress->get_user_admin_language( get_current_user_id() )
			: false;

		if ( is_string( $current_language ) && $current_language && $current_language !== 'en' ) {
			$this->notice_key = self::NOTICES_OPTION_KEY . '_' . $current_language;
		}

		$this->notices              = $this->filter_invalid_notices( $this->get_all_notices() );
		$this->dismissed            = $this->get_all_dismissed();
		$this->original_notices_md5 = md5( maybe_serialize( $this->notices ) );
	}

	public function count() {
		$this->init_notices();

		$all_notices = $this->get_all_notices();
		$count       = 0;
		foreach ( $all_notices as $group => $group_notices ) {
			$count += count( $group_notices );
		}

		return $count;
	}

	public function get_all_notices() {
		$all_notices = get_option( $this->notice_key );
		if ( ! is_array( $all_notices ) ) {
			$all_notices = array();
		}
		return $all_notices;
	}

	private function get_all_dismissed() {
		$dismissed = get_option( self::DISMISSED_OPTION_KEY );
		if ( ! is_array( $dismissed ) ) {
			$dismissed = array();
		}
		return $dismissed;
	}

	private function init_all_user_dismissed() {
		if ( null === $this->user_dismissed ) {
			$this->user_dismissed = get_user_meta( get_current_user_id(), self::USER_DISMISSED_KEY, true );

			if ( ! is_array( $this->user_dismissed ) ) {
				$this->user_dismissed = array();
			}
		}
	}

	public function get_notice( $id, $group = 'default' ) {
		$this->init_notices();

		$notice = null;

		if ( isset( $this->notices[ $group ][ $id ] ) ) {
			$notice = $this->notices[ $group ][ $id ];
		}

		return $notice;
	}

	public function create_notice( $id, $text, $group = 'default' ) {
		return new WPML_Notice( $id, $text, $group );
	}

	public function add_notice( WPML_Notice $notice, $force_update = false ) {
		if ( ! did_action( 'init' ) ) {
			$this->notices_to_add[] = [ $notice, $force_update ];
			return;
		}

		$this->init_notices();

		$existing_notice = $this->notice_exists( $notice ) ? $this->notices[ $notice->get_group() ][ $notice->get_id() ] : null;

		$new_notice_is_different = null === $existing_notice || $notice->is_different( $existing_notice );

		if ( $notice->must_reset_dismiss() && $this->is_notice_dismissed( $notice ) ) {
			$this->undismiss_notice( $notice );
		}

		if ( ! $existing_notice || ( $new_notice_is_different || $force_update ) ) {
			$this->notices[ $notice->get_group() ][ $notice->get_id() ] = $notice;
			$this->save_notices();
		}
	}

	public function get_new_notice( $id, $text, $group = 'default' ) {
		return new WPML_Notice( $id, $text, $group );
	}

	public function get_new_notice_action( $text, $url = '#', $dismiss = false, $hide = false, $display_as_button = false ) {
		return new WPML_Notice_Action( $text, $url, $dismiss, $hide, $display_as_button );
	}

	private function notice_exists( WPML_Notice $notice ) {
		$notice_id    = $notice->get_id();
		$notice_group = $notice->get_group();

		return $this->group_and_id_exist( $notice_group, $notice_id );
	}

	private function get_notices_for_group( $group ) {
		if ( array_key_exists( $group, $this->notices ) ) {
			return $this->notices[ $group ];
		}

		return array();
	}

	private function save_notices() {
		if ( ! has_action( 'shutdown', array( $this, 'save_to_option' ) ) ) {
			add_action( 'shutdown', array( $this, 'save_to_option' ), 1000 );
		}
	}

	public function save_to_option() {
		if ( null === $this->notices ) {
			return;
		}

		$is_allowed_user = is_user_logged_in() && (
			current_user_can( User::CAP_MANAGE_TRANSLATIONS ) ||
			current_user_can( User::CAP_TRANSLATE )
		);

		if ( ! $is_allowed_user ) {
			return;
		}

		if (
			$this->original_notices_md5
			&& ( $this->original_notices_md5 !== md5( maybe_serialize( $this->notices ) ) )
		) {
			update_option( $this->notice_key, $this->notices, false );
		}
	}

	private function save_dismissed() {
		update_user_meta( get_current_user_id(), self::USER_DISMISSED_KEY, $this->user_dismissed );
		if ( is_array( $this->dismissed ) ) {
			update_option( self::DISMISSED_OPTION_KEY, $this->dismissed, false );
		}
	}

	public function remove_notices() {
		if ( $this->notices_to_remove ) {
			$this->init_notices();
			foreach ( $this->notices_to_remove as $group => $group_notices ) {
				foreach ( $group_notices as $index => $id ) {
					if ( array_key_exists( $group, $this->notices ) && array_key_exists( $id, $this->notices[ $group ] ) ) {
						unset( $this->notices[ $group ][ $id ] );
					}
					unset( $this->notices_to_remove[ $group ][ $index ] );
				}
				if ( array_key_exists( $group, $this->notices_to_remove ) && ! $this->notices_to_remove[ $group ] ) {
					unset( $this->notices_to_remove[ $group ] );
				}
				if ( array_key_exists( $group, $this->notices ) && ! $this->notices[ $group ] ) {
					unset( $this->notices[ $group ] );
				}
			}

			$this->save_notices();
		}
	}

	public function admin_enqueue_scripts() {
		if ( WPML_Block_Editor_Helper::is_edit_post() ) {
			wp_enqueue_script(
				'block-editor-notices',
				ICL_PLUGIN_URL . '/dist/js/blockEditorNotices/app.js',
				array( 'wp-edit-post', Resources::vendorAsDependency() ),
				ICL_SITEPRESS_SCRIPT_VERSION,
				true
			);
		}
		if ( $this->must_display_notices() ) {
			wp_enqueue_style( 'otgs-notices', ICL_PLUGIN_URL . '/res/css/otgs-notices.css', array( 'sitepress-style' ) );
			wp_enqueue_script(
				'otgs-notices',
				ICL_PLUGIN_URL . '/res/js/otgs-notices.js',
				array( 'underscore' ),
				ICL_SITEPRESS_SCRIPT_VERSION,
				true
			);

			do_action( 'wpml-notices-scripts-enqueued' );
		}
	}

	private function must_display_notices() {
		if ( $this->notices ) {
			foreach ( $this->notices as $group => $notices ) {
				foreach ( $notices as $notice ) {
					if ( $this->notice_render->must_display_notice( $notice ) && ! $this->must_hide_if_notice_exists( $notice ) ) {
						return true;
					}
				}
			}
		}

		return false;
	}

	private function must_hide_if_notice_exists( WPML_Notice $notice ) {
		$hide_if_notice_exists = $notice->get_hide_if_notice_exists();
		if ( $hide_if_notice_exists ) {
			$other_notice = $this->get_notice( $hide_if_notice_exists['id'], $hide_if_notice_exists['group'] );

			return $other_notice;
		}
		return false;
	}

	public function admin_notices() {
		$this->init_notices();
		if ( $this->notices && $this->must_display_notices() ) {
			foreach ( $this->notices as $group => $notices ) {
				foreach ( $notices as $notice ) {
					if ( $notice instanceof WPML_Notice && ! $this->is_notice_dismissed( $notice ) ) {
						$this->notice_render->render( $notice );
						if ( $notice->is_flash() ) {
							$this->remove_notice( $notice->get_group(), $notice->get_id() );
						}
					}
				}
			}
		}
	}

	public function wp_ajax_hide_notice() {
		$this->init_notices();

		list( $notice_group, $notice_id ) = $this->parse_group_and_id();

		if ( ! $notice_group ) {
			$notice_group = self::DEFAULT_GROUP;
		}

		if ( $this->has_valid_nonce() && $this->group_and_id_exist( $notice_group, $notice_id ) ) {
			$this->remove_notice( $notice_group, $notice_id );
			wp_send_json_success( true );
		}

		wp_send_json_error( __( 'Notice does not exists.', 'sitepress' ) );
	}

	public function wp_ajax_dismiss_notice() {
		$this->init_notices();

		list( $notice_group, $notice_id ) = $this->parse_group_and_id();

		if ( $this->has_valid_nonce() && $this->dismiss_notice_by_id( $notice_id, $notice_group ) ) {
			wp_send_json_success( true );
		}

		wp_send_json_error( __( 'Notice does not exist.', 'sitepress' ) );
	}

	private function dismiss_notice_by_id( $notice_id, $notice_group = null ) {
		if ( ! $notice_group ) {
			$notice_group = self::DEFAULT_GROUP;
		}

		if ( $this->group_and_id_exist( $notice_group, $notice_id ) ) {
			$notice = $this->get_notice( $notice_id, $notice_group );

			if ( $notice ) {
				$this->dismiss_notice( $notice );
				$this->remove_notice( $notice_group, $notice_id );

				return true;
			}
		}

		return false;
	}

	public function wp_ajax_dismiss_group() {
		$this->init_notices();

		list( $notice_group ) = $this->parse_group_and_id();

		if ( $notice_group && $this->has_valid_nonce() && $this->dismiss_notice_group( $notice_group ) ) {
			wp_send_json_success( true );
		}
		wp_send_json_error( __( 'Group does not exist.', 'sitepress' ) );
	}

	private function dismiss_notice_group( $notice_group ) {
		if ( $notice_group ) {
			$notices = $this->get_notices_for_group( $notice_group );

			if ( $notices ) {
				foreach ( $notices as $notice ) {
					$this->dismiss_notice( $notice, false );
					$this->remove_notice( $notice_group, $notice->get_id() );
				}

				$this->save_dismissed();

				return true;
			}
		}

		return false;
	}

	private function parse_group_and_id() {
		$group = isset( $_POST['group'] ) ? sanitize_text_field( $_POST['group'] ) : false;
		$id    = isset( $_POST['id'] ) ? sanitize_text_field( $_POST['id'] ) : false;

		return array( $group, $id );
	}

	private function has_valid_nonce() {
		$nonce = isset( $_POST['nonce'] ) ? $_POST['nonce'] : null;
		return wp_verify_nonce( $nonce, self::NONCE_NAME );
	}

	public function group_and_id_exist( $group, $id ) {
		return array_key_exists( $group, $this->notices ) && array_key_exists( $id, $this->notices[ $group ] );
	}

	public function remove_notice( $notice_group, $notice_id ) {
		$this->notices_to_remove[ $notice_group ][] = $notice_id;
		$this->notices_to_remove[ $notice_group ]   = array_unique( $this->notices_to_remove[ $notice_group ] );

		if ( ! did_action( 'init' ) ) {
			return;
		}

		$this->remove_notices();
	}

	public function remove_notice_group( $notice_group ) {
		$this->init_notices();

		$notices     = $this->get_notices_for_group( $notice_group );
		$notices_ids = array_keys( $notices );
		foreach ( $notices_ids as $notices_id ) {
			$this->remove_notice( $notice_group, $notices_id );
		}
	}

	public function dismiss_notice( WPML_Notice $notice, $persist = true ) {
		if ( method_exists( $notice, 'is_user_restricted' ) && $notice->is_user_restricted() ) {
			$this->init_all_user_dismissed();
			$this->user_dismissed[ $notice->get_group() ][ $notice->get_id() ] = md5( $notice->get_text() );
		} else {
			$this->init_notices();
			$this->dismissed[ $notice->get_group() ][ $notice->get_id() ] = md5( $notice->get_text() );
		}

		if ( $persist ) {
			$this->save_dismissed();
		}
	}

	public function undismiss_notice( WPML_Notice $notice, $persist = true ) {
		if ( method_exists( $notice, 'is_user_restricted' ) && $notice->is_user_restricted() ) {
			$this->init_all_user_dismissed();
			unset( $this->user_dismissed[ $notice->get_group() ][ $notice->get_id() ] );
		} else {
			$this->init_notices();
			unset( $this->dismissed[ $notice->get_group() ][ $notice->get_id() ] );
		}

		if ( $persist ) {
			$this->save_dismissed();
		}
	}

	public function is_notice_dismissed( WPML_Notice $notice ) {
		$this->init_notices();

		$group = $notice->get_group();
		$id    = $notice->get_id();

		$is_dismissed = isset( $this->dismissed[ $group ][ $id ] ) && $this->dismissed[ $group ][ $id ];

		if ( ! $is_dismissed ) {
			$this->init_all_user_dismissed();
			$is_dismissed = isset( $this->user_dismissed[ $group ][ $id ] ) && $this->user_dismissed[ $group ][ $id ];
		}

		if ( $is_dismissed && method_exists( $notice, 'can_be_dismissed_for_different_text' )
			 && ! $notice->can_be_dismissed_for_different_text() ) {
			$is_dismissed = md5( $notice->get_text() ) === $this->dismissed[ $group ][ $id ];
		}

		return $is_dismissed;
	}

	public function init_hooks() {
		$this->add_admin_notices_action();
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ), \WPML_Admin_Scripts_Setup::PRIORITY_ENQUEUE_SCRIPTS + 1 );
		add_action( 'wp_ajax_otgs-hide-notice', array( $this, 'wp_ajax_hide_notice' ) );
		add_action( 'wp_ajax_otgs-dismiss-notice', array( $this, 'wp_ajax_dismiss_notice' ) );
		add_action( 'wp_ajax_otgs-dismiss-group', array( $this, 'wp_ajax_dismiss_group' ) );
		add_action( 'otgs_add_notice', array( $this, 'add_notice' ), 10, 2 );
		add_action( 'otgs_remove_notice', array( $this, 'remove_notice' ), 10, 2 );
		add_action( 'otgs_remove_notice_group', array( $this, 'remove_notice_group' ), 10, 1 );
	}

	public function add_admin_notices_action() {
		add_action( 'admin_notices', [ $this, 'admin_notices' ] );
	}

	private function filter_invalid_notices( $notices ) {
		foreach ( $notices as $group => $notices_in_group ) {
			if ( ! is_array( $notices_in_group ) ) {
				unset( $notices[ $group ] );
				continue;
			}
			foreach ( $notices_in_group as $index => $notice ) {
				if ( ! $notice instanceof WPML_Notice ) {
					unset( $notices[ $group ][ $index ] );
				}
			}
		}
		return $notices;
	}

	private function get_caller_from_backtrace(): string {
		$wp_api = $this->sitepress->get_wp_api();

		$backtrace = $wp_api->get_backtrace( 4, false, true );

		foreach ( [ 1, 2 ] as $index ) {
			if (
				is_array( $backtrace )
				&& isset( $backtrace[ $index ]['file'], $backtrace[ $index ]['line'] )
				&& strpos( __FILE__, (string) $backtrace[ $index ]['file'] ) === false
			) {
				return $backtrace[ $index ]['file'] . ':' . $backtrace[ $index ]['line'];
			}
		}

		return '';
	}
}

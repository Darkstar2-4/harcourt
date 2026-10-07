<?php
/**
 * Media library class.
 *
 * Responsible for displaying a UI (stats + action links) in the media library and the editor.
 *
 * @since 3.4.0
 * @package Smush\App
 */

namespace Smush\App;

use Smush\Core\Core;
use Smush\Core\Helper;
use Smush\Core\Media\Media_Item;
use Smush\Core\Media\Media_Item_Optimizer;
use Smush\Core\Media_Library\Media_Library_Row;
use Smush\Core\Modules\Abstract_Module;
use Smush\Core\Modules\Smush;
use Smush\Core\Stats\Global_Stats;
use Smush\Core\Membership\Membership;
use Smush\Core\Hub_Connector;
use Smush\Core\Backups\Bulk_Restore;
use Smush\Core\Modules\Helpers\WhiteLabel;
use Smush\Core\Array_Utils;
use Smush\Core\Settings;
use WP_Post;
use WP_Query;
use WP_Smush;

/**
 * Class Media_Library
 */
class Media_Library extends Abstract_Module {

	/**
	 * Core instance.
	 *
	 * @var Core $core
	 */
	private $core;
	private $allowed_image_sizes;

	/**
	 * @var Array_Utils
	 */
	private $array_utils;

	/**
	 * @var WhiteLabel
	 */
	private $whitelabel;

	/**
	 * Media_Library constructor.
	 *
	 * @param Core $core  Core instance.
	 */
	public function __construct( $core ) {
		parent::__construct();
		$this->core        = $core;
		$this->array_utils = new Array_Utils();
		$this->whitelabel  = new WhiteLabel();
	}

	/**
	 * Init functionality that is related to the UI.
	 */
	public function init_ui() {
		// Switch to lossless and skip media hub connect.
		add_action( 'wp_ajax_skip_media_hub_connect', array( $this, 'ajax_skip_media_hub_connect' ) );
		if ( Membership::get_instance()->is_api_hub_access_required() ) {
			add_action( 'all_admin_notices', array( $this, 'smush_media_hub_connect_notice' ), 5 );
			add_action( 'admin_print_footer_scripts', array( $this, 'print_hub_connect_inline_script' ) );
			return false;
		}

		// Media library columns.
		add_filter( 'manage_media_columns', array( $this, 'columns' ) );
		add_filter( 'manage_upload_sortable_columns', array( $this, 'sortable_column' ) );
		add_action( 'manage_media_custom_column', array( $this, 'custom_column' ), 10, 2 );

		// Manage column sorting.
		add_action( 'pre_get_posts', array( $this, 'smushit_orderby' ) );

		// Smush image filter from Media Library.
		add_filter( 'ajax_query_attachments_args', array( $this, 'filter_media_query' ) );
		// Smush image filter from Media Library (list view).
		add_action( 'restrict_manage_posts', array( $this, 'add_filter_dropdown' ) );

		// Add pre WordPress 5.0 compatibility.
		add_filter( 'wp_kses_allowed_html', array( $this, 'filter_html_attributes' ) );

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_media_scripts' ), 15 );

		add_filter( 'wp_prepare_attachment_for_js', array( $this, 'smush_send_status' ), 99, 3 );

		// Add bulk restore action.
		add_filter( 'bulk_actions-upload', array( $this, 'add_bulk_restore_action' ) );
		add_filter( 'handle_bulk_actions-upload', array( $this, 'bulk_restore_media' ), 10, 3 );
		add_action( 'all_admin_notices', array( $this, 'show_bulk_restore_notice' ) );
	}

	/**
	 * Add bulk restore action to media lib page.
	 *
	 * @param array $actions List actions.
	 * @return array
	 */
	public function add_bulk_restore_action( $actions ) {
		$actions['smush-bulk-restore'] = $this->whitelabel->replace_branding_terms( esc_html__( 'Smush restore', 'wp-smushit' ) );

		return $actions;
	}

	/**
	 * Bulk restore images.
	 *
	 * @param string $sendback       The redirect URL.
	 * @param string $doaction       Bulk action name.
	 * @param array  $attachment_ids List image ids.
	 *
	 * @return string
	 */
	public function bulk_restore_media( $sendback, $doaction, $attachment_ids ) {
		// If there is not bulk restore images, return.
		if ( 'smush-bulk-restore' !== $doaction || empty( $attachment_ids ) ) {
			return $sendback;
		}

		$bulk_restore = new Bulk_Restore( $attachment_ids );
		$bulk_restore->bulk_restore();
		$restored_count       = $bulk_restore->get_restored_count();
		$total_count          = $bulk_restore->get_total_count();
		$missing_backup_count = $bulk_restore->get_error_count( 'missing_backup' );
		$error_copy_count     = $bulk_restore->get_error_count( 'copy_failed' );

		$sendback = add_query_arg(
			array(
				'smush_total'                => $total_count,
				'smush_restored'             => $restored_count,
				'smush_missing_backup_count' => $missing_backup_count,
				'smush_copy_failed_count'    => $error_copy_count,
			),
			$sendback
		);

		// Return original location.
		return $sendback;
	}

	/**
	 * Show bulk restore notice.
	 *
	 * @return void
	 */
	public function show_bulk_restore_notice() {
		if ( ! isset( $_GET['smush_restored'], $_GET['smush_total'] ) ) {
			return;
		}

		$total                = (int) $this->array_utils->get_array_value( $_GET, 'smush_total', 0 );
		$restored             = (int) $this->array_utils->get_array_value( $_GET, 'smush_restored', 0 );
		$missing_backup_count = (int) $this->array_utils->get_array_value( $_GET, 'smush_missing_backup_count', 0 );
		$error_copy_count     = (int) $this->array_utils->get_array_value( $_GET, 'smush_copy_failed_count', 0 );
		$failed               = $total - $restored;

		if ( $total <= 0 || $restored > $total ) {
			return;
		}

		$classes    = array(
			'notice',
			'is-dismissible',
		);
		if ( $failed > 0 ) {
			$classes[]  = 'notice-warning';
		} else {
			$classes[] = 'notice-success';
		}
		?>
		<div class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>" style="padding-left:5px">
			<p>
				<?php
				echo wp_kses_post(
					$this->get_bulk_restore_message(
						$restored,
						$total,
						$missing_backup_count,
						$error_copy_count
					)
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Get the bulk restore notice message.
	 *
	 * @param int $restored              Restored count.
	 * @param int $total                 Total count.
	 * @param int $missing_backup_count  Missing backup count.
	 * @param int $error_copy_count      Error copy count.
	 * @return string
	 */
	private function get_bulk_restore_message( $restored, $total, $missing_backup_count, $error_copy_count ) {
		$backup_link       = '<a href="' . esc_url( Helper::get_page_url( 'smush#smush-backup-setting-card' ) ) . '"><strong>';
		$backup_link_close = '</strong></a>';

		$restored = (int) $restored;
		$total    = (int) $total;

		// Success message (plural-aware).
		$success = sprintf(
			/* translators: 1: opening strong tag, 2: restored count, 3: total count, 4: closing strong tag */
			_n(
				'%1$s%2$d/%3$d image was restored successfully%4$s.',
				'%1$s%2$d/%3$d images were restored successfully%4$s.',
				$restored,
				'wp-smushit'
			),
			'<strong>',
			$restored,
			$total,
			'</strong>'
		);

		// No failures.
		if ( $missing_backup_count <= 0 && $error_copy_count <= 0 ) {
			return sprintf(
				/* translators: %1$d: restored count, %2$d: total count */
				esc_html__( 'All selected images were restored successfully (%1$d/%2$d).', 'wp-smushit' ),
				$restored,
				$total
			);
		}

		$ensure_backup = sprintf(
			/* translators: 1: link start tag, 2: link end tag */
			esc_html__( 'Ensure %1$sBackup original images%2$s is enabled to keep copies of your originals.', 'wp-smushit' ),
			$backup_link,
			$backup_link_close
		);

		$no_backup_clause = '';
		if ( $missing_backup_count > 0 ) {
			$missing_backup_count = (int) $missing_backup_count;
			$no_backup_clause     = sprintf(
				/* translators: %d: number of images */
				_n(
					'%d image couldn\'t be restored as no backup exists',
					'%d images couldn\'t be restored as no backup exists',
					$missing_backup_count,
					'wp-smushit'
				),
				$missing_backup_count
			);
		}

		$copy_error_clause = '';
		if ( $error_copy_count > 0 ) {
			$error_copy_count  = (int) $error_copy_count;
			$copy_error_clause = sprintf(
				/* translators: %d: number of images */
				_n(
					'%d image couldn\'t be restored due to a backup copy error',
					'%d images couldn\'t be restored due to a backup copy error',
					$error_copy_count,
					'wp-smushit'
				),
				$error_copy_count
			);
		}

		$failures = '';
		if ( $no_backup_clause && $copy_error_clause ) {
			$failures = sprintf(
				/* translators: 1: first failure clause, 2: second failure clause */
				esc_html__( '%1$s, and %2$s.', 'wp-smushit' ),
				$no_backup_clause,
				$copy_error_clause
			);
		} elseif ( $no_backup_clause ) {
			$failures = $no_backup_clause . '.';
		} elseif ( $copy_error_clause ) {
			$failures = $copy_error_clause . '.';
		}

		return $success . ' ' . $failures . ' ' . $ensure_backup;
	}

	/**
	 * Print column header for Smush results in the media library using the `manage_media_columns` hook.
	 *
	 * @param array $defaults  Defaults array.
	 *
	 * @return array
	 */
	public function columns( $defaults ) {
		$defaults['smushit'] = 'Smush';

		return $defaults;
	}

	/**
	 * Add the Smushit Column to sortable list
	 *
	 * @param array $columns  Columns array.
	 *
	 * @return array
	 */
	public function sortable_column( $columns ) {
		$columns['smushit'] = 'smushit';

		return $columns;
	}

	/**
	 * Print column data for Smush results in the media library using
	 * the `manage_media_custom_column` hook.
	 *
	 * @param string $column_name  Column name.
	 * @param int    $id           Attachment ID.
	 */
	public function custom_column( $column_name, $id ) {
		if ( 'smushit' === $column_name ) {
			$escaped_text = wp_kses_post( $this->generate_markup( $id ) );
			if ( $this->is_failed_processing_page() ) {
				$escaped_text = sprintf( '<div class="smush-failed-processing">%s</div>', $escaped_text );
			}
			echo $escaped_text;
		}
	}

	/**
	 * Detect failed processing page.
	 *
	 * @since 3.12.0
	 *
	 * @return boolean
	 */
	private function is_failed_processing_page() {
		static $is_failed_processing_page;
		if ( null === $is_failed_processing_page ) {
			$filter = filter_input( INPUT_GET, 'smush-filter', FILTER_SANITIZE_SPECIAL_CHARS );
			$is_failed_processing_page = 'failed_processing' === $filter;
		}
		return $is_failed_processing_page;
	}

	/**
	 * Order by query for smush columns.
	 *
	 * @param WP_Query $query  Query.
	 *
	 * @return WP_Query
	 */
	public function smushit_orderby( $query ) {
		global $current_screen;

		// Filter only media screen.
		if (
			! is_admin()
			|| ( ! empty( $current_screen ) && 'upload' !== $current_screen->base )
			|| 'attachment' !== $query->get( 'post_type' )
		) {
			return $query;
		}

		$filter = filter_input( INPUT_GET, 'smush-filter', FILTER_SANITIZE_SPECIAL_CHARS );

		// Ignored.
		if ( 'ignored' === $filter ) {
			$query->set( 'meta_query', $this->query_ignored() );
			return $query;
		} elseif ( 'unsmushed' === $filter ) {
			// Not processed.
			$query->set( 'meta_query', $this->query_unsmushed() );
			return $query;
		} elseif ( 'failed_processing' === $filter ) {
			// Failed processing.
			$query->set( 'meta_query', $this->query_failed_processing() );
			return $query;
		}

		// TODO: do we need this?
		$orderby = $query->get( 'orderby' );

		if ( isset( $orderby ) && 'smushit' === $orderby ) {
			$query->set(
				'meta_query',
				array(
					'relation' => 'OR',
					array(
						'key'     => Smush::$smushed_meta_key,
						'compare' => 'EXISTS',
					),
					array(
						'key'     => Smush::$smushed_meta_key,
						'compare' => 'NOT EXISTS',
					),
				)
			);
			$query->set( 'orderby', 'meta_value_num' );
		}

		return $query;
	}

	/**
	 * Add our filter to the media query filter in Media Library.
	 *
	 * @since 2.9.0
	 *
	 * @see wp_ajax_query_attachments()
	 *
	 * @param array $query  Query.
	 *
	 * @return mixed
	 */
	public function filter_media_query( $query ) {
		$post_query = filter_input( INPUT_POST, 'query', FILTER_SANITIZE_SPECIAL_CHARS, FILTER_REQUIRE_ARRAY );

		if ( ! isset( $post_query['stats'] ) ) {
			return $query;
		}

		$filter_name = $post_query['stats'];

		// Excluded.
		if ( 'excluded' === $filter_name ) {
			$query['meta_query'] = $this->query_ignored();
		} elseif ( 'unsmushed' === $filter_name ) {
			// Unsmushed.
			$query['meta_query'] = $this->query_unsmushed();
		} elseif ( 'failed_processing' === $filter_name ) {
			// Failed processing.
			$query['meta_query'] = $this->query_failed_processing();
		}

		return $query;
	}

	/**
	 * Meta query for images skipped from bulk smush.
	 *
	 * @return array
	 */
	private function query_failed_processing() {
		// Custom query to add error items.
		add_filter( 'posts_where_request', array( $this, 'filter_query_to_add_media_item_errors' ) );

		// Custom query for failed on optimization.
		$meta_query = array(
			'relation' => 'AND',
			array(
				'key'     => Media_Item_Optimizer::get_error_meta_key(),
				'compare' => 'EXISTS',
			),
			array(
				'key'     => Media_Item::get_ignored_meta_key(),
				'compare' => 'NOT EXISTS',
			),
		);

		return $meta_query;
	}


	public function filter_query_to_add_media_item_errors( $where ) {
		global $wpdb;

		remove_filter( 'posts_where_request', array( $this, 'filter_query_to_add_media_item_errors' ) );

		$media_error_ids = Global_Stats::get()->get_error_list()->get_ids();
		if ( empty( $media_error_ids ) ) {
			return $where;
		}

		$where .= sprintf( " OR {$wpdb->posts}.ID IN (%s)", join( ',', $media_error_ids ) );

		return $where;
	}

	/**
	 * Meta query for images skipped from bulk smush.
	 *
	 * @return array
	 */
	private function query_ignored() {
		return array(
			array(
				'key'     => Media_Item::get_ignored_meta_key(),
				'compare' => 'EXISTS',
			),
		);
	}

	/**
	 * Meta query for uncompressed images.
	 *
	 * @return array
	 */
	private function query_unsmushed() {
		return Core::get_unsmushed_meta_query();
	}

	/**
	 * Adds a search dropdown in Media Library list view to filter out images that have been
	 * ignored with bulk Smush.
	 *
	 * @since 3.2.0
	 */
	public function add_filter_dropdown() {
		$scr = get_current_screen();

		if ( 'upload' !== $scr->base ) {
			return;
		}

		$ignored = filter_input( INPUT_GET, 'smush-filter', FILTER_SANITIZE_SPECIAL_CHARS );

		?>
		<label for="smush_filter" class="screen-reader-text">
			<?php echo esc_html( $this->whitelabel->replace_branding_terms( __( 'Filter by Smush status', 'wp-smushit' ) ) ); ?>
		</label>
		<select class="smush-filters" name="smush-filter" id="smush_filter">
			<option value="" <?php selected( $ignored, '' ); ?>><?php echo esc_html( $this->whitelabel->replace_branding_terms( __( 'Smush: All images', 'wp-smushit' ) ) ); ?></option>
			<option value="unsmushed" <?php selected( $ignored, 'unsmushed' ); ?>><?php echo esc_html( $this->whitelabel->replace_branding_terms( __( 'Smush: Not processed', 'wp-smushit' ) ) ); ?></option>
			<option value="ignored" <?php selected( $ignored, 'ignored' ); ?>><?php echo esc_html( $this->whitelabel->replace_branding_terms( __( 'Smush: Bulk ignored', 'wp-smushit' ) ) ); ?></option>
			<option value="failed_processing" <?php selected( $ignored, 'failed_processing' ); ?>><?php echo esc_html( $this->whitelabel->replace_branding_terms( __( 'Smush: Failed Processing', 'wp-smushit' ) ) ); ?></option>
		</select>
		<?php
	}

	/**
	 * Data attributes are not allowed on <a> elements on WordPress before 5.0.0.
	 * Add backward compatibility.
	 *
	 * @since 3.5.0
	 * @see https://github.com/WordPress/WordPress/commit/a0309e80b6a4d805e4f230649be07b4bfb1a56a5#diff-a0e0d196dd71dde453474b0f791828fe
	 * @param array $context  Context.
	 *
	 * @return mixed
	 */
	public function filter_html_attributes( $context ) {
		global $wp_version;

		if ( version_compare( '5.0.0', $wp_version, '<' ) ) {
			return $context;
		}

		$context['a']['data-tooltip'] = true;
		$context['a']['data-id']      = true;
		$context['a']['data-nonce']   = true;

		return $context;
	}

	/**
	 * Load media assets.
	 *
	 * Localization also used in Gutenberg integration.
	 */
	public function enqueue_media_scripts() {
		// Get current screen..
		$current_screen = get_current_screen();

		if ( empty( $current_screen ) || 'upload' !== $current_screen->base ) {
			return;
		}

		$mode = get_user_option( 'media_library_mode', get_current_user_id() ) ? get_user_option( 'media_library_mode', get_current_user_id() ) : 'grid';

		wp_enqueue_script(
			'smush-media-admin',
			WP_SMUSH_URL . 'app/assets/js/smush-media-admin.min.js',
			array(
				'jquery',
			),
			WP_SMUSH_VERSION,
			true
		);

		$wp_smush_msgs = array(
			'nonce'         => wp_create_nonce( 'wp-smush-ajax' ),
			'resmush'       => $this->whitelabel->get_whitelabel_text( esc_html__( 'Smushing...', 'wp-smushit' ), esc_html__( 'Optimizing...', 'wp-smushit' ) ),
			'restore'       => esc_html__( 'Restoring image...', 'wp-smushit' ),
			'smush'         => $this->whitelabel->get_whitelabel_text( esc_html__( 'Smushing...', 'wp-smushit' ), esc_html__( 'Optimizing...', 'wp-smushit' ) ),
			'ignored'       => esc_html__( 'Ignored', 'wp-smushit' ),
			'not_processed' => esc_html__( 'Not processed', 'wp-smushit' ),
		);

		wp_localize_script( 'smush-media-admin', 'wp_smush_msgs', $wp_smush_msgs );

		if ( 'grid' === $mode ) {
			$this->enqueue_media_grid_scripts();
		}
	}

	/**
	 * Enqueue media grid scripts.
	 *
	 * @return void
	 */
	private function enqueue_media_grid_scripts() {
		wp_enqueue_script(
			'smush-media-library-grid',
			WP_SMUSH_URL . 'app/assets/js/smush-media-library-grid.min.js',
			array(
				'jquery',
				'media-editor', // Used in image filters.
				'media-views',
				'media-grid',
				'wp-util',
				'wp-api',
			),
			WP_SMUSH_VERSION,
			true
		);
	}

	/**
	 * Send smush status for attachment.
	 *
	 * @param array   $response    Response array.
	 * @param WP_Post $attachment  Attachment object.
	 *
	 * @return mixed
	 */
	public function smush_send_status( $response, $attachment ) {
		if ( ! isset( $attachment->ID ) ) {
			return $response;
		}

		// Validate nonce.
		$status            = $this->smush_status( $attachment->ID );
		$response['smush'] = $status;

		return $response;
	}

	/**
	 * Get the smush button text for attachment.
	 *
	 * @param int $id  Attachment ID for which the Status has to be set.
	 *
	 * @return string
	 */
	private function smush_status( $id ) {
		$action = filter_input( INPUT_POST, 'action', FILTER_SANITIZE_SPECIAL_CHARS, FILTER_NULL_ON_FAILURE );

		// Show Temporary Status, For Async Optimisation, No Good workaround.
		if ( ! get_transient( 'wp-smush-restore-' . $id ) && 'upload-attachment' === $action && $this->settings->get( 'auto' ) ) {
			$status_txt = '<p class="smush-status">' . __( 'Smushing in progress...', 'wp-smushit' ) . '</p>';
			$button_txt = __( 'Smush Now!', 'wp-smushit' );

			return $this->column_html( $id, $status_txt, $button_txt, false );
		}

		// Else return the normal status.
		return trim( $this->generate_markup( $id ) );
	}

	/**
	 * Display the Smush Hub Connect notice in Media Library.
	 *
	 * Callback for the 'admin_notices' action.
	 *
	 * @return void
	 */
	public function smush_media_hub_connect_notice() {
		if ( ! $this->should_display_media_notice() ) {
			return;
		}

		$hub_connect_url = Hub_Connector::get_connect_site_url( 'smush', 'smush_wpadmin_media_library_connect_create_account' );
		if ( is_multisite() ) {
			$hub_connect_url = str_replace( '/wp-admin/', '/wp-admin/network/', $hub_connect_url );
		}

		?>
		<div id="smush-hub-connect-media-notice" class="smush-hub-connect-media-notice sui-smush-media" style="display: none">
			<div class="sui-notice sui-notice-blue" style="margin-top: 10px">
				<div class="sui-notice-content">
					<div class="sui-notice-message">
						<h4><?php esc_html_e( 'Unlock 2X Smush instantly!', 'wp-smushit' ); ?></h4>
						<p>
							<?php
							printf(
							/* translators: %s - strong tags */
								esc_html__( 'Connect your site to WPMU DEV for %1$sfree%2$s and start Smushing with Super 2X—takes just a few seconds, no credit card or API key needed.', 'wp-smushit' ),
								'<strong>',
								'</strong>'
							);
							?>
						</p>
						<p>
							<a id="smush-media-hub-connect" class="sui-button sui-button-blue" href="<?php echo esc_url( $hub_connect_url ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp-smush-ajax' ) ); ?>">
								<?php esc_html_e( 'Connect my site', 'wp-smushit' ); ?>
							</a>
							<a id="smush-media-skip-connect" class="smus-media-notification-skip" data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp-smush-ajax' ) ); ?>" href="#"><?php esc_html_e( "I'm happy with 1X Smush", 'wp-smushit' ); ?></a>
						</p>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Print the inline script for the Hub Connect notice.
	 *
	 * @return void
	 */
	public function print_hub_connect_inline_script() {
		?>
		<script type="text/javascript">
			jQuery(function($) {
				function insertHubConnectNotice() {
					const mediaHubConnectNotice = $('#smush-hub-connect-media-notice');
					if (!mediaHubConnectNotice.length) {
						return;
					}
					const headerEnd = $('.wrap > .wp-header-end');
					if (headerEnd.length) {
						headerEnd.after(mediaHubConnectNotice.show());
					}
				}
				insertHubConnectNotice();

				$('#smush-media-skip-connect').on('click', function(e) {
					e.preventDefault();
					$.post(ajaxurl, {
						action: 'skip_media_hub_connect',
						_ajax_nonce: $(this).data('nonce')
					})
					.done(function(response) {
						if (response && response.success) {
							$('#smush-hub-connect-media-notice').remove();
							window.location.reload();
						}
					})
					.fail(function() {
						console.error('Failed to dismiss the media hub connect notice.');
					});
				});
			});
		</script>
		<?php
	}

	/**
	 * Switch to Lossless to skip media hub connect notice.
	 *
	 * @return void
	 */
	public function ajax_skip_media_hub_connect() {
		check_ajax_referer( 'wp-smush-ajax' );

		$capability = is_multisite() ? 'manage_network' : 'manage_options';
		// Check capability.
		if ( ! Helper::is_user_allowed( $capability ) ) {
			wp_die( esc_html__( 'Unauthorized', 'wp-smushit' ), 403 );
		}

		// Switch to lossless compression.
		Settings::get_instance()->set_lossless_level();

		wp_send_json_success();
	}

	/**
	 * Skip messages respective to their IDs.
	 *
	 * @param string $msg_id  Message ID.
	 *
	 * TODO: Remove this method as no longer need.
	 *
	 * @return bool
	 */
	public function skip_reason( $msg_id ) {
		$count           = count( get_intermediate_image_sizes() );
		$smush_orgnl_txt = sprintf(
			/* translators: %s: number of thumbnails */
			esc_html__( 'When you upload an image to WordPress it automatically creates %s thumbnail sizes that are commonly used in your pages. WordPress also stores the original full-size image, but because these are not usually embedded on your site we don’t Smush them. Pro users can override this.', 'wp-smushit' ),
			$count
		);

		$skip_msg = array(
			'large_size' => $smush_orgnl_txt,
			'size_limit' => esc_html__( "Image couldn't be smushed as it exceeded the 5Mb size limit, Pro users can smush images without any size restriction.", 'wp-smushit' ),
		);

		$skip_rsn = '';
		if ( ! empty( $skip_msg[ $msg_id ] ) ) {
			$skip_rsn = '<a href="https://wpmudev.com/project/wp-smush-pro/?utm_source=smush&utm_medium=plugin&utm_campaign=smush_medialibrary_savings" target="_blank">
				<span class="sui-tooltip sui-tooltip-left sui-tooltip-constrained sui-tooltip-top-right-mobile" data-tooltip="' . $skip_msg[ $msg_id ] . '">
				<span class="sui-tag sui-tag-purple sui-tag-sm">' . esc_html__( 'PRO', 'wp-smushit' ) . '</span></span></a>';
		}

		return $skip_rsn;
	}

	/**
	 * Generate HTML for image status on the media library page.
	 *
	 * @since 3.5.0  Refactored from set_status().
	 *
	 * @param int $id  Attachment ID.
	 *
	 * @return string  HTML content or array of results.
	 */
	public function generate_markup( $id ) {
		$media_lib_item = Media_Library_Row::get_instance( $id );
		return $media_lib_item->generate_markup();
	}

	/**
	 * Print the column html.
	 *
	 * @param string  $id           Media id.
	 * @param string  $html         Status text.
	 * @param string  $button_txt   Button label.
	 * @param boolean $show_button  Whether to shoe the button.
	 *
	 * @return string
	 */
	private function column_html( $id, $html = '', $button_txt = '', $show_button = true ) {
		// Don't proceed if attachment is not image, or if image is not a jpg, png or gif, or if is not found.
		$is_smushable = Helper::is_smushable( $id );
		if ( ! $is_smushable ) {
			return false === $is_smushable ? esc_html__( 'Image not found!', 'wp-smushit' ) : esc_html__( 'Not processed', 'wp-smushit' );
		}

		// If we aren't showing the button.
		if ( ! $show_button ) {
			return $html;
		}

		if ( 'Super-Smush' === $button_txt ) {
			$html .= ' | ';
		}

		$html .= "<a href='#' class='wp-smush-send' data-id='{$id}'>{$button_txt}</a>";

		if ( get_post_meta( $id, 'wp-smush-ignore-bulk', true ) ) {
			$nonce = wp_create_nonce( 'wp-smush-remove-skipped' );
			$html .= " | <a href='#' class='wp-smush-remove-skipped' data-id={$id} data-nonce={$nonce}>" . esc_html__( 'Show in bulk optimization', 'wp-smushit' ) . '</a>';
		} else {
			$html .= " | <a href='#' class='smush-ignore-image' data-id='{$id}'>" . esc_html__( 'Ignore', 'wp-smushit' ) . '</a>';
		}

		$html .= self::progress_bar();

		return $html;
	}


	/**
	 * Check whether the Smush media notice should be displayed.
	 *
	 * @return bool
	 */
	private function should_display_media_notice() {
        if( ! Helper::is_user_allowed( 'manage_options' )  || ( is_multisite() && ! Helper::is_user_allowed( 'manage_network' ) ) ){
	        return false;
        }

		if ( ! function_exists( 'get_current_screen' ) ) {
			return false;
		}

		$current_screen = get_current_screen();

		// Show only on Media Library (upload) page.
		if ( false === strpos( $current_screen->id, 'upload' ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Returns the HTML for progress bar
	 *
	 * @return string
	 */
	public static function progress_bar() {
		return '<span class="spinner wp-smush-progress"></span>';
	}
}
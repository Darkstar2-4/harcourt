<?php
/**
 * Admin class.
 *
 * @package Smush\App
 */

namespace Smush\App;

use Smush\Core\Core;
use Smush\Core\Error_Handler;
use Smush\Core\Helper;
use Smush\Core\Next_Gen\Next_Gen_Manager;
use Smush\Core\Server_Utils;
use Smush\Core\Settings;
use Smush\Core\Stats\Global_Stats;
use Smush\Core\Membership\Membership;
use WP_Smush;

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class Admin
 */
class Admin {
	private static $plugin_discount_percent = 80;
	private static $cdn_pop_locations = 119;
	private static $review_prompts_option_key = 'wp-smush-review_prompt_next_show';
	private static $review_prompts_min_images = 10;
	private static $review_prompts_optimized_images_threshold = 100;
	private static $review_prompts_optimization_failed_percent_threshold = 10;

	/**
	 * Plugin pages.
	 *
	 * @var array
	 */
	public $pages = array();

	/**
	 * AJAX module.
	 *
	 * @var Ajax
	 */
	public $ajax;

	/**
	 * List of smush settings pages.
	 *
	 * @var array $plugin_pages
	 */
	public static $plugin_pages = array(
		'toplevel_page_smush',
		'toplevel_page_smush-network',
	);

	/**
	 * Admin constructor.
	 */
	public function __construct() {
		add_action( 'admin_init', array( $this, 'smush_i18n' ) );
		// Add information to privacy policy page (only during creation).
		add_action( 'admin_init', array( $this, 'add_policy' ) );

		// Plugin conflict notice.
		add_action( 'admin_notices', array( $this, 'show_plugin_conflict_notice' ) );
		// add_action( 'admin_notices', array( $this, 'show_parallel_unavailability_notice' ) );
		// add_action( 'admin_notices', array( $this, 'show_background_unavailability_notice' ) );
        add_action( 'smush_check_for_conflicts', array( $this, 'check_for_conflicts_cron' ) );
		add_action( 'activated_plugin', array( $this, 'check_for_conflicts_cron' ) );
		add_action( 'deactivated_plugin', array( $this, 'check_for_conflicts_cron' ) );

		// Filter built-in wpmudev branding script.
		add_filter( 'wpmudev_whitelabel_plugin_pages', array( $this, 'builtin_wpmudev_branding' ) );
		// add_action( 'wp_smush_header_notices', array( $this, 'maybe_show_local_webp_convert_original_images_notice' ) );

		// Tempo hide deactivation survey modal in plugin page.
		// add_action( 'admin_footer-plugins.php', array( $this, 'load_deactivation_survey_modal' ) );
	}

	public function __call( $method_name, $arguments ) {
		_deprecated_function( esc_html( $method_name ), '4.2.0' );
	}

	public static function get_cdn_pop_locations() {
		return self::$cdn_pop_locations;
	}

	/**
	 * Get review_prompts_option_key.
	 *
	 * @return mixed
	 */
	public static function get_review_prompts_option_key() {
		return self::$review_prompts_option_key;
	}

	/**
	 * Load translation files.
	 */
	public function smush_i18n() {
		load_plugin_textdomain(
			'wp-smushit',
			false,
			dirname( WP_SMUSH_BASENAME ) . '/languages'
		);
	}

	/**
	 * Register JS and CSS.
	 */
	private function register_scripts() {
		global $wp_version;
		/**
		 * Queue clipboard.js from your plugin if WP's version is below 5.2.0
		 * since it's only included from 5.2.0 on.
		 *
		 * Use 'clipboard' as the handle so it matches WordPress' handle for the script.
		 *
		 * @since 3.8.0
		 */
		if ( version_compare( $wp_version, '5.2', '<' ) ) {
			wp_register_script( 'clipboard', WP_SMUSH_URL . 'app/assets/js/smush-clipboard.min.js', array(), WP_SMUSH_VERSION, true );
		}

		// JS that can be used on all pages in the WP backend.
		wp_register_script( 'smush-admin-common', WP_SMUSH_URL . 'app/assets/js/smush-admin-common.min.js', array( 'jquery' ), WP_SMUSH_VERSION, true );

		// Styles that can be used on all pages in the WP backend.
		wp_register_style( 'smush-admin-common', WP_SMUSH_URL . 'app/assets/css/smush-global.min.css', array(), WP_SMUSH_VERSION );
	}

	/**
	 * Add Smush Policy to "Privacy Policy" page during creation.
	 *
	 * @since 2.3.0
	 */
	public function add_policy() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$content  = '<h3>' . __( 'Plugin: Smush', 'wp-smushit' ) . '</h3>';
		$content .=
			'<p>' . __( 'Note: Smush does not interact with end users on your website. The only input option Smush has is to a newsletter subscription for site admins only. If you would like to notify your users of this in your privacy policy, you can use the information below.', 'wp-smushit' ) . '</p>';
		$content .=
			'<p>' . __( 'Smush sends images to the WPMU DEV servers to optimize them for web use. This includes the transfer of EXIF data. The EXIF data will either be stripped or returned as it is. It is not stored on the WPMU DEV servers.', 'wp-smushit' ) . '</p>';
		$content .=
			'<p>' . sprintf( /* translators: %1$s - opening <a>, %2$s - closing </a> */
				__( "Smush uses the Stackpath Content Delivery Network (CDN). Stackpath may store web log information of site visitors, including IPs, UA, referrer, Location and ISP info of site visitors for 7 days. Files and images served by the CDN may be stored and served from countries other than your own. Stackpath's privacy policy can be found %1\$shere%2\$s.", 'wp-smushit' ),
				'<a href="https://www.stackpath.com/legal/privacy-statement/" target="_blank">',
				'</a>'
			) . '</p>';

		if ( strpos( WP_SMUSH_DIR, 'wp-smushit' ) !== false ) {
			// Only for wordpress.org members.
			$content .=
				'<p>' . __( 'Smush uses a third-party email service (Drip) to send informational emails to the site administrator. The administrator\'s email address is sent to Drip and a cookie is set by the service. Only administrator information is collected by Drip.', 'wp-smushit' ) . '</p>';
		}

		wp_add_privacy_policy_content(
			__( 'WP Smush', 'wp-smushit' ),
			wp_kses_post( wpautop( $content, false ) )
		);
	}

	/**
	 * Check for plugin conflicts cron.
	 *
	 * @since 3.6.0
	 *
	 * @param string $deactivated  Holds the slug of activated/deactivated plugin.
	 */
	public function check_for_conflicts_cron( $deactivated = '' ) {
		$optimization_plugins = array(
			'autoptimize/autoptimize.php',
			'ewww-image-optimizer/ewww-image-optimizer.php',
			'imagify/imagify.php',
			'resmushit-image-optimizer/resmushit.php',
			'shortpixel-image-optimiser/wp-shortpixel.php',
			'tiny-compress-images/tiny-compress-images.php',
			'optimole-wp/optimole-wp.php',
			'image-optimization/image-optimization.php',
		);

		$lazyload_plugins = array(
			// Optimization plugins that also include lazy load.
			'autoptimize/autoptimize.php',
			'ewww-image-optimizer/ewww-image-optimizer.php',
			'shortpixel-image-optimiser/wp-shortpixel.php',
			'optimole-wp/optimole-wp.php',
			// Lazy load plugins.
			'wp-rocket/wp-rocket.php',
			'rocket-lazy-load/rocket-lazy-load.php',
			'a3-lazy-load/a3-lazy-load.php',
			'jetpack/jetpack.php',
			'sg-cachepress/sg-cachepress.php',
			'w3-total-cache/w3-total-cache.php',
			'wp-fastest-cache/wpFastestCache.php',
			'wp-optimize/wp-optimize.php',
			'nitropack/main.php',
		);

		$plugins = get_plugins();

		$active_conflicts = array(
			'optimization' => array(),
			'lazyload'     => array(),
		);

		foreach ( $optimization_plugins as $plugin ) {
			if ( $this->is_plugin_active_conflict( $plugin, $plugins, $deactivated ) ) {
				$active_conflicts['optimization'][] = $plugins[ $plugin ]['Name'];
			}
		}

		foreach ( $lazyload_plugins as $plugin ) {
			if ( $this->is_plugin_active_conflict( $plugin, $plugins, $deactivated ) ) {
				$active_conflicts['lazyload'][] = $plugins[ $plugin ]['Name'];
			}
		}

		set_transient( 'wp-smush-conflict-plugins', $active_conflicts, 3600 );
	}

	private function is_plugin_active_conflict( $plugin, $plugins, $deactivated ) {
		if ( ! array_key_exists( $plugin, $plugins ) ) {
			return false;
		}
		if ( ! is_plugin_active( $plugin ) ) {
			return false;
		}
		if ( doing_action( 'deactivated_plugin' ) && $deactivated === $plugin ) {
			return false;
		}
		return true;
	}

	/**
	 * Display plugin incompatibility notice.
	 *
	 * @since 3.6.0
	 */
	public function show_plugin_conflict_notice() {

		$dismissed = $this->is_notice_dismissed( 'plugin-conflict' );
		if ( $dismissed ) {
			return;
		}

		$conflict_check = get_transient( 'wp-smush-conflict-plugins' );

		// Have never checked before.
		if ( false === $conflict_check ) {
			wp_schedule_single_event( time(), 'smush_check_for_conflicts' );
			return;
		}

		$optimization_plugins = isset( $conflict_check['optimization'] ) ? $conflict_check['optimization'] : array();
		$lazyload_plugins     = isset( $conflict_check['lazyload'] ) ? $conflict_check['lazyload'] : array();
		$conflict_plugins      = array_unique( array_merge( $optimization_plugins, $lazyload_plugins ) );

		// No conflicting plugins detected.
		if ( empty( $conflict_plugins ) ) {
			return;
		}

		array_walk(
			$conflict_plugins,
			function ( &$item ) {
				$item = '<strong>' . $item . '</strong>';
			}
		);
		?>
		<div class="notice notice-info is-dismissible smush-dismissible-notice smush-plugin-conflict-notice"
			 id="smush-conflict-notice"
			 data-key="plugin-conflict">

			<p><?php esc_html_e( 'You have multiple image optimization plugins installed that could conflict with Smush and cause issues. For best results, we recommend deactivating the following plugin(s):', 'wp-smushit' ); ?></p>
			<p>
				<?php echo wp_kses_post( join( '<br>', $conflict_plugins ) ); ?>
			</p>
			<p>
				<a href="<?php echo esc_url( admin_url( 'plugins.php' ) ); ?>" class="button button-primary smush-plugin-conflict-manage-button">
					<?php esc_html_e( 'Manage Plugins', 'wp-smushit' ); ?>
				</a>
				<a href="#"
				   style="margin-left: 15px"
				   id="smush-dismiss-conflict-notice" class="smush-dismiss-notice-button">

					<?php esc_html_e( 'Dismiss', 'wp-smushit' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Prints the content for pending images for the Bulk Smush section.
	 *
	 * @param int $remaining_count
	 * @param int $reoptimize_count
	 * @param int $optimize_count
	 *
	 * @since 3.7.2
	 */
	public function print_pending_bulk_smush_content( $remaining_count, $reoptimize_count, $optimize_count ) {
		$optimize_message = '';
		if ( 0 < $optimize_count ) {
			$optimize_message = sprintf(
				/* translators: 1. opening strong tag, 2: unsmushed images count,3. closing strong tag. */
				esc_html( _n( '%1$s%2$d attachment%3$s that needs smushing', '%1$s%2$d attachments%3$s that need smushing', $optimize_count, 'wp-smushit' ) ),
				'<strong>',
				absint( $optimize_count ),
				'</strong>'
			);
		}

		$reoptimize_message = '';
		if ( 0 < $reoptimize_count ) {
			$reoptimize_message = sprintf(
				/* translators: 1. opening strong tag, 2: re-smush images count,3. closing strong tag. */
				esc_html( _n( '%1$s%2$d attachment%3$s that needs re-smushing', '%1$s%2$d attachments%3$s that need re-smushing', $reoptimize_count, 'wp-smushit' ) ),
				'<strong>',
				esc_html( $reoptimize_count ),
				'</strong>'
			);
		}

		$image_count_description = sprintf(
			/* translators: 1. username, 2. unsmushed images message, 3. 'and' text for when having both unsmushed and re-smush images, 4. re-smush images message. */
			__( '%1$s, you have %2$s%3$s%4$s!', 'wp-smushit' ),
			esc_html( Helper::get_user_name() ),
			$optimize_message,
			( $optimize_message && $reoptimize_message ? esc_html__( ' and ', 'wp-smushit' ) : '' ),
			$reoptimize_message
		);
		?>
		<span id="wp-smush-bulk-image-count"><?php echo esc_html( $remaining_count ); ?></span>
		<p id="wp-smush-bulk-image-count-description">
			<?php echo wp_kses_post( $image_count_description ); ?>
		</p>
		<?php
	}

	/**
	 * Add more pages to builtin wpmudev branding.
	 *
	 * @since 3.0
	 *
	 * @param array $plugin_pages  Plugin pages for wpmudev branding.
	 *
	 * @return array
	 */
	public function builtin_wpmudev_branding( $plugin_pages ) {

		foreach ( $this->pages as $key => $value ) {
			$plugin_pages[ "smush-pro_page_smush-{$key}" ] = array(
				'wpmudev_whitelabel_sui_plugins_branding',
				'wpmudev_whitelabel_sui_plugins_footer',
				'wpmudev_whitelabel_sui_plugins_doc_links',
			);
		}

		return $plugin_pages;
	}

	public function is_notice_dismissed( $notice ) {
		$dismiss_key = 'wp-smush-dismissed-notices';
		$user_id     = get_current_user_id();
		if ( $user_id ) {
			$dismissed_notices = get_user_meta( $user_id, $dismiss_key, true );

			if ( is_array( $dismissed_notices ) && ! empty( $dismissed_notices[ $notice ] ) ) {
				return true;
			}
		}

		$dismissed_notices = get_option( $dismiss_key, array() );

		return is_array( $dismissed_notices ) && ! empty( $dismissed_notices[ $notice ] );
	}

	public function show_parallel_unavailability_notice() {
		$smush                     = WP_Smush::get_instance()->core()->mod->smush;
		$curl_multi_exec_available = ( new Server_Utils() )->curl_multi_exec_available();
		$is_current_user_not_admin = ! current_user_can( 'manage_options' );
		$is_not_bulk_smush_page    = false === strpos( get_current_screen()->id, 'page_smush-bulk' );
		$notice_hidden             = $this->is_notice_dismissed( 'curl-multi-unavailable' );

		if (
			$curl_multi_exec_available ||
			$is_current_user_not_admin ||
			$is_not_bulk_smush_page ||
			$notice_hidden
		) {
			return;
		}

		$notice_text = sprintf(
			/* translators: %s: <strong>curl_multi_exec()</strong> */
			esc_html__( 'Smush was unable to activate parallel processing on your site as your web hosting provider has disabled the %s function on your server. We highly recommend contacting your hosting provider to enable that function to optimize images on your site faster.', 'wp-smushit' ),
			'<strong>curl_multi_exec()</strong>'
		);

		?>
		<div class="notice notice-warning is-dismissible smush-dismissible-notice"
			 id="smush-parallel-unavailability-notice"
			 data-key="curl-multi-unavailable">

			<strong style="font-size: 15px;line-height: 30px;margin: 8px 0 0 2px;display: inline-block;">
				<?php esc_html_e( 'Smush images faster with parallel image optimization', 'wp-smushit' ); ?>
			</strong>
			<br/>
			<p style="margin-bottom: 13px;margin-top: 0;">
				<?php echo wp_kses_post( $notice_text ); ?><br/>

				<a style="margin-top: 5px;display: inline-block;" href="#" class="smush-dismiss-notice-button">
					<?php esc_html_e( 'Dismiss', 'wp-smushit' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	public function show_background_unavailability_notice() {
		$bg_optimization           = WP_Smush::get_instance()->core()->mod->bg_optimization;
		$background_supported      = $bg_optimization->is_background_supported();
		$background_disabled       = ! $bg_optimization->is_background_enabled();
		$is_current_user_not_admin = ! current_user_can( 'manage_options' );
		$is_not_bulk_smush_page    = false === strpos( get_current_screen()->id, 'page_smush-bulk' );
		$notice_hidden             = $this->is_notice_dismissed( 'background-smush-unavailable' );

		if (
			$background_supported ||
			$background_disabled ||
			$is_current_user_not_admin ||
			$is_not_bulk_smush_page ||
			$notice_hidden
		) {
			return;
		}

		$notice_text = sprintf(
			/* translators: 1: Current MYSQL version, 2: Required MYSQL version */
			esc_html__( 'Smush was unable to activate background processing on your site as your web hosting provider is using an old version of MySQL on your server (version %1$s). We highly recommend contacting your hosting provider to upgrade MySQL to version %2$s or higher to optimize images in the background.', 'wp-smushit' ),
			$bg_optimization->get_actual_mysql_version(),
			$bg_optimization->get_required_mysql_version()
		);
		?>
		<div class="notice notice-warning is-dismissible smush-dismissible-notice"
			 id="smush-background-unavailability-notice"
			 data-key="background-smush-unavailable">

			<strong style="font-size: 15px;line-height: 30px;margin: 8px 0 0 2px;display: inline-block;">
				<?php esc_html_e( 'Smush images in the background', 'wp-smushit' ); ?>
			</strong>
			<br/>
			<p style="margin-bottom: 13px;margin-top: 0;">
				<?php echo wp_kses_post( $notice_text ); ?><br/>

				<a style="margin-top: 5px;display: inline-block;" href="#" class="smush-dismiss-notice-button">
					<?php esc_html_e( 'Dismiss', 'wp-smushit' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	public function maybe_show_local_webp_convert_original_images_notice() {
		$redirected_from_next_gen = isset( $_GET['smush-action'] ) && 'start-bulk-next-gen-conversion' === $_GET['smush-action'];
		$settings                 = Settings::get_instance();
		$should_show_notice       = $redirected_from_next_gen &&
									current_user_can( 'manage_options' ) &&
									$settings->has_next_gen_page() &&
									! $settings->is_optimize_original_images_active();
		if ( ! $should_show_notice ) {
			return;
		}

		$next_gen_format_ext = Next_Gen_Manager::get_instance()->get_active_format_key();
		$error_message       = sprintf(
			/* translators: 1: Open a link, 2: Close the link */
			esc_html__( 'If you wish to also convert your original uploaded images to .%1$s format, please enable the %2$sOptimize original images%3$s setting below.', 'wp-smushit' ),
			esc_html( $next_gen_format_ext ),
			'<a href="#original" class="smush-close-and-dismiss-notice">',
			'</a>'
		);
		$error_message = '<p>' . $error_message . '</p>';
		?>
		<div role="alert" id="wp-smush-local-webp-convert-original-notice" class="sui-notice wp-smush-dismissible-header-notice" data-message="<?php echo esc_attr( $error_message ); ?>" aria-live="assertive"></div>
		<?php
	}

	public function get_plugin_discount() {
		return self::$plugin_discount_percent . '%';
	}

	public function load_deactivation_survey_modal() {
		$deactivation_survey_template_file = WP_SMUSH_DIR . 'app/modals/deactivation-survey.php';
		if ( ! file_exists( $deactivation_survey_template_file ) ) {
			return;
		}

		ob_start();
		include $deactivation_survey_template_file;
		// Everything escaped in all template files.
		echo ob_get_clean(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Get the notice for optimized images.
	 *
	 * @param int $optimized_count Number of optimized images.
	 * @return void
	 */
	private function get_optimized_images_notice( $optimized_count ) {
		?>
		<div id="smush-review-prompts-notice" class="notice notice-info is-dismissible" data-notice-type="smushed_hundred_images">
			<div class="smush-review-prompts-notice-logo">
				<img
					style="margin-top:-2px;margin-bottom:-3px"
					src="<?php echo esc_url( WP_SMUSH_URL . 'app/assets/images/notices/review-prompts-icon.png' ); ?>"
					srcset="<?php echo esc_url( WP_SMUSH_URL . 'app/assets/images/notices/review-prompts-icon@2x.png' ); ?> 2x"
					alt="<?php esc_html_e( 'Smush review prompts icon', 'wp-smushit' ); ?>"
				>
			</div>
			<div class="smush-review-prompts-notice-message">
				<h3>
				<?php
					/* translators: %d: optimized images count */
					printf( esc_html__( 'You’ve optimized %d images! 🎉', 'wp-smushit' ), (int) $optimized_count );
				?>
				</h3>
				<p><?php esc_html_e( 'Seeing faster speeds? We’d really appreciate a quick review. It keeps us growing and helps more WordPress users discover Smush.', 'wp-smushit' ); ?></p>
				<div id="smush-review-prompts-actions">
					<a target="_blank" href="https://wordpress.org/support/plugin/wp-smushit/reviews/?filter=5#new-post"
					class="button button-small button-primary"><?php esc_html_e( 'Rate Smush', 'wp-smushit' ); ?></a>
					<span id="smush-review-prompts-remind-later" class="button button-small" style="background-color: transparent;"><?php esc_html_e( 'Remind me later', 'wp-smushit' ); ?></span>
					<span id="smush-review-prompts-already-did" class="button button-small" style="box-shadow:unset!important;background-color: transparent;" href="#"><?php esc_html_e( 'I already did', 'wp-smushit' ); ?></span>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Get the notice for all optimized images.
	 *
	 * @return void
	 */
	private function get_all_optimized_images_notice() {
		?>
		<div id="smush-review-prompts-notice"
			class="notice notice-info is-dismissible"
			style="padding-top:12px;padding-bottom:12px;"
			data-notice-type="all_images_optimized">
			<div class="smush-review-prompts-notice-logo">
				<img
					style="margin-top:-2px;margin-bottom:-3px"
					src="<?php echo esc_url( WP_SMUSH_URL . 'app/assets/images/notices/review-prompts-icon.png' ); ?>"
					srcset="<?php echo esc_url( WP_SMUSH_URL . 'app/assets/images/notices/review-prompts-icon@2x.png' ); ?> 2x"
					alt="<?php esc_html_e( 'Smush review prompts icon', 'wp-smushit' ); ?>"
				>
			</div>
			<div class="smush-review-prompts-notice-message">
				<h3><?php esc_html_e( '100% of your images are now optimized! 🎉', 'wp-smushit' ); ?></h3>
				<p>
					<?php
					printf(
						/* translators: 1: <br>, 2: Open the link <a>, 3: Close the link </a> */
						esc_html__( 'Your site’s faster and lighter than ever. Plus, Smush will keep every new image optimized, free for life. %1$sHappy with the results? Share the love with a 5-star review on %2$sWordPress.org%3$s.', 'wp-smushit' ),
						'<br>',
						'<a href="https://wordpress.org/support/plugin/wp-smushit/reviews/?filter=5#new-post" target="_blank">',
						'</a>'
					);
					?>
				</p>
				<div id="smush-review-prompts-actions">
					<a target="_blank" href="https://wordpress.org/support/plugin/wp-smushit/reviews/?filter=5#new-post"
					class="button button-small button-primary"><?php esc_html_e( 'Rate Smush', 'wp-smushit' ); ?></a>
					<span id="smush-review-prompts-remind-later" class="button button-small" style="background-color: transparent;"><?php esc_html_e( 'Remind me later', 'wp-smushit' ); ?></span>
					<span id="smush-review-prompts-already-did" class="button button-small" style="box-shadow:unset!important;background-color: transparent;"><?php esc_html_e( 'I already did', 'wp-smushit' ); ?></span>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Get the notice for reminding later.
	 *
	 * @return void
	 */
	private function get_remind_later_notice() {
		?>
		<div id="smush-review-prompts-notice"
			class="notice notice-info is-dismissible"
			style="padding-top:8px;padding-bottom:10px;"
			data-notice-type="seven_days">
			<div class="smush-review-prompts-notice-logo">
				<img
					style="margin-top:-2px;margin-bottom:-3px"
					src="<?php echo esc_url( WP_SMUSH_URL . 'app/assets/images/notices/review-prompts-icon.png' ); ?>"
					srcset="<?php echo esc_url( WP_SMUSH_URL . 'app/assets/images/notices/review-prompts-icon@2x.png' ); ?> 2x"
					alt="<?php esc_html_e( 'Smush review prompts icon', 'wp-smushit' ); ?>"
				>
			</div>
			<div class="smush-review-prompts-notice-message">
				<h3><?php esc_html_e( 'Thanks for choosing Smush! 💙', 'wp-smushit' ); ?></h3>
				<p><?php esc_html_e( 'If your site’s feeling faster, we’d be so grateful for a quick 5-star review. It really helps us out!', 'wp-smushit' ); ?></p>
				<div id="smush-review-prompts-actions">
					<a target="_blank" href="https://wordpress.org/support/plugin/wp-smushit/reviews/?filter=5#new-post"
					class="button button-small button-primary"><?php esc_html_e( 'Rate Smush', 'wp-smushit' ); ?></a>
					<span id="smush-review-prompts-remind-later" class="button button-small" style="background-color: transparent;"><?php esc_html_e( 'Remind me later', 'wp-smushit' ); ?></span>
					<span id="smush-review-prompts-already-did" class="button button-small" style="box-shadow:unset!important;background-color: transparent;"><?php esc_html_e( 'I already did', 'wp-smushit' ); ?></span>
				</div>
			</div>
		</div>
		<?php
	}
}
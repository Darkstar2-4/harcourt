<?php


namespace WPML\TM\Jobs\Log;

use WPML\TM\Jobs\FsJobLogStorage;
use WPML\TM\Jobs\JobLog;

class Hooks implements \IWPML_Backend_Action, \IWPML_DIC_Action {

	const SUBMENU_HANDLE = 'wpml-tm-job-log';

	private $viewFactory;

	public function __construct( ViewFactory $viewFactory ) {
		$this->viewFactory = $viewFactory;
	}

	public function add_hooks() {
		add_action( 'admin_menu', [ $this, 'addLogSubmenuPage' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueueScripts' ] );
		add_action( 'wp_ajax_wpml_tm_job_log_toggle_feature', [ $this, 'handleAjaxToggle' ] );
		add_action( 'wp_ajax_wpml_tm_job_log_clear', [ $this, 'handleAjaxClear' ] );
		add_action( 'wp_ajax_wpml_tm_job_log_save_caps', [ $this, 'handleAjaxSaveCaps' ] );
		add_action( 'wp_ajax_wpml_tm_job_log_download', [ $this, 'handleAjaxDownload' ] );
		add_action( 'wp_ajax_wpml_tm_job_log_download_all', [ $this, 'handleAjaxDownloadAll' ] );
		add_action( 'wp_ajax_wpml_tm_job_log_download_last_100', [ $this, 'handleAjaxDownloadLast100' ] );
		add_action( 'wp_ajax_wpml_tm_job_log_get_request_detail', [ $this, 'handleAjaxGetRequestDetail' ] );
		add_action( 'wp_ajax_wpml_tm_job_log_search_posts', [ $this, 'handleAjaxSearchPosts' ] );
		add_action( 'wp_ajax_wpml_tm_job_log_entity_timeline', [ $this, 'handleAjaxEntityTimeline' ] );
		add_action( 'wp_ajax_wpml_tm_job_log_download_entity_timeline', [ $this, 'handleAjaxDownloadEntityTimeline' ] );
	}

	public function addLogSubmenuPage() {
		add_submenu_page(
			WPML_PLUGIN_FOLDER . '/menu/support.php',
			__( 'Translation Management Job Logs', 'sitepress' ),
			__( 'TM job logs', 'sitepress' ),
			'manage_options',
			self::SUBMENU_HANDLE,
			[ $this, 'renderPage' ]
		);
	}

	public function renderPage() {
		$tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : '';
		if ( $tab === 'by-post' ) {
			( new EntityTimelineView() )->renderPage();
			return;
		}
		$this->viewFactory->create()->renderPage();
	}

	public function enqueueScripts() {
		if ( isset( $_GET['page'] ) && $_GET['page'] === self::SUBMENU_HANDLE ) {
			wp_enqueue_style(
				'wpml-tm-job-log',
				WPML_TM_URL . '/res/css/job-log.css',
				array(),
				ICL_SITEPRESS_SCRIPT_VERSION
			);

			wp_enqueue_script(
				'support-tm-logs',
				WPML_TM_URL . '/res/js/support-tm-logs.js',
				array( 'jquery' ),
				ICL_SITEPRESS_SCRIPT_VERSION,
				true
			);
			wp_localize_script(
				'support-tm-logs',
				'wpmlTmJobLog',
				array(
					'ajaxUrl'              => admin_url( 'admin-ajax.php' ),
					'nonce'                => wp_create_nonce( 'wpml_tm_job_log' ),
					'confirmClearLogs'     => __( 'Are you sure you want to clear all job logs?', 'sitepress' ),
					'logsClearedSuccess'   => __( 'Logs cleared successfully', 'sitepress' ),
					'logsClearedFailed'    => __( 'Failed to clear logs', 'sitepress' ),
					'logsClearedError'     => __( 'Error clearing logs', 'sitepress' ),
					'loadDetailFailed'     => __( 'Failed to load request details', 'sitepress' ),
					'capsSavedReloading'   => __( 'Caps saved — reloading…', 'sitepress' ),
					'capsSaveFailed'       => __( 'Failed to save caps', 'sitepress' ),
					'entitySearchFailed'   => __( 'Search failed', 'sitepress' ),
					'entityTimelineFailed' => __( 'Failed to load timeline', 'sitepress' ),
					'entityNoResults'      => __( 'No posts found', 'sitepress' ),
				)
			);
		}
	}

	public function handleAjaxToggle() {
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'wpml_tm_job_log' ) ) {
			wp_send_json_error( 'Invalid nonce' );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions' );
		}

		$enabled = isset( $_POST['enabled'] ) ? (bool) intval( $_POST['enabled'] ) : false;
		JobLog::setIsEnabled( $enabled );

		wp_send_json_success(
            array(
				'enabled' => $enabled,
            )
        );
	}

	public function handleAjaxClear() {
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'wpml_tm_job_log' ) ) {
			wp_send_json_error( 'Invalid nonce' );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions' );
		}

		$result = JobLog::clearLogs();

		if ( $result ) {
			wp_send_json_success(
                array(
					'message' => __( 'Logs cleared successfully', 'sitepress' ),
                )
            );
		} else {
			wp_send_json_error( __( 'Failed to clear logs', 'sitepress' ) );
		}
	}

	public function handleAjaxSaveCaps() {
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'wpml_tm_job_log' ) ) {
			wp_send_json_error( 'Invalid nonce' );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions' );
		}

		$maxMb    = isset( $_POST['max_mb'] ) ? (int) $_POST['max_mb'] : 0;
		$maxFiles = isset( $_POST['max_files'] ) ? (int) $_POST['max_files'] : 0;

		if ( $maxMb < FsJobLogStorage::MIN_CONFIGURABLE_MB || $maxMb > FsJobLogStorage::MAX_CONFIGURABLE_MB ) {
			wp_send_json_error(
                sprintf(
				/* translators: 1: min MB; 2: max MB */
                    __( 'Maximum size must be between %1$d and %2$d MB.', 'sitepress' ),
                    FsJobLogStorage::MIN_CONFIGURABLE_MB,
                    FsJobLogStorage::MAX_CONFIGURABLE_MB
                )
            );
		}
		if ( $maxFiles < FsJobLogStorage::MIN_CONFIGURABLE_FILES || $maxFiles > FsJobLogStorage::MAX_CONFIGURABLE_FILES ) {
			wp_send_json_error(
                sprintf(
				/* translators: 1: min files; 2: max files */
                    __( 'Maximum file count must be between %1$d and %2$d.', 'sitepress' ),
                    FsJobLogStorage::MIN_CONFIGURABLE_FILES,
                    FsJobLogStorage::MAX_CONFIGURABLE_FILES
                )
            );
		}

		global $sitepress;
		if ( ! $sitepress ) {
			wp_send_json_error( __( 'Settings unavailable.', 'sitepress' ) );
		}

		$sitepress->set_setting( FsJobLogStorage::OPTION_MAX_MB, $maxMb );
		$sitepress->set_setting( FsJobLogStorage::OPTION_MAX_FILES, $maxFiles );
		$sitepress->save_settings();

		wp_send_json_success(
            array(
				'max_mb'    => $maxMb,
				'max_files' => $maxFiles,
            )
        );
	}

	public function handleAjaxGetRequestDetail() {
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'wpml_tm_job_log' ) ) {
			wp_send_json_error( 'Invalid nonce' );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions' );
		}

		$logUid = $this->validateLogUid( $_POST['loguid'] ?? '' );
		if ( $logUid === null ) {
			wp_send_json_error( 'Wrong loguid.' );
		}

		$view = $this->viewFactory->create();
		$html = $view->renderRequestDetail( $logUid );

		wp_send_json_success( array( 'html' => $html ) );
	}

	public function handleAjaxSearchPosts() {
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'wpml_tm_job_log' ) ) {
			wp_send_json_error( 'Invalid nonce' );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions' );
		}

		$q = isset( $_POST['q'] ) ? sanitize_text_field( wp_unslash( $_POST['q'] ) ) : '';
		if ( $q === '' ) {
			wp_send_json_success( array( 'results' => array() ) );
		}

		global $wpdb;
		$results = array();

		if ( ctype_digit( $q ) ) {
			$row = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT p.ID, p.post_title, p.post_type, t.language_code
				 FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->prefix}icl_translations t
				        ON t.element_id = p.ID AND t.element_type = CONCAT('post_', p.post_type)
				 WHERE p.ID = %d AND p.post_status NOT IN ('auto-draft','trash')
				 LIMIT 1",
                    (int) $q
                ),
                ARRAY_A
            );
			if ( $row ) {
				$results[] = $row;
			}
		} else {
			$like    = '%' . $wpdb->esc_like( $q ) . '%';
			$results = (array) $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT p.ID, p.post_title, p.post_type, t.language_code
				 FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->prefix}icl_translations t
				        ON t.element_id = p.ID AND t.element_type = CONCAT('post_', p.post_type)
				 WHERE p.post_status NOT IN ('auto-draft','trash')
				   AND p.post_title LIKE %s
				   AND ( t.source_language_code IS NULL OR t.translation_id IS NULL )
				 ORDER BY p.post_modified DESC
				 LIMIT 20",
                    $like
                ),
                ARRAY_A
            );
		}

		$shaped = array_map(
            static function ( $r ) {
				return array(
					'id'            => (int) $r['ID'],
					'title'         => (string) $r['post_title'],
					'post_type'     => (string) $r['post_type'],
					'language_code' => isset( $r['language_code'] ) ? (string) $r['language_code'] : '',
				);
			},
            $results
        );

		wp_send_json_success( array( 'results' => $shaped ) );
	}

	public function handleAjaxEntityTimeline() {
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'wpml_tm_job_log' ) ) {
			wp_send_json_error( 'Invalid nonce' );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions' );
		}

		$postId = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		if ( $postId <= 0 ) {
			wp_send_json_error( 'Wrong post id.' );
		}

		$payload = EntityTimelineFinder::build( $postId );
		if ( $payload === null ) {
			wp_send_json_error( 'Post not found.' );
		}

		$html = ( new EntityTimelineView() )->renderTimeline( $payload );
		wp_send_json_success( array( 'html' => $html ) );
	}

	public function handleAjaxDownloadEntityTimeline() {
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'wpml_tm_job_log' ) ) {
			wp_die( 'Invalid nonce' );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Insufficient permissions' );
		}

		$postId = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		if ( $postId <= 0 ) {
			wp_die( 'Wrong post id.' );
		}

		$payload = EntityTimelineFinder::build( $postId );
		if ( $payload === null ) {
			wp_die( 'Post not found' );
		}

		$filename = 'wpml-joblog-post-' . $postId . '-' . gmdate( 'Y-m-d-His' ) . '.txt';

		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Cache-Control: no-cache, must-revalidate' );
		header( 'Expires: 0' );

		while ( ob_get_level() > 0 ) {
			ob_end_flush();
		}
		@set_time_limit( 300 );

		( new EntityTimelineView() )->streamTimelineText( $payload );

		exit;
	}

	public function handleAjaxDownload() {
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'wpml_tm_job_log' ) ) {
			wp_die( 'Invalid nonce' );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Insufficient permissions' );
		}

		$logUid = $this->validateLogUid( $_POST['loguid'] ?? '' );
		if ( $logUid === null ) {
			wp_die( 'Wrong loguid.' );
		}

		$summary = $this->findSummaryByUid( $logUid );
		if ( $summary === null ) {
			wp_die( 'Log not found' );
		}

		$text     = $this->generateLogTextFromEvents( $summary );
		$filename = 'job-log-' . sanitize_file_name( $summary['requestDateTime'] ) . '.txt';

		$this->streamTextDownload( $text, $filename );
	}

	public function handleAjaxDownloadAll() {
		$this->streamBulkDownload( null, 'wpml-job-log-all' );
	}

	public function handleAjaxDownloadLast100() {
		$this->streamBulkDownload( 100, 'wpml-job-log-last-100' );
	}

	private function streamBulkDownload( $limit, $filenamePrefix ) {
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'wpml_tm_job_log' ) ) {
			wp_die( 'Invalid nonce' );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Insufficient permissions' );
		}

		$summaries = JobLog::getSummaries();
		if ( empty( $summaries ) ) {
			wp_die( 'No logs found' );
		}

		if ( $limit !== null && count( $summaries ) > $limit ) {
			$summaries = array_slice( $summaries, 0, $limit );
		}
		$summaries = array_reverse( $summaries );

		$filename = $filenamePrefix . '-' . gmdate( 'Y-m-d-His' ) . '.txt';

		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Cache-Control: no-cache, must-revalidate' );
		header( 'Expires: 0' );

		while ( ob_get_level() > 0 ) {
			ob_end_flush();
		}
		@set_time_limit( 300 );

		$this->streamBulkBody( $summaries, $limit );

		exit;
	}

	private function streamBulkBody( array $summaries, $limit ) {
		echo "##########################################\n";
		echo "# WPML Job Log Export\n";
		echo '# Generated:       ' . gmdate( 'Y-m-d\TH:i:s\Z' ) . "\n";
		echo '# Total requests:  ' . count( $summaries ) . "\n";
		if ( $limit !== null ) {
			echo '# Slice:           last ' . (int) $limit . " requests\n";
		}
		echo "##########################################\n\n";

		echo $this->generateIndexBlock( $summaries );
		echo "\n";
		flush();

		$requestNum = 0;
		foreach ( $summaries as $summary ) {
			++$requestNum;
			echo "==========================================\n";
			echo 'REQUEST ' . $requestNum . ' — ' . ( $summary['status'] ?? '' ) . "\n";
			echo "==========================================\n";
			echo $this->generateLogTextFromEvents( $summary );
			echo "\n\n";
			flush();
		}
	}

	private function generateIndexBlock( array $summaries ) {
		$text  = "INDEX\n";
		$text .= str_repeat( '=', 88 ) . "\n";
		$text .= "Look up requests by entity ID below, then jump to the matching REQUEST n block.\n";
		$text .= str_repeat( '-', 88 ) . "\n";
		$text .= sprintf(
            "%-3s %-22s %-12s %-22s %-8s %s\n",
            '#',
            'Time',
            'Status',
            'URL (suffix)',
            'pid',
            'Entities touched'
        );
		$text .= str_repeat( '-', 88 ) . "\n";

		$num = 0;
		foreach ( $summaries as $summary ) {
			++$num;
			$urlSuffix = (string) ( $summary['requestUrl'] ?? '' );
			if ( strlen( $urlSuffix ) > 22 ) {
				$urlSuffix = '…' . substr( $urlSuffix, -21 );
			}
			$text .= sprintf(
				"%-3d %-22s %-12s %-22s %-8s %s\n",
				$num,
				$summary['requestDateTime'] ?? '',
				$summary['status'] ?? '',
				$urlSuffix,
				$summary['php_pid'] ?? '—',
				$this->formatEntityIdsForIndex( $summary['entityIds'] ?? null )
			);
		}

		$text .= str_repeat( '=', 88 ) . "\n";
		return $text;
	}

	private function formatEntityIdsForIndex( $entityIds ) {
		if ( ! is_array( $entityIds ) || empty( $entityIds ) ) {
			return '—';
		}
		$parts = [];
		foreach ( $entityIds as $bucket => $values ) {
			if ( ! is_array( $values ) || empty( $values ) ) {
				continue;
			}
			$parts[] = $bucket . ':[' . implode( ',', array_map( 'strval', $values ) ) . ']';
		}
		return $parts ? implode( ' ', $parts ) : '—';
	}


	private function validateLogUid( $raw ) {
		$raw = (string) $raw;
		return preg_match( '/^[0-9a-f]{13}$/i', $raw ) ? $raw : null;
	}

	private function findSummaryByUid( $logUid ) {
		foreach ( JobLog::getSummaries() as $summary ) {
			if ( ( $summary['logUid'] ?? '' ) === $logUid ) {
				return $summary;
			}
		}
		return null;
	}

	private function streamTextDownload( $text, $filename ) {
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Cache-Control: no-cache, must-revalidate' );
		header( 'Expires: 0' );
		echo $text;
		exit;
	}


	private function generateLogTextFromEvents( array $summary ) {
		$logUid = (string) ( $summary['logUid'] ?? '' );

		$text  = 'Date/Time: ' . ( $summary['requestDateTime'] ?? '' ) . "\n";
		$text .= 'URL: ' . ( $summary['requestUrl'] ?? '' ) . "\n";
		if ( ! empty( $summary['php_pid'] ) ) {
			$text .= 'PHP PID: ' . (int) $summary['php_pid'] . "\n";
		}
		if ( ! empty( $summary['durationMs'] ) ) {
			$text .= 'Duration: ' . (int) $summary['durationMs'] . " ms\n";
		}
		$text .= 'Status: ' . ( $summary['status'] ?? '' ) . "\n";
		$text .= "\n";

		$requestParams = null;
		$groups        = [];
		$current       = null;

		foreach ( JobLog::getEvents( $logUid ) as $event ) {
			if ( ! isset( $event['type'] ) ) {
				continue;
			}

			switch ( $event['type'] ) {
				case 'request_started':
					if ( $requestParams === null && isset( $event['requestParams'] ) && is_array( $event['requestParams'] ) ) {
						$requestParams = $event['requestParams'];
					}
					break;

				case 'group_started':
					$current = [
						'label' => $event['label'] ?? '',
						'logs'  => [],
					];
					break;

				case 'log':
					if ( $current === null ) {
						$current = [
							'label' => '(orphan)',
							'logs'  => [],
						];
					}
					$current['logs'][] = $event;
					break;

				case 'group_finished':
					if ( $current !== null ) {
						$groups[] = $current;
						$current  = null;
					}
					break;

				case 'request_finished':
					if ( isset( $event['requestParams'] ) && is_array( $event['requestParams'] ) ) {
						$requestParams = $event['requestParams'];
					}
					break;
			}
		}
		if ( $current !== null ) {
			$groups[] = $current;
		}

		$text .= "Request Parameters:\n";
		$text .= "------------------\n";
		$text .= wp_json_encode( $requestParams ?: [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n\n";

		$actionNum = 0;
		foreach ( $groups as $group ) {
			++$actionNum;
			$text .= "========================================\n";
			$text .= 'Action ' . $actionNum . ': ' . ( $group['label'] ?? '' ) . "\n";
			$text .= "========================================\n\n";

			$stepNum = 0;
			foreach ( $group['logs'] as $log ) {
				++$stepNum;
				$text .= 'Step ' . $stepNum . ': ' . ( $log['id'] ?? 'N/A' );
				if ( isset( $log['elapsed_ms'] ) ) {
					$text .= ' (+' . (int) $log['elapsed_ms'] . 'ms)';
				}
				if ( isset( $log['php_pid'] ) ) {
					$text .= ' [pid:' . (int) $log['php_pid'] . ']';
				}
				$text .= "\n";
				$text .= str_repeat( '-', 40 ) . "\n";

				if ( isset( $log['data'] ) ) {
					$text .= wp_json_encode( $log['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n\n";
				}
			}
		}

		$text .= "========================================\n";
		$text .= "End of Log\n";
		$text .= "========================================\n";

		return $text;
	}
}

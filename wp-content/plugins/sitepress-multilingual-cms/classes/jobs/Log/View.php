<?php


namespace WPML\TM\Jobs\Log;

use WPML\Collect\Support\Collection;
use WPML\TM\Jobs\FsJobLogStorage;
use WPML\TM\Jobs\JobLog;

class View {

	private $summaries;

	private $isLoggingEnabled;

	public function __construct( Collection $summaries, $isLoggingEnabled ) {
		$this->summaries        = $summaries;
		$this->isLoggingEnabled = $isLoggingEnabled;
	}


	public static function renderTabs( $active ) {
		$baseUrl = admin_url( 'admin.php?page=' . Hooks::SUBMENU_HANDLE );
		$tabs    = [
			'requests' => [
				'label' => __( 'Requests', 'sitepress' ),
				'url'   => $baseUrl,
			],
			'by-post'  => [
				'label' => __( 'By post / page', 'sitepress' ),
				'url'   => $baseUrl . '&tab=by-post',
			],
		];
		echo '<nav class="nav-tab-wrapper wpml-tm-job-log-tabs">';
		foreach ( $tabs as $key => $tab ) {
			$class = 'nav-tab' . ( $active === $key ? ' nav-tab-active' : '' );
			printf(
				'<a href="%s" class="%s">%s</a>',
				esc_url( $tab['url'] ),
				esc_attr( $class ),
				esc_html( $tab['label'] )
			);
		}
		echo '</nav>';
	}


	public function renderPage() {
		$isEnabled = $this->isLoggingEnabled;
		$label     = $isEnabled ? esc_html__( 'Job logs are enabled', 'sitepress' ) : esc_html__( 'Job logs are disabled', 'sitepress' );

		$textEnabled  = esc_attr__( 'Job logs are enabled', 'sitepress' );
		$textDisabled = esc_attr__( 'Job logs are disabled', 'sitepress' );
		?>
		<div class="wrap wpml-tm-job-log">
			<h1><?php esc_html_e( 'Translation Management Job Logs', 'sitepress' ); ?></h1>
			<p class="job-log-help-text" style="margin-bottom: 0">
				<?php
				$supportUrl = __( 'https://wpml.org/forums/forum/english-support/', 'sitepress' );
				printf(
					/* translators: %1$s: opening link tag, %2$s: closing link tag */
					esc_html__( 'In order to get help, please %1$sopen a ticket in our Forum Thread (Maiya)%2$s. Enable logging, reproduce the issue, then click "Download all" to get the log file and attach it to your support ticket.', 'sitepress' ),
					'<a href="' . esc_url( $supportUrl ) . '" target="_blank" rel="noopener noreferrer">',
					'</a>'
				);
				?>
			</p>
			<?php self::renderTabs( 'requests' ); ?>
			<div class="job-log-toolbar">
				<div class="job-log-settings-toggle-wrapper">
					<label class="job-log-settings-toggle">
						<input type="checkbox" class="job-log-toggle-input" id="job-log-feature-toggle" <?php checked( $isEnabled ); ?> />
						<span class="job-log-toggle-slider"></span>
					</label>
					<span class="job-log-toggle-label" data-textenabled="<?php echo $textEnabled; ?>" data-textdisabled="<?php echo $textDisabled; ?>"><?php echo $label; ?></span>
					<span class="job-log-toggle-loader spinner" style="display: none; float: none; margin: 0 0 0 8px;"></span>
				</div>
				<div class="job-log-clear-wrapper">
					<button type="button" class="button" id="job-log-download-all-button"><?php esc_html_e( 'Download all', 'sitepress' ); ?></button>
					<button type="button" class="button" id="job-log-download-last-100-button"><?php esc_html_e( 'Download last 100', 'sitepress' ); ?></button>
					<button type="button" class="button" id="job-log-clear-button"><?php esc_html_e( 'Clear logs', 'sitepress' ); ?></button>
					<?php
					$totalBytes = FsJobLogStorage::getTotalSize();
					$capBytes   = FsJobLogStorage::getMaxTotalBytes();
					$capFiles   = FsJobLogStorage::getMaxStoredRequestsCount();
					$capMb      = (int) round( $capBytes / 1024 / 1024 );
					$capRatio   = $capBytes > 0 ? $totalBytes / $capBytes : 0;
					$capState   = $capRatio >= 0.9 ? 'is-critical' : ( $capRatio >= 0.7 ? 'is-warning' : '' );
					$sizeLabel  = FsJobLogStorage::formatBytes( $totalBytes ) . ' / ' . FsJobLogStorage::formatBytes( $capBytes );
					$capTooltip = sprintf(
						/* translators: 1: cap size formatted (e.g. "50.0 MB"); 2: file count */
						__( 'Total job-log disk usage. Caps: %1$s / %2$d files. Click to change.', 'sitepress' ),
						FsJobLogStorage::formatBytes( $capBytes ),
						$capFiles
					);
					?>
					<button type="button"
					        class="job-log-total-size <?php echo esc_attr( $capState ); ?>"
					        id="job-log-cap-trigger"
					        title="<?php echo esc_attr( $capTooltip ); ?>"
						        data-current-mb="<?php echo esc_attr( (string) $capMb ); ?>"
						        data-current-files="<?php echo esc_attr( (string) $capFiles ); ?>"
						        data-min-mb="<?php echo esc_attr( (string) FsJobLogStorage::MIN_CONFIGURABLE_MB ); ?>"
						        data-max-mb="<?php echo esc_attr( (string) FsJobLogStorage::MAX_CONFIGURABLE_MB ); ?>"
						        data-min-files="<?php echo esc_attr( (string) FsJobLogStorage::MIN_CONFIGURABLE_FILES ); ?>"
						        data-max-files="<?php echo esc_attr( (string) FsJobLogStorage::MAX_CONFIGURABLE_FILES ); ?>"><?php echo esc_html( $sizeLabel ); ?></button>
					<span class="job-log-clear-loader spinner" style="display: none; float: none; margin: 0 0 0 8px;"></span>
					<span class="job-log-clear-message" style="margin-left: 10px; color: #1C7D6B;"></span>
				</div>
			</div>
			<table class="wp-list-table widefat fixed striped posts job-log-summary-table">
				<thead><?php $this->renderTableHeader(); ?></thead>
				<tbody id="the-list">
				<?php
				if ( $this->summaries->isEmpty() ) {
					$this->renderEmptyTable();
				} else {
					$this->summaries->each( [ $this, 'renderSummaryRow' ] );
				}
				?>
				</tbody>
				<tfoot><?php $this->renderTableHeader(); ?></tfoot>
			</table>

			<div class="job-log-cap-modal-backdrop" id="job-log-cap-modal" style="display:none" aria-hidden="true">
				<div class="job-log-cap-modal" role="dialog" aria-modal="true" aria-labelledby="job-log-cap-modal-title">
					<h2 id="job-log-cap-modal-title"><?php esc_html_e( 'Job-log storage limits', 'sitepress' ); ?></h2>
					<div class="job-log-cap-modal-field">
						<label for="job-log-cap-mb"><?php esc_html_e( 'Maximum total size (MB)', 'sitepress' ); ?></label>
						<input type="number" id="job-log-cap-mb" step="1" />
						<span class="job-log-cap-modal-range" id="job-log-cap-mb-range"></span>
					</div>
					<div class="job-log-cap-modal-field">
						<label for="job-log-cap-files"><?php esc_html_e( 'Maximum stored requests', 'sitepress' ); ?></label>
						<input type="number" id="job-log-cap-files" step="1" />
						<span class="job-log-cap-modal-range" id="job-log-cap-files-range"></span>
					</div>
					<div class="job-log-cap-modal-error" id="job-log-cap-modal-error" style="display:none"></div>
					<div class="job-log-cap-modal-actions">
						<button type="button" class="button" id="job-log-cap-cancel"><?php esc_html_e( 'Cancel', 'sitepress' ); ?></button>
						<button type="button" class="button button-primary" id="job-log-cap-save"><?php esc_html_e( 'Save', 'sitepress' ); ?></button>
						<span class="job-log-cap-modal-loader spinner" style="float:none;margin:0 0 0 8px;display:none"></span>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	private function renderTableHeader() {
		?>
		<tr>
			<th class="job-log-col-status" style="width: 110px"><?php esc_html_e( 'Status', 'sitepress' ); ?></th>
			<th class="job-log-col-when"><?php esc_html_e( 'When', 'sitepress' ); ?></th>
			<th class="job-log-col-endpoint"><?php esc_html_e( 'Endpoint', 'sitepress' ); ?></th>
			<th class="job-log-col-duration" style="width: 100px"><?php esc_html_e( 'Duration', 'sitepress' ); ?></th>
			<th class="job-log-col-pid" style="width: 80px"><?php esc_html_e( 'PID', 'sitepress' ); ?></th>
			<th class="job-log-col-actions" style="width: 220px"><?php esc_html_e( 'Actions', 'sitepress' ); ?></th>
		</tr>
		<?php
	}

	private function renderEmptyTable() {
		?>
		<tr>
			<td colspan="6"><?php esc_html_e( 'No entries', 'sitepress' ); ?></td>
		</tr>
		<?php
	}

	public function renderSummaryRow( $summary, $i ) {
		$logUid          = (string) ( $summary['logUid'] ?? '' );
		$requestUrl      = (string) ( $summary['requestUrl'] ?? '' );
		$requestDateTime = (string) ( $summary['requestDateTime'] ?? '' );
		$status          = (string) ( $summary['status'] ?? '' );
		$hasErrorLogs    = ! empty( $summary['hasErrorLogs'] );
		$durationMs      = $summary['durationMs'] ?? null;
		$phpPid          = $summary['php_pid'] ?? null;
		$endpointLabel   = $this->classifyEndpoint( $requestUrl );

		$detailPanelId = 'job-log-detail-' . esc_attr( $logUid );

		$relativeWhen = $this->renderRelativeTime( $summary, $requestDateTime );

		?>
		<tr class="job-log-summary-row" data-loguid="<?php echo esc_attr( $logUid ); ?>">
			<td><?php echo $this->renderStatusBadge( $status, $hasErrorLogs ); ?></td>
			<td><?php echo $relativeWhen; ?></td>
			<td><?php echo esc_html( $endpointLabel ); ?></td>
			<td><?php echo $this->renderDuration( $durationMs, $status ); ?></td>
			<td><?php echo $phpPid !== null ? esc_html( (string) $phpPid ) : '—'; ?></td>
			<td>
				<button class="button tm-log-view"
				        data-loguid="<?php echo esc_attr( $logUid ); ?>"
				        data-target="<?php echo esc_attr( $detailPanelId ); ?>"
				        data-textopen="<?php esc_attr_e( 'View', 'sitepress' ); ?>"
				        data-textclose="<?php esc_attr_e( 'Hide', 'sitepress' ); ?>">
					<?php esc_html_e( 'View', 'sitepress' ); ?>
				</button>
				<button class="button tm-log-download" data-loguid="<?php echo esc_attr( $logUid ); ?>">
					<?php esc_html_e( 'Download', 'sitepress' ); ?>
				</button>
			</td>
		</tr>
		<tr class="job-log-detail-row" id="<?php echo esc_attr( $detailPanelId ); ?>" style="display: none">
			<td colspan="6" class="job-log-detail-cell">
				<div class="job-log-detail-content" data-loaded="0">
					<em><?php esc_html_e( 'Click View to load details…', 'sitepress' ); ?></em>
				</div>
			</td>
		</tr>
		<?php
	}

	private function renderRelativeTime( array $summary, $requestDateTime ) {
		$age = isset( $summary['ageSeconds'] ) ? (int) $summary['ageSeconds'] : null;

		if ( $age === null || $age < 0 ) {
			return esc_html( $requestDateTime );
		}

		$now      = time();
		$fromTime = max( 0, $now - $age );
		$relative = human_time_diff( $fromTime, $now );
		$label = sprintf( __( '%s ago' ), $relative );

		return '<span title="' . esc_attr( $requestDateTime ) . '">' . esc_html( $label ) . '</span>';
	}

	private function renderStatusBadge( $status, $hasErrorLogs ) {
		$colour = '#666';
		$text   = $status;

		switch ( $status ) {
			case FsJobLogStorage::STATUS_COMPLETE:
				$colour = $hasErrorLogs ? '#c8471f' : '#1C7D6B';
				$text   = $hasErrorLogs ? __( 'errors', 'sitepress' ) : __( 'complete', 'sitepress' );
				break;
			case FsJobLogStorage::STATUS_IN_PROGRESS:
				$colour = '#2F7D92';
				$text   = __( 'in progress', 'sitepress' );
				break;
			case FsJobLogStorage::STATUS_STUCK:
				$colour = '#996415';
				$text   = __( 'stuck', 'sitepress' );
				break;
			case FsJobLogStorage::STATUS_LEGACY:
				$colour = $hasErrorLogs ? '#c8471f' : '#999';
				$text   = $hasErrorLogs ? __( 'legacy/errors', 'sitepress' ) : __( 'legacy', 'sitepress' );
				break;
			case FsJobLogStorage::STATUS_ABORTED:
				$colour = '#c8471f';
				$text   = __( 'aborted', 'sitepress' );
				break;
		}

		return sprintf(
			'<span class="job-log-status" style="background:%1$s">%2$s</span>',
			esc_attr( $colour ),
			esc_html( $text )
		);
	}

	private function renderDuration( $durationMs, $status ) {
		if ( $durationMs === null ) {
			if ( $status === FsJobLogStorage::STATUS_STUCK || $status === FsJobLogStorage::STATUS_IN_PROGRESS ) {
				return '<em>' . esc_html__( '—', 'sitepress' ) . '</em>';
			}
			return '—';
		}
		return esc_html( number_format( (int) $durationMs ) . ' ms' );
	}

	private function classifyEndpoint( $url ) {
		if ( $this->endsWith( $url, 'send-to-translation' ) ) {
			return __( 'Send', 'sitepress' );
		}
		if ( $this->endsWith( $url, 'sync' ) ) {
			return __( 'Sync', 'sitepress' );
		}
		if ( $this->endsWith( $url, 'download' ) ) {
			return __( 'Download', 'sitepress' );
		}
		return __( 'Other', 'sitepress' );
	}

	private function endsWith( $haystack, $needle ) {
		$needleLen = strlen( $needle );
		if ( $needleLen === 0 ) {
			return true;
		}
		return substr( (string) $haystack, -$needleLen ) === $needle;
	}


	public function renderRequestDetail( $logUid ) {
		$collected = $this->collectGroups( $logUid );

		if ( $collected['started'] === null ) {
			return '<div class="job-log-detail-missing">'
				. esc_html__( 'Request not found.', 'sitepress' )
				. '</div>';
		}

		ob_start();
		$this->renderDetailContents( $logUid, $collected );
		return ob_get_clean();
	}

	private function renderDetailContents( $logUid, array $collected ) {
		$started      = $collected['started'];
		$finished     = $collected['finished'];
		$groups       = $collected['groups'];
		$hasErrorLogs = $collected['hasErrorLogs'];
		$wasFinalised = is_array( $finished );

		$requestUrl      = $started['requestUrl'] ?? '';
		$requestParams   = is_array( $finished ) && isset( $finished['requestParams'] ) && is_array( $finished['requestParams'] )
			? $finished['requestParams']
			: ( isset( $started['requestParams'] ) && is_array( $started['requestParams'] ) ? $started['requestParams'] : [] );
		$requestDateTime = $started['requestDateTime'] ?? '';
		$phpPid          = $started['php_pid'] ?? null;
		$startedMs       = $started['timestamp_utc'] ?? '';
		$durationMs      = null;
		if ( isset( $started['ts'], $finished['ts'] ) ) {
			$durationMs = (int) round( ( (float) $finished['ts'] - (float) $started['ts'] ) * 1000 );
		}
		?>
		<div class="job-log-detail-header">
			<div><strong><?php esc_html_e( 'URL', 'sitepress' ); ?>:</strong> <code><?php echo esc_html( $requestUrl ); ?></code></div>
			<div><strong><?php esc_html_e( 'Started', 'sitepress' ); ?>:</strong> <?php echo esc_html( $startedMs !== '' ? $startedMs : $requestDateTime ); ?></div>
			<?php if ( $durationMs !== null ) : ?>
				<div><strong><?php esc_html_e( 'Duration', 'sitepress' ); ?>:</strong> <?php echo esc_html( number_format( $durationMs ) . ' ms' ); ?></div>
			<?php elseif ( ! $wasFinalised ) : ?>
				<div style="color:#c8471f"><strong><?php esc_html_e( 'Did not complete', 'sitepress' ); ?></strong> — <?php esc_html_e( 'request_finished was never written; the worker likely crashed mid-request.', 'sitepress' ); ?></div>
			<?php endif; ?>
			<?php if ( $phpPid !== null ) : ?>
				<div><strong><?php esc_html_e( 'PHP PID', 'sitepress' ); ?>:</strong> <?php echo esc_html( (string) $phpPid ); ?></div>
			<?php endif; ?>
			<?php if ( $hasErrorLogs ) : ?>
				<div style="color:#c8471f"><strong><?php esc_html_e( 'This request contains error logs.', 'sitepress' ); ?></strong></div>
			<?php endif; ?>
		</div>

		<div class="job-log-detail-params">
			<strong><?php esc_html_e( 'Request parameters', 'sitepress' ); ?>:</strong>
			<pre><?php echo esc_html( (string) wp_json_encode( $requestParams, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ); ?></pre>
		</div>

		<?php
		if ( empty( $groups ) ) {
			echo '<em>' . esc_html__( 'No groups recorded.', 'sitepress' ) . '</em>';
			return;
		}

		$i = 0;
		foreach ( $groups as $group ) {
			++$i;
			$this->renderGroup( $i, $group );
		}
	}

	private function renderGroup( $index, array $group ) {
		echo '<div class="job-log-group">';
		echo '<strong>'
			. esc_html__( 'Action', 'sitepress' ) . ' ' . (int) $index . ': '
			. esc_html( (string) ( $group['label'] ?? '' ) )
			. '</strong><br>';
		echo '<div class="job-log-group-body">';

		$groupId = $group['groupId'] ?? null;
		$logs    = isset( $group['logs'] ) && is_array( $group['logs'] ) ? $group['logs'] : [];

		if ( JobLog::isSendJobsLogsGroup( $groupId ) ) {
			$this->renderSendJobsLogs( $logs );
		} else {
			$j = 0;
			while ( $j < count( $logs ) ) {
				$j = $this->renderLog( $j, $logs, $logs[ $j ] );
			}
		}

		echo '</div>';
		echo '</div>';
	}

	private function collectGroups( $logUid ) {
		$started      = null;
		$finished     = null;
		$hasErrorLogs = false;
		$groups       = [];
		$current      = null;

		foreach ( JobLog::getEvents( $logUid ) as $event ) {
			if ( ! isset( $event['type'] ) ) {
				continue;
			}

			switch ( $event['type'] ) {
				case 'request_started':
					$started = $event;
					break;

				case 'group_started':
					$current = [
						'groupId' => $event['groupId'] ?? null,
						'label'   => $event['label'] ?? '',
						'data'    => isset( $event['data'] ) && is_array( $event['data'] ) ? $event['data'] : [],
						'logs'    => [],
					];
					break;

				case 'log':
					if ( $current === null ) {
						$current = [
							'groupId' => $event['groupId'] ?? null,
							'label'   => '',
							'data'    => [],
							'logs'    => [],
						];
					}
					$current['logs'][] = $event;
					if ( isset( $event['logType'] ) && (int) $event['logType'] === 1 ) {
						$hasErrorLogs = true;
					}
					break;

				case 'group_finished':
					if ( $current !== null ) {
						$groups[] = $current;
						$current  = null;
					}
					break;

				case 'request_finished':
					$finished = $event;
					if ( isset( $event['hasErrorLogs'] ) ) {
						$hasErrorLogs = (bool) $event['hasErrorLogs'];
					}
					break;
			}
		}

		if ( $current !== null ) {
			$groups[] = $current;
		}

		if ( is_array( $finished ) && isset( $finished['stringIdsByBatchId'] ) && is_array( $finished['stringIdsByBatchId'] ) ) {
			$this->applyStringBatchEnrichment( $groups, $finished['stringIdsByBatchId'] );
		}

		return [
			'started'      => $started,
			'finished'     => $finished,
			'groups'       => $groups,
			'hasErrorLogs' => $hasErrorLogs,
		];
	}

	private function applyStringBatchEnrichment( array &$groups, array $stringIdsByBatchId ) {
		$alreadyAdded = [];
		foreach ( $groups as &$group ) {
			if ( ! isset( $group['logs'] ) || ! is_array( $group['logs'] ) ) {
				continue;
			}
			foreach ( $group['logs'] as &$log ) {
				if ( ! isset( $log['type'] ) || $log['type'] !== 'st-batch' || ! isset( $log['element_id'] ) ) {
					continue;
				}
				$batchId = (int) $log['element_id'];
				if ( in_array( $batchId, $alreadyAdded, true ) ) {
					continue;
				}
				if ( isset( $stringIdsByBatchId[ $batchId ] ) ) {
					$log['string_ids_in_batch'] = $stringIdsByBatchId[ $batchId ];
					$alreadyAdded[]             = $batchId;
				}
			}
			unset( $log );
		}
		unset( $group );
	}


	private function renderSendJobsLogs( $logs ) {
		$i = 0;
		while ( $i < count( $logs ) ) {
			$log = $logs[ $i ];
			if ( ! isset( $log['element_id'] ) ) {
				$i = $this->renderLog( $i, $logs, $log );
				continue;
			}

			$elementId = $log['element_id'];
			$postfix   = '';

			if ( isset( $log['string_ids_in_batch'] ) ) {
				$postfix = ', (string ids in batch: ' . implode( ', ', $log['string_ids_in_batch'] ) . ')';
			}

			$sectionId = 'element-' . $elementId . '-' . uniqid();
			echo '<div>';
			echo '<span class="job-log-label job-log-sublabel">' . $this->getStepHtml( $i ) . __( 'Processing element with id', 'sitepress' ) . ' `' . $elementId . '`' . $postfix . '`</span> ';
			echo $this->getToggleButtonHtml( $sectionId );
			echo '</div>';
			echo '<div id="' . esc_attr( $sectionId ) . '" data-element-id="' . esc_attr( $elementId ) . '" style="display: none; padding-left: 20px;">';
			do {
				$i = $this->renderSendJobsLogForElement( $i, $logs, $logs[ $i ] );
			} while (
				$i < count( $logs )
				&& isset( $logs[ $i ]['element_id'] )
				&& $logs[ $i ]['element_id'] === $elementId
			);
			echo '</div>';
		}
	}

	private function renderSendJobsLogForElement( $i, $logs, $log ) {
		if ( ! isset( $log['target_lang'] ) ) {
			$i = $this->renderLog( $i, $logs, $log );
			return $i;
		}

		$elementId  = $log['element_id'];
		$targetLang = $log['target_lang'];

		$sectionId = 'target-' . $elementId . '-' . $targetLang . '-' . uniqid();
		echo '<div>';
		echo '<span class="job-log-label job-log-sublabel">' . $this->getStepHtml( $i ) . __( 'Processing target language', 'sitepress' ) . ' `' . $targetLang . '`</span> ';
		echo $this->getToggleButtonHtml( $sectionId );
		echo '</div>';
		echo '<div id="' . esc_attr( $sectionId ) . '" data-element-id="' . esc_attr( $elementId ) . '" data-target-lang="' . esc_attr( $targetLang ) . '" style="display: none; padding-left: 20px">';
		do {
			$i = $this->renderLog( $i, $logs, $logs[ $i ] );
		} while (
			$i < count( $logs )
			&& isset( $logs[ $i ]['element_id'] )
			&& $logs[ $i ]['element_id'] === $elementId
			&& isset( $logs[ $i ]['target_lang'] )
			&& $logs[ $i ]['target_lang'] === $targetLang
		);
		echo '</div>';

		return $i;
	}

	private function renderLog( $i, $logs, $log ) {
		if ( ! isset( $log['apiCall'] ) ) {
			$this->renderLogItem( $log, $i );
			return ++$i;
		}

		$url      = $log['apiCall'];
		$urlParts = explode( '?', $url );
		$url      = $urlParts[0];

		$sectionId = 'target-log' . uniqid();
		echo '<div>';
		echo '<span class="job-log-label job-log-sublabel">' . $this->getStepHtml( $i, $this->isErrorEvent( $log ) ) . __( 'Api call to url', 'sitepress' ) . ' `' . $url . '`</span> ';
		echo $this->getToggleButtonHtml( $sectionId );
		echo '</div>';
		echo '<div id="' . esc_attr( $sectionId ) . '" style="display: none">';
		do {
			$this->renderLogItem(
                $logs[ $i ],
                $i,
                'padding-left: 20px',
                [
					'padding-left' => '40px',
				]
            );
			++$i;
		} while (
			$i < count( $logs )
			&& isset( $logs[ $i ]['apiCall'] )
		);
		echo '</div>';

		return $i;
	}

	private function renderLogItem( $log, $i, $rootCss = '', $logCss = [] ) {
		$defaultLogCss = [
			'padding-left' => '20px',
		];
		$logCss        = array_merge( $defaultLogCss, $logCss );

		$logCssStr = '';
		foreach ( $logCss as $key => $value ) {
			$logCssStr .= $key . ': ' . $value . ';';
		}

		$sectionId = 'target-log-item' . $i . uniqid();

		$elapsedBadge = '';
		if ( isset( $log['elapsed_ms'] ) ) {
			$elapsedBadge = ' <span class="job-log-elapsed">+' . esc_html( (string) (int) $log['elapsed_ms'] ) . 'ms</span>';
		}

		$pidBadge = '';
		if ( isset( $log['php_pid'] ) ) {
			$pidBadge = ' <span class="job-log-pid">pid:' . esc_html( (string) (int) $log['php_pid'] ) . '</span>';
		}

		echo '<div style="' . $rootCss . '">';
		echo '<span class="job-log-label job-log-sublabel">' . $this->getStepHtml( $i, $this->isErrorEvent( $log ) ) . esc_html( (string) ( $log['id'] ?? '' ) ) . $elapsedBadge . $pidBadge . '</span> ';
		echo '<button class="button tm-log-toggle tm-log-stack-trace-toggle" data-target="' . esc_attr( $sectionId ) . '">' . esc_html__( 'View trace', 'sitepress' ) . '</button>';
		echo '</div>';
		echo '<div id="' . esc_attr( $sectionId ) . '" style="display: none">';
		echo '<pre>';
		echo esc_html( (string) wp_json_encode( $this->maybeJsonStringsToArray( $log['trace'] ?? [] ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		echo '</pre>';
		echo '</div>';
		echo '<pre style="' . $logCssStr . '">';
		echo esc_html( (string) wp_json_encode( $this->maybeJsonStringsToArray( $log['data'] ?? [] ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		echo '</pre>';
	}

	private function isErrorEvent( $event ) {
		return isset( $event['logType'] ) && (int) $event['logType'] === JobLog::LOG_TYPE_ERROR;
	}

	private function maybeJsonStringsToArray( $data ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}
		foreach ( $data as $key => $value ) {
			if ( $key === 'body' && is_string( $value ) ) {
				$decoded = json_decode( $value, true );
				if ( json_last_error() === JSON_ERROR_NONE ) {
					$data[ $key ] = $decoded;
				}
			}

			if ( is_array( $value ) ) {
				$data[ $key ] = $this->maybeJsonStringsToArray( $value );
			}
		}

		return $data;
	}

	private function getToggleButtonHtml( $section_id ) {
		$text_open  = esc_attr__( 'Open', 'sitepress' );
		$text_close = esc_attr__( 'Close', 'sitepress' );
		$section_id = esc_attr( $section_id );

		return sprintf(
			'<button class="button tm-log-toggle" data-textopen="%1$s" data-textclose="%2$s" data-target="%3$s">%4$s</button>',
			$text_open,
			$text_close,
			$section_id,
			esc_html__( 'Open', 'sitepress' )
		);
	}

	private function getStepHtml( $i, $error = false ) {
		$class = $error ? ' job-log-step-error' : '';
		return '<span class="job-log-step' . $class . '">' . __( 'Step', 'sitepress' ) . ' ' . ( $i + 1 ) . ':' . '</span> ';
	}
}

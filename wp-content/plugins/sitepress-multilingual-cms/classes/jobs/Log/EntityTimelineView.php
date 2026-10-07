<?php


namespace WPML\TM\Jobs\Log;

use WPML\TM\Jobs\JobLog;

class EntityTimelineView {

	public function renderPage() {
		?>
		<div class="wrap wpml-tm-job-log">
			<h1><?php esc_html_e( 'Translation Management Job Logs', 'sitepress' ); ?></h1>
			<?php View::renderTabs( 'by-post' ); ?>

			<div class="job-log-entity-finder">
				<label for="job-log-entity-picker" class="job-log-entity-picker-label">
					<?php esc_html_e( 'Pick a post or page to see its translation history from joblog events:', 'sitepress' ); ?>
				</label>
				<div class="job-log-entity-picker-wrap">
					<input
						type="text"
						id="job-log-entity-picker"
						class="job-log-entity-picker"
						autocomplete="off"
						placeholder="<?php esc_attr_e( 'Type post ID or title…', 'sitepress' ); ?>"
					/>
					<div class="job-log-entity-picker-dropdown" id="job-log-entity-picker-dropdown" style="display:none"></div>
					<span class="job-log-entity-picker-loader spinner" style="display:none;float:none;margin:0 0 0 8px"></span>
				</div>
			</div>

			<div class="job-log-entity-content" id="job-log-entity-content">
				<p class="job-log-entity-hint">
					<?php esc_html_e( 'Select a post above to load its timeline. Events from every logged request that touched the post, its trid, or its jobs are merged chronologically.', 'sitepress' ); ?>
				</p>
			</div>
		</div>
		<?php
	}

	public function renderTimeline( array $payload ) {
		ob_start();

		$state        = isset( $payload['state'] ) && is_array( $payload['state'] ) ? $payload['state'] : [];
		$events       = isset( $payload['events'] ) && is_array( $payload['events'] ) ? $payload['events'] : [];
		$eventTotal   = isset( $payload['event_total'] ) ? (int) $payload['event_total'] : count( $events );
		$truncated    = ! empty( $payload['truncated'] );
		$requestCount = isset( $payload['request_count'] ) ? (int) $payload['request_count'] : 0;

		$this->renderStatePanel( $state );
		$this->renderTimelineBody( $events, $requestCount, $eventTotal, $truncated );

		return ob_get_clean();
	}

	private function renderStatePanel( array $state ) {
		$postId       = isset( $state['post_id'] ) ? (int) $state['post_id'] : 0;
		$title        = (string) ( $state['post_title'] ?? '' );
		$postType     = (string) ( $state['post_type'] ?? '' );
		$sourceLang   = (string) ( $state['source_lang'] ?? '' );
		$modified     = (string) ( $state['modified_utc'] ?? '' );
		$trid         = isset( $state['trid'] ) ? (int) $state['trid'] : 0;
		$translations = isset( $state['translations'] ) && is_array( $state['translations'] ) ? $state['translations'] : [];
		$activeJobs   = isset( $state['active_jobs'] ) && is_array( $state['active_jobs'] ) ? $state['active_jobs'] : [];

		?>
		<div class="job-log-entity-state">
			<div class="job-log-entity-state-header">
				<div class="job-log-entity-state-title-row">
					<strong>#<?php echo esc_html( (string) $postId ); ?></strong>
					<?php if ( $title !== '' ) : ?>
						— <span class="job-log-entity-state-title"><?php echo esc_html( $title ); ?></span>
					<?php endif; ?>
					<span class="job-log-entity-state-meta">
						(<?php echo esc_html( $postType ); ?>, <?php echo esc_html( $sourceLang ); ?>)
					</span>
				</div>
				<button type="button"
				        class="button job-log-entity-download-button"
				        data-post-id="<?php echo esc_attr( (string) $postId ); ?>"
				        title="<?php esc_attr_e( 'Download this post\'s full timeline as text', 'sitepress' ); ?>">
					<?php esc_html_e( 'Download timeline', 'sitepress' ); ?>
				</button>
			</div>
			<div class="job-log-entity-state-row">
				<?php if ( $modified ) : ?>
					<span><strong><?php esc_html_e( 'Modified:', 'sitepress' ); ?></strong> <?php echo esc_html( $modified ); ?></span>
				<?php endif; ?>
				<?php if ( $trid ) : ?>
					<span><strong>trid:</strong> <?php echo esc_html( (string) $trid ); ?></span>
				<?php endif; ?>
			</div>

			<div class="job-log-entity-state-block">
				<strong><?php esc_html_e( 'Translations (current state):', 'sitepress' ); ?></strong>
				<?php if ( empty( $translations ) ) : ?>
					<em><?php esc_html_e( 'none', 'sitepress' ); ?></em>
				<?php else : ?>
					<ul>
						<?php foreach ( $translations as $tr ) : ?>
							<li>
								<?php echo esc_html( $tr['language_code'] ?? '?' ); ?>
								→ <?php echo esc_html( ( isset( $tr['translated_post_id'] ) && $tr['translated_post_id'] ) ? '#' . $tr['translated_post_id'] : __( 'no translated post', 'sitepress' ) ); ?>
								<?php if ( ! empty( $tr['status'] ) ) : ?>
									(<?php echo esc_html( $tr['status'] ); ?>)
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</div>

			<div class="job-log-entity-state-block">
				<strong><?php esc_html_e( 'In-flight jobs (translated = 0):', 'sitepress' ); ?></strong>
				<?php if ( empty( $activeJobs ) ) : ?>
					<em><?php esc_html_e( 'none', 'sitepress' ); ?></em>
				<?php else : ?>
					<ul>
						<?php foreach ( $activeJobs as $j ) : ?>
							<li>
								<?php
								/* translators: 1: job id, 2: target language, 3: status int, 4: age */
								echo esc_html(
                                    sprintf(
                                        __( '#%1$d (%2$s, status %3$s, age %4$s)', 'sitepress' ),
                                        (int) ( $j['job_id'] ?? 0 ),
                                        (string) ( $j['target_lang'] ?? '?' ),
                                        (string) ( $j['status'] ?? '?' ),
                                        self::formatAge( $j['age_seconds'] ?? null )
                                    )
                                );
								?>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	private function renderTimelineBody( array $events, $requestCount, $eventTotal, $truncated ) {
		?>
		<div class="job-log-entity-timeline">
			<div class="job-log-entity-timeline-header">
				<?php
				printf(
					/* translators: 1: total events, 2: request count */
					esc_html__( 'Timeline — %1$d events across %2$d requests', 'sitepress' ),
					(int) $eventTotal,
					(int) $requestCount
				);
				if ( $truncated ) {
					echo ' <span class="job-log-entity-timeline-truncated">'
						. esc_html__( '(truncated — only the most recent events shown)', 'sitepress' )
						. '</span>';
				}
				?>
			</div>
			<?php if ( empty( $events ) ) : ?>
				<p><em><?php esc_html_e( 'No events found for this post in the joblog.', 'sitepress' ); ?></em></p>
			<?php else : ?>
				<ol class="job-log-entity-timeline-list">
					<?php foreach ( $events as $event ) : ?>
						<?php $this->renderEventRow( $event ); ?>
					<?php endforeach; ?>
				</ol>
			<?php endif; ?>
		</div>
		<?php
	}

	private function renderEventRow( array $event ) {
		$isError = isset( $event['logType'] ) && (int) $event['logType'] === JobLog::LOG_TYPE_ERROR;
		$ts      = (string) ( $event['timestamp_utc'] ?? '' );
		$logUid  = (string) ( $event['__logUid'] ?? '' );
		$type    = (string) ( $event['type'] ?? '' );

		$isEnvelope = false;
		switch ( $type ) {
			case 'log':
				$label = (string) ( $event['id'] ?? '' );
				break;
			case 'group_started':
				$groupLabel = (string) ( $event['label'] ?? '' );
				$label      = '▶ group: ' . ( $groupLabel !== '' ? $groupLabel : ( 'id ' . ( $event['groupId'] ?? '?' ) ) );
				$isEnvelope = true;
				break;
			case 'group_finished':
				$label      = '◀ end of group ' . ( $event['groupId'] ?? '?' );
				$isEnvelope = true;
				break;
			case 'request_started':
				$label      = '▶ request started';
				$isEnvelope = true;
				break;
			case 'request_finished':
				$label      = ! empty( $event['aborted'] )
					? '◀ request aborted'
					: '◀ request finished';
				$isEnvelope = true;
				break;
			default:
				$label      = $type !== '' ? '[' . $type . ']' : '(unknown event)';
				$isEnvelope = true;
				break;
		}

		$dataJson = isset( $event['data'] )
			? wp_json_encode( $event['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
			: '';

		$classes = 'job-log-entity-event';
		if ( $isError ) {
			$classes .= ' is-error';
		}
		if ( $isEnvelope ) {
			$classes .= ' is-envelope';
		}

		?>
		<li class="<?php echo esc_attr( $classes ); ?>">
			<div class="job-log-entity-event-head">
				<span class="job-log-entity-event-time"><?php echo esc_html( $ts ); ?></span>
				<?php if ( $logUid ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . Hooks::SUBMENU_HANDLE ) ); ?>" class="job-log-entity-event-req" title="<?php esc_attr_e( 'Originating request', 'sitepress' ); ?>">
						req:<?php echo esc_html( substr( $logUid, 0, 6 ) ); ?>
					</a>
				<?php endif; ?>
				<span class="job-log-entity-event-id"><?php echo esc_html( $label ); ?></span>
				<?php if ( $isError ) : ?>
					<span class="job-log-entity-event-badge"><?php esc_html_e( 'error', 'sitepress' ); ?></span>
				<?php endif; ?>
			</div>
			<?php if ( $dataJson && $dataJson !== '[]' ) : ?>
				<pre class="job-log-entity-event-data"><?php echo esc_html( $dataJson ); ?></pre>
			<?php endif; ?>
		</li>
		<?php
	}

	private static function formatAge( $seconds ) {
		if ( $seconds === null ) {
			return '?';
		}
		$s = (int) $seconds;
		if ( $s < 60 ) {
			return $s . 's';
		}
		if ( $s < 3600 ) {
			return floor( $s / 60 ) . 'm';
		}
		return floor( $s / 3600 ) . 'h';
	}

	public function streamTimelineText( array $payload ) {
		$state        = isset( $payload['state'] ) && is_array( $payload['state'] ) ? $payload['state'] : [];
		$events       = isset( $payload['events'] ) && is_array( $payload['events'] ) ? $payload['events'] : [];
		$eventTotal   = isset( $payload['event_total'] ) ? (int) $payload['event_total'] : count( $events );
		$truncated    = ! empty( $payload['truncated'] );
		$requestCount = isset( $payload['request_count'] ) ? (int) $payload['request_count'] : 0;

		$postId     = isset( $state['post_id'] ) ? (int) $state['post_id'] : 0;
		$title      = (string) ( $state['post_title'] ?? '' );
		$postType   = (string) ( $state['post_type'] ?? '' );
		$sourceLang = (string) ( $state['source_lang'] ?? '' );
		$modified   = (string) ( $state['modified_utc'] ?? '' );
		$trid       = isset( $state['trid'] ) ? (int) $state['trid'] : 0;

		echo "##########################################\n";
		echo "# WPML Job Log — Entity Timeline Export\n";
		echo '# Generated:       ' . gmdate( 'Y-m-d\TH:i:s\Z' ) . "\n";
		echo '# Post:            #' . $postId
			. ( $title !== '' ? ' — ' . $title : '' )
			. ' (' . $postType . ', ' . $sourceLang . ")\n";
		if ( $trid ) {
			echo '# trid:            ' . $trid . "\n";
		}
		echo "##########################################\n\n";
		flush();

		echo "CURRENT STATE\n";
		echo "==========================================\n";
		if ( $modified !== '' ) {
			echo 'Modified:         ' . $modified . "\n";
		}
		echo 'Source language:  ' . $sourceLang . "\n\n";

		$translations = isset( $state['translations'] ) && is_array( $state['translations'] ) ? $state['translations'] : [];
		echo "Translations:\n";
		if ( empty( $translations ) ) {
			echo "  (none)\n";
		} else {
			foreach ( $translations as $tr ) {
				$lang   = (string) ( $tr['language_code'] ?? '?' );
				$tpId   = isset( $tr['translated_post_id'] ) ? (int) $tr['translated_post_id'] : 0;
				$status = (string) ( $tr['status'] ?? '' );
				echo '  ' . $lang . ' → '
					. ( $tpId > 0 ? '#' . $tpId : 'no translated post' )
					. ( $status !== '' ? ' (status ' . $status . ')' : '' )
					. "\n";
			}
		}

		$activeJobs = isset( $state['active_jobs'] ) && is_array( $state['active_jobs'] ) ? $state['active_jobs'] : [];
		echo "\nIn-flight jobs (translated = 0):\n";
		if ( empty( $activeJobs ) ) {
			echo "  (none)\n";
		} else {
			foreach ( $activeJobs as $j ) {
				echo '  #' . (int) ( $j['job_id'] ?? 0 )
					. ' (' . (string) ( $j['target_lang'] ?? '?' ) . ', '
					. 'status ' . (string) ( $j['status'] ?? '?' ) . ', '
					. 'age ' . self::formatAge( $j['age_seconds'] ?? null )
					. ")\n";
			}
		}
		echo "\n";
		flush();

		echo "==========================================\n";
		echo 'TIMELINE — ' . $eventTotal . ' events across ' . $requestCount . ' requests';
		if ( $truncated ) {
			echo ' (truncated — only the most recent shown)';
		}
		echo "\n==========================================\n\n";

		if ( empty( $events ) ) {
			echo "(no events found for this post)\n";
			return;
		}

		$lastReq = null;
		foreach ( $events as $event ) {
			$ts     = (string) ( $event['timestamp_utc'] ?? '' );
			$logUid = (string) ( $event['__logUid'] ?? '' );
			$reqTag = $logUid !== '' ? 'req:' . substr( $logUid, 0, 6 ) : '';
			$label  = self::eventLabel( $event );
			$isErr  = isset( $event['logType'] ) && (int) $event['logType'] === JobLog::LOG_TYPE_ERROR;
			$marker = $isErr ? ' [ERROR]' : '';

			echo $ts . '  [' . $reqTag . ']  ' . $label . $marker . "\n";

			if ( isset( $event['data'] ) && is_array( $event['data'] ) && ! empty( $event['data'] ) ) {
				$json = wp_json_encode( $event['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
				if ( is_string( $json ) ) {
					echo '    ' . str_replace( "\n", "\n    ", $json ) . "\n";
				}
			}

			if ( $reqTag !== $lastReq ) {
				flush();
				$lastReq = $reqTag;
			}
		}

		echo "\n========================================\n";
		echo "End of timeline\n";
		echo "========================================\n";
	}

	private static function eventLabel( array $event ) {
		$type = (string) ( $event['type'] ?? '' );
		switch ( $type ) {
			case 'log':
				return (string) ( $event['id'] ?? '' );
			case 'group_started':
				$lbl = (string) ( $event['label'] ?? '' );
				return '▶ group: ' . ( $lbl !== '' ? $lbl : ( 'id ' . ( $event['groupId'] ?? '?' ) ) );
			case 'group_finished':
				return '◀ end of group ' . ( $event['groupId'] ?? '?' );
			case 'request_started':
				return '▶ request started';
			case 'request_finished':
				return ! empty( $event['aborted'] ) ? '◀ request aborted' : '◀ request finished';
		}
		return $type !== '' ? '[' . $type . ']' : '(unknown event)';
	}
}

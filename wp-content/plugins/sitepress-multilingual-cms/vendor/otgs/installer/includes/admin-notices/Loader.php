<?php

namespace OTGS\Installer\AdminNotices;

use function OTGS\Installer\FP\partial;

class Loader {

	public static function addHooks( $isAjax ) {
		add_action( 'current_screen', self::class . '::initDisplay' );
		if ( $isAjax ) {
			add_action( 'wp_ajax_installer_dismiss_nag', Dismissed::class . '::dismissNotice' );
		}
		add_action( 'activate_plugin', Dismissed::class . '::dismissNoticeOnPluginActivation', 10, 2 );
	}

	public static function initDisplay() {

		remove_action( 'current_screen', self::class . '::initDisplay' );

		$messages = apply_filters( 'otgs_installer_admin_notices', [] );

		if ( ! empty( $messages ) ) {

			$config = apply_filters( 'otgs_installer_admin_notices_config', [] );

			$texts = apply_filters( 'otgs_installer_admin_notices_texts', [] );

			$dismissedNotices = self::refreshDismissed();

			( new Display(
				$messages,
				$config,
				new MessageTexts( $texts ),
				partial( Dismissed::class . '::isDismissed', $dismissedNotices )
			) )->addHooks();
		}
	}

	public static function isDismissed( $repository_id, $notice_id ) {
		$remainigNotices = self::refreshDismissed();
		return Dismissed::isDismissed( $remainigNotices, $repository_id, $notice_id );
	}

	private static function refreshDismissed() {
		$store = new Store();

		$dismissedMessages = $store->get( Dismissed::STORE_KEY, [] );
		$remainingMessages = Dismissed::clearExpired(
			$dismissedMessages,
			[ self::class, 'timeOut' ]
		);

		if ( $dismissedMessages !== $remainingMessages ) {
			$store->save( Dismissed::STORE_KEY, $remainingMessages );
		}

		return $remainingMessages;
	}

	public static function timeOut( $start, $repo, $id ) {
		$timeout = apply_filters(
			'otgs_installer_admin_notices_dismissed_time',
			2 * MONTH_IN_SECONDS,
			$repo,
			$id
		);

		return time() - $start > $timeout;
	}

}

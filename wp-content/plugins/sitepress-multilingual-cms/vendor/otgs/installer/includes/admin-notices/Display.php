<?php

namespace OTGS\Installer\AdminNotices;

class Display {

	private $currentNotices;
	private $pageConfig;
	private $messageTexts;
	private $isDismissed;
	private $screenConfig;

	public function __construct(
		array $currentNotices,
		array $config,
		MessageTexts $messageTexts,
		callable $isDismissed
	) {
		$this->currentNotices = $currentNotices;
		$this->pageConfig     = new PageConfig( $config );
		$this->screenConfig   = new ScreenConfig( $config );
		$this->messageTexts   = $messageTexts;
		$this->isDismissed    = $isDismissed;
	}

	public function addHooks() {
		if ( ! empty( $this->currentNotices ) && $this->isRelevantOnPage() ) {
			add_action( 'admin_notices', [ $this, 'addNotices' ] );
			add_action( 'admin_enqueue_scripts', [ $this, 'addScripts' ] );
		}
	}

	public function addNotices() {
		foreach ( $this->currentNotices['repo'] as $repo => $ids ) {
			foreach ( $ids as $id => $type ) {
				if ( is_array( $type ) ) {
					$index       = $id;
					$noticesData = $type;
				} else {
					$index       = $type;
					$noticesData = [ $type ];
				}

				if ( $this->pageConfig->shouldShowMessage( $repo, $index ) || $this->screenConfig->shouldShowMessage( $repo, $index ) ) {
					foreach ( $noticesData as $noticeData ) {
						$this->displayNotice( $repo, $index, $noticeData );
					}
				}
			}
		}
	}

	private function isRelevantOnPage() {
		return $this->pageConfig->isAnyMessageOnPage( $this->currentNotices ) ||
		       $this->screenConfig->isAnyMessageOnPage( $this->currentNotices );
	}

	private function displayNotice( $repo, $id, $notice_params = [] ) {
		$noticeId = $id;
		if ( isset( $notice_params['noticeId'] ) ) {
			$noticeId = $notice_params['noticeId'];
		}
		if ( ! call_user_func( $this->isDismissed, $repo, $noticeId ) ) {
			$html = $this->messageTexts->get( $repo, $id, $notice_params );
			if ( $html ) {
				echo $html;
			}
		}
	}

	public function addScripts() {
		$installer = OTGS_Installer();
		wp_enqueue_style(
			'installer-admin-notices',
			$installer->res_url() . '/res/css/admin-notices.css',
			[],
			$installer->version()
		);
		wp_enqueue_script(
			'installer-admin',
			$installer->res_url() . '/res/js/admin.js',
			[ 'jquery' ],
			$installer->version()
		);
		wp_enqueue_script(
			'expired-notice',
			$installer->res_url() . '/dist/js/expired-notice/app.js',
			[ 'installer-admin' ],
			$installer->version()
		);
	}
}


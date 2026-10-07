<?php

namespace WPML\Notices\SiteKey;

use WPML\Core\WP\App\Resources;
use WPML\TM\ATE\ClonedSites\AutoMigration\Handler as AutoMigrationHandler;
use WPML\UIPage;

class Notice implements \IWPML_Action, \IWPML_Backend_Action {
	const NOTICE_ID             = 'wpml-site-key-notice-regular';
	const NOTICE_ID_TRANSLATION = 'wpml-site-key-notice-translation';
	const NOTICE_GROUP          = 'wpml-site-key-notices';

	private $notices;

	public function __construct( \WPML_Notices $notices ) {
		$this->notices = $notices;
	}

	public function add_hooks() {
		add_action( 'admin_init', [ $this, 'addNotice' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueueScripts' ] );
	}

	public function enqueueScripts() {
		if ( $this->isSuppressedByAutoMigration() ) {
			return;
		}

		if ( ! $this->isSiteKeyDefined() ) {
			$fn = Resources::enqueueApp( 'notices-site-key' );
			$fn( $this->getData() );
		}
	}

	private function getData() {
		return [
			'name' => 'wpml_site_key_notice',
			'data' => [
				'nonce'   => wp_create_nonce( 'save_site_key_wpml' ),
				'siteUrl' => get_site_url(),
			],
		];
	}

	public function addNotice() {
		if ( $this->isSuppressedByAutoMigration() ) {
			$this->notices->remove_notice( self::NOTICE_GROUP, self::NOTICE_ID );
			$this->notices->remove_notice( self::NOTICE_GROUP, self::NOTICE_ID_TRANSLATION );
			return;
		}

		if ( ! $this->isSiteKeyDefined() ) {
			$regularNotice = $this->createNotice( self::NOTICE_ID, true );
			$regularNotice->add_display_callback( [ self::class, 'shouldDisplayRegularNotice' ] );
			$this->notices->add_notice( $regularNotice );

			$translationNotice = $this->createNotice( self::NOTICE_ID_TRANSLATION, false );
			$translationNotice->add_display_callback( [ self::class, 'shouldDisplayTranslationNotice' ] );
			$this->notices->add_notice( $translationNotice );
		} else {
			$this->notices->remove_notice( self::NOTICE_GROUP, self::NOTICE_ID );
			$this->notices->remove_notice( self::NOTICE_GROUP, self::NOTICE_ID_TRANSLATION );
		}
	}

	private function isSuppressedByAutoMigration(): bool {
		return AutoMigrationHandler::getMigrationData() !== null
		       || AutoMigrationHandler::hasFailed();
	}

	private function isSiteKeyDefined(): bool {
		if ( ! function_exists( 'OTGS_Installer' ) ) {
			return true;
		}

		return (bool) \OTGS_Installer()->get_site_key( 'wpml' );
	}

	private function createNotice( $noticeId, $allowDismiss ) {
		$notice = $this->notices->create_notice(
			$noticeId,
			$this->getNoticeText(),
			self::NOTICE_GROUP
		);

		$notice->set_dismissible( $allowDismiss );
		$notice->set_css_class_types( 'error' );

		return $notice;
	}

	private function getNoticeText(): string {
		$notice_id = 'wpml-site-key-notice-wpml-site-key-notice-translation';
		$site_url  = get_site_url();

		return sprintf(
			'<div id="%s" class="wpml-site-key-notice-container" data-notice-id="wpml-site-key-notice-translation" style="margin-top: 5px;">' .
			'<h2>%s</h2>' .
			'<p>%s</p>' .
			'<form class="wpml-register-form">' .
			'<label for="wpml_site_key">%s</label>' .
			'<div class="wpml-register-form__input">' .
			'<input type="text" size="20" name="wpml_site_key" id="wpml_site_key" class="" placeholder="%s" value="">' .
			'<button type="submit" class="button-secondary" disabled="">%s</button>' .
			'</div>' .
			'</form>' .
			'<div class="wpml-notice-help">' .
			'<a href="https://wpml.org/account/sites/?add=%s" target="_blank" rel="noopener noreferrer">%s</a>' .
			'</div>' .
			'</div>',
			esc_attr( $notice_id ),
			esc_html__( 'A New Site Key Is Required', 'sitepress' ),
			esc_html__( 'To continue using WPML features like automatic translation and plugin updates, please register your site again. Don\'t worry, your existing content and translations are safe.', 'sitepress' ),
			esc_html__( 'Site key:', 'sitepress' ),
			esc_attr__( 'Enter your site key here', 'sitepress' ),
			esc_html__( 'Register', 'sitepress' ),
			esc_url( $site_url ),
			esc_html__( 'Get a key for this site', 'sitepress' )
		);
	}

	private static function isWpmlPageResponsibleForTranslation(): bool {
		return UIPage::isTranslationManagement( $_GET ) || UIPage::isTranslationQueue( $_GET );
	}

	public static function shouldDisplayRegularNotice() {
		return ! self::isWpmlPageResponsibleForTranslation();
	}

	public static function shouldDisplayTranslationNotice() {
		return self::isWpmlPageResponsibleForTranslation();
	}
}

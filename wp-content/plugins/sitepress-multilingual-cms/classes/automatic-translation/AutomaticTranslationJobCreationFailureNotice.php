<?php

namespace WPML\TM\AutomaticTranslation\Actions;

use WPML\FP\Lst;
use WPML\LIB\WP\Hooks;
use WPML\LIB\WP\Option;
use WPML\UIPage;
use function WPML\FP\spreadArgs;

class AutomaticTranslationJobCreationFailureNotice implements \IWPML_Action {
	const OPTION_KEY = 'auto-translation-job-creation-error';
	const NOTICE_ID  = 'automatic-job-creation-failed';

	private $jobFailedElements;

	private $wpmlNotices;

	private $wpmlTranslationElementFactory;

	public function __construct( \WPML_Translation_Element_Factory $translationElementFactory, \WPML_Notices $wpmlNotices ) {
		$optionVal                           = Option::get( self::OPTION_KEY );
		$this->jobFailedElements             = $optionVal ? json_decode( $optionVal, true ) : [];
		$this->wpmlNotices                   = $wpmlNotices;
		$this->wpmlTranslationElementFactory = $translationElementFactory;
	}

	public function add_hooks() {
		Hooks::onAction( 'admin_init' )->then( spreadArgs( [ $this, 'updateNotice' ] ) );

		Hooks::onAction( 'wpml_update_failed_jobs_notice' )
			->then(
				spreadArgs(
					[ $this, 'updateNotice' ]
				)
			);
	}

	public function updateNotice( $postElement = null ) {
		$previousJobFailedElements = $this->jobFailedElements;

		$this->deleteElementsThatHaveJobsCreated();

		if ( $postElement ) {
			$this->addFailedJobPostElement( $postElement );
		}

		if ( Lst::length( $previousJobFailedElements ) !== Lst::length( $this->jobFailedElements ) ) {
			$this->updateOrDismissNotice();
		}
	}

	public function deleteElementsThatHaveJobsCreated() {
		foreach ( $this->jobFailedElements as $contentId => $contentInfo ) {
			$postElement = $this->wpmlTranslationElementFactory->create_post( $contentId );
			if ( Lst::length( $postElement->get_translations() ) > 1 ) {
				unset( $this->jobFailedElements[ $contentId ] );

				$encodedContent = $this->encodedContent( $this->jobFailedElements );
				Option::update( self::OPTION_KEY, $encodedContent );
			}
		}

		if ( ! Lst::length( $this->jobFailedElements ) ) {
			$this->deleteOption();
		}
	}

	public function addFailedJobPostElement( $postElement ) {
		$this->jobFailedElements[ $postElement->get_id() ] = [
			'title' => $postElement->get_wp_object()->post_title,
			'lang'  => $postElement->get_language_code(),
		];

		$encodedContent = $this->encodedContent( $this->jobFailedElements );
		Option::update( self::OPTION_KEY, $encodedContent );
	}

	public function updateOrDismissNotice() {
		if ( Lst::length( $this->jobFailedElements ) ) {
			$this->displayNotice();
		} else {
			$notice = $this->wpmlNotices->get_notice( self::NOTICE_ID );
			$notice && $this->wpmlNotices->dismiss_notice( $notice );
		}
	}

	private function displayNotice() {
		$message = $this->constructMessage();
		$notice  = $this->createNotice( $message );
		$this->wpmlNotices->add_notice( $notice, true );
	}

	private function constructMessage() {
		$message  = '<h2 id="job_creation_fail_notice">' . __( 'WPML experienced an issue while trying to automatically translating some of your content:', 'sitepress' ) . '</h2>';
		$message .= '<ul>';
		foreach ( $this->jobFailedElements as $contentInfo ) {
			$message .= '<li class="job_creation_fail_element">' . $contentInfo['title'] . '</li>';
		}

		$message .= '</ul>';
		$message .= '<p id="job_creation_fail_tm_link">' . sprintf( __( 'To translate these items, please go to <a rel="noreferrer" href="%s">Translation Management</a> and send them for translation.', 'sitepress' ), UIPage::getTMDashboard() ) . '</p>';
		$message .= '<p id="job_creation_fail_support_link">' . sprintf( __( 'If the problem continues, contact <a target="_blank" rel="noreferrer" href="%s">WPML support</a> for assistance.', 'sitepress' ), 'https://wpml.org/forums/forum/english-support/?utm_source=plugin&utm_medium=gui&utm_campaign=wpml-posts' ) . '</p>';

		return $message;
	}

	private function createNotice( $message ) {
		$notice = $this->wpmlNotices->create_notice( self::NOTICE_ID, $message );
		$notice->set_dismissible( true );
		$notice->reset_dismiss();
		$notice->set_css_class_types( [ 'error' ] );

		return $notice;
	}

	private function deleteOption() {
		if ( ! Option::get( self::OPTION_KEY ) ) {
			return;
		}

		Option::delete( self::OPTION_KEY );
	}

	private function encodedContent( $content ) {
		return json_encode( $content ) ?: '';
	}
}

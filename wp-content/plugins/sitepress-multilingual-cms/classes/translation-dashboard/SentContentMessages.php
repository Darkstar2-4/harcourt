<?php

namespace WPML\TM\TranslationDashboard;

use WPML\TM\TranslationDashboard\EncodedFieldsValidation\ErrorEntry;
use WPML\UIPage;

class SentContentMessages {
	private static $confirmation = null;

	private static $encodedFieldErrorEntries = null;

	public function duplicate() {
		self::$confirmation = [
			'message'     => '',
			'description' => __( 'You successfully duplicated your content.', 'sitepress-multilingual-cms' ),
			'type'        => 'success',
		];
	}

	public function duplicateAndAutomatic() {
		self::$confirmation = [
			'message'     => __( 'You successfully duplicated your content, and WPML is handling your translations for you.', 'sitepress-multilingual-cms' ),
			'description' => __( 'Your translations will be ready soon. You can see the status of your automatic translations below or in the status bar at the top of WordPress admin.', 'sitepress-multilingual-cms' ),
			'type'        => 'success',
			'automatic'   => true,
		];
	}

	public function duplicateAndMyself() {
		self::$confirmation = [
			'message'     => __( 'You successfully duplicated your content. What’s next for your translations?', 'sitepress-multilingual-cms' ),
			'description' => sprintf(
				__( 'Go to the <a href="%s">Translations Queue</a> to translate it.', 'sitepress-multilingual-cms' ),
				UIPage::getTranslationQueue()
			),
			'type'        => 'info',
		];
	}

	public function duplicateAndBasket() {
		self::$confirmation = [
			'message'     => __( 'You successfully duplicated your content. What’s next for your translations?', 'sitepress-multilingual-cms' ),
			'description' => sprintf(
				__( 'Go to the <a href="%s">Translation Basket</a> to decide who should translate your content', 'sitepress-multilingual-cms' ),
				UIPage::getTMBasket()
			),
			'type'        => 'info',
		];
	}

	public function automatic() {
		self::$confirmation = [
			'message'     => __( 'WPML is translating your content', 'sitepress-multilingual-cms' ),
			'description' => __( 'Your translations will be ready soon. You can see the status of your automatic translations below or in the status bar at the top of WordPress admin.', 'sitepress-multilingual-cms' ),
			'type'        => 'success',
			'automatic'   => true,
		];
	}

	public function myself() {
		self::$confirmation = [
			'message'     => __( 'You’ve queued up your content for translation. What’s next?', 'sitepress-multilingual-cms' ),
			'description' => sprintf(
				__( 'Go to the <a href="%s">Translations Queue</a> to translate it.', 'sitepress-multilingual-cms' ),
				UIPage::getTranslationQueue()
			),
			'type'        => 'info',
		];
	}

	public function basket() {
		self::$confirmation = [
			'message'     => __( 'You added your translations to the basket. What’s next?', 'sitepress-multilingual-cms' ),
			'description' => sprintf(
				__( 'Go to the <a href="%s">Translation Basket</a> to decide who should translate your content', 'sitepress-multilingual-cms' ),
				UIPage::getTMBasket()
			),
			'type'        => 'info',
		];
	}

	public function postsWithEncodedFieldsHasBeenSkipped( array $invalidElements ) {
		self::$encodedFieldErrorEntries = $invalidElements;
	}

	public function get() {
		return [
			'confirmation'             => self::$confirmation,
			'encodedFieldErrorEntries' => self::$encodedFieldErrorEntries,
		];
	}
}
<?php

namespace WPML\TM\Jobs\Dispatch;

class Messages {

	public function ignoreOriginalPostMessage( $post, $language ) {
		return sprintf(
			__(
				'Post "%1$s" will be ignored for %2$s, because it is an original post.',
				'wpml-translation-management'
			),
			$post->post_title,
			$language
		);
	}

	public function ignoreInProgressPostMessage( $post, $language ) {
		return sprintf(
			__(
				'Post "%1$s" will be ignored for %2$s, because translation is already in progress.',
				'wpml-translation-management'
			),
			$post->post_title,
			$language
		);
	}

	public function ignoreInProgressStringMessage( \WPML_ST_String $string, $language ) {
		return sprintf(
			__(
				'String "%1$s" will be ignored for %2$s, because translation is already waiting for translator.',
				'wpml-translation-management'
			),
			$string->get_value(),
			$language
		);
	}

	public function ignoreInProgressPackageMessage( $package, $language ) {
		return sprintf(
			__(
				'Package "%1$s" will be ignored for %2$s, because translation is already in progress.',
				'wpml-translation-management'
			),
			$package->title,
			$language
		);
	}

	public function ignoreOriginalPackageMessage( $package, $language ) {
		return sprintf(
			__(
				'Package "%1$s" will be ignored for %2$s, because it is an original post.',
				'wpml-translation-management'
			),
			$package->title,
			$language
		);
	}

	public function showForPosts( array $messages, $type ) {
		$this->show(
			'translation-basket-notification',
			[ WPML_TM_FOLDER . '/menu/main.php' ],
			'translation-basket-notification',
			$messages,
			$type
		);
	}

	public function showForStrings( array $messages, $type ) {
		if ( defined( 'WPML_ST_FOLDER' ) ) {
			$this->show(
				'string-translation-top',
				[ WPML_ST_FOLDER . '/menu/string-translation.php' ],
				'string-translation-top',
				$messages,
				$type
			);
		}
	}

	private function show( $id, array $pages, $group, array $messages, $type ) {
		if ( $messages ) {
			$messageArgs = [
				'id'               => $id,
				'text'             => '<ul><li>' . implode( '</li><li>', $messages ) . '</li></ul>',
				'classes'          => 'small',
				'type'             => $type,
				'group'            => $group,
				'admin_notice'     => true,
				'hide_per_user'    => false,
				'dismiss_per_user' => false,
				'limit_to_page'    => $pages,
				'show_once'        => true,
			];
			\ICL_AdminNotifier::add_message( $messageArgs );
		}
	}
}
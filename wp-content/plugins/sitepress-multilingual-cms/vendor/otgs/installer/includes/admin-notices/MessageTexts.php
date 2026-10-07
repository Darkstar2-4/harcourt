<?php

namespace OTGS\Installer\AdminNotices;

class MessageTexts {
	private $messages;

	public function __construct( array $messages ) {
		$this->messages = $messages;
	}

	public function get( $repo, $messageId, $parameters = [] ) {
		if ( isset( $this->messages['repo'][ $repo ][ $messageId ] ) ) {
			if ( ! empty( $parameters ) ) {
				return call_user_func(
					$this->messages['repo'][ $repo ][ $messageId ],
					$parameters
				);
			} else {
				return call_user_func( $this->messages['repo'][ $repo ][ $messageId ], $messageId );
			}
		}

		return null;
	}

}

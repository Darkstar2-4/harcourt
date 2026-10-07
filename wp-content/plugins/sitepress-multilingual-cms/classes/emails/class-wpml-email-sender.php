<?php

class WPML_Mail_Sender {

	public static function send( $to, $subject, $message, $headers = '', $attachments = array(), $group = '' ) {
		$args = array(
			'to'          => $to,
			'subject'     => $subject,
			'message'     => $message,
			'headers'     => $headers,
			'attachments' => $attachments,
			'group'       => $group,
		);

		if ( apply_filters( 'wpml_mail_enabled', true, $args ) ) {
			return wp_mail( $to, $subject, $message, $headers, $attachments );
		}

		return null;
	}
}

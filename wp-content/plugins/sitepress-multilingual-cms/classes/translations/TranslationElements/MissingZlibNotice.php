<?php

namespace WPML\Translation\TranslationElements;

class MissingZlibNotice {

	public static function maybeAddNotice( $job_id ) {
		if ( CompressionTracker::shouldShowMissingZlibError( $job_id ) ) {
			add_action( 'wpml_tm_editor_messages', [ __CLASS__, 'displayNotice' ] );
		}
	}

	public static function displayNotice() {
		$main_message = esc_html__(
			'This translation job contains compressed data but the PHP zlib extension is not available on this server.',
			'wpml-translation-management'
		);

		$technical_details = esc_html__(
			'WPML uses the zlib extension (gzcompress/gzuncompress functions) to optimize database storage. This translation job was created on a server with zlib enabled, but your current server does not have this extension.',
			'wpml-translation-management'
		);

		$solution = esc_html__(
			'To properly view and edit all fields in this translation, please ask your hosting provider to enable the PHP zlib extension. This is a standard PHP extension available on most hosting environments.',
			'wpml-translation-management'
		);

		$note = esc_html__(
			'Note: Some fields may display garbled characters if they were compressed. Fields that were not compressed will display normally.',
			'wpml-translation-management'
		);

		?>
		<div class="notice notice-error">
			<p><strong><?php echo $main_message; ?></strong></p>
			<p><?php echo $technical_details; ?></p>
			<p><?php echo $solution; ?></p>
			<p><em><?php echo $note; ?></em></p>
		</div>
		<?php
	}
}

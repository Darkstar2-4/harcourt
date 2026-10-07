<?php

namespace Smush\Core;

class String_Utils {
	/**
	 * Returns the raw (untranslated) string for storage in the database.
	 * Any additional parameters should be calls to __() to ensure all strings are extracted for translation.
	 *
	 * Example usage:
	 *   $raw = (new String_Utils())->get_raw_string(
	 *     $activated ? 'CDN activated.' : 'CDN deactivated.',
	 *     __( 'CDN activated.', 'wp-smushit' ),
	 *     __( 'CDN deactivated.', 'wp-smushit' )
	 *   );
	 *
	 * @param string $raw_text The raw English string to store.
	 * @param string ...$translated_texts One or more translated strings (from __()), used only for .pot extraction.
	 * @return string The raw string for storage.
	 */
	public function get_raw_string( $raw_text, ...$translated_texts ) {
		return $raw_text;
	}

	/**
	 * Returns the translated string for display, based on the current site language.
	 *
	 * Example usage:
	 *   echo (new String_Utils())->get_translated_string( 'CDN activated.' );
	 *
	 * @param string $raw_text The raw English string stored in the database.
	 * @return string The translated string.
	 */
	public function get_translated_string( $raw_text ) {
		return translate( $raw_text, 'wp-smushit' );//phpcs:ignore.
	}
}
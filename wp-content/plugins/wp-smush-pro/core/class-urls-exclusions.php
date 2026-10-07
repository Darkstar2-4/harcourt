<?php
namespace Smush\Core;

/**
 * URL Exclusions helper.
 */
class Urls_Exclusions {
	/**
	 * Regex delimiter used for preg_match/preg_quote.
	 *
	 * @var string
	 */
	private $delimiter;

	/**
	 * Constructor.
	 *
	 * @param string $delimiter Regex delimiter.
	 */
	public function __construct( $delimiter = '~' ) {
		$this->delimiter = $delimiter;
	}

	/**
	 * Checks if the current request URI matches any of the excluded pages.
	 *
	 * @return bool True if the current request URI is excluded, false otherwise.
	 */
	public function is_excluded_uri( $request_uri, $excluded_page_urls ) {
		$pattern = $this->get_excluded_uri_pattern( $excluded_page_urls );
		if ( empty( $pattern ) ) {
			return false;
		}

		$request_uri = wp_parse_url( $request_uri, PHP_URL_PATH );
		$regex = $this->delimiter . $pattern . $this->delimiter . 'i';
		return (bool) preg_match( $regex, $request_uri );
	}

	/**
	 * Generate a regex pattern from excluded page URLs.
	 *
	 * @return string Regex pattern without delimiters or flags.
	 */
	private function get_excluded_uri_pattern( $excluded_page_urls ) {
		if ( empty( $excluded_page_urls ) ) {
			return '';
		}

		$patterns = array_map( array( $this, 'build_url_pattern' ), $excluded_page_urls );

		return implode( '|', array_filter( $patterns ) );
	}

	/**
	 * Build regex pattern for a single URL.
	 *
	 * @param string $url The URL to convert to regex pattern.
	 *
	 * @return string Regex pattern for the URL.
	 */
	private function build_url_pattern( $url ) {
		$url = trim( $url );

		if ( empty( $url ) ) {
			return '';
		}

		if ( '/' === $url ) {
			return $this->is_subsite()
				? '^' . preg_quote( get_blog_details( get_current_blog_id() )->path, '~' ) . '$'
				: '^/$';
		}

		return preg_quote( $url, '~' );
	}

	/**
	 * Checks if the current site is a subsite in a multisite network.
	 *
	 * @return bool True if the site is a subsite, false otherwise.
	 */
	private function is_subsite() {
		return is_multisite() && ! is_main_site();
	}
}
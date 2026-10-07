<?php

class WPML_Sunrise_Lang_In_Domains {

	private $wpdb;

	private $table_prefix;

	private $current_blog;

	private $no_recursion;

	public function init() {
		if ( ! defined( 'WPML_SUNRISE_MULTISITE_DOMAINS' ) ) {
			define( 'WPML_SUNRISE_MULTISITE_DOMAINS', true );
		}

		add_filter( 'query', array( $this, 'query_filter' ) );
	}

	public function query_filter( $q ) {
		$this->set_private_properties();

		if ( ! $this->current_blog && ! $this->no_recursion ) {

			$this->no_recursion = true;

			$domains = $this->extract_variables_from_query( $q, 'domain' );

			if ( $domains && $this->query_has_no_result( $q ) ) {
				$q = $this->transpose_query_if_one_domain_is_matching( $q, $domains );
			}

			$this->no_recursion = false;
		}

		return $q;
	}

	private function set_private_properties() {
		global $wpdb, $table_prefix, $current_blog;

		$this->wpdb         = $wpdb;
		$this->table_prefix = $table_prefix;
		$this->current_blog = $current_blog;

	}

	private function extract_variables_from_query( $query, $field ) {
		$variables = array();
		$patterns  = array(
			'#WHERE\s+' . $field . '\s+IN\s*\(([^\)]+)\)#',
			'#WHERE\s+' . $field . '\s*=\s*([^\s]+)#',
			'#AND\s+' . $field . '\s+IN\s*\(([^\)]+)\)#',
			'#AND\s+' . $field . '\s*=\s*([^\s]+)#',
		);

		foreach ( $patterns as $pattern ) {
			$found = preg_match( $pattern, $query, $matches );
			if ( $found && array_key_exists( 1, $matches ) ) {
				$variables = $matches[1];
				$variables = preg_replace( '/\s+/', '', $variables );
				$variables = preg_replace( '/[\'"]/', '', $variables );
				$variables = explode( ',', $variables );
				break;
			}
		}

		return $variables;
	}

	private function query_has_no_result( $q ) {
		return ! (bool) $this->wpdb->get_row( $q );
	}

	private function transpose_query_if_one_domain_is_matching( $q, $domains ) {
		$paths = $this->extract_variables_from_query( $q, 'path' );

		$placeholders = implode( ',', array_fill( 0, sizeof( $paths ), '%s' ) );

		$parameters   = $paths;
		$parameters[] = BLOG_ID_CURRENT_SITE;

		$blogs = $this->wpdb->get_col(
			$this->wpdb->prepare(
				"SELECT blog_id FROM {$this->wpdb->blogs} WHERE path IN ($placeholders) ORDER BY blog_id = %d",
				$parameters
			)
		);

		$found_blog_id = null;
		foreach ( (array) $blogs as $blog_id ) {
			$prefix = $this->table_prefix;

			if ( $blog_id > 1 ) {
				$prefix .= $blog_id . '_';
			}

			$icl_settings = $this->wpdb->get_var( "SELECT option_value FROM {$prefix}options WHERE option_name = 'icl_sitepress_settings'" );

			if ( $icl_settings ) {
				$icl_settings = unserialize( $icl_settings );

				if ( $icl_settings && 2 === (int) $icl_settings['language_negotiation_type'] ) {
					$found_blog_id = $this->get_blog_id_from_domain( $domains, $icl_settings, $blog_id );
					if ( $found_blog_id ) {
						$q = $this->wpdb->prepare( "SELECT blog_id FROM {$this->wpdb->blogs} WHERE blog_id = %d", $found_blog_id );
						break;
					}
				}
			}
		}

		return $q;
	}

	private function get_blog_id_from_domain( array $domains, array $wpml_settings, $blog_id ) {
		foreach ( $domains as $domain ) {
			if ( in_array( 'http://' . $domain, $wpml_settings['language_domains'], true ) ) {
				return $blog_id;
			} elseif ( in_array( $domain, $wpml_settings['language_domains'], true ) ) {
				return $blog_id;
			}
		}

		return null;
	}
}

$wpml_sunrise_lang_in_domains = new WPML_Sunrise_Lang_In_Domains();
$wpml_sunrise_lang_in_domains->init();


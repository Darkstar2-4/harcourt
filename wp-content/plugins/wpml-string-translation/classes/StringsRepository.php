<?php

namespace WPML\ST;

use WPML\FP\Fns;

class StringsRepository {
	private $sitepress;

	private $wpdb;

	public function __construct( \SitePress $sitepress, \wpdb $wpdb ) {
		$this->sitepress = $sitepress;
		$this->wpdb      = $wpdb;
	}

	private function getLanguagesSql( $langs = [] ) {
		return ' AND language IN (' . wpml_prepare_in( $langs, '%s' ) . ')';
	}

	private function getNotPrioritiesSql( $notPriorities = [] ) {
		return ' AND translation_priority NOT IN (' . wpml_prepare_in( $notPriorities, '%s' ) . ')';
	}

	private function execGetCountInDomains( $domains = [], $extraSql = '' ) {
		if ( ! $domains ) {
			return 0;
		}

		return (int) $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT count(id) FROM {$this->wpdb->prefix}icl_strings WHERE 1=%d AND context IN ("
				. wpml_prepare_in( $domains, '%s' ) . ')'
				. $extraSql,
				1
			)
		);
	}

	public function getCountInDomains( $domains = [] ) {
		return $this->execGetCountInDomains( $domains );
	}

	public function getCountInDomainsByLangs( $domains = [], $langs = [] ) {
		return $this->execGetCountInDomains( $domains, $this->getLanguagesSql( $langs ) );
	}

	public function getCountInDomainsByNotPriorities( $domains = [], $notPriorities = [] ) {
		return $this->execGetCountInDomains( $domains, $this->getNotPrioritiesSql( $notPriorities ) );
	}

	private function execGetFromDomains( $domains = [], $limit = 20, $extraSql = '' ) {
		if ( ! $domains ) {
			return [];
		}

		return $this->wpdb->get_col(
			$this->wpdb->prepare(
				"SELECT id FROM {$this->wpdb->prefix}icl_strings WHERE context IN ("
				. wpml_prepare_in( $domains, '%s' ) . ')' . $extraSql . ' LIMIT 0, %d',
				$limit
			)
		);
	}

	public function getFromDomains( $domains = [], $limit = 20 ) {
		return $this->execGetFromDomains( $domains, $limit );
	}

	public function getStringIdFromDomainsByLangs( $domains = [], $langs = [], $limit = 20 ) {
		return $this->execGetFromDomains( $domains, $limit, $this->getLanguagesSql( $langs ) );
	}

	public function getStringIdsFromDomainsWithExcludedPriorities( $domains = [], $notPriorities = [], $limit = 20 ) {
		return $this->execGetFromDomains( $domains, $limit, $this->getNotPrioritiesSql( $notPriorities ) );
	}

	public function getLanguagesUsedInDomains( $domains = [], $ignoreLangs = [] ) {
		if ( ! $domains ) {
			return [];
		}

		$allLanguages = array_keys( $this->sitepress->get_languages( $this->sitepress->get_admin_language() ) );
		$allLanguages = Fns::filter(
			function( $language ) use ( $ignoreLangs ) {
				return ! in_array( $language, $ignoreLangs );
			},
			$allLanguages
		);

		$results = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT DISTINCT(language) FROM {$this->wpdb->prefix}icl_strings s"
				. " WHERE 1=%d AND context IN (" . wpml_prepare_in( $domains ) . ") AND language IN (" . wpml_prepare_in( $allLanguages ) . ')',
				1
			),
			ARRAY_A
		);

		return Fns::map(
			function( $data ) {
				return $data['language'];
			},
			$results
		);
	}
}

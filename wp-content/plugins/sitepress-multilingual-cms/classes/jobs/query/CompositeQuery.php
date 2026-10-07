<?php

namespace WPML\TM\Jobs\Query;

use \InvalidArgumentException;
use \WPML_TM_Jobs_Search_Params;
use \RuntimeException;


class CompositeQuery implements Query {
	const METHOD_UNION = 'union';
	const METHOD_COUNT = 'count';

	private $queries;

	private $limit_query_helper;

	private $order_query_helper;

	public function __construct(
		array $queries,
		LimitQueryHelper $limit_helper,
		OrderQueryHelper $order_helper
	) {
		$queries = array_filter( $queries, array( $this, 'is_query_valid' ) );
		if ( empty( $queries ) ) {
			throw new InvalidArgumentException( 'Collection of sub-queries is empty or contains only invalid elements' );
		}

		$this->queries            = $queries;
		$this->limit_query_helper = $limit_helper;
		$this->order_query_helper = $order_helper;
	}


	public function get_data_query( WPML_TM_Jobs_Search_Params $params ) {
		if ( ! $params->get_job_types() ) {
			$params_without_pagination_and_sorting = clone $params;
			$params_without_pagination_and_sorting->set_limit( 0 )->set_offset( 0 );
			$params_without_pagination_and_sorting->set_sorting( array() );

			$query = $this->get_sql( $params_without_pagination_and_sorting, self::METHOD_UNION );

			$order = $this->order_query_helper->get_order( $params );
			if ( $order ) {
				$query .= ' ' . $order;
			}
			$limit = $this->limit_query_helper->get_limit( $params );
			if ( $limit ) {
				$query .= ' ' . $limit;
			}

			return $query;
		} else {
			return $this->get_sql( $params, self::METHOD_UNION );
		}
	}

	public function get_count_query( WPML_TM_Jobs_Search_Params $params ) {
		$params_without_pagination_and_sorting = clone $params;
		$params_without_pagination_and_sorting->set_limit( 0 )->set_offset( 0 );
		$params_without_pagination_and_sorting->set_sorting( array() );

		return $this->get_sql( $params_without_pagination_and_sorting, self::METHOD_COUNT );
	}

	private function get_sql( WPML_TM_Jobs_Search_Params $params, $method ) {
		switch ( $method ) {
			case self::METHOD_UNION:
				$query_method = 'get_data_query';
				break;
			case self::METHOD_COUNT:
				$query_method = 'get_count_query';
				break;
			default:
				throw new InvalidArgumentException( 'Invalid method argument: ' . $method );
		}

		$parts = array();
		foreach ( $this->queries as $query ) {
			$query_string = $query->$query_method( $params );
			if ( $query_string ) {
				$parts[] = $query_string;
			}
		}

		if ( ! $parts ) {
			throw new RuntimeException( 'None of subqueries matches to specified search parameters' );
		}

		if ( 1 === count( $parts ) ) {
			return current( $parts );
		}

		switch ( $method ) {
			case self::METHOD_UNION:
				return $this->get_union( $parts );
			case self::METHOD_COUNT:
				return $this->get_count( $parts );
		}

		return null;
	}

	private function get_union( array $parts ) {
		return '( ' . implode( ' ) UNION ( ', $parts ) . ' )';
	}

	private function get_count( array $parts ) {
		return 'SELECT ( ' . implode( ' ) + ( ', $parts ) . ' )';
	}

	private function is_query_valid( $query ) {
		return $query instanceof Query;
	}
}

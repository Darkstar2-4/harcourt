<?php

class WPML_TM_Jobs_Collection implements IteratorAggregate, Countable {
	private $jobs = array();

	public function __construct( array $jobs ) {
		foreach ( $jobs as $job ) {
			if ( $job instanceof WPML_TM_Job_Entity ) {
				$this->add( $job );
			}
		}
	}

	private function add( WPML_TM_Job_Entity $job ) {
		$this->jobs[] = $job;
	}

	public function get_by_tp_id( $tp_id ) {
		foreach ( $this->jobs as $job ) {
			if ( $tp_id === $job->get_tp_id() ) {
				return $job;
			}
		}

		return null;
	}

	public function filter( $callback ) {
		return new WPML_TM_Jobs_Collection( array_filter( $this->jobs, $callback ) );
	}

	public function filter_by_status( $status, $exclude = false ) {
		if ( ! is_array( $status ) ) {
			$status = array( $status );
		}

		$result = array();
		if ( $exclude ) {
			foreach ( $this->jobs as $job ) {
				if ( ! in_array( $job->get_status(), $status, true ) ) {
					$result[] = $job;
				}
			}
		} else {
			foreach ( $this->jobs as $job ) {
				if ( in_array( $job->get_status(), $status, true ) ) {
					$result[] = $job;
				}
			}
		}

		return new WPML_TM_Jobs_Collection( $result );
	}

	public function map( $callback, $return_job_collection = false ) {
		$mapped_result = array_map( $callback, $this->jobs );

		return $return_job_collection ? new WPML_TM_Jobs_Collection( $mapped_result ) : $mapped_result;
	}

	public function map_to_property( $property ) {
		$method = 'get_' . $property;
		$result = array();

		foreach ( $this->jobs as $job ) {
			if ( ! method_exists( $job, $method ) ) {
				throw new InvalidArgumentException( 'Property ' . $property . ' does not exist' );
			}

			$result[] = $job->{$method}();
		}

		return $result;
	}

	public function append( $jobs ) {
		if ( $jobs instanceof WPML_TM_Jobs_Collection ) {
			$jobs = $jobs->toArray();
		}

		return new WPML_TM_Jobs_Collection( array_merge( $this->jobs, $jobs ) );
	}

	#[\ReturnTypeWillChange]
	public function getIterator() {
		return new ArrayIterator( $this->jobs );
	}

	public function toArray() {
		return $this->jobs;
	}

	#[\ReturnTypeWillChange]
	public function count() {
		return count( $this->jobs );
	}
}

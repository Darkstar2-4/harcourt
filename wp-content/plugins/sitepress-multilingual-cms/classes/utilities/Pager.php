<?php

namespace WPML\Utils;

use WPML\Collect\Support\Collection;

class Pager {
	protected $optionName;

	protected $pageSize;

	public function __construct( $optionName, $pageSize = 10 ) {
		$this->optionName = $optionName;
		$this->pageSize   = $pageSize;
	}

	public function iterate( Collection $collection, callable $callback, $timeout = PHP_INT_MAX ) {
		$processedItems = $this->getProcessedCount();

		$this->getItemsToProcess( $collection, $processedItems )->eachWithTimeout(
			function ( $item ) use (
			&$processedItems,
			$callback
			) {
				return $callback( $item ) && ++ $processedItems;
			},
			$timeout
		);

		$remainingPages = $this->getRemainingPages( $collection, $processedItems );

		if ( $remainingPages ) {
			\update_option( $this->optionName, $processedItems );
		} else {
			\delete_option( $this->optionName );
		}

		return $remainingPages;
	}

	private function getItemsToProcess( Collection $collection, $processedItems ) {
		return $collection->slice( $processedItems, $this->pageSize );
	}

	public function getPagesCount( Collection $collection ) {
		return (int) ceil( $collection->count() / $this->pageSize );
	}

	protected function getRemainingPages( Collection $collection, $processedItems ) {
		return (int) ceil( $collection->slice( $processedItems )->count() / $this->pageSize );
	}

	public function getProcessedCount() {
		return get_option( $this->optionName, 0 );
	}
}

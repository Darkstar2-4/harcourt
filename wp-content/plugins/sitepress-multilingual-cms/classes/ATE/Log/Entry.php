<?php

namespace WPML\TM\ATE\Log;

class Entry {

	public $timestamp = 0;

	public $eventType = 0;

	public $description = '';

	public $wpmlJobId = 0;

	public $ateJobId = 0;

	public $extraData = [];

	public function __construct( ?array $item = null ) {
		if ( $item ) {
			$this->timestamp   = (int) $item['timestamp'];
			$this->eventType   = (int) ( isset( $item['eventType'] ) ? $item['eventType'] : $item['event'] );
			$this->description = $item['description'];
			$this->wpmlJobId   = (int) $item['wpmlJobId'];
			$this->ateJobId    = (int) $item['ateJobId'];
			$this->extraData   = (array) $item['extraData'];
		}
	}

	public static function createForType($eventType, $extraData) {
		$entry = new self();
		$entry->eventType = $eventType;
		$entry->extraData = $extraData;

		return $entry;
	}

	public static function retryJob( $wpmlJobId, $extraData ) {
		$entry = self::createForType(EventsTypes::JOB_RETRY, $extraData);
		$entry->wpmlJobId = $wpmlJobId;

		return $entry;
	}

	public function getFormattedDate() {
		return date_i18n( 'Y/m/d g:i:s A', $this->timestamp );
	}

	public function getExtraDataToString() {
		return json_encode( $this->extraData );
	}
}

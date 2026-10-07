<?php

namespace WPML\StringTranslation\Infrastructure\StringCore\Command;

abstract class BulkActionBaseCommand {

	protected $wpdb;

	protected $chunk_size = 1000;

	protected function runBulkQuery( string $query ) {
		$this->wpdb->suppress_errors = true;
		$this->wpdb->query($query);
		$this->wpdb->suppress_errors = false;
	}
}
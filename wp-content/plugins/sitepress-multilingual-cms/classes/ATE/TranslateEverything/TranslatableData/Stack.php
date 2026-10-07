<?php

namespace WPML\TM\ATE\TranslateEverything\TranslatableData;

class Stack {
	private $type;

	private $name;

	private $count;

	private $words = 0;

	private $completed = false;

	private $labels;

	public function __construct( $type, $name, $count = 0, $words = 0, $labels = [] ) {
		if ( empty( $type ) || empty( $name ) ) {
			throw new \InvalidArgumentException(
				'Stack "type" and "name" should not be empty.'
			);
		}
		$this->type   = $type;
		$this->name   = $name;
		$this->count  = $count;
		$this->words  = $words;
		$this->labels = $labels;
	}

	public function type() {
		return $this->type;
	}

	public function name() {
		return $this->name;
	}

	public function count() {
		return $this->count;
	}

	public function addWords( $words ) {
		$this->words += $words;

		return $this;
	}

	public function addCount( $count ) {
		$this->count += $count;

		return $this;
	}

	public function completed() {
		$this->completed = true;
	}

	public function toArray() {
	  	return [
			'type'      => $this->type,
			'name'      => $this->name,
			'labels'    => $this->labels,
			'count'     => $this->count,
			'words'     => $this->words,
			'completed' => $this->completed,
		];
	}
}


<?php

namespace WPML\StringTranslation\Application\StringCore\Domain;

class StringPosition {

	private $id;

	private $kind;

	private $positionInPage;

	private $string;

	public function __construct( int $kind, string $positionInPage, $string ) {
		$this->setKind( $kind );
		$this->setPositionInPage( $positionInPage );
		$this->setString( $string );
	}

	public function setId( int $id ) {
		$this->id = $id;
	}

	public function getId() {
		return $this->id;
	}

	public function hasId(): bool {
		return ! is_null( $this->getId() );
	}

	public function setKind( int $kind ) {
		$this->kind = $kind;
	}

	public function getKind(): int {
		return $this->kind;
	}

	public function setPositionInPage( string $positionInPage ) {
		$this->positionInPage = $positionInPage;
	}

	public function getPositionInPage(): string {
		return $this->positionInPage;
	}

	public function setString( StringItem $string ) {
		$this->string = $string;
	}

	public function getString(): StringItem {
		return $this->string;
	}

	public function isEqualTo( StringPosition $position ) {
		return (
			$this->getString()->getId() === $position->getString()->getId() &&
			$this->getKind() === $position->getKind() &&
			$this->getPositionInPage() === $position->getPositionInPage()
		);
	}
}
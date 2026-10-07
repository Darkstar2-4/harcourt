<?php

namespace WPML\StringTranslation\Infrastructure\StringCore\Command;

use WPML\StringTranslation\Application\StringCore\Command\SaveStringPositionsCommandInterface;
use WPML\StringTranslation\Application\StringCore\Domain\StringItem;
use WPML\StringTranslation\Application\StringCore\Domain\StringPosition;
use WPML\StringTranslation\Application\StringCore\Query\Criteria\DomainValueAndContextCriteria;
use WPML\StringTranslation\Application\StringCore\Query\FindByDomainValueAndContextQueryInterface;
use WPML\StringTranslation\Application\StringCore\Command\InsertStringPositionsCommandInterface;

class SaveStringPositionsCommand implements SaveStringPositionsCommandInterface {

	private $findByDomainValueAndContextQuery;

	private $insertStringPositionsCommand;

	public function __construct(
		FindByDomainValueAndContextQueryInterface $findByDomainValueAndContextQuery,
		InsertStringPositionsCommandInterface     $insertStringPositionsCommand
	) {
		$this->findByDomainValueAndContextQuery = $findByDomainValueAndContextQuery;
		$this->insertStringPositionsCommand     = $insertStringPositionsCommand;
	}

	private function findStringFieldsByDomainValueAndContext( array $strings, array $fields ): array {
		$criteria = new DomainValueAndContextCriteria( $strings, $fields );
		return $this->findByDomainValueAndContextQuery->execute( $criteria );
	}

	public function run( array $strings ) {
		$strings = $this->findStringFieldsByDomainValueAndContext( $strings, ['id', 'positions'] );

		$newPositions = [];
		foreach ( $strings as $string ) {
			$newPositions = array_merge(
				$newPositions,
				$string->getNewPositions()
			);
		}
		$this->insertStringPositionsCommand->run( $newPositions );
	}
}
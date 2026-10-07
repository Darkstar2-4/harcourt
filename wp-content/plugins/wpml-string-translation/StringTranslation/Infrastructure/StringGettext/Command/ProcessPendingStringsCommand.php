<?php

namespace WPML\StringTranslation\Infrastructure\StringGettext\Command;

use WPML\StringTranslation\Application\StringCore\Domain\StringItem;
use WPML\StringTranslation\Application\StringCore\Domain\StringPosition;
use WPML\StringTranslation\Application\StringGettext\Command\ProcessPendingStringsCommandInterface;
use WPML\StringTranslation\Application\StringCore\Command\LoadExistingStringTranslationsCommandInterface;
use WPML\StringTranslation\Application\StringCore\Command\SaveStringsCommandInterface;
use WPML\StringTranslation\Application\StringCore\Command\InsertStringTranslationsCommandInterface;
use WPML\StringTranslation\Application\StringCore\Repository\TranslationsRepositoryInterface;
use WPML\StringTranslation\Application\StringCore\Domain\Factory\StringItemFactory;
use WPML\StringTranslation\Application\StringCore\Command\SaveStringPositionsCommandInterface;
use WPML\StringTranslation\Application\Setting\Repository\SettingsRepositoryInterface;
use WPML\StringTranslation\Application\StringCore\Command\UpdateStringsCommandInterface;

class ProcessPendingStringsCommand implements ProcessPendingStringsCommandInterface {

	const TIME_LIMIT = 60;

	private $saveStringsCommand;

	private $translationsRepository;

	private $settingsRepository;

	private $saveStringPositionsCommand;

	private $loadExistingStringTranslationsCommand;

	private $insertStringTranslations;

	private $updateStringsCommand;

	private $stringItemFactory;

	public function __construct(
		SaveStringsCommandInterface $saveStringsCommand,
		TranslationsRepositoryInterface $translationsRepository,
		SettingsRepositoryInterface $settingsRepository,
		SaveStringPositionsCommandInterface $saveStringPositionsCommand,
		LoadExistingStringTranslationsCommandInterface $loadExistingStringTranslationsCommand,
		InsertStringTranslationsCommandInterface $insertStringTranslations,
		UpdateStringsCommandInterface $updateStringsCommand,
		StringItemFactory $stringItemFactory
	) {
		$this->saveStringsCommand                    = $saveStringsCommand;
		$this->translationsRepository                = $translationsRepository;
		$this->settingsRepository                    = $settingsRepository;
		$this->saveStringPositionsCommand            = $saveStringPositionsCommand;
		$this->loadExistingStringTranslationsCommand = $loadExistingStringTranslationsCommand;
		$this->insertStringTranslations              = $insertStringTranslations;
		$this->updateStringsCommand                  = $updateStringsCommand;
		$this->stringItemFactory                     = $stringItemFactory;
	}

	public function run( array $allPendingStrings ) : bool {
		$createString = function( array $stringData, string $domain, string $text, ?string $name = null, ?string $context = null ) {
			return $this->stringItemFactory->create(
				$domain,
				$text,
				$context,
				[
					'name'          => $name,
					'componentId'   => isset( $stringData['cmp'] ) ? $stringData['cmp'][0] : null,
					'componentType' => isset( $stringData['cmp'] ) ? $stringData['cmp'][1] : null,
					'stringType'    => StringItem::STRING_TYPE_AUTOREGISTER,
				]
			);
		};

		$startTime = time();

		foreach ( $allPendingStrings as $domain => $pendingStrings ) {
			$strings              = [];
			$stringsWithPositions = [];
			foreach ( $pendingStrings as $textAndContext => $stringData ) {
				list( $text, $context ) = StringItem::parseTextAndContextKey( $textAndContext );
				$allStringsForKey       = [];

				if ( isset( $stringData['names'] ) && is_array( $stringData['names'] ) && count( $stringData['names'] ) > 0 ) {
					foreach ( $stringData['names'] as $name ) {
						$allStringsForKey[] = $createString( $stringData, $domain, $text, $name, $context );
					}
				} else {
					$allStringsForKey[] = $createString( $stringData, $domain, $text, null, $context );
				}

				foreach ( $stringData['urls'] as $url ) {
					foreach ( $allStringsForKey as $string ) {
						$position = new StringPosition(
							$url['kind'],
							$url['url'],
							$string
						);
						$string->addPosition( $position );
					}
				}

				if ( isset( $stringData['saveStringInDb'] ) && $stringData['saveStringInDb'] ) {
					foreach ( $allStringsForKey as $string ) {
						$strings[] = $string;
					}
				}
				foreach ( $allStringsForKey as $string ) {
					$stringsWithPositions[] = $string;
				}

				if ( time() - $startTime > self::TIME_LIMIT ) {
					return false;
				}
			}

			if ( count( $strings ) > 0 ) {
				$this->saveStringsCommand->run( $strings );
				$this->loadExistingStringTranslationsCommand->run( $strings );
			}

			if ( count( $stringsWithPositions ) > 0 ) {
				$this->saveStringPositionsCommand->run( $stringsWithPositions );
			}
		}

		return true;
	}
}

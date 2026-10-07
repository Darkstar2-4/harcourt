<?php

namespace WPML\StringTranslation\Infrastructure\StringGettext\Repository;

use WPML\StringTranslation\Application\StringCore\Domain\StringItem;
use WPML\StringTranslation\Application\Setting\Repository\FilesystemRepositoryInterface;
use WPML\StringTranslation\Application\StringGettext\Repository\QueueStorageInterface;
use WPML\StringTranslation\Application\StringCore\Domain\Factory\StringItemFactory;

class QueuePhpFileStorage implements QueueStorageInterface {

	private $filesystemRepository;

	private $stringItemFactory;

	public function __construct(
		FilesystemRepositoryInterface $filesystemRepository,
		StringItemFactory $stringItemFactory
	) {
		$this->filesystemRepository = $filesystemRepository;
		$this->stringItemFactory    = $stringItemFactory;
	}

	private function get( string $phpFilepath ): array {
		if ( ! file_exists( $phpFilepath ) || ! is_readable( $phpFilepath ) ) {
			return [];
		}

		$queueDir = realpath( $this->filesystemRepository->getQueueDir() );
		$realpath = realpath( $phpFilepath );
		if ( false === $queueDir || false === $realpath
			|| ! is_file( $realpath )
			|| strpos( $realpath, $queueDir . DIRECTORY_SEPARATOR ) !== 0
		) {
			return [];
		}

		$result = include $realpath;
		if ( ! $result || ! is_array( $result ) ) {
			return [];
		}

		return isset( $result['items'] ) && is_array( $result['items'] ) ? $result['items'] : [];
	}

	public function getPendingStringDomainNames(): array {
		return $this->filesystemRepository->getPendingStringDomainNames( 'php' );
	}

	public function getProcessedStringDomainNames(): array {
		return $this->filesystemRepository->getProcessedStringDomainNames( 'php' );
	}

	public function getProcessedStringsByDomain( string $domain ): array {
		return $this->get( $this->filesystemRepository->getProcessedStringsFilepath( $domain, 'php' ) );
	}

	public function getPendingStringsByDomain( string $domain ): array {
		return $this->get( $this->filesystemRepository->getPendingStringsFilepath( $domain, 'php' ) );
	}
}
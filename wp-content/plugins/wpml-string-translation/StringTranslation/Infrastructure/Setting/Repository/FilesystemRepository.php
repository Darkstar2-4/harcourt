<?php

namespace WPML\StringTranslation\Infrastructure\Setting\Repository;

use WPML\StringTranslation\Application\Setting\Repository\FilesystemRepositoryInterface;
use WPML\FP\Str;

class FilesystemRepository implements FilesystemRepositoryInterface {

	const ENCODED_DOMAIN_PREFIX = 'wpmlenc-';

	public function createQueueDir() {
		if ( ! file_exists( $this->getWpmlDir() ) ) {
			mkdir( $this->getWpmlDir(), 0777, true );
		}

		if ( file_exists( $this->getQueueDir() ) ) {
			return;
		}

		mkdir( $this->getQueueDir(), 0777, true );
	}

	private function getWpmlDir(): string {
		return WP_LANG_DIR . '/wpml/';
	}

	public function getQueueDir(): string {
		$subdir = '';
		if ( is_multisite() ) {
			$subdir = get_current_blog_id() . '/';
		}

		return $this->getWpmlDir() . 'queue/' . $subdir;
	}

	public function getFilepath( string $filename ): string {
		return $this->getQueueDir() . $filename;
	}

	public function getProcessedStringsFilepath( string $domain, string $ext = 'php' ): string {
		return $this->getQueueDir() . $this->encodeDomain( $domain ) . '.' . $this->validateExt( $ext );
	}

	public function getPendingStringsFilepath( string $domain, string $ext = 'php' ): string {
		return $this->getQueueDir() . $this->encodeDomain( $domain ) . '_pending.' . $this->validateExt( $ext );
	}

	private function encodeDomain( string $domain ): string {
		if ( strpos( $domain, self::ENCODED_DOMAIN_PREFIX ) !== 0
			&& preg_match( '/^[A-Za-z0-9_-]+(\.[A-Za-z0-9_-]+)*$/', $domain )
		) {
			return $domain;
		}

		return self::ENCODED_DOMAIN_PREFIX . bin2hex( $domain );
	}

	private function decodeDomain( string $filename ): string {
		if ( strpos( $filename, self::ENCODED_DOMAIN_PREFIX ) !== 0 ) {
			return $filename;
		}

		$encoded = substr( $filename, strlen( self::ENCODED_DOMAIN_PREFIX ) );
		if ( '' === $encoded ) {
			return '';
		}

		if ( strlen( $encoded ) % 2 === 0 && ctype_xdigit( $encoded ) ) {
			return hex2bin( $encoded );
		}

		return $filename;
	}

	private function validateExt( string $ext ): string {
		if ( ! preg_match( '/^[A-Za-z0-9]+$/', $ext ) ) {
			throw new \InvalidArgumentException( 'Invalid queue file extension.' );
		}

		return $ext;
	}

	public function getDomainFromFilepath( string $filepath ): string {
		$parts    = explode( '/', $filepath );
		$filename = $parts[ count( $parts ) - 1 ];

		$filenameParts = explode( '.', $filename );
		array_pop( $filenameParts );
		$filename = implode( '.', $filenameParts );

		if ( substr( $filename, -strlen( '_pending' ) ) === '_pending' ) {
			$filename = substr( $filename, 0, -strlen( '_pending' ) );
		}

		return $this->decodeDomain( $filename );
	}

	private function getQueueFileData( $ext = 'php' ): array {
		$filenames = [];
		if ( file_exists( $this->getQueueDir() ) ) {
			$filenames = array_filter(
				scandir( $this->getQueueDir() ),
				function( $filename ) use ( $ext ) {
					$tmpFileExt = '_tmp.' . $ext;
					return substr( $filename, -strlen( $tmpFileExt ) ) !== $tmpFileExt;
				}
			);
		}
		$fileData  = [
			'processed' => [
				'strings' => [
					'filenames' => [],
					'filePaths' => [],
				],
				'domains' => [],
			],
			'pending' => [
				'strings' => [
					'filenames' => [],
					'filePaths' => [],
				],
				'domains' => [],
				'paths'   => [],
			],
		];

		foreach ( $filenames as $filename ) {
			$pendingSettingsExt = '_settings_pending.' . $ext;
			$pendingStringsExt  = '_pending.' . $ext;
			$stringsExt         = '.' . $ext;

			$filepath = $this->getQueueDir() . $filename;
			$domain   = $this->getDomainFromFilepath( $filename );

			if ( substr( $filename, -strlen( $pendingStringsExt ) ) === $pendingStringsExt ) {
				$fileData['pending']['strings']['filenames'][] = $filename;
				$fileData['pending']['strings']['filePaths'][] = $filepath;
				$fileData['pending']['domains'][]              = $domain;
			} else if ( substr( $filename, -strlen( $stringsExt ) ) === $stringsExt ) {
				$fileData['processed']['strings']['filenames'][] = $filename;
				$fileData['processed']['strings']['filePaths'][] = $filepath;
				$fileData['processed']['domains'][]              = $domain;
			}
		}

		$fileData['processed']['domains'] = array_unique( $fileData['processed']['domains'] );
		$fileData['pending']['domains']   = array_unique( $fileData['pending']['domains'] );

		return $fileData;
	}

	public function getPendingStringDomainNames( string $ext = 'php' ): array {
		return $this->getQueueFileData( $ext )['pending']['domains'];
	}

	public function getProcessedStringDomainNames( string $ext = 'php' ): array {
		return $this->getQueueFileData( $ext )['processed']['domains'];
	}

	public function getProcessedStringFilenames( string $ext = 'php' ): array {
		return $this->getQueueFileData( $ext )['processed']['strings']['filenames'];
	}

	public function getProcessedStringFilePaths( string $ext = 'php' ): array {
		return $this->getQueueFileData( $ext )['processed']['strings']['filePaths'];
	}

	public function getPendingStringFilenames( string $ext = 'php' ): array {
		return $this->getQueueFileData( $ext )['pending']['strings']['filenames'];
	}

	public function getPendingStringFilePaths( string $ext = 'php' ): array {
		return $this->getQueueFileData( $ext )['pending']['strings']['filePaths'];
	}
}
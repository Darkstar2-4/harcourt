<?php

namespace WPML\StringTranslation\Application\StringGettext\Repository;

use WPML\StringTranslation\Application\StringCore\Domain\StringItem;

interface QueueRepositoryInterface {
	public function addCurrentUrlString( string $text, string $domain, ?string $context = null );
	public function getCurrentUrlStrings(): array;
	public function unloadStrings();
	public function isStringAlreadyRegistered( string $text, string $domain, ?string $context = null, ?string $name = null ): bool;
	public function isStringAlreadyTrackedOnUrl( string $text, string $domain, string $requestUrl, ?string $context = null ): bool;
	public function queueStringAsPending( string $text, string $domain, ?string $context = null, ?string $name = null ): bool;
	public function canTrackString( string $text, string $domain, ?string $context = null ): bool;
	public function trackString( string $text, string $domain, string $requestUrl, ?string $context = null );
	public function savePendingStringsQueue();
	public function loadPendingStrings(): array;
	public function markPendingStringsAsProcessed();
	public function getPendingStringsByDomain( string $domain ): array;
	public function removeProcessedStrings( array $strings );
}

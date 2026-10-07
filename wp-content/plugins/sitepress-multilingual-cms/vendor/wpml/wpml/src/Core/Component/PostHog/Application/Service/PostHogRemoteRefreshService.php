<?php

namespace WPML\Core\Component\PostHog\Application\Service;

use WPML\Core\Component\PostHog\Application\Repository\PostHogRefreshRateLimitRepositoryInterface;
use WPML\Core\Component\PostHog\Domain\RefreshSignatureValidator;
use WPML\Core\SharedKernel\Component\Installer\Application\Query\WpmlSiteKeyQueryInterface;

class PostHogRemoteRefreshService {

  private $siteKeyQuery;

  private $signatureValidator;

  private $rateLimitRepository;

  private $checkService;


  public function __construct(
    WpmlSiteKeyQueryInterface $siteKeyQuery,
    RefreshSignatureValidator $signatureValidator,
    PostHogRefreshRateLimitRepositoryInterface $rateLimitRepository,
    CheckPostHogShouldRecordService $checkService
  ) {
    $this->siteKeyQuery        = $siteKeyQuery;
    $this->signatureValidator  = $signatureValidator;
    $this->rateLimitRepository = $rateLimitRepository;
    $this->checkService        = $checkService;
  }


  public function refresh( int $timestamp, string $signature ): array {
    $siteKey = $this->siteKeyQuery->get();

    if ( ! $siteKey ) {
      return [ 'success' => false, 'error' => 'no_site_key', 'code' => 500 ];
    }

    if ( ! $this->signatureValidator->validate( $siteKey, $timestamp, $signature ) ) {
      return [ 'success' => false, 'error' => 'invalid_signature', 'code' => 401 ];
    }

    if ( $this->rateLimitRepository->isRateLimited() ) {
      return [ 'success' => false, 'error' => 'rate_limited', 'code' => 429 ];
    }

    $this->rateLimitRepository->setRateLimit();

    $result = $this->checkService->run( true );

    return [ 'success' => $result, 'code' => $result ? 200 : 503 ];
  }


}

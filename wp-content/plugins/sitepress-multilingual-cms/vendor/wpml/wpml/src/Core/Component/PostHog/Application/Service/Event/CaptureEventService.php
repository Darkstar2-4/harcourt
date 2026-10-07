<?php

namespace WPML\Core\Component\PostHog\Application\Service\Event;

use WPML\Core\Component\PostHog\Application\Cookies\CookiesInterface;
use WPML\Core\Component\PostHog\Application\Repository\PostHogStateRepositoryInterface;
use WPML\Core\Component\PostHog\Domain\Config\Config;
use WPML\Core\Component\PostHog\Domain\Event\CaptureInterface;
use WPML\Core\Component\PostHog\Domain\Event\EventInterface;
use WPML\Core\Component\PostHog\Domain\Event\TEAEventInterface;
use WPML\Core\Component\PostHog\Domain\TrackingMode;
use WPML\Core\SharedKernel\Component\Installer\Application\Query\WpmlSiteKeyQueryInterface;
use WPML\Core\SharedKernel\Component\Site\Application\Query\SiteUrlQueryInterface;
use WPML\Core\SharedKernel\Component\User\Application\Query\UserQueryInterface;
use WPML\PHP\Exception\RemoteException;

class CaptureEventService {

  private $postHogStateRepository;

  private $cookies;

  private $captureEvent;

  private $userQuery;

  private $siteKeyQuery;

  private $siteUrlQuery;


  public function __construct(
    PostHogStateRepositoryInterface $postHogStateRepository,
    CookiesInterface $cookies,
    CaptureInterface $captureEvent,
    UserQueryInterface $userQuery,
    WpmlSiteKeyQueryInterface $siteKeyQuery,
    SiteUrlQueryInterface $siteUrlQuery
  ) {
    $this->postHogStateRepository = $postHogStateRepository;
    $this->cookies                = $cookies;
    $this->captureEvent           = $captureEvent;
    $this->userQuery              = $userQuery;
    $this->siteKeyQuery           = $siteKeyQuery;
    $this->siteUrlQuery           = $siteUrlQuery;
  }


  public function capture(
    Config $config,
    EventInterface $event,
    array $personProperties = []
  ): bool {
    $trackingMode = $this->postHogStateRepository->getTrackingMode();
    $isTeaEvent   = $event instanceof TEAEventInterface;

    if ( ! TrackingMode::isEventAllowed( $trackingMode, $isTeaEvent ) ) {
      return false;
    }

    $event->addProperties( [ 'tracking_mode' => $trackingMode ] );

    $properties = $event->getProperties();

    $apiKey     = $config->getApiKey();
    $host       = $config->getHost();
    $distinctId = $properties['distinct_id'] ?? $this->cookies->getDistinctId();
    $sessionId  = $properties['session_id'] ?? $this->cookies->getSessionId() ?: '';

    if ( ! $distinctId ) {
      return false;
    }

    return $this->captureEvent->capture(
      $apiKey,
      $host,
      $distinctId,
      $sessionId,
      $event,
      $this->preparePersonProps( $personProperties )
    );
  }


  private function preparePersonProps( array $personProps = [] ): array {

    if ( ! isset( $personProps['wp_email'] ) ) {
      $currentUser             = $this->userQuery->getCurrent();
      $personProps['wp_email'] = $currentUser ? $currentUser->getEmail() : '';
    }

    if ( ! isset( $personProps['site_key'] ) ) {
      $personProps['site_key'] = $this->siteKeyQuery->get();
    }

    if ( ! isset( $personProps['site_url'] ) ) {
      $personProps['site_url'] = $this->siteUrlQuery->get();
    }

    return $personProps;
  }


}

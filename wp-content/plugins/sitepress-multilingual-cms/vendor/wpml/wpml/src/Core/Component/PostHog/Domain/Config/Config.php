<?php

namespace WPML\Core\Component\PostHog\Domain\Config;

class Config {

  const DEFAULT_API_KEY = 'phc_UUIWtbxYIro1TjT0zROVCJA2heo5jGOlYsn2NV2Fm6A';
  const DEFAULT_HOST = 'https://us.i.posthog.com';
  const DEFAULT_DISABLE_SURVEYS = true;
  const DEFAULT_AUTO_CAPTURE = false;
  const DEFAULT_CAPTURE_PAGE_VIEW = false;
  const DEFAULT_CAPTURE_PAGE_LEAVE = false;
  const DEFAULT_DISABLE_SESSION_RECORDING = false;

  const DEFAULT_PERSON_PROFILES = 'identified_only';

  private $apiKey;

  private $host;

  private $personProfiles;

  private $disableSurveys;

  private $autoCapture;

  private $capturePageView;

  private $capturePageLeave;

  private $disableSessionRecording;


  public function __construct(
    string $apiKey,
    string $host,
    string $personProfiles,
    bool $disableSurveys,
    bool $autoCapture,
    bool $capturePageView,
    bool $capturePageLeave,
    bool $disableSessionRecording
  ) {
    $this->apiKey                  = $apiKey;
    $this->host                    = $host;
    $this->personProfiles          = $personProfiles;
    $this->disableSurveys          = $disableSurveys;
    $this->autoCapture             = $autoCapture;
    $this->capturePageView         = $capturePageView;
    $this->capturePageLeave        = $capturePageLeave;
    $this->disableSessionRecording = $disableSessionRecording;
  }


  public function setApiKey( string $apiKey ) {
    $this->apiKey = $apiKey;
  }


  public function setHost( string $host ) {
    $this->host = $host;
  }


  public function setDisableSurveys( bool $disableSurveys ) {
    $this->disableSurveys = $disableSurveys;
  }


  public function setAutoCapture( bool $autoCapture ) {
    $this->autoCapture = $autoCapture;
  }


  public function setCapturePageView( bool $capturePageView ) {
    $this->capturePageView = $capturePageView;
  }


  public function setCapturePageLeave( bool $capturePageLeave ) {
    $this->capturePageLeave = $capturePageLeave;
  }


  public function setDisableSessionRecording( bool $disableSessionRecording ) {
    $this->disableSessionRecording = $disableSessionRecording;
  }


  public function setPersonProfiles( string $personProfiles ) {
    $this->personProfiles = $personProfiles;
  }


  public function getApiKey(): string {
    return defined( 'WPML_POSTHOG_API_KEY' ) ?
      constant( 'WPML_POSTHOG_API_KEY' ) :
      $this->apiKey;
  }


  public function getHost(): string {
    return $this->host;
  }


  public function getPersonProfiles(): string {
    return $this->personProfiles;
  }


  public function getDisableSurveys(): bool {
    return $this->disableSurveys;
  }


  public function getAutoCapture(): bool {
    return $this->autoCapture;
  }


  public function getCapturePageView(): bool {
    return $this->capturePageView;
  }


  public function getCapturePageLeave(): bool {
    return $this->capturePageLeave;
  }


  public function getDisableSessionRecording(): bool {
    return $this->disableSessionRecording;
  }


}

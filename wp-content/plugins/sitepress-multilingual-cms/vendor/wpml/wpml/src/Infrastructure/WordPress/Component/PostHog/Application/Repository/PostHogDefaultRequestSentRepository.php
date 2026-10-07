<?php

namespace WPML\Infrastructure\WordPress\Component\PostHog\Application\Repository;

use WPML\Core\Component\PostHog\Application\Repository\PostHogDefaultRequestSentRepositoryInterface;
use WPML\Core\Port\Persistence\OptionsInterface;
use WPML\Core\Port\Persistence\QueryPrepareInterface;

class PostHogDefaultRequestSentRepository implements PostHogDefaultRequestSentRepositoryInterface {

  const OPTION_KEY = 'wpml_posthog_default_request_sent';

  private $options;

  private $wpdb;

  private $queryPreparer;


  public function __construct(
    $wpdb,
    QueryPrepareInterface $queryPreparer,
    OptionsInterface $options
  ) {
    $this->wpdb          = $wpdb;
    $this->queryPreparer = $queryPreparer;
    $this->options       = $options;
  }


  public function isSent(): bool {
    return boolval(
      $this->options->get( self::OPTION_KEY, false )
    );
  }


  public function tryAcquireLock(): bool {
    $query = "INSERT INTO {$this->wpdb->options} (option_name, option_value, autoload) 
         VALUES (%s, %s, 'off')
         ON DUPLICATE KEY UPDATE option_value = option_value";

    $sqlPrepared = $this->queryPreparer->prepare(
      $query,
      self::OPTION_KEY,
      '1'
    );

    $result = $this->wpdb->query( $sqlPrepared );

    return $result === 1;
  }


  public function setIsSent( bool $isSent ) {
    $this->options->save( self::OPTION_KEY, $isSent );
  }


  public function delete() {
    $this->options->delete( self::OPTION_KEY );
  }


}

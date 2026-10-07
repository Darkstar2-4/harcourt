<?php

namespace WPML\Infrastructure\WordPress\Component\Translator\Domain\Query;

use WPML\Core\Port\Persistence\Exception\DatabaseErrorException;
use WPML\Core\Port\Persistence\QueryHandlerInterface;
use WPML\Core\Port\Persistence\QueryPrepareInterface;
use WPML\Core\SharedKernel\Component\Translator\Domain\Query\TranslatorLanguagePairsQueryInterface;
use WPML\Core\SharedKernel\Component\Translator\Domain\Query\TranslatorsQueryInterface;
use WPML\Core\SharedKernel\Component\Translator\Domain\Translator;


class TranslatorsQuery implements TranslatorsQueryInterface {

  const CAPABILITY_TRANSLATE = 'translate';

  private $queryHandler;

  private $queryPrepare;

  private $translatorLanguagePairsQuery;


  public function __construct(
    QueryHandlerInterface $queryHandler,
    QueryPrepareInterface $queryPrepare,
    TranslatorLanguagePairsQueryInterface $translatorLanguagePairsQuery
  ) {
    $this->queryHandler                 = $queryHandler;
    $this->queryPrepare                 = $queryPrepare;
    $this->translatorLanguagePairsQuery = $translatorLanguagePairsQuery;
  }


  public function get() {
    return $this->getTranslators();
  }


  public function getById( int $id ) {
    $translators = $this->getTranslators( ' AND user.ID=%d', [ $id ] );

    return count( $translators ) ? $translators[0] : null;
  }


  public function getCurrentlyLoggedId() {
    $currentUser = \wp_get_current_user();

    if ( $currentUser->ID === 0 ) {
      return null;
    }

    return $this->getById( $currentUser->ID );
  }


  private function getTranslators( string $whereClause = '', array $whereParams = [] ) {
    $sql = "SELECT user.ID, user.display_name, user.user_nicename
      FROM {$this->queryPrepare->prefix()}users user
      INNER JOIN {$this->queryPrepare->prefix()}usermeta umeta
      ON umeta.user_id = user.ID
      AND CAST(umeta.meta_key AS BINARY)=%s
      AND umeta.meta_value LIKE %s" . $whereClause;

    $params = array_merge(
      [
        $this->queryPrepare->prefix() . 'capabilities',
        '%' . self::CAPABILITY_TRANSLATE . '%'
      ],
      $whereParams
    );

    $preparedSql = $this->queryPrepare->prepare( $sql, ...$params );

    try {
      $translators = $this->queryHandler->query( $preparedSql )->getResults();
    } catch ( DatabaseErrorException $e ) {
      $translators = [];
    }

    $translatorsIds = array_map(
      function ( $translator ) {
        return $translator['ID'];
      },
      $translators
    );

    $translatorsLanguagePairs = $this->translatorLanguagePairsQuery->getForManyTranslators(
      $translatorsIds
    );

    $translators = array_filter(
      $translators,
      function ( $translator ) use ( $translatorsLanguagePairs ) {
        return isset( $translatorsLanguagePairs[ $translator['ID'] ] );
      }
    );

    return array_map(
      function ( $translator ) use ( $translatorsLanguagePairs ) {
        return new Translator(
          $translator['ID'],
          $translator['display_name'],
          $translator['user_nicename'],
          $translatorsLanguagePairs[ $translator['ID'] ]
        );
      },
      $translators
    );
  }


}

<?php

namespace WPML\Infrastructure\WordPress\Component\Translator\Domain\Query;

use WPML\Core\Port\Persistence\Exception\DatabaseErrorException;
use WPML\Core\Port\Persistence\QueryHandlerInterface;
use WPML\Core\Port\Persistence\QueryPrepareInterface;
use WPML\Core\SharedKernel\Component\Translator\Domain\LanguagePair;
use WPML\Core\SharedKernel\Component\Translator\Domain\Query\TranslatorLanguagePairsQueryInterface;

class TranslatorLanguagePairsQuery implements TranslatorLanguagePairsQueryInterface {


  const LANGUAGE_PAIRS_META_KEY = 'language_pairs';

  private $queryHandler;

  private $queryPrepare;


  public function __construct(
    QueryHandlerInterface $queryHandler,
    QueryPrepareInterface $queryPrepare
  ) {
    $this->queryHandler = $queryHandler;
    $this->queryPrepare = $queryPrepare;
  }


  public function getForSingleTranslator( int $translatorId ): array {
    $metaKey = $this->queryPrepare->prefix() . self::LANGUAGE_PAIRS_META_KEY;

    $languagePairs = get_user_meta( $translatorId, $metaKey, true );

    $languagePairsDtoArray = [];

    if ( is_array( $languagePairs ) ) {
      foreach ( $languagePairs as $languagePairFrom => $languagePairTo ) {
        $languagePairsDtoArray[] = new LanguagePair(
          $languagePairFrom,
          array_keys( $languagePairTo )
        );
      }
    }

    return $languagePairsDtoArray;
  }


  public function getForManyTranslators( array $translatorsIds ): array {
    $translatorsIdsIn = implode( ',', $translatorsIds );

    if ( empty( $translatorsIdsIn ) ) {
      return [];
    }

    $sql = "SELECT umeta.user_id, umeta.meta_value 
    FROM {$this->queryPrepare->prefix()}usermeta umeta 
    WHERE umeta.meta_key=%s
    AND umeta.user_id IN($translatorsIdsIn)";

    $preparedSql = $this->queryPrepare->prepare(
      $sql,
      $this->queryPrepare->prefix() . 'language_pairs'
    );

    try {
      $translatorsLanguagePairsMeta = $this->queryHandler->query( $preparedSql )->getResults();
    } catch ( DatabaseErrorException $e ) {
      $translatorsLanguagePairsMeta = [];
    }

    $translatorsIdsWithLanguagePairsArray = [];

    foreach ( $translatorsLanguagePairsMeta as $languagePairsMeta ) {
      $languagePairsArray = unserialize( $languagePairsMeta['meta_value'] );

      if ( ! is_array( $languagePairsArray ) ) {
        continue;
      }

      if ( ! in_array( $languagePairsMeta['user_id'], $translatorsIds ) ) {
        continue;
      }

      foreach ( $languagePairsArray as $languagePairFrom => $languagePairTo ) {
        $translatorsIdsWithLanguagePairsArray[ $languagePairsMeta['user_id'] ][]
          = new LanguagePair( $languagePairFrom, array_keys( $languagePairTo ) );
      }
    }

    return $translatorsIdsWithLanguagePairsArray;
  }


}

<?php

namespace WPML\Core\Component\WordsToTranslate\Application\Service;

use WPML\Core\Component\WordsToTranslate\Domain\Item;
use WPML\Core\Component\WordsToTranslate\Domain\Job\Job;
use WPML\Core\Component\WordsToTranslate\Domain\Job\JobDTO;
use WPML\Core\Component\WordsToTranslate\Domain\Job\Provider as ProviderJob;
use WPML\Core\Component\WordsToTranslate\Domain\Job\Query\TranslationEngineQueryInterface;
use WPML\Core\Component\WordsToTranslate\Domain\Post\Provider as ProviderPost;
use WPML\Core\Component\WordsToTranslate\Domain\Provider;
use WPML\Core\Component\WordsToTranslate\Domain\StringBatch\Provider as ProviderStringBatch;
use WPML\Core\Component\WordsToTranslate\Domain\StringPackage\Provider as ProviderStringPackage;
use WPML\Core\Component\WordsToTranslate\Domain\Strings\Provider as ProviderString;
use WPML\PHP\Exception\InvalidArgumentException;
use WPML\PHP\Exception\RuntimeException;

class WordsToTranslateService {

  private $provider;

  private $providerJob;

  private $translationEngineQuery;


  public function __construct(
    Provider $provider,
    ProviderJob $providerJob,
    TranslationEngineQueryInterface $translationEngineQuery
  ) {
    $this->provider = $provider;
    $this->providerJob = $providerJob;
    $this->translationEngineQuery = $translationEngineQuery;
  }


  public function getForIdAndType( $id, $type, $langs, $freshTranslation = false ) {
    switch ( $type ) {
      case ProviderPost::TYPE:
        return $this->getForPost( $id, $langs, $freshTranslation );
      case ProviderString::TYPE:
        return $this->getForString( $id, $langs, $freshTranslation );
      case ProviderStringPackage::TYPE:
        return $this->getForStringPackage( $id, $langs, $freshTranslation );
      default:
        throw new InvalidArgumentException( sprintf( 'Item type "%s" is not recognized.', $type ) );
    }
  }


  public function getForPost( $idPost, $langs, $freshTranslation = false ) {
    return $this->provider->getByIdAndTypeForLangs(
      $idPost,
      ProviderPost::TYPE,
      $langs,
      $freshTranslation
    );
  }


  public function getForString( $idString, $targetLangs, $freshTranslation = false ) {
    return $this->provider->getByIdAndTypeForLangs(
      $idString,
      ProviderString::TYPE,
      $targetLangs,
      $freshTranslation
    );
  }


  public function getForStringPackage( $idStringPackage, $targetLangs, $freshTranslation = false ) {
    return $this->provider->getByIdAndTypeForLangs(
      $idStringPackage,
      ProviderStringPackage::TYPE,
      $targetLangs,
      $freshTranslation
    );
  }


  public function getForStringBatch( $idBatch, $targetLangs, $freshTranslation = false ) {
    return $this->provider->getByIdAndTypeForLangs(
      $idBatch,
      ProviderStringBatch::TYPE,
      $targetLangs,
      $freshTranslation
    );
  }


  public function getForJob( $idJob, $freshTranslation = false ) {
    return $this->providerJob->getById( $idJob, $freshTranslation );
  }


  public function getForJobDebug( $idJob ) {
    return $this->providerJob->getWithItemById( $idJob );
  }


  public function getCostsPerWordForLang( string $langCode, $sourceLang = null ) {
    return $this->translationEngineQuery->getCostsPerWordForLang( $langCode, $sourceLang );
  }


}

<?php

namespace WPML\Infrastructure\WordPress\Component\Item\Application\Query;

use WPML\Core\Port\Persistence\Exception\DatabaseErrorException;
use WPML\Core\Port\Persistence\QueryHandlerInterface;
use WPML\Core\Port\Persistence\QueryPrepareInterface;
use WPML\Core\SharedKernel\Component\Item\Application\Query\Dto\UntranslatedTypeCountDto;
use WPML\Core\SharedKernel\Component\Item\Application\Query\UntranslatedTypesCountQueryInterface;
use WPML\Core\SharedKernel\Component\Language\Application\Query\LanguagesQueryInterface;
use WPML\Core\SharedKernel\Component\Post\Application\Query\Dto\PostTypeDto;
use WPML\Core\SharedKernel\Component\Post\Application\Query\TranslatableTypesQueryInterface;
use WPML\Core\SharedKernel\Component\Translation\Domain\TranslationStatus;

class UntranslatedTypesCountQuery implements UntranslatedTypesCountQueryInterface {

  const POST_META_KEY_USE_NATIVE_EDITOR = '_wpml_post_translation_editor_native';

  private $queryHandler;

  private $queryPrepare;

  private $translatableTypesQuery;

  private $languagesQuery;


  public function __construct(
    QueryHandlerInterface $queryHandler,
    QueryPrepareInterface $queryPrepare,
    TranslatableTypesQueryInterface $translatableTypesQuery,
    LanguagesQueryInterface $languagesQuery
  ) {
    $this->queryHandler = $queryHandler;
    $this->queryPrepare = $queryPrepare;
    $this->translatableTypesQuery = $translatableTypesQuery;
    $this->languagesQuery = $languagesQuery;
  }


  public function forKind() {
    return UntranslatedTypesCountQueryInterface::KIND_POST;
  }


  public function get( array $queryData = [] ) : array {
    $translatableTypes = $this->translatableTypesQuery->getTranslatable();
    $nativeEditorGlobalSetting = $queryData['nativeEditorGlobalSetting'] ?? false;
    $nativeEditorSettingPerType = $queryData['nativeEditorSettingPerType'] ?? [];

    $typesWithoutAttachmentType = array_filter(
      $translatableTypes,
      function ( $postTypeDto ) {
        return $postTypeDto->getId() !== 'attachment';
      }
    );

    $postTypes = array_map(
      function ( $postTypesDto ) {
        return "'post_" . $postTypesDto->getId() . "'";
      },
      $typesWithoutAttachmentType
    );

    $typesIn = implode( ',', $postTypes );

    $statusesIn = $this->getStatusesIn();
    $defaultLanguageCode = $this->languagesQuery->getDefaultCode();

    $secondaryLanguages = $this->languagesQuery->getSecondary();

    $sql = "
    SELECT translations.post_type,
            COUNT(translations.ID) count
			FROM (
	            SELECT RIGHT(element_type, LENGTH(element_type) - 5) as post_type, posts.ID
	            FROM {$this->queryPrepare->prefix()}icl_translations
	            INNER JOIN {$this->queryPrepare->prefix()}posts posts ON element_id = ID

                LEFT JOIN {$this->queryPrepare->prefix()}postmeta postmeta
                  ON postmeta.post_id = posts.ID
                  AND postmeta.meta_key = %s

	            WHERE element_type IN ($typesIn)
		           AND post_status = 'publish'
		           AND source_language_code IS NULL
		           AND language_code = %s
		           AND (
	                   SELECT COUNT(trid)
	                   FROM {$this->queryPrepare->prefix()}icl_translations icl_translations_inner
	                   INNER JOIN {$this->queryPrepare->prefix()}icl_translation_status icl_translations_status
	                   ON icl_translations_inner.translation_id = icl_translations_status.translation_id
	                   WHERE icl_translations_inner.trid = {$this->queryPrepare->prefix()}icl_translations.trid
	                     AND icl_translations_status.status NOT IN ({$statusesIn})
	                     AND icl_translations_status.needs_update != 1
	               ) < %d
	               AND {$this->getPostMetaConditions( $nativeEditorGlobalSetting, $nativeEditorSettingPerType )}
	         ) as translations
			GROUP BY translations.post_type;
    ";

    $preparedSql = $this->queryPrepare->prepare(
      $sql,
      self::POST_META_KEY_USE_NATIVE_EDITOR,
      $defaultLanguageCode,
      count( $secondaryLanguages )
    );

    try {
      $untranslatedTypesCount = $this->queryHandler->query( $preparedSql )->getResults();
    } catch ( DatabaseErrorException $e ) {
      $untranslatedTypesCount = [];
    }

    return $this->mapTypesToTypeWithCountDto( $untranslatedTypesCount, $translatableTypes );
  }


  public function getSomeIds( $numberOfIdsToFetch, $offset, $type ) {
    $statusesIn = $this->getStatusesIn();
    $defaultLanguageCode = $this->languagesQuery->getDefaultCode();
    $secondaryLanguages = $this->languagesQuery->getSecondary();

    $sql = "
      SELECT p.ID
      FROM {$this->queryPrepare->prefix()}icl_translations AS itr
      INNER JOIN {$this->queryPrepare->prefix()}posts AS p
              ON itr.element_id = p.ID
      LEFT JOIN {$this->queryPrepare->prefix()}postmeta AS pm
             ON pm.post_id = p.ID
            AND pm.meta_key = %s
      WHERE itr.element_type = CONCAT('post_', %s)
        AND p.post_status = 'publish'
        AND itr.source_language_code IS NULL
        AND itr.language_code = %s
        AND (
          SELECT COUNT(inner_itr.trid)
          FROM {$this->queryPrepare->prefix()}icl_translations AS inner_itr
          INNER JOIN {$this->queryPrepare->prefix()}icl_translation_status AS its
                  ON inner_itr.translation_id = its.translation_id
          WHERE inner_itr.trid = itr.trid
            AND its.status NOT IN ({$statusesIn})
            AND its.needs_update != 1
        ) < %d
        AND ( pm.meta_value IS NULL OR pm.meta_value = 'no' )
      ORDER BY p.ID ASC
      LIMIT %d OFFSET %d
    ";

    try {
      $ids = $this->queryHandler->queryColumn(
        $this->queryPrepare->prepare(
          $sql,
          self::POST_META_KEY_USE_NATIVE_EDITOR,
          $type,
          $defaultLanguageCode,
          count( $secondaryLanguages ),
          $numberOfIdsToFetch,
          $offset
        )
      );
      return $ids;
    } catch ( DatabaseErrorException $e ) {
      return [];
    }
  }


  private function getStatusesIn() : string {
    return implode(
      ',',
      [
        TranslationStatus::NOT_TRANSLATED,
        TranslationStatus::ATE_CANCELED
      ]
    );
  }


  private function mapTypesToTypeWithCountDto( array $untranslatedTypesCount, array $translatablePostTypesDto ): array {
    return array_map(
      function ( $typeWithCount ) use ( $translatablePostTypesDto ) {

        $postTypeDto = current(
          array_filter(
            $translatablePostTypesDto,
            function ( $dto ) use ( $typeWithCount ) {
              return $dto->getId() === $typeWithCount['post_type'];
            }
          )
        );

        return new UntranslatedTypeCountDto(
          $postTypeDto ? $postTypeDto->getPlural() : $typeWithCount['post_type'],
          $postTypeDto ? $postTypeDto->getSingular() : $typeWithCount['post_type'],
          $typeWithCount['count'],
          UntranslatedTypesCountQueryInterface::KIND_POST,
          $typeWithCount['post_type']
        );
      },
      $untranslatedTypesCount
    );
  }


  private function getPostMetaConditions(
    bool $nativeEditorGlobalSetting,
    array $nativeEditorSettingPerType
  ) : string {
    return $nativeEditorGlobalSetting
      ? $this->getGlobalNativeEditorCondition( $nativeEditorSettingPerType )
      : $this->getPerPostTypeNativeEditorCondition( $nativeEditorSettingPerType );
  }


  private function getGlobalNativeEditorCondition( array $nativeEditorSettingPerType ) : string {
    $typesNotUsingNativeEditor = $this->getPostTypes( $nativeEditorSettingPerType, false );

    return $typesNotUsingNativeEditor
      ? '(
              (posts.post_type IN (' . $this->queryPrepare->prepareIn( $typesNotUsingNativeEditor ) . ')
                AND postmeta.meta_value IS NULL)
              OR postmeta.meta_value = \'no\'
           )'
      : 'postmeta.meta_value = \'no\'';
  }


  private function getPerPostTypeNativeEditorCondition( array $nativeEditorSettingPerType ) : string {
    $typesUsingNativeEditor = $this->getPostTypes( $nativeEditorSettingPerType, true );

    return $typesUsingNativeEditor
      ? '(
              (posts.post_type NOT IN (' . $this->queryPrepare->prepareIn( $typesUsingNativeEditor ) . ')
                AND postmeta.meta_value IS NULL)
               OR postmeta.meta_value = \'no\'
           )'
      : '( postmeta.meta_value IS NULL OR postmeta.meta_value = \'no\' )';
  }


  private function getPostTypes( array $nativeEditorSettingPerType, bool $usingWpEditor ) : array {
    return array_keys(
      array_filter(
        $nativeEditorSettingPerType,
        function ( $postTypeValue ) use ( $usingWpEditor ) {
          return $postTypeValue === $usingWpEditor;
        }
      )
    );
  }


}

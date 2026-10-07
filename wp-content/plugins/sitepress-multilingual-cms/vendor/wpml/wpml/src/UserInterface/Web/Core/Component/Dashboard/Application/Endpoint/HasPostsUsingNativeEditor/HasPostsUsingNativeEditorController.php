<?php

namespace WPML\UserInterface\Web\Core\Component\Dashboard\Application\Endpoint\HasPostsUsingNativeEditor;

use WPML\Core\Component\Translation\Application\Query\HasPostsUsingNativeEditorQueryInterface;
use WPML\Core\Component\Translation\Application\Repository\SettingsRepository;
use WPML\Core\Port\Endpoint\EndpointInterface;
use WPML\Core\Port\Persistence\Exception\DatabaseErrorException;
use WPML\Core\SharedKernel\Component\Post\Application\Query\Dto\PostTypeDto;
use WPML\Core\SharedKernel\Component\Post\Application\Query\TranslatableTypesQueryInterface;

class HasPostsUsingNativeEditorController implements EndpointInterface {

  private $translationSettingsRepository;

  private $query;

  private $translatableTypesQuery;

  private $translatablePostTypes;


  public function __construct(
    SettingsRepository $translationSettingsRepository,
    TranslatableTypesQueryInterface $translatableTypesQuery,
    HasPostsUsingNativeEditorQueryInterface $query
  ) {
    $this->translationSettingsRepository = $translationSettingsRepository;
    $this->translatableTypesQuery = $translatableTypesQuery;
    $this->translatablePostTypes = null;
    $this->query = $query;
  }


  public function handle( $requestData = null ) : array {
    $results = [
      'success' => true,
      'data' => null,
      'errorMsg' => ''
    ];

    $editorSettings = $this->translationSettingsRepository->getSettings()->getTranslationEditor();

    if ( ! $editorSettings ) {
      $results['success'] = false;
      $results['errorMsg'] = 'Error: getTranslationEditor() is not a valid object.';
      return $results;
    }

    $nativeEditorGlobalSetting = $editorSettings->useNativeEditorForAllPostTypes();
    $postTypesSettings = $editorSettings->getPostTypesUsingNativeEditor();

    $postTypesUsingWpEditor = $nativeEditorGlobalSetting
      ? array_diff( $this->getTranslatablePostTypes(), $this->getPostTypes( $postTypesSettings, false ) )
      : $this->getPostTypes( $postTypesSettings, true );

    try {
      $results['data'] = $this->query->get(
        $this->getTranslatablePostTypes(),
        $postTypesUsingWpEditor
      );
    } catch ( DatabaseErrorException $exception ) {
      $results['success'] = false;
      $results['errorMsg'] = 'Database Error: ' . $exception->getMessage();
    }

    return $results;
  }


  private function getPostTypes( array $postTypesSettings, bool $usingWpEditor ) : array {
    return array_keys(
      array_filter(
        $postTypesSettings,
        function ( $postTypeValue ) use ( $usingWpEditor ) {
          return $postTypeValue === $usingWpEditor;
        }
      )
    );
  }


  private function getTranslatablePostTypes() : array {
    if ( $this->translatablePostTypes === null ) {
      $this->translatablePostTypes = array_filter(
        array_map(
          function ( PostTypeDto $postType ) {
            return $postType->isPublic() && $postType->hasUi() && $postType->getId() !== 'attachment'
              ? $postType->getId()
              : null;
          },
          $this->translatableTypesQuery->getTranslatable()
        )
      );
    }

    return $this->translatablePostTypes;
  }


}

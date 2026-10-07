<?php

namespace WPML;

use WPML\UserInterface\Web\Core\Component\ATE\Application\Endpoint\GetWebsiteContext\GetWebsiteContextController;
use WPML\UserInterface\Web\Core\Component\Dashboard\Application\DashboardController;
use WPML\UserInterface\Web\Core\Component\Dashboard\Application\DashboardRequirements;
use WPML\UserInterface\Web\Core\Component\Dashboard\Application\Endpoint\AutomaticTranslation\CancelAllAutomaticJobsController;
use WPML\UserInterface\Web\Core\Component\Dashboard\Application\Endpoint\GetCredits\GetCreditsController;
use WPML\UserInterface\Web\Core\Component\Dashboard\Application\Endpoint\GetHierarchicalPosts\GetHierarchicaPostsController;
use WPML\UserInterface\Web\Core\Component\Dashboard\Application\Endpoint\GetLocalTranslatorById\GetTranslatorByIdController;
use WPML\UserInterface\Web\Core\Component\Dashboard\Application\Endpoint\GetNeedsUpdateCreatedInCte\GetNeedsUpdateCreatedInCteController;
use WPML\UserInterface\Web\Core\Component\Dashboard\Application\Endpoint\GetPopulatedItemSections\GetPopulatedItemSectionsController;
use WPML\UserInterface\Web\Core\Component\Dashboard\Application\Endpoint\GetPostTaxonomies\GetPostTaxonomiesController;
use WPML\UserInterface\Web\Core\Component\Dashboard\Application\Endpoint\GetPostTerms\GetPostTermsController;
use WPML\UserInterface\Web\Core\Component\Dashboard\Application\Endpoint\GetPosts\GetPostControllerInterface;
use WPML\UserInterface\Web\Core\Component\Dashboard\Application\Endpoint\GetPosts\GetPostsCountController;
use WPML\UserInterface\Web\Core\Component\Dashboard\Application\Endpoint\GetRemoteTranslationService\GetRemoteTranslationServiceController;
use WPML\UserInterface\Web\Core\Component\Dashboard\Application\Endpoint\GetTranslationBatchDefaultName\GetTranslationBatchDefaultName;
use WPML\UserInterface\Web\Core\Component\Dashboard\Application\Endpoint\GetTranslationEditorType\GetTranslationEditorTypeController;
use WPML\UserInterface\Web\Core\Component\Dashboard\Application\Endpoint\GetTranslationStatus\GetTranslationStatusController;
use WPML\UserInterface\Web\Core\Component\Dashboard\Application\Endpoint\GetUntranslatedTypesCount\GetUntranslatedTypesCountController;
use WPML\UserInterface\Web\Core\Component\Dashboard\Application\Endpoint\GetWordsToTranslate\GetCreditsPerWordController;
use WPML\UserInterface\Web\Core\Component\Dashboard\Application\Endpoint\GetWordsToTranslate\GetWordsToTranslateForItemsController;
use WPML\UserInterface\Web\Core\Component\Dashboard\Application\Endpoint\GetWordsToTranslate\GetWordsToTranslateForTypesController;
use WPML\UserInterface\Web\Core\Component\Dashboard\Application\Endpoint\HasPostsUsingNativeEditor\HasPostsUsingNativeEditorController;
use WPML\UserInterface\Web\Core\Component\Dashboard\Application\Endpoint\SaveTranslatorNote\SaveTranslatorNoteController;
use WPML\UserInterface\Web\Core\Component\Dashboard\Application\Endpoint\SendToTranslation\SendToTranslationController;
use WPML\UserInterface\Web\Core\Component\Dashboard\Application\Endpoint\SetReviewTranslationOption\SetReviewTranslationOptionController;
use WPML\UserInterface\Web\Core\Component\Dashboard\Application\Endpoint\TranslateEverything\DisableController;
use WPML\UserInterface\Web\Core\Component\Dashboard\Application\Endpoint\TranslateEverything\EnableController;
use WPML\UserInterface\Web\Core\Component\Dashboard\Application\Endpoint\TranslateEverything\TranslateExistingContentController;
use WPML\UserInterface\Web\Core\Component\Dashboard\Application\Endpoint\TranslationProxy\GetLastPickedUpController;
use WPML\UserInterface\Web\Core\Component\Dashboard\Application\Endpoint\TranslationProxy\GetRemoteJobsCountController;
use WPML\UserInterface\Web\Core\Component\Dashboard\Application\Endpoint\TranslationProxy\SendCommitRequestController;
use WPML\UserInterface\Web\Core\Component\Dashboard\Application\Endpoint\ValidateSelectedTranslationMethods\ValidateSelectedTranslationMethodsController;
use WPML\UserInterface\Web\Core\Component\Dashboard\Application\Endpoint\ValidateTranslationBatchName\ValidateTranslationBatchNameController;
use WPML\UserInterface\Web\Core\Component\Preferences\Application\AutomaticTranslationsSectionController;
use WPML\UserInterface\Web\Core\Component\Preferences\Application\Endpoint\GetEngines\GetEnginesController;
use WPML\UserInterface\Web\Core\Component\Preferences\Application\Endpoint\SaveAutomaticTranslationsSettings\SaveAutomaticTranslationsSettingsController;
use WPML\UserInterface\Web\Core\Component\Troubleshooting\Application\Endpoint\EnableAliasDomainController;
use WPML\UserInterface\Web\Core\Component\Troubleshooting\Application\Endpoint\RegisterAliasDomainController;
use WPML\UserInterface\Web\Core\Component\Troubleshooting\Application\Endpoint\ResetAliasDomainController;
use WPML\UserInterface\Web\Core\Component\Troubleshooting\Application\Endpoint\UpdatePostHogStateController;
use WPML\UserInterface\Web\Core\Component\Troubleshooting\Application\TroubleshootingController;
use WPML\UserInterface\Web\Core\SharedKernel\Config\Endpoint\MethodType;

return [
  'sitepress-multilingual-cms/menu/troubleshooting.php' => [
    'controller'                     => TroubleshootingController::class,
    'legacyExtension'                => 'after_setup_complete_troubleshooting_functions',
    'requiresWPMLSetupToBeCompleted' => true,
    'dependencies'                   => [
      'wpml-node-modules',
      'wp-i18n',
      'lodash'
    ],
    'scripts'                        => [
      [
        'id'            => 'wpml-troubleshooting',
        'src'           => 'public/js/wpml-troubleshooting.js',
        'dependencies'  => [ 'wpml-node-modules', 'wp-i18n', 'lodash' ],
        'dataProvider'  => TroubleshootingController::class,
        'prerequisites' => TroubleshootingController::class,
      ],
    ],
    'styles'                         => [
      'src' => 'public/css/wpml-troubleshooting.css',
    ],
    'endpoints'                      => [
      'updateposthogstate' => [
        'path'    => '/troubleshooting/posthog',
        'method'  => MethodType::POST,
        'handler' => UpdatePostHogStateController::class,
      ],
      'enablealiasdomain' => [
        'path'    => '/troubleshooting/enable-alias-domain',
        'method'  => MethodType::POST,
        'handler' => EnableAliasDomainController::class,
      ],
      'registeraliasdomain' => [
        'path'    => '/troubleshooting/register-alias-domain',
        'method'  => MethodType::POST,
        'handler' => RegisterAliasDomainController::class,
      ],
      'resetaliasdomain' => [
        'path'    => '/troubleshooting/reset-alias-domain',
        'method'  => MethodType::POST,
        'handler' => ResetAliasDomainController::class,
      ],
    ],
  ],
  'tm/menu/main.php'                          => [
    'title' => __( 'Translation Dashboard', 'wpml' ),

    'controller' => DashboardController::class,

    'legacyParentId' => 'WPML',
    'position'       => 1,

    'requirements'                   => DashboardRequirements::class,
    'requiresWPMLSetupToBeCompleted' => true,

    'scripts'                        => [
      [
        'id'            => 'wpml-dashboard',
        'src'           => 'public/js/dashboard.js',
        'prerequisites' => DashboardController::class,
        'dataProvider'  => DashboardController::class,
        'dependencies'  => [ 'wpml-node-modules', 'wp-i18n', 'lodash' ],
        'supportsHMR'   => true,
      ],
      [
        'id'            => 'wpml-notice-glossary',
        'src'           => 'public/js/notice-glossary.js',
        'prerequisites' => DashboardController::class,
        'dependencies'  => [ 'wpml-node-modules', 'wp-i18n', 'lodash' ]
      ],
    ],

    'styles'                         => [
      'src'          => 'public/css/dashboard.css',
      'dependencies' => [ 'otgs-icons' ]
    ],

    'endpoints'                      => [
      'getpopulateditemsections'       => [
        'path'    => '/item-sections/populated',
        'handler' => GetPopulatedItemSectionsController::class,
      ],
      'getposts'                       => [
        'path'    => '/posts',
        'handler' => GetPostControllerInterface::class,
      ],
      'getpostscount'                  => [
        'path'    => '/posts/count',
        'handler' => GetPostsCountController::class,
      ],
      'sendtotranslation'              => [
        'path'    => '/send-to-translation',
        'handler' => SendToTranslationController::class,
        'method'  => 'POST',
        'useAjax' => true,
      ],
      'validatetranslationoptions'     => [
        'path'    => '/send-to-translation/validate-translation-options',
        'handler' => ValidateSelectedTranslationMethodsController::class,
        'method'  => 'POST',
      ],
      'getdefaultbatchname'            => [
        'path'    => '/send-to-translation/default-batch-name',
        'handler' => GetTranslationBatchDefaultName::class,
        'method'  => 'GET',
      ],
      'validatebatchname'              => [
        'path'    => '/send-to-translation/validate-batch-name',
        'handler' => ValidateTranslationBatchNameController::class,
        'method'  => 'POST',
      ],
      'setreviewtranslationoption'     => [
        'path'    => '/set-review-translation-option',
        'handler' => SetReviewTranslationOptionController::class,
        'method'  => 'POST',
      ],
      'gethierarchicalposts'           => [
        'path'    => '/posts/hierarchical',
        'handler' => GetHierarchicaPostsController::class,
      ],
      'getposttaxonomies'              => [
        'path'    => '/posts/taxonomies',
        'handler' => GetPostTaxonomiesController::class,
      ],
      'getpostterms'                   => [
        'path'    => '/posts/terms',
        'handler' => GetPostTermsController::class,
      ],
      'enabletranslateeverything'      => [
        'path'    => '/translate-everything/enable',
        'handler' => EnableController::class,
        'method'  => 'POST',
      ],
      'disabletranslateeverything'     => [
        'path'    => '/translate-everything/disable',
        'handler' => DisableController::class,
        'method'  => 'POST',
      ],
      'cancelallautomaticjobs'     => [
        'path'    => '/cancelallautomaticjobs',
        'handler' => CancelAllAutomaticJobsController::class,
        'method'  => 'GET',
      ],

      'getuntranslatedtypescount'      => [
        'path'    => '/getuntranslatedtypescount',
        'handler' => GetUntranslatedTypesCountController::class,
        'method'  => 'GET',
      ],
      'savetranslatornote'             => [
        'path'    => '/save-translator-note',
        'handler' => SaveTranslatorNoteController::class,
        'method'  => 'POST',
      ],
      'getcredits'                     => [
        'path'    => '/credits',
        'handler' => GetCreditsController::class,
        'method'  => 'GET',
      ],
      'committotranslationproxy'       => [
        'path'    => '/translation-proxy/commit-batch',
        'handler' => SendCommitRequestController::class,
        'method'  => 'POST',
      ],
      'getlastpickedup'                => [
        'path'    => '/tranlsation-proxy/getlastpickedup',
        'handler' => GetLastPickedUpController::class,
        'method'  => 'GET',
      ],
      'getremotejobscount'             => [
        'path'    => '/translation-proxy/getRemoteJobsCount',
        'handler' => GetRemoteJobsCountController::class,
        'method'  => 'GET',
      ],
      'gettranslationstatus'           => [
        'path'    => '/gettranslationstatus',
        'handler' => GetTranslationStatusController::class,
        'method'  => 'GET',
      ],
      'getlocaltranslatorbyid'         => [
        'path'    => '/getlocaltranslatorbyid',
        'handler' => GetTranslatorByIdController::class,
        'method'  => 'GET',
      ],
      'reloadremotetranslationservice' => [
        'path'    => '/reloadremotetranslationservice',
        'handler' => GetRemoteTranslationServiceController::class,
        'method'  => 'GET',
      ],
      'gettranslationeditortype'       => [
        'path'    => '/gettranslationeditortype',
        'handler' => GetTranslationEditorTypeController::class,
        'method'  => 'GET',
      ],

      'translateexistingcontent'        => [
        'path'    => '/translate-existing-content',
        'handler' => TranslateExistingContentController::class,
        'method'  => 'POST',
      ],
      'getneedsupdatecountcreatedincte' => [
        'path'    => '/get-needs-update-count-created-in-cte',
        'handler' => GetNeedsUpdateCreatedInCteController::class,
        'method'  => 'GET',
      ],
      'getengines'                      => [
        'path'    => '/get-engines',
        'handler' => GetEnginesController::class,
        'method'  => 'GET',
      ],
      'getwebsitecontext'                      => [
        'path'    => '/website-contexts',
        'handler' => GetWebsiteContextController::class,
        'method'  => 'GET',
      ],
      'haspostsusingnativeeditor'       => [
        'path'    => '/has-posts-using-native-editor',
        'handler' => HasPostsUsingNativeEditorController::class,
        'method'  => 'GET',
      ],
      'getcreditsperword' => [
        'path'    => '/get-credits-per-word',
        'handler' => GetCreditsPerWordController::class,
        'method'  => 'GET',
      ],
      'getwordstotranslateforitems' => [
        'path'    => '/get-words-to-translate-for-items',
        'handler' => GetWordsToTranslateForItemsController::class,
        'method'  => 'POST',
      ],
      'getwordstotranslatefortypes' => [
        'path'    => '/get-words-to-translate-for-types',
        'handler' => GetWordsToTranslateForTypesController::class,
        'method'  => 'POST',
      ],
    ],
  ],
  'automatic-translations-settings'           => [
    'title' => __( 'Automatic translation settings', 'wpml' ),

    'controller'                     => AutomaticTranslationsSectionController::class,
    'prerequisites'                  => AutomaticTranslationsSectionController::class,
    'requiresWPMLSetupToBeCompleted' => true,
    'dependencies'                   => [
      'wpml-node-modules',
      'wp-i18n',
      'lodash'
    ],

    'legacyExtension' => 'load-wpml_page_tm/menu/settings',

    'scripts' => [
      [
        'id'            => 'wpml-automatic-translations-settings',
        'src'           => 'public/js/automatic-translations-settings.js',
        'prerequisites' => AutomaticTranslationsSectionController::class,
        'dataProvider'  => AutomaticTranslationsSectionController::class,
        'dependencies'  => [ 'wpml-node-modules', 'wp-i18n', 'lodash' ]
      ],
    ],

    'styles' => [
      'src'          => 'public/css/automatic-translations-settings.css',
      'dependencies' => []
    ],

    'endpoints' => [
      'getengines'                       => [
        'path'    => '/get-engines',
        'handler' => GetEnginesController::class,
        'method'  => 'GET',
      ],
      'saveautomatictranslationsettings' => [
        'path'    => '/save-automatic-translation-settings',
        'handler' => SaveAutomaticTranslationsSettingsController::class,
        'method'  => 'POST',
      ],
    ],
  ],
  'sitepress-multilingual-cms/menu/setup.php' => [
    'title'                          => __( 'WPML Setup', 'wpml' ),
    'requiresWPMLSetupToBeCompleted' => false,
    'dependencies'                   => [
      'wpml-node-modules',
      'wp-i18n',
      'lodash'
    ],

    'legacyExtension' => 'load-sitepress-multilingual-cms/menu/setup.php',

    'scripts' => [
      [
        'id'           => 'wc-minimum-requirements.js',
        'src'          => 'public/js/wc-minimum-requirements.js',
        'dependencies' => [ 'wpml-node-modules', 'wp-i18n', 'lodash' ]
      ],
      [
        'id'           => 'wc-minimum-requirements-warning-banner.js',
        'src'          => 'public/js/wc-minimum-requirements-warning-banner.js',
        'dependencies' => [ 'wpml-node-modules', 'wp-i18n', 'lodash' ]
      ],
    ],

    'styles' => [
      'src'          => 'public/css/tailwind.css',
      'dependencies' => []
    ]
  ],
  'sitepress-multilingual-cms/menu/support.php' => [
    'title'                          => __( 'WPML Support', 'wpml' ),
    'requiresWPMLSetupToBeCompleted' => false,
    'dependencies'                   => [
      'wpml-node-modules',
      'wp-i18n',
      'lodash'
    ],

    'legacyExtension' => 'load-sitepress-multilingual-cms/menu/support.php',

    'scripts' => [
      [
        'id'           => 'wc-minimum-requirements.js',
        'src'          => 'public/js/wc-minimum-requirements.js',
        'dependencies' => [ 'wpml-node-modules', 'wp-i18n', 'lodash' ]
      ],
      [
        'id'           => 'wc-minimum-requirements-warning-banner.js',
        'src'          => 'public/js/wc-minimum-requirements-warning-banner.js',
        'dependencies' => [ 'wpml-node-modules', 'wp-i18n', 'lodash' ]
      ],
    ],

    'styles' => [
      'src'          => 'public/css/tailwind.css',
      'dependencies' => []
    ]
  ],
];

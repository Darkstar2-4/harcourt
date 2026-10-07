<?php

namespace WPML\Core\Component\PostHog\Application\Service\Event;

use WPML\Core\Component\PostHog\Domain\Event\Custom\Event as CustomEvent;
use WPML\Core\Component\PostHog\Domain\Event\Custom\TEAEvent as CustomTEAEvent;
use WPML\Core\Component\PostHog\Domain\Event\OpenTranslationEditor\Event as OpenTranslationEditorEvent;
use WPML\Core\Component\PostHog\Domain\Event\SetupWizard\WizardCompleted\Event as WizardCompletedEvent;
use WPML\Core\Component\PostHog\Domain\Event\SetupWizard\WizardFirstStepCompleted\Event as WizardFirstStepCompletedEvent;
use WPML\Core\Component\PostHog\Domain\Event\SetupWizard\WizardStarted\Event as WizardStartedEvent;
use WPML\Core\Component\PostHog\Domain\Event\SetupWizard\WizardStepCompleted\Event as WizardStepCompletedEvent;
use WPML\Core\Component\PostHog\Domain\Event\TaxonomyTranslation\TaxonomyHierarchySyncCompleted\Event as TaxonomyHierarchySyncCompletedEvent;
use WPML\Core\Component\PostHog\Domain\Event\TaxonomyTranslation\TaxonomyHierarchySyncNoticeDisplayed\Event as TaxonomyHierarchySyncNoticeDisplayedEvent;
use WPML\Core\Component\PostHog\Domain\Event\TaxonomyTranslation\TaxonomyHierarchySyncNoticeLinkClicked\Event as TaxonomyHierarchySyncNoticeLinkClickedEvent;
use WPML\Core\Component\PostHog\Domain\Event\TaxonomyTranslation\TaxonomyTermTranslationSaved\Event as TaxonomyTermTranslationSavedEvent;
use WPML\Core\Component\PostHog\Domain\Event\WPMLLanguages\EditLanguagesFormSubmitted\Event as EditLanguagesFormSubmittedEvent;
use WPML\Core\Component\PostHog\Domain\Event\WPMLLanguages\FooterLanguageSwitcherToggled\Event as FooterLanguageSwitcherToggledEvent;
use WPML\Core\Component\PostHog\Domain\Event\WPMLLanguages\RootPageSaved\Event as RootPageSavedEvent;
use WPML\Core\Component\PostHog\Domain\Event\WPMLLanguages\SetLanguageUrlFormat\Event as SetLanguageUrlFormatEvent;
use WPML\Core\Component\PostHog\Domain\Event\WPMLLanguages\SetLanguageUrlFormatFailed\Event as SetLanguageUrlFormatFailedEvent;
use WPML\Core\Component\PostHog\Domain\Event\WPMLSettings\ATEForOldTranslationsEnabled\Event as ATEForOldTranslationsEnabledEvent;
use WPML\Core\Component\PostHog\Domain\Event\WPMLSettings\AutomaticTranslationSettingsSaved\Event as AutomaticTranslationSettingsSavedEvent;
use WPML\Core\Component\PostHog\Domain\Event\WPMLSettings\PostTypeUnlocked\Event as PostTypeUnlockedEvent;
use WPML\Core\Component\PostHog\Domain\Event\WPMLSettings\TaxonomyUnlocked\Event as TaxonomyUnlockedEvent;
use WPML\Core\Component\PostHog\Domain\Event\WPMLSettings\TranslationEditorSwitched\Event as TranslationEditorSwitchedEvent;

class EventInstanceService {


  public function getCustomEvent( string $name, array $props ): CustomEvent {
      return new CustomEvent( $name, $props );
  }


  public function getCustomTEAEvent( string $name, array $props ): CustomTEAEvent {
      return new CustomTEAEvent( $name, $props );
  }


  public function getAutomaticTranslationSettingsSavedEvent( array $props ): AutomaticTranslationSettingsSavedEvent {
      return new AutomaticTranslationSettingsSavedEvent( $props );
  }


  public function getRootPageSavedEvent( array $props ): RootPageSavedEvent {
      return new RootPageSavedEvent( $props );
  }


  public function getSetLanguageUrlFormatEvent( array $props ): SetLanguageUrlFormatEvent {
      return new SetLanguageUrlFormatEvent( $props );
  }


  public function getSetLanguageUrlFormatFailedEvent( array $props ): SetLanguageUrlFormatFailedEvent {
      return new SetLanguageUrlFormatFailedEvent( $props );
  }


  public function getEditLanguagesFormSubmittedEvent( array $props ): EditLanguagesFormSubmittedEvent {
      return new EditLanguagesFormSubmittedEvent( $props );
  }


  public function getFooterLanguageSwitcherToggledEvent( array $props ): FooterLanguageSwitcherToggledEvent {
      return new FooterLanguageSwitcherToggledEvent( $props );
  }


  public function getTaxonomyHierarchySyncNoticeDisplayedEvent( array $props ):
    TaxonomyHierarchySyncNoticeDisplayedEvent {
      return new TaxonomyHierarchySyncNoticeDisplayedEvent( $props );
  }


  public function getTaxonomyHierarchySyncLinkClickedEvent( array $props ):
    TaxonomyHierarchySyncNoticeLinkClickedEvent {
      return new TaxonomyHierarchySyncNoticeLinkClickedEvent( $props );
  }


  public function getTaxonomyHierarchySyncCompletedEvent( array $props ): TaxonomyHierarchySyncCompletedEvent {
      return new TaxonomyHierarchySyncCompletedEvent( $props );
  }


  public function getTaxonomyTermTranslationSavedEvent( array $props ): TaxonomyTermTranslationSavedEvent {
      return new TaxonomyTermTranslationSavedEvent( $props );
  }


  public function getTranslationEditorSwitchedEvent( array $props ): TranslationEditorSwitchedEvent {
      return new TranslationEditorSwitchedEvent( $props );
  }


  public function getPostTypeUnlockedEvent( array $props ): PostTypeUnlockedEvent {
      return new PostTypeUnlockedEvent( $props );
  }


  public function getTaxonomyUnlockedEvent( array $props ): TaxonomyUnlockedEvent {
      return new TaxonomyUnlockedEvent( $props );
  }


  public function getATEForOldTranslationsEnabledEvent( array $props ): ATEForOldTranslationsEnabledEvent {
      return new ATEForOldTranslationsEnabledEvent( $props );
  }


  public function getOpenTranslationEditorEvent( array $props ): OpenTranslationEditorEvent {
      return new OpenTranslationEditorEvent( $props );
  }


  public function getWizardStartedEvent( array $props ): WizardStartedEvent {
      return new WizardStartedEvent( $props );
  }


  public function getWizardCompletedEvent( array $props ): WizardCompletedEvent {
      return new WizardCompletedEvent( $props );
  }


  public function getWizardFirstStepCompletedEvent( array $props ): WizardFirstStepCompletedEvent {
      return new WizardFirstStepCompletedEvent( $props );
  }


  public function getWizardStepCompletedEvent( array $props ): WizardStepCompletedEvent {
      return new WizardStepCompletedEvent( $props );
  }


}

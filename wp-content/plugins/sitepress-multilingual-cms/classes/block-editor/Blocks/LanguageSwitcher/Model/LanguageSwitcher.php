<?php
namespace WPML\BlockEditor\Blocks\LanguageSwitcher\Model;

use WPML\FP\Fns;
use WPML\FP\Lst;
use WPML\FP\Relation;
use function WPML\FP\invoke;
use function WPML\FP\pipe;

class LanguageSwitcher {

	private $languageItems;

	private $currentLanguageCode;

	public function __construct( $currentLanguageCode, array $languageItems )
	{
		$this->currentLanguageCode = $currentLanguageCode;
		$this->languageItems = $languageItems;
	}

	public function getLanguageItems() {
		return $this->languageItems;
	}

	public function getCurrentLanguageItem() {
		return Lst::find(pipe(invoke('getCode'), Relation::equals($this->currentLanguageCode)), $this->languageItems);
	}

	public function getAlternativeLanguageItems() {
		return Fns::reject(pipe(invoke('getCode'), Relation::equals($this->currentLanguageCode)), $this->languageItems);
	}

	public function getCurrentLanguageCode() {
		return $this->currentLanguageCode;
	}
}
<?php

namespace WPML\UserInterface\Web\Core\Port\Script;

interface ScriptDataProviderInterface {


  public function jsWindowKey(): string;


  public function initialScriptData(): array;


}

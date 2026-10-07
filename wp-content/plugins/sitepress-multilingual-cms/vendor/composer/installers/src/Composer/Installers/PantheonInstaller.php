<?php

namespace Composer\Installers;

class PantheonInstaller extends BaseInstaller
{
    protected $locations = array(
        'script' => 'web/private/scripts/quicksilver/{$name}',
        'module' => 'web/private/scripts/quicksilver/{$name}',
    );
}

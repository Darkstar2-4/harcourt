<?php
namespace Composer\Installers;

class MODXEvoInstaller extends BaseInstaller
{
    protected $locations = array(
        'snippet'       => 'assets/snippets/{$name}/',
        'plugin'        => 'assets/plugins/{$name}/',
        'module'        => 'assets/modules/{$name}/',
        'template'      => 'assets/templates/{$name}/',
        'lib'           => 'assets/lib/{$name}/'
    );
}

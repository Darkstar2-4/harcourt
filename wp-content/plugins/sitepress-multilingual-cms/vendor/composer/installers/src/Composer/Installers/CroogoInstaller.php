<?php
namespace Composer\Installers;

class CroogoInstaller extends BaseInstaller
{
    protected $locations = array(
        'plugin' => 'Plugin/{$name}/',
        'theme' => 'View/Themed/{$name}/',
    );

    public function inflectPackageVars($vars)
    {
        $vars['name'] = strtolower(str_replace(array('-', '_'), ' ', $vars['name']));
        $vars['name'] = str_replace(' ', '', ucwords($vars['name']));

        return $vars;
    }
}

<?php
namespace Composer\Installers;

class AsgardInstaller extends BaseInstaller
{
    protected $locations = array(
        'module' => 'Modules/{$name}/',
        'theme' => 'Themes/{$name}/'
    );

    public function inflectPackageVars($vars)
    {
        if ($vars['type'] === 'asgard-module') {
            return $this->inflectPluginVars($vars);
        }

        if ($vars['type'] === 'asgard-theme') {
            return $this->inflectThemeVars($vars);
        }

        return $vars;
    }

    protected function inflectPluginVars($vars)
    {
        $vars['name'] = preg_replace('/-module$/', '', $vars['name']);
        $vars['name'] = str_replace(array('-', '_'), ' ', $vars['name']);
        $vars['name'] = str_replace(' ', '', ucwords($vars['name']));

        return $vars;
    }

    protected function inflectThemeVars($vars)
    {
        $vars['name'] = preg_replace('/-theme$/', '', $vars['name']);
        $vars['name'] = str_replace(array('-', '_'), ' ', $vars['name']);
        $vars['name'] = str_replace(' ', '', ucwords($vars['name']));

        return $vars;
    }
}

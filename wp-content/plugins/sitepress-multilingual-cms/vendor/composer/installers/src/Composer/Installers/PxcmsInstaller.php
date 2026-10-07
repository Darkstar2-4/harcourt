<?php
namespace Composer\Installers;

class PxcmsInstaller extends BaseInstaller
{
    protected $locations = array(
        'module' => 'app/Modules/{$name}/',
        'theme' => 'themes/{$name}/',
    );

    public function inflectPackageVars($vars)
    {
        if ($vars['type'] === 'pxcms-module') {
            return $this->inflectModuleVars($vars);
        }

        if ($vars['type'] === 'pxcms-theme') {
            return $this->inflectThemeVars($vars);
        }

        return $vars;
    }

    protected function inflectModuleVars($vars)
    {
        $vars['name'] = str_replace('pxcms-', '', $vars['name']);
        $vars['name'] = str_replace('module-', '', $vars['name']);
        $vars['name'] = preg_replace('/-module$/', '', $vars['name']);
        $vars['name'] = str_replace('-', '_', $vars['name']);
        $vars['name'] = ucwords($vars['name']);

        return $vars;
    }


    protected function inflectThemeVars($vars)
    {
        $vars['name'] = str_replace('pxcms-', '', $vars['name']);
        $vars['name'] = str_replace('theme-', '', $vars['name']);
        $vars['name'] = preg_replace('/-theme$/', '', $vars['name']);
        $vars['name'] = str_replace('-', '_', $vars['name']);
        $vars['name'] = ucwords($vars['name']);

        return $vars;
    }
}

<?php
namespace Composer\Installers;

class VgmcpInstaller extends BaseInstaller
{
    protected $locations = array(
        'bundle' => 'src/{$vendor}/{$name}/',
        'theme' => 'themes/{$name}/'
    );

    public function inflectPackageVars($vars)
    {
        if ($vars['type'] === 'vgmcp-bundle') {
            return $this->inflectPluginVars($vars);
        }

        if ($vars['type'] === 'vgmcp-theme') {
            return $this->inflectThemeVars($vars);
        }

        return $vars;
    }

    protected function inflectPluginVars($vars)
    {
        $vars['name'] = preg_replace('/-bundle$/', '', $vars['name']);
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

<?php
namespace Composer\Installers;

class ShopwareInstaller extends BaseInstaller
{
    protected $locations = array(
        'backend-plugin'    => 'engine/Shopware/Plugins/Local/Backend/{$name}/',
        'core-plugin'       => 'engine/Shopware/Plugins/Local/Core/{$name}/',
        'frontend-plugin'   => 'engine/Shopware/Plugins/Local/Frontend/{$name}/',
        'theme'             => 'templates/{$name}/',
        'plugin'            => 'custom/plugins/{$name}/',
        'frontend-theme'    => 'themes/Frontend/{$name}/',
    );

    public function inflectPackageVars($vars)
    {
        if ($vars['type'] === 'shopware-theme') {
            return $this->correctThemeName($vars);
        }

        return $this->correctPluginName($vars);        
    }

    private function correctPluginName($vars)
    {
        $camelCasedName = preg_replace_callback('/(-[a-z])/', function ($matches) {
            return strtoupper($matches[0][1]);
        }, $vars['name']);

        $vars['name'] = ucfirst($vars['vendor']) . ucfirst($camelCasedName);

        return $vars;
    }

    private function correctThemeName($vars)
    {
        $vars['name'] = str_replace('-', '_', $vars['name']);

        return $vars;
    }
}

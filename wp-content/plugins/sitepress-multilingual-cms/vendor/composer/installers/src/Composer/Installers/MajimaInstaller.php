<?php
namespace Composer\Installers;

class MajimaInstaller extends BaseInstaller
{
    protected $locations = array(
        'plugin' => 'plugins/{$name}/',
    );

    public function inflectPackageVars($vars)
    {
        return $this->correctPluginName($vars);
    }

    private function correctPluginName($vars)
    {
        $camelCasedName = preg_replace_callback('/(-[a-z])/', function ($matches) {
            return strtoupper($matches[0][1]);
        }, $vars['name']);
        $vars['name'] = ucfirst($camelCasedName);
        return $vars;
    }
}

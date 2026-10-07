<?php
namespace Composer\Installers;

class DokuWikiInstaller extends BaseInstaller
{
    protected $locations = array(
        'plugin' => 'lib/plugins/{$name}/',
        'template' => 'lib/tpl/{$name}/',
    );

    public function inflectPackageVars($vars)
    {

        if ($vars['type'] === 'dokuwiki-plugin') {
            return $this->inflectPluginVars($vars);
        }

        if ($vars['type'] === 'dokuwiki-template') {
            return $this->inflectTemplateVars($vars);
        }

        return $vars;
    }

    protected function inflectPluginVars($vars)
    {
        $vars['name'] = preg_replace('/-plugin$/', '', $vars['name']);
        $vars['name'] = preg_replace('/^dokuwiki_?-?/', '', $vars['name']);

        return $vars;
    }

    protected function inflectTemplateVars($vars)
    {
        $vars['name'] = preg_replace('/-template$/', '', $vars['name']);
        $vars['name'] = preg_replace('/^dokuwiki_?-?/', '', $vars['name']);

        return $vars;
    }

}

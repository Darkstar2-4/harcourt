<?php

namespace Composer\Installers;

use Composer\Util\Filesystem;

class BitrixInstaller extends BaseInstaller
{
    protected $locations = array(
        'module'    => '{$bitrix_dir}/modules/{$name}/',
        'component' => '{$bitrix_dir}/components/{$name}/',
        'theme'     => '{$bitrix_dir}/templates/{$name}/',
        'd7-module'    => '{$bitrix_dir}/modules/{$vendor}.{$name}/',
        'd7-component' => '{$bitrix_dir}/components/{$vendor}/{$name}/',
        'd7-template'     => '{$bitrix_dir}/templates/{$vendor}_{$name}/',
    );

    private static $checkedDuplicates = array();

    public function inflectPackageVars($vars)
    {
        if ($this->composer->getPackage()) {
            $extra = $this->composer->getPackage()->getExtra();

            if (isset($extra['bitrix-dir'])) {
                $vars['bitrix_dir'] = $extra['bitrix-dir'];
            }
        }

        if (!isset($vars['bitrix_dir'])) {
            $vars['bitrix_dir'] = 'bitrix';
        }

        return parent::inflectPackageVars($vars);
    }

    protected function templatePath($path, array $vars = array())
    {
        $templatePath = parent::templatePath($path, $vars);
        $this->checkDuplicates($templatePath, $vars);

        return $templatePath;
    }

    protected function checkDuplicates($path, array $vars = array())
    {
        $packageType = substr($vars['type'], strlen('bitrix') + 1);
        $localDir = explode('/', $vars['bitrix_dir']);
        array_pop($localDir);
        $localDir[] = 'local';
        $localDir = implode('/', $localDir);

        $oldPath = str_replace(
            array('{$bitrix_dir}', '{$name}'),
            array($localDir, $vars['name']),
            $this->locations[$packageType]
        );

        if (in_array($oldPath, static::$checkedDuplicates)) {
            return;
        }

        if ($oldPath !== $path && file_exists($oldPath) && $this->io && $this->io->isInteractive()) {

            $this->io->writeError('    <error>Duplication of packages:</error>');
            $this->io->writeError('    <info>Package ' . $oldPath . ' will be called instead package ' . $path . '</info>');

            while (true) {
                switch ($this->io->ask('    <info>Delete ' . $oldPath . ' [y,n,?]?</info> ', '?')) {
                    case 'y':
                        $fs = new Filesystem();
                        $fs->removeDirectory($oldPath);
                        break 2;

                    case 'n':
                        break 2;

                    case '?':
                    default:
                        $this->io->writeError(array(
                            '    y - delete package ' . $oldPath . ' and to continue with the installation',
                            '    n - don\'t delete and to continue with the installation',
                        ));
                        $this->io->writeError('    ? - print help');
                        break;
                }
            }
        }

        static::$checkedDuplicates[] = $oldPath;
    }
}

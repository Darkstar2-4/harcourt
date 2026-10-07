<?php

/*
 * This file is part of Twig.
 *
 * (c) Fabien Potencier
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace WPML\Core\Twig\Loader;

interface ExistsLoaderInterface
{
    public function exists($name);
}
\class_alias('WPML\\Core\\Twig\\Loader\\ExistsLoaderInterface', 'WPML\\Core\\Twig_ExistsLoaderInterface');

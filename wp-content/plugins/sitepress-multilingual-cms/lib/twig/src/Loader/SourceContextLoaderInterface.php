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

use WPML\Core\Twig\Error\LoaderError;
use WPML\Core\Twig\Source;
interface SourceContextLoaderInterface
{
    public function getSourceContext($name);
}
\class_alias('WPML\\Core\\Twig\\Loader\\SourceContextLoaderInterface', 'WPML\\Core\\Twig_SourceContextLoaderInterface');

<?php

namespace WPML\Core;

/*
 * This file is part of Twig.
 *
 * (c) Fabien Potencier
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
use WPML\Core\Twig\Environment;
interface Twig_TemplateInterface
{
    const ANY_CALL = 'any';
    const ARRAY_CALL = 'array';
    const METHOD_CALL = 'method';
    public function render(array $context);
    public function display(array $context, array $blocks = []);
    public function getEnvironment();
}

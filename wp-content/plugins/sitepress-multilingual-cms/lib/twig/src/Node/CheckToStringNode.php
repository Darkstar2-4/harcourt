<?php

/*
 * This file is part of Twig.
 *
 * (c) Fabien Potencier
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace WPML\Core\Twig\Node;

use WPML\Core\Twig\Compiler;
use WPML\Core\Twig\Node\Expression\AbstractExpression;
class CheckToStringNode extends \WPML\Core\Twig\Node\Expression\AbstractExpression
{
    public function __construct(\WPML\Core\Twig\Node\Expression\AbstractExpression $expr)
    {
        parent::__construct(['expr' => $expr], [], $expr->getTemplateLine(), $expr->getNodeTag());
    }
    public function compile(\WPML\Core\Twig\Compiler $compiler)
    {
        $compiler->raw('$this->sandbox->ensureToStringAllowed(')->subcompile($this->getNode('expr'))->raw(')');
    }
}

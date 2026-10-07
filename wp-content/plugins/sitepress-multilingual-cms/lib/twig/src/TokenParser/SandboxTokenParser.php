<?php

/*
 * This file is part of Twig.
 *
 * (c) Fabien Potencier
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace WPML\Core\Twig\TokenParser;

use WPML\Core\Twig\Error\SyntaxError;
use WPML\Core\Twig\Node\IncludeNode;
use WPML\Core\Twig\Node\SandboxNode;
use WPML\Core\Twig\Node\TextNode;
use WPML\Core\Twig\Token;
class SandboxTokenParser extends \WPML\Core\Twig\TokenParser\AbstractTokenParser
{
    public function parse(\WPML\Core\Twig\Token $token)
    {
        $stream = $this->parser->getStream();
        $stream->expect(\WPML\Core\Twig\Token::BLOCK_END_TYPE);
        $body = $this->parser->subparse([$this, 'decideBlockEnd'], \true);
        $stream->expect(\WPML\Core\Twig\Token::BLOCK_END_TYPE);
        if (!$body instanceof \WPML\Core\Twig\Node\IncludeNode) {
            foreach ($body as $node) {
                if ($node instanceof \WPML\Core\Twig\Node\TextNode && \ctype_space($node->getAttribute('data'))) {
                    continue;
                }
                if (!$node instanceof \WPML\Core\Twig\Node\IncludeNode) {
                    throw new \WPML\Core\Twig\Error\SyntaxError('Only "include" tags are allowed within a "sandbox" section.', $node->getTemplateLine(), $stream->getSourceContext());
                }
            }
        }
        return new \WPML\Core\Twig\Node\SandboxNode($body, $token->getLine(), $this->getTag());
    }
    public function decideBlockEnd(\WPML\Core\Twig\Token $token)
    {
        return $token->test('endsandbox');
    }
    public function getTag()
    {
        return 'sandbox';
    }
}
\class_alias('WPML\\Core\\Twig\\TokenParser\\SandboxTokenParser', 'WPML\\Core\\Twig_TokenParser_Sandbox');

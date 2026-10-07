<?php


namespace PhpMyAdmin\SqlParser\Components;

use PhpMyAdmin\SqlParser\Component;
use PhpMyAdmin\SqlParser\Parser;
use PhpMyAdmin\SqlParser\Token;
use PhpMyAdmin\SqlParser\TokensList;

/**
 * Parses a function call.
 *
 * @category   Keywords
 *
 * @license    https://www.gnu.org/licenses/gpl-2.0.txt GPL-2.0+
 */
class FunctionCall extends Component
{
    public $name;

    public $parameters;

    public function __construct($name = null, $parameters = null)
    {
        $this->name = $name;
        if (is_array($parameters)) {
            $this->parameters = new ArrayObj($parameters);
        } elseif ($parameters instanceof ArrayObj) {
            $this->parameters = $parameters;
        }
    }

    public static function parse(Parser $parser, TokensList $list, array $options = array())
    {
        $ret = new self();

        $state = 0;

        for (; $list->idx < $list->count; ++$list->idx) {
            $token = $list->tokens[$list->idx];

            if ($token->type === Token::TYPE_DELIMITER) {
                break;
            }

            if (($token->type === Token::TYPE_WHITESPACE) || ($token->type === Token::TYPE_COMMENT)) {
                continue;
            }

            if ($state === 0) {
                $ret->name = $token->value;
                $state = 1;
            } elseif ($state === 1) {
                if (($token->type === Token::TYPE_OPERATOR) && ($token->value === '(')) {
                    $ret->parameters = ArrayObj::parse($parser, $list);
                }
                break;
            }
        }

        return $ret;
    }

    public static function build($component, array $options = array())
    {
        return $component->name . $component->parameters;
    }
}

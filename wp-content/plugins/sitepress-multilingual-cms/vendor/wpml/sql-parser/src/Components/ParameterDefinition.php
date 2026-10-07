<?php


namespace PhpMyAdmin\SqlParser\Components;

use PhpMyAdmin\SqlParser\Component;
use PhpMyAdmin\SqlParser\Context;
use PhpMyAdmin\SqlParser\Parser;
use PhpMyAdmin\SqlParser\Token;
use PhpMyAdmin\SqlParser\TokensList;

/**
 * The definition of a parameter of a function or procedure.
 *
 * @category   Components
 *
 * @license    https://www.gnu.org/licenses/gpl-2.0.txt GPL-2.0+
 */
class ParameterDefinition extends Component
{
    public $name;

    public $inOut;

    public $type;

    public function __construct($name = null, $inOut = null, $type = null)
    {
        $this->name = $name;
        $this->inOut = $inOut;
        $this->type = $type;
    }

    public static function parse(Parser $parser, TokensList $list, array $options = array())
    {
        $ret = array();

        $expr = new self();

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
                if (($token->type === Token::TYPE_OPERATOR) && ($token->value === '(')) {
                    $state = 1;
                }
                continue;
            } elseif ($state === 1) {
                if (($token->value === 'IN') || ($token->value === 'OUT') || ($token->value === 'INOUT')) {
                    $expr->inOut = $token->value;
                    ++$list->idx;
                } elseif ($token->value === ')') {
                    ++$list->idx;
                    break;
                } else {
                    $expr->name = $token->value;
                    $state = 2;
                }
            } elseif ($state === 2) {
                $expr->type = DataType::parse($parser, $list);
                $state = 3;
            } elseif ($state === 3) {
                $ret[] = $expr;
                $expr = new self();
                if ($token->value === ',') {
                    $state = 1;
                } elseif ($token->value === ')') {
                    ++$list->idx;
                    break;
                }
            }
        }

        if (isset($expr->name) && ($expr->name !== '')) {
            $ret[] = $expr;
        }

        --$list->idx;

        return $ret;
    }

    public static function build($component, array $options = array())
    {
        if (is_array($component)) {
            return '(' . implode(', ', $component) . ')';
        }

        $tmp = '';
        if (! empty($component->inOut)) {
            $tmp .= $component->inOut . ' ';
        }

        return trim(
            $tmp . Context::escape($component->name) . ' ' . $component->type
        );
    }
}

<?php


namespace PhpMyAdmin\SqlParser\Components;

use PhpMyAdmin\SqlParser\Component;
use PhpMyAdmin\SqlParser\Parser;
use PhpMyAdmin\SqlParser\Token;
use PhpMyAdmin\SqlParser\TokensList;

/**
 * Parses a reference to a LOCK expression.
 *
 * @category   Components
 *
 * @license    https://www.gnu.org/licenses/gpl-2.0.txt GPL-2.0+
 */
class LockExpression extends Component
{
    public $table;

    public $type;

    public static function parse(Parser $parser, TokensList $list, array $options = array())
    {
        $ret = new self();

        $state = 0;

        $prevToken = null;

        for (; $list->idx < $list->count; ++$list->idx) {
            $token = $list->tokens[$list->idx];

            if ($token->type === Token::TYPE_DELIMITER
                || ($token->type === Token::TYPE_OPERATOR
                && $token->value === ',')
            ) {
                break;
            }

            if ($state === 0) {
                $ret->table = Expression::parse($parser, $list, array('parseField' => 'table'));
                $state = 1;
            } elseif ($state === 1) {
                $ret->type = self::parseLockType($parser, $list);
                $state = 2;
            }
            $prevToken = $token;
        }

        if ($state !== 2) {
            $parser->error('Unexpected end of LOCK expression.', $prevToken);
        }

        --$list->idx;

        return $ret;
    }

    public static function build($component, array $options = array())
    {
        if (is_array($component)) {
            return implode(', ', $component);
        }

        return $component->table . ' ' . $component->type;
    }

    private static function parseLockType(Parser $parser, TokensList $list)
    {
        $lockType = '';

        $state = 0;

        $prevToken = null;

        for (; $list->idx < $list->count; ++$list->idx) {
            $token = $list->tokens[$list->idx];

            if ($token->type === Token::TYPE_DELIMITER
                || ($token->type === Token::TYPE_OPERATOR
                && $token->value === ',')
            ) {
                --$list->idx;
                break;
            }

            if ($token->type === Token::TYPE_WHITESPACE || $token->type === Token::TYPE_COMMENT) {
                continue;
            }

            if ($token->type !== Token::TYPE_KEYWORD) {
                $parser->error('Unexpected token.', $token);
                break;
            }

            if ($state === 0) {
                if ($token->keyword === 'READ') {
                    $state = 1;
                } elseif ($token->keyword === 'LOW_PRIORITY') {
                    $state = 2;
                } elseif ($token->keyword === 'WRITE') {
                    $state = 3;
                } else {
                    $parser->error('Unexpected keyword.', $token);
                    break;
                }
                $lockType .= $token->keyword;
            } elseif ($state === 1) {
                if ($token->keyword === 'LOCAL') {
                    $lockType .= ' ' . $token->keyword;
                    $state = 3;
                } else {
                    $parser->error('Unexpected keyword.', $token);
                    break;
                }
            } elseif ($state === 2) {
                if ($token->keyword === 'WRITE') {
                    $lockType .= ' ' . $token->keyword;
                    $state = 3;
                } else {
                    $parser->error('Unexpected keyword.', $token);
                    break;
                }
            }

            $prevToken = $token;
        }

        if ($state !== 1 && $state !== 3) {
            $parser->error('Unexpected end of Lock expression.', $prevToken);
        }

        return $lockType;
    }
}

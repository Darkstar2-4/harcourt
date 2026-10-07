<?php


namespace PhpMyAdmin\SqlParser\Components;

use PhpMyAdmin\SqlParser\Component;
use PhpMyAdmin\SqlParser\Parser;
use PhpMyAdmin\SqlParser\Token;
use PhpMyAdmin\SqlParser\TokensList;

/**
 * `SET` keyword parser.
 *
 * @category   Keywords
 *
 * @license    https://www.gnu.org/licenses/gpl-2.0.txt GPL-2.0+
 */
class SetOperation extends Component
{
    public $column;

    public $value;

    public function __construct($column = '', $value = '')
    {
        $this->column = $column;
        $this->value = $value;
    }

    public static function parse(Parser $parser, TokensList $list, array $options = array())
    {
        $ret = array();

        $expr = new self();

        $state = 0;

        $commaLastSeenAt = null;

        for (; $list->idx < $list->count; ++$list->idx) {
            $token = $list->tokens[$list->idx];

            if ($token->type === Token::TYPE_DELIMITER) {
                break;
            }

            if (($token->type === Token::TYPE_WHITESPACE) || ($token->type === Token::TYPE_COMMENT)) {
                continue;
            }

            if (($token->type === Token::TYPE_KEYWORD)
                && ($token->flags & Token::FLAG_KEYWORD_RESERVED)
                && ($state === 0)
            ) {
                break;
            }

            if ($state === 0) {
                if ($token->token === '=') {
                    $state = 1;
                } elseif ($token->value !== ',') {
                    $expr->column .= $token->token;
                } elseif ($token->value === ',') {
                    $commaLastSeenAt = $token;
                }
            } elseif ($state === 1) {
                $tmp = Expression::parse(
                    $parser,
                    $list,
                    array(
                        'breakOnAlias' => true
                    )
                );
                if (is_null($tmp)) {
                    $parser->error('Missing expression.', $token);
                    break;
                }
                $expr->column = trim($expr->column);
                $expr->value = $tmp->expr;
                $ret[] = $expr;
                $expr = new self();
                $state = 0;
                $commaLastSeenAt = null;
            }
        }
        --$list->idx;

        if ($commaLastSeenAt !== null) {
            $parser->error('Unexpected token.', $commaLastSeenAt);
        }

        return $ret;
    }

    public static function build($component, array $options = array())
    {
        if (is_array($component)) {
            return implode(', ', $component);
        }

        return $component->column . ' = ' . $component->value;
    }
}

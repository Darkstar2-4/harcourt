<?php


namespace PhpMyAdmin\SqlParser\Components;

use PhpMyAdmin\SqlParser\Component;
use PhpMyAdmin\SqlParser\Parser;
use PhpMyAdmin\SqlParser\Token;
use PhpMyAdmin\SqlParser\TokensList;

/**
 * `ORDER BY` keyword parser.
 *
 * @category   Keywords
 *
 * @license    https://www.gnu.org/licenses/gpl-2.0.txt GPL-2.0+
 */
class OrderKeyword extends Component
{
    public $expr;

    public $type;

    public function __construct($expr = null, $type = 'ASC')
    {
        $this->expr = $expr;
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
                $expr->expr = Expression::parse($parser, $list);
                $state = 1;
            } elseif ($state === 1) {
                if (($token->type === Token::TYPE_KEYWORD)
                    && (($token->keyword === 'ASC') || ($token->keyword === 'DESC'))
                ) {
                    $expr->type = $token->keyword;
                } elseif (($token->type === Token::TYPE_OPERATOR)
                    && ($token->value === ',')
                ) {
                    if (! empty($expr->expr)) {
                        $ret[] = $expr;
                    }
                    $expr = new self();
                    $state = 0;
                } else {
                    break;
                }
            }
        }

        if (! empty($expr->expr)) {
            $ret[] = $expr;
        }

        --$list->idx;

        return $ret;
    }

    public static function build($component, array $options = array())
    {
        if (is_array($component)) {
            return implode(', ', $component);
        }

        return $component->expr . ' ' . $component->type;
    }
}

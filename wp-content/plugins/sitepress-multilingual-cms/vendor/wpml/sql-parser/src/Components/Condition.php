<?php


namespace PhpMyAdmin\SqlParser\Components;

use PhpMyAdmin\SqlParser\Component;
use PhpMyAdmin\SqlParser\Parser;
use PhpMyAdmin\SqlParser\Token;
use PhpMyAdmin\SqlParser\TokensList;

/**
 * `WHERE` keyword parser.
 *
 * @category   Keywords
 *
 * @license    https://www.gnu.org/licenses/gpl-2.0.txt GPL-2.0+
 */
class Condition extends Component
{
    public static $DELIMITERS = array(
        '&&',
        '||',
        'AND',
        'OR',
        'XOR'
    );

    public static $ALLOWED_KEYWORDS = array(
        'ALL' => 1,
        'AND' => 1,
        'BETWEEN' => 1,
        'EXISTS' => 1,
        'IF' => 1,
        'IN' => 1,
        'INTERVAL' => 1,
        'IS' => 1,
        'LIKE' => 1,
        'MATCH' => 1,
        'NOT IN' => 1,
        'NOT NULL' => 1,
        'NOT' => 1,
        'NULL' => 1,
        'OR' => 1,
        'REGEXP' => 1,
        'RLIKE' => 1,
        'XOR' => 1
    );

    public $identifiers = array();

    public $isOperator = false;

    public $expr;

    public function __construct($expr = null)
    {
        $this->expr = trim((string) $expr);
    }

    public static function parse(Parser $parser, TokensList $list, array $options = array())
    {
        $ret = array();

        $expr = new self();

        $brackets = 0;

        $betweenBefore = false;

        for (; $list->idx < $list->count; ++$list->idx) {
            $token = $list->tokens[$list->idx];

            if ($token->type === Token::TYPE_DELIMITER) {
                break;
            }

            if ($token->type === Token::TYPE_COMMENT) {
                continue;
            }

            if ($token->type === Token::TYPE_WHITESPACE) {
                $expr->expr .= ' ';
                continue;
            }

            if (in_array($token->value, static::$DELIMITERS, true)) {
                if ($betweenBefore && ($token->value === 'AND')) {
                    $betweenBefore = false;
                } else {
                    $expr->expr = trim($expr->expr);
                    if (! empty($expr->expr)) {
                        $ret[] = $expr;
                    }

                    $expr = new self($token->value);
                    $expr->isOperator = true;
                    $ret[] = $expr;

                    $expr = new self();
                    continue;
                }
            }

            if (($token->type === Token::TYPE_KEYWORD)
                && ($token->flags & Token::FLAG_KEYWORD_RESERVED)
                && ! ($token->flags & Token::FLAG_KEYWORD_FUNCTION)
            ) {
                if ($token->value === 'BETWEEN') {
                    $betweenBefore = true;
                }
                if (($brackets === 0) && empty(static::$ALLOWED_KEYWORDS[$token->value])) {
                    break;
                }
            }

            if ($token->type === Token::TYPE_OPERATOR) {
                if ($token->value === '(') {
                    ++$brackets;
                } elseif ($token->value === ')') {
                    if ($brackets === 0) {
                        break;
                    }
                    --$brackets;
                }
            }

            $expr->expr .= $token->token;
            if (($token->type === Token::TYPE_NONE)
                || (($token->type === Token::TYPE_KEYWORD)
                && (! ($token->flags & Token::FLAG_KEYWORD_RESERVED)))
                || ($token->type === Token::TYPE_STRING)
                || ($token->type === Token::TYPE_SYMBOL)
            ) {
                if (! in_array($token->value, $expr->identifiers)) {
                    $expr->identifiers[] = $token->value;
                }
            }
        }

        $expr->expr = trim($expr->expr);
        if (! empty($expr->expr)) {
            $ret[] = $expr;
        }

        --$list->idx;

        return $ret;
    }

    public static function build($component, array $options = array())
    {
        if (is_array($component)) {
            return implode(' ', $component);
        }

        return $component->expr;
    }
}

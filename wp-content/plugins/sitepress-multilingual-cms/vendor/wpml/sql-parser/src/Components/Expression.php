<?php


namespace PhpMyAdmin\SqlParser\Components;

use PhpMyAdmin\SqlParser\Component;
use PhpMyAdmin\SqlParser\Context;
use PhpMyAdmin\SqlParser\Parser;
use PhpMyAdmin\SqlParser\Token;
use PhpMyAdmin\SqlParser\TokensList;

/**
 * Parses a reference to an expression (column, table or database name, function
 * call, mathematical expression, etc.).
 *
 * @category   Components
 *
 * @license    https://www.gnu.org/licenses/gpl-2.0.txt GPL-2.0+
 */
class Expression extends Component
{
    private static $ALLOWED_KEYWORDS = array(
        'AS' => 1,
        'DUAL' => 1,
        'NULL' => 1,
        'REGEXP' => 1,
        'CASE' => 1,
        'DIV' => 1,
        'AND' => 1,
        'OR' => 1,
        'XOR' => 1,
        'NOT' => 1,
        'MOD' => 1
    );

    public $database;

    public $table;

    public $column;

    public $expr = '';

    public $alias;

    public $function;

    public $subquery;

    public function __construct($database = null, $table = null, $column = null, $alias = null)
    {
        if (($column === null) && ($alias === null)) {
            $this->expr = $database;
            $this->alias = $table;
        } else {
            $this->database = $database;
            $this->table = $table;
            $this->column = $column;
            $this->alias = $alias;
        }
    }

    public static function parse(Parser $parser, TokensList $list, array $options = array())
    {
        $ret = new self();

        $isExpr = false;

        $dot = false;

        $alias = false;

        $brackets = 0;

        $prev = array(
            null,
            null
        );

        if (! empty($options['parseField'])) {
            $options['breakOnParentheses'] = true;
            $options['field'] = $options['parseField'];
        }

        for (; $list->idx < $list->count; ++$list->idx) {
            $token = $list->tokens[$list->idx];

            if ($token->type === Token::TYPE_DELIMITER) {
                break;
            }

            if (($token->type === Token::TYPE_WHITESPACE)
                || ($token->type === Token::TYPE_COMMENT)
            ) {
                if ($isExpr) {
                    $ret->expr .= $token->token;
                }
                continue;
            }

            if ($token->type === Token::TYPE_KEYWORD) {
                if (($brackets > 0) && empty($ret->subquery)
                    && ! empty(Parser::$STATEMENT_PARSERS[$token->keyword])
                ) {
                    $ret->subquery = $token->keyword;
                } elseif (($token->flags & Token::FLAG_KEYWORD_FUNCTION)
                    && (empty($options['parseField'])
                    && ! $alias)
                ) {
                    $isExpr = true;
                } elseif (($token->flags & Token::FLAG_KEYWORD_RESERVED)
                    && ($brackets === 0)
                ) {
                    if (empty(self::$ALLOWED_KEYWORDS[$token->keyword])) {
                        break;
                    }
                    if ($token->keyword === 'AS') {
                        if (! empty($options['breakOnAlias'])) {
                            break;
                        }
                        if ($alias) {
                            $parser->error(
                                'An alias was expected.',
                                $token
                            );
                            break;
                        }
                        $alias = true;
                        continue;
                    } elseif ($token->keyword === 'CASE') {
                        $tempCaseExpr = CaseExpression::parse($parser, $list);
                        $ret->expr .= CaseExpression::build($tempCaseExpr);
                        $isExpr = true;
                        continue;
                    }
                    $isExpr = true;
                } elseif ($brackets === 0 && strlen($ret->expr) > 0 && ! $alias) {
                    break;
                }
            }

            if (($token->type === Token::TYPE_NUMBER)
                || ($token->type === Token::TYPE_BOOL)
                || (($token->type === Token::TYPE_SYMBOL)
                && ($token->flags & Token::FLAG_SYMBOL_VARIABLE))
                || (($token->type === Token::TYPE_SYMBOL)
                && ($token->flags & Token::FLAG_SYMBOL_PARAMETER))
                || (($token->type === Token::TYPE_OPERATOR)
                && ($token->value !== '.'))
            ) {
                if (! empty($options['parseField'])) {
                    break;
                }

                $isExpr = true;
            }

            if ($token->type === Token::TYPE_OPERATOR) {
                if (! empty($options['breakOnParentheses'])
                    && (($token->value === '(') || ($token->value === ')'))
                ) {
                    break;
                }
                if ($token->value === '(') {
                    ++$brackets;
                    if (empty($ret->function) && ($prev[1] !== null)
                        && (($prev[1]->type === Token::TYPE_NONE)
                        || ($prev[1]->type === Token::TYPE_SYMBOL)
                        || (($prev[1]->type === Token::TYPE_KEYWORD)
                        && ($prev[1]->flags & Token::FLAG_KEYWORD_FUNCTION)))
                    ) {
                        $ret->function = $prev[1]->value;
                    }
                } elseif ($token->value === ')') {
                    if ($brackets === 0) {
                        break;
                    } else {
                        --$brackets;
                        if ($brackets === 0) {
                            if (! empty($options['parenthesesDelimited'])) {
                                $ret->expr .= $token->token;
                                ++$list->idx;
                                break;
                            }
                        } elseif ($brackets < 0) {
                            break;
                        }
                    }
                } elseif ($token->value === ',') {
                    if ($brackets === 0) {
                        break;
                    }
                }
            }

            $prev[0] = $prev[1];
            $prev[1] = $token;

            if ($alias) {
                if (! empty($ret->alias)) {
                    $parser->error('An alias was previously found.', $token);
                    break;
                }
                $ret->alias = $token->value;
                $alias = false;
            } elseif ($isExpr) {
                if (  ($brackets === 0)
                    && (($prev[0] === null)
                    || ((($prev[0]->type !== Token::TYPE_OPERATOR)
                    || ($prev[0]->token === ')'))
                    && (($prev[0]->type !== Token::TYPE_KEYWORD)
                    || (! ($prev[0]->flags & Token::FLAG_KEYWORD_RESERVED)))))
                    && (($prev[1]->type === Token::TYPE_STRING)
                    || (($prev[1]->type === Token::TYPE_SYMBOL)
                    && (! ($prev[1]->flags & Token::FLAG_SYMBOL_VARIABLE)))
                    || ($prev[1]->type === Token::TYPE_NONE))
                ) {
                    if (! empty($ret->alias)) {
                        $parser->error('An alias was previously found.', $token);
                        break;
                    }
                    $ret->alias = $prev[1]->value;
                } else {
                    $ret->expr .= $token->token;
                }
            } elseif (! $isExpr) {
                if (($token->type === Token::TYPE_OPERATOR) && ($token->value === '.')) {
                    if (! empty($ret->database) || $dot) {
                        $parser->error('Unexpected dot.', $token);
                    }
                    $ret->database = $ret->table;
                    $ret->table = $ret->column;
                    $ret->column = null;
                    $dot = true;
                    $ret->expr .= $token->token;
                } else {
                    $field = empty($options['field']) ? 'column' : $options['field'];
                    if (empty($ret->$field)) {
                        $ret->$field = $token->value;
                        $ret->expr .= $token->token;
                        $dot = false;
                    } else {
                        if (! empty($options['breakOnAlias'])) {
                            break;
                        }
                        if (! empty($ret->alias)) {
                            $parser->error('An alias was previously found.', $token);
                            break;
                        }
                        $ret->alias = $token->value;
                    }
                }
            }
        }

        if ($alias) {
            $parser->error(
                'An alias was expected.',
                $list->tokens[$list->idx - 1]
            );
        }

        $ret->expr = trim($ret->expr);

        if ($ret->expr === '') {
            return null;
        }

        --$list->idx;

        return $ret;
    }

    public static function build($component, array $options = array())
    {
        if (is_array($component)) {
            return implode(', ', $component);
        }

        if ($component->expr !== '' && ! is_null($component->expr)) {
            $ret = $component->expr;
        } else {
            $fields = array();
            if (isset($component->database) && ($component->database !== '')) {
                $fields[] = $component->database;
            }
            if (isset($component->table) && ($component->table !== '')) {
                $fields[] = $component->table;
            }
            if (isset($component->column) && ($component->column !== '')) {
                $fields[] = $component->column;
            }
            $ret = implode('.', Context::escape($fields));
        }

        if (! empty($component->alias)) {
            $ret .= ' AS ' . Context::escape($component->alias);
        }

        return $ret;
    }
}

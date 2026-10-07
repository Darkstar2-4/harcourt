<?php


namespace PhpMyAdmin\SqlParser\Statements;

use PhpMyAdmin\SqlParser\Components\ArrayObj;
use PhpMyAdmin\SqlParser\Components\Condition;
use PhpMyAdmin\SqlParser\Components\Expression;
use PhpMyAdmin\SqlParser\Components\ExpressionArray;
use PhpMyAdmin\SqlParser\Components\JoinKeyword;
use PhpMyAdmin\SqlParser\Components\Limit;
use PhpMyAdmin\SqlParser\Components\OptionsArray;
use PhpMyAdmin\SqlParser\Components\OrderKeyword;
use PhpMyAdmin\SqlParser\Parser;
use PhpMyAdmin\SqlParser\Statement;
use PhpMyAdmin\SqlParser\Token;
use PhpMyAdmin\SqlParser\TokensList;

/**
 * `DELETE` statement.
 *
 * DELETE [LOW_PRIORITY] [QUICK] [IGNORE] FROM tbl_name
 *     [PARTITION (partition_name,...)]
 *     [WHERE where_condition]
 *     [ORDER BY ...]
 *     [LIMIT row_count]
 *
 * Multi-table syntax
 *
 * DELETE [LOW_PRIORITY] [QUICK] [IGNORE]
 *   tbl_name[.*] [, tbl_name[.*]] ...
 *   FROM table_references
 *   [WHERE where_condition]
 *
 * OR
 *
 * DELETE [LOW_PRIORITY] [QUICK] [IGNORE]
 *   FROM tbl_name[.*] [, tbl_name[.*]] ...
 *   USING table_references
 *   [WHERE where_condition]
 *
 *
 * @category   Statements
 *
 * @license    https://www.gnu.org/licenses/gpl-2.0.txt GPL-2.0+
 */
class DeleteStatement extends Statement
{
    public static $OPTIONS = array(
        'LOW_PRIORITY' => 1,
        'QUICK' => 2,
        'IGNORE' => 3
    );

    public static $CLAUSES = array(
        'DELETE' => array(
            'DELETE',
            2,
        ),
        '_OPTIONS' => array(
            '_OPTIONS',
            1,
        ),
        'FROM' => array(
            'FROM',
            3,
        ),
        'PARTITION' => array(
            'PARTITION',
            3,
        ),
        'USING' => array(
            'USING',
            3,
        ),
        'WHERE' => array(
            'WHERE',
            3,
        ),
        'ORDER BY' => array(
            'ORDER BY',
            3,
        ),
        'LIMIT' => array(
            'LIMIT',
            3,
        )
    );

    public $from;

    public $join;

    public $using;

    public $columns;

    public $partition;

    public $where;

    public $order;

    public $limit;

    public function build()
    {
        $ret = 'DELETE ' . OptionsArray::build($this->options);

        if (! is_null($this->columns) && count($this->columns) > 0) {
            $ret .= ' ' . ExpressionArray::build($this->columns);
        }
        if (! is_null($this->from) && count($this->from) > 0) {
            $ret .= ' FROM ' . ExpressionArray::build($this->from);
        }
        if (! is_null($this->join) && count($this->join) > 0) {
            $ret .= ' ' . JoinKeyword::build($this->join);
        }
        if (! is_null($this->using) && count($this->using) > 0) {
            $ret .= ' USING ' . ExpressionArray::build($this->using);
        }
        if (! is_null($this->where) && count($this->where) > 0) {
            $ret .= ' WHERE ' . Condition::build($this->where);
        }
        if (! is_null($this->order) && count($this->order) > 0) {
            $ret .= ' ORDER BY ' . ExpressionArray::build($this->order);
        }
        if (! is_null($this->limit) && strlen($this->limit) > 0) {
            $ret .= ' LIMIT ' . Limit::build($this->limit);
        }

        return $ret;
    }

    public function parse(Parser $parser, TokensList $list)
    {
        ++$list->idx;

        $this->options = OptionsArray::parse(
            $parser,
            $list,
            static::$OPTIONS
        );
        ++$list->idx;

        $state = 0;

        $multiTable = false;

        for (; $list->idx < $list->count; ++$list->idx) {
            $token = $list->tokens[$list->idx];

            if ($token->type === Token::TYPE_DELIMITER) {
                break;
            }

            if ($state === 0) {
                if ($token->type === Token::TYPE_KEYWORD) {
                    if ($token->keyword !== 'FROM') {
                        $parser->error('Unexpected keyword.', $token);
                        break;
                    } else {
                        ++$list->idx;
                        $this->from = ExpressionArray::parse($parser, $list);

                        $state = 2;
                    }
                } else {
                    $this->columns = ExpressionArray::parse($parser, $list);
                    $state = 1;
                }
            } elseif ($state === 1) {
                if ($token->type === Token::TYPE_KEYWORD) {
                    if ($token->keyword !== 'FROM') {
                        $parser->error('Unexpected keyword.', $token);
                        break;
                    } else {
                        ++$list->idx;
                        $this->from = ExpressionArray::parse($parser, $list);

                        $state = 2;
                    }
                } else {
                    $parser->error('Unexpected token.', $token);
                    break;
                }
            } elseif ($state === 2) {
                if ($token->type === Token::TYPE_KEYWORD) {
                    if (stripos($token->keyword, 'JOIN') !== false) {
                        ++$list->idx;
                        $this->join = JoinKeyword::parse($parser, $list);

                    } else {
                        switch ($token->keyword) {
                            case 'USING':
                                ++$list->idx;
                                $this->using = ExpressionArray::parse($parser, $list);
                                $state = 3;

                                $multiTable = true;
                                break;
                            case 'WHERE':
                                ++$list->idx;
                                $this->where = Condition::parse($parser, $list);
                                $state = 4;
                                break;
                            case 'ORDER BY':
                                ++$list->idx;
                                $this->order = OrderKeyword::parse($parser, $list);
                                $state = 5;
                                break;
                            case 'LIMIT':
                                ++$list->idx;
                                $this->limit = Limit::parse($parser, $list);
                                $state = 6;
                                break;
                            default:
                                $parser->error('Unexpected keyword.', $token);
                                break 2;
                        }
                    }
                }
            } elseif ($state === 3) {
                if ($token->type === Token::TYPE_KEYWORD) {
                    if ($token->keyword === 'WHERE') {
                        ++$list->idx;
                        $this->where = Condition::parse($parser, $list);
                        $state = 4;
                    } else {
                        $parser->error('Unexpected keyword.', $token);
                        break;
                    }
                } else {
                    $parser->error('Unexpected token.', $token);
                    break;
                }
            } elseif ($state === 4) {
                if ($multiTable === true
                    && $token->type === Token::TYPE_KEYWORD
                ) {
                    $parser->error(
                        'This type of clause is not valid in Multi-table queries.',
                        $token
                    );
                    break;
                }

                if ($token->type === Token::TYPE_KEYWORD) {
                    switch ($token->keyword) {
                        case 'ORDER BY':
                            ++$list->idx;
                            $this->order = OrderKeyword::parse($parser, $list);
                            $state = 5;
                            break;
                        case 'LIMIT':
                            ++$list->idx;
                            $this->limit = Limit::parse($parser, $list);
                            $state = 6;
                            break;
                        default:
                            $parser->error('Unexpected keyword.', $token);
                            break 2;
                    }
                }
            } elseif ($state === 5) {
                if ($token->type === Token::TYPE_KEYWORD) {
                    if ($token->keyword === 'LIMIT') {
                        ++$list->idx;
                        $this->limit = Limit::parse($parser, $list);
                        $state = 6;
                    } else {
                        $parser->error('Unexpected keyword.', $token);
                        break;
                    }
                }
            }
        }

        if ($state >= 2) {
            foreach ($this->from as $from_expr) {
                $from_expr->database = $from_expr->table;
                $from_expr->table = $from_expr->column;
                $from_expr->column = null;
            }
        }

        --$list->idx;
    }
}

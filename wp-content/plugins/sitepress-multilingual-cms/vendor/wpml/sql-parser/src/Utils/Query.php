<?php


namespace PhpMyAdmin\SqlParser\Utils;

use PhpMyAdmin\SqlParser\Components\Expression;
use PhpMyAdmin\SqlParser\Lexer;
use PhpMyAdmin\SqlParser\Parser;
use PhpMyAdmin\SqlParser\Statement;
use PhpMyAdmin\SqlParser\Statements\AlterStatement;
use PhpMyAdmin\SqlParser\Statements\AnalyzeStatement;
use PhpMyAdmin\SqlParser\Statements\CallStatement;
use PhpMyAdmin\SqlParser\Statements\CheckStatement;
use PhpMyAdmin\SqlParser\Statements\ChecksumStatement;
use PhpMyAdmin\SqlParser\Statements\CreateStatement;
use PhpMyAdmin\SqlParser\Statements\DeleteStatement;
use PhpMyAdmin\SqlParser\Statements\DropStatement;
use PhpMyAdmin\SqlParser\Statements\ExplainStatement;
use PhpMyAdmin\SqlParser\Statements\InsertStatement;
use PhpMyAdmin\SqlParser\Statements\LoadStatement;
use PhpMyAdmin\SqlParser\Statements\OptimizeStatement;
use PhpMyAdmin\SqlParser\Statements\RenameStatement;
use PhpMyAdmin\SqlParser\Statements\RepairStatement;
use PhpMyAdmin\SqlParser\Statements\ReplaceStatement;
use PhpMyAdmin\SqlParser\Statements\SelectStatement;
use PhpMyAdmin\SqlParser\Statements\SetStatement;
use PhpMyAdmin\SqlParser\Statements\ShowStatement;
use PhpMyAdmin\SqlParser\Statements\TruncateStatement;
use PhpMyAdmin\SqlParser\Statements\UpdateStatement;
use PhpMyAdmin\SqlParser\Token;
use PhpMyAdmin\SqlParser\TokensList;

/**
 * Statement utilities.
 *
 * @category   Statement
 *
 * @license    https://www.gnu.org/licenses/gpl-2.0.txt GPL-2.0+
 */
class Query
{
    public static $FUNCTIONS = array(
        'SUM',
        'AVG',
        'STD',
        'STDDEV',
        'MIN',
        'MAX',
        'BIT_OR',
        'BIT_AND'
    );

    public static $ALLFLAGS = array(
        'distinct' => false,

        'drop_database' => false,

        'group' => false,

        'having' => false,

        'is_affected' => false,

        'is_analyse' => false,

        'is_count' => false,

        'is_delete' => false,

        'is_explain' => false,

        'is_export' => false,

        'is_func' => false,

        'is_group' => false,

        'is_insert' => false,

        'is_maint' => false,

        'is_procedure' => false,

        'is_replace' => false,

        'is_select' => false,

        'is_show' => false,

        'is_subquery' => false,

        'join' => false,

        'limit' => false,

        'offset' => false,

        'order' => false,

        'querytype' => false,

        'reload' => false,

        'select_from' => false,

        'union' => false
    );

    private static function getFlagsSelect($statement, $flags)
    {
        $flags['querytype'] = 'SELECT';
        $flags['is_select'] = true;

        if (! empty($statement->from)) {
            $flags['select_from'] = true;
        }

        if ($statement->options->has('DISTINCT')) {
            $flags['distinct'] = true;
        }

        if (! empty($statement->group) || ! empty($statement->having)) {
            $flags['is_group'] = true;
        }

        if (! empty($statement->into)
            && ($statement->into->type === 'OUTFILE')
        ) {
            $flags['is_export'] = true;
        }

        $expressions = $statement->expr;
        if (! empty($statement->join)) {
            foreach ($statement->join as $join) {
                $expressions[] = $join->expr;
            }
        }

        foreach ($expressions as $expr) {
            if (! empty($expr->function)) {
                if ($expr->function === 'COUNT') {
                    $flags['is_count'] = true;
                } elseif (in_array($expr->function, static::$FUNCTIONS)) {
                    $flags['is_func'] = true;
                }
            }
            if (! empty($expr->subquery)) {
                $flags['is_subquery'] = true;
            }
        }

        if (! empty($statement->procedure)
            && ($statement->procedure->name === 'ANALYSE')
        ) {
            $flags['is_analyse'] = true;
        }

        if (! empty($statement->group)) {
            $flags['group'] = true;
        }

        if (! empty($statement->having)) {
            $flags['having'] = true;
        }

        if (! empty($statement->union)) {
            $flags['union'] = true;
        }

        if (! empty($statement->join)) {
            $flags['join'] = true;
        }

        return $flags;
    }

    public static function getFlags($statement, $all = false)
    {
        $flags = array('querytype' => false);
        if ($all) {
            $flags = self::$ALLFLAGS;
        }

        if ($statement instanceof AlterStatement) {
            $flags['querytype'] = 'ALTER';
            $flags['reload'] = true;
        } elseif ($statement instanceof CreateStatement) {
            $flags['querytype'] = 'CREATE';
            $flags['reload'] = true;
        } elseif ($statement instanceof AnalyzeStatement) {
            $flags['querytype'] = 'ANALYZE';
            $flags['is_maint'] = true;
        } elseif ($statement instanceof CheckStatement) {
            $flags['querytype'] = 'CHECK';
            $flags['is_maint'] = true;
        } elseif ($statement instanceof ChecksumStatement) {
            $flags['querytype'] = 'CHECKSUM';
            $flags['is_maint'] = true;
        } elseif ($statement instanceof OptimizeStatement) {
            $flags['querytype'] = 'OPTIMIZE';
            $flags['is_maint'] = true;
        } elseif ($statement instanceof RepairStatement) {
            $flags['querytype'] = 'REPAIR';
            $flags['is_maint'] = true;
        } elseif ($statement instanceof CallStatement) {
            $flags['querytype'] = 'CALL';
            $flags['is_procedure'] = true;
        } elseif ($statement instanceof DeleteStatement) {
            $flags['querytype'] = 'DELETE';
            $flags['is_delete'] = true;
            $flags['is_affected'] = true;
        } elseif ($statement instanceof DropStatement) {
            $flags['querytype'] = 'DROP';
            $flags['reload'] = true;

            if ($statement->options->has('DATABASE')
                || $statement->options->has('SCHEMA')
            ) {
                $flags['drop_database'] = true;
            }
        } elseif ($statement instanceof ExplainStatement) {
            $flags['querytype'] = 'EXPLAIN';
            $flags['is_explain'] = true;
        } elseif ($statement instanceof InsertStatement) {
            $flags['querytype'] = 'INSERT';
            $flags['is_affected'] = true;
            $flags['is_insert'] = true;
        } elseif ($statement instanceof LoadStatement) {
            $flags['querytype'] = 'LOAD';
            $flags['is_affected'] = true;
            $flags['is_insert'] = true;
        } elseif ($statement instanceof ReplaceStatement) {
            $flags['querytype'] = 'REPLACE';
            $flags['is_affected'] = true;
            $flags['is_replace'] = true;
            $flags['is_insert'] = true;
        } elseif ($statement instanceof SelectStatement) {
            $flags = self::getFlagsSelect($statement, $flags);
        } elseif ($statement instanceof ShowStatement) {
            $flags['querytype'] = 'SHOW';
            $flags['is_show'] = true;
        } elseif ($statement instanceof UpdateStatement) {
            $flags['querytype'] = 'UPDATE';
            $flags['is_affected'] = true;
        } elseif ($statement instanceof SetStatement) {
            $flags['querytype'] = 'SET';
        }

        if (($statement instanceof SelectStatement)
            || ($statement instanceof UpdateStatement)
            || ($statement instanceof DeleteStatement)
        ) {
            if (! empty($statement->limit)) {
                $flags['limit'] = true;
            }
            if (! empty($statement->order)) {
                $flags['order'] = true;
            }
        }

        return $flags;
    }

    public static function getAll($query)
    {
        $parser = new Parser($query);

        if (empty($parser->statements[0])) {
            return static::getFlags(null, true);
        }

        $statement = $parser->statements[0];

        $ret = static::getFlags($statement, true);

        $ret['parser'] = $parser;
        $ret['statement'] = $statement;

        if ($statement instanceof SelectStatement) {
            $ret['select_tables'] = array();
            $ret['select_expr'] = array();

            $tableAliases = array();
            foreach ($statement->from as $expr) {
                if (isset($expr->table, $expr->alias) && ($expr->table !== '') && ($expr->alias !== '')
                ) {
                    $tableAliases[$expr->alias] = array(
                        $expr->table,
                        isset($expr->database) ? $expr->database : null
                    );
                }
            }

            foreach ($statement->expr as $expr) {
                if (isset($expr->table) && ($expr->table !== '')) {
                    if (isset($tableAliases[$expr->table])) {
                        $arr = $tableAliases[$expr->table];
                    } else {
                        $arr = array(
                            $expr->table,
                            (isset($expr->database) && ($expr->database !== '')) ?
                                $expr->database : null
                        );
                    }
                    if (! in_array($arr, $ret['select_tables'])) {
                        $ret['select_tables'][] = $arr;
                    }
                } else {
                    $ret['select_expr'][] = $expr->expr;
                }
            }

            if (empty($ret['select_tables'])) {
                foreach ($statement->from as $expr) {
                    if (isset($expr->table) && ($expr->table !== '')) {
                        $arr = array(
                            $expr->table,
                            (isset($expr->database) && ($expr->database !== '')) ?
                                $expr->database : null
                        );
                        if (! in_array($arr, $ret['select_tables'])) {
                            $ret['select_tables'][] = $arr;
                        }
                    }
                }
            }
        }

        return $ret;
    }

    public static function getTables($statement)
    {
        $expressions = array();

        if (($statement instanceof InsertStatement)
            || ($statement instanceof ReplaceStatement)
        ) {
            $expressions = array($statement->into->dest);
        } elseif ($statement instanceof UpdateStatement) {
            $expressions = $statement->tables;
        } elseif (($statement instanceof SelectStatement)
            || ($statement instanceof DeleteStatement)
        ) {
            $expressions = $statement->from;
        } elseif (($statement instanceof AlterStatement)
            || ($statement instanceof TruncateStatement)
        ) {
            $expressions = array($statement->table);
        } elseif ($statement instanceof DropStatement) {
            if (! $statement->options->has('TABLE')) {
                return array();
            }
            $expressions = $statement->fields;
        } elseif ($statement instanceof RenameStatement) {
            foreach ($statement->renames as $rename) {
                $expressions[] = $rename->old;
            }
        }

        $ret = array();
        foreach ($expressions as $expr) {
            if (! empty($expr->table)) {
                $expr->expr = null;
                $expr->alias = null;
                $ret[] = Expression::build($expr);
            }
        }

        return $ret;
    }

    public static function getClause($statement, $list, $clause, $type = 0, $skipFirst = true)
    {
        $currIdx = 0;

        $brackets = 0;

        $ret = '';

        $clauses = array_flip(array_keys($statement->getClauses()));

        $lexer = new Lexer($clause);

        $clauseType = $lexer->list->getNextOfType(Token::TYPE_KEYWORD)->keyword;

        $clauseIdx = isset($clauses[$clauseType]) ? $clauses[$clauseType] : -1;

        $firstClauseIdx = $clauseIdx;
        $lastClauseIdx = $clauseIdx;

        if ($type === -1) {
            $firstClauseIdx = -1;
            $lastClauseIdx = $clauseIdx - 1;
        } elseif ($type === 1) {
            $firstClauseIdx = $clauseIdx + 1;
            $lastClauseIdx = 10000;
        } elseif (is_string($type) && isset($clauses[$type])) {
            if ($clauses[$type] > $clauseIdx) {
                $firstClauseIdx = $clauseIdx + 1;
                $lastClauseIdx = $clauses[$type] - 1;
            } else {
                $firstClauseIdx = $clauses[$type] + 1;
                $lastClauseIdx = $clauseIdx - 1;
            }
        }

        if ($type !== 0) {
            $skipFirst = false;
        }

        for ($i = $statement->first; $i <= $statement->last; ++$i) {
            $token = $list->tokens[$i];

            if ($token->type === Token::TYPE_COMMENT) {
                continue;
            }

            if ($token->type === Token::TYPE_OPERATOR) {
                if ($token->value === '(') {
                    ++$brackets;
                } elseif ($token->value === ')') {
                    --$brackets;
                }
            }

            if ($brackets === 0) {
                if (($token->type === Token::TYPE_KEYWORD)
                    && isset($clauses[$token->keyword])
                    && ($clauses[$token->keyword] >= $currIdx)
                ) {
                    $currIdx = $clauses[$token->keyword];
                    if ($skipFirst && ($currIdx === $clauseIdx)) {
                        continue;
                    }
                }
            }

            if (($firstClauseIdx <= $currIdx) && ($currIdx <= $lastClauseIdx)) {
                $ret .= $token->token;
            }
        }

        return trim($ret);
    }

    public static function replaceClause($statement, $list, $old, $new = null, $onlyType = false)
    {

        if ($new === null) {
            $new = $old;
        }

        if ($onlyType) {
            return static::getClause($statement, $list, $old, -1, false) . ' ' .
                $new . ' ' . static::getClause($statement, $list, $old, 0) . ' ' .
                static::getClause($statement, $list, $old, 1, false);
        }

        return static::getClause($statement, $list, $old, -1, false) . ' ' .
            $new . ' ' . static::getClause($statement, $list, $old, 1, false);
    }

    public static function replaceClauses($statement, $list, array $ops)
    {
        $count = count($ops);

        if ($count === 0) {
            return '';
        }

        $ret = '';

        if ($count === 1) {
            return static::replaceClause(
                $statement,
                $list,
                $ops[0][0],
                $ops[0][1]
            );
        }

        $ret .= static::getClause($statement, $list, $ops[0][0], -1) . ' ';

        foreach ($ops as $i => $clause) {
            $ret .= $clause[1] . ' ';

            if ($i + 1 !== $count) {
                $ret .= static::getClause($statement, $list, $clause[0], $ops[$i + 1][0]) . ' ';
            }
        }

        $ret .= static::getClause($statement, $list, $ops[$count - 1][0], 1);

        return $ret;
    }

    public static function getFirstStatement($query, $delimiter = null)
    {
        $lexer = new Lexer($query, false, $delimiter);
        $list = $lexer->list;

        $fullStatement = false;

        $statement = '';

        for ($list->idx = 0; $list->idx < $list->count; ++$list->idx) {
            $token = $list->tokens[$list->idx];

            if ($token->type === Token::TYPE_COMMENT) {
                continue;
            }

            $statement .= $token->token;

            if (($token->type === Token::TYPE_DELIMITER) && ! empty($token->token)) {
                $delimiter = $token->token;
                $fullStatement = true;
                break;
            }
        }

        if (! $fullStatement) {
            return array(
                null,
                $query,
                $delimiter
            );
        }

        $query = '';
        for (++$list->idx; $list->idx < $list->count; ++$list->idx) {
            $query .= $list->tokens[$list->idx]->token;
        }

        return array(
            trim($statement),
            $query,
            $delimiter
        );
    }

    public static function getClauseStartOffset($statement, $list, $clause)
    {
        $brackets = 0;

        $clauses = array_flip(array_keys($statement->getClauses()));

        for ($i = $statement->first; $i <= $statement->last; ++$i) {
            $token = $list->tokens[$i];

            if ($token->type === Token::TYPE_COMMENT) {
                continue;
            }

            if ($token->type === Token::TYPE_OPERATOR) {
                if ($token->value === '(') {
                    ++$brackets;
                } elseif ($token->value === ')') {
                    --$brackets;
                }
            }

            if ($brackets === 0) {
                if (($token->type === Token::TYPE_KEYWORD)
                    && isset($clauses[$token->keyword])
                    && ($clause === $token->keyword)
                ) {
                    return $i;
                } elseif ($token->keyword === 'UNION') {
                    return -1;
                }
            }
        }

        return -1;
    }
}

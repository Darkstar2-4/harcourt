<?php


namespace PhpMyAdmin\SqlParser;

use PhpMyAdmin\SqlParser\Exceptions\ParserException;
use PhpMyAdmin\SqlParser\Statements\SelectStatement;
use PhpMyAdmin\SqlParser\Statements\TransactionStatement;

/**
 * Takes multiple tokens (contained in a Lexer instance) as input and builds a
 * parse tree.
 *
 * @category Parser
 *
 * @license  https://www.gnu.org/licenses/gpl-2.0.txt GPL-2.0+
 */
class Parser extends Core
{
    public static $STATEMENT_PARSERS = array(
        'DESCRIBE' => 'PhpMyAdmin\\SqlParser\\Statements\\ExplainStatement',
        'DESC' => 'PhpMyAdmin\\SqlParser\\Statements\\ExplainStatement',
        'EXPLAIN' => 'PhpMyAdmin\\SqlParser\\Statements\\ExplainStatement',
        'FLUSH' => '',
        'GRANT' => '',
        'HELP' => '',
        'SET PASSWORD' => '',
        'STATUS' => '',
        'USE' => '',

        'ANALYZE' => 'PhpMyAdmin\\SqlParser\\Statements\\AnalyzeStatement',
        'BACKUP' => 'PhpMyAdmin\\SqlParser\\Statements\\BackupStatement',
        'CHECK' => 'PhpMyAdmin\\SqlParser\\Statements\\CheckStatement',
        'CHECKSUM' => 'PhpMyAdmin\\SqlParser\\Statements\\ChecksumStatement',
        'OPTIMIZE' => 'PhpMyAdmin\\SqlParser\\Statements\\OptimizeStatement',
        'REPAIR' => 'PhpMyAdmin\\SqlParser\\Statements\\RepairStatement',
        'RESTORE' => 'PhpMyAdmin\\SqlParser\\Statements\\RestoreStatement',

        'SET' => 'PhpMyAdmin\\SqlParser\\Statements\\SetStatement',
        'SHOW' => 'PhpMyAdmin\\SqlParser\\Statements\\ShowStatement',

        'ALTER' => 'PhpMyAdmin\\SqlParser\\Statements\\AlterStatement',
        'CREATE' => 'PhpMyAdmin\\SqlParser\\Statements\\CreateStatement',
        'DROP' => 'PhpMyAdmin\\SqlParser\\Statements\\DropStatement',
        'RENAME' => 'PhpMyAdmin\\SqlParser\\Statements\\RenameStatement',
        'TRUNCATE' => 'PhpMyAdmin\\SqlParser\\Statements\\TruncateStatement',

        'CALL' => 'PhpMyAdmin\\SqlParser\\Statements\\CallStatement',
        'DELETE' => 'PhpMyAdmin\\SqlParser\\Statements\\DeleteStatement',
        'DO' => '',
        'HANDLER' => '',
        'INSERT' => 'PhpMyAdmin\\SqlParser\\Statements\\InsertStatement',
        'LOAD DATA' => 'PhpMyAdmin\\SqlParser\\Statements\\LoadStatement',
        'REPLACE' => 'PhpMyAdmin\\SqlParser\\Statements\\ReplaceStatement',
        'SELECT' => 'PhpMyAdmin\\SqlParser\\Statements\\SelectStatement',
        'UPDATE' => 'PhpMyAdmin\\SqlParser\\Statements\\UpdateStatement',

        'DEALLOCATE' => '',
        'EXECUTE' => '',
        'PREPARE' => '',

        'BEGIN' => 'PhpMyAdmin\\SqlParser\\Statements\\TransactionStatement',
        'COMMIT' => 'PhpMyAdmin\\SqlParser\\Statements\\TransactionStatement',
        'ROLLBACK' => 'PhpMyAdmin\\SqlParser\\Statements\\TransactionStatement',
        'START TRANSACTION' => 'PhpMyAdmin\\SqlParser\\Statements\\TransactionStatement',

        'PURGE' => 'PhpMyAdmin\\SqlParser\\Statements\\PurgeStatement',

        'LOCK' => 'PhpMyAdmin\\SqlParser\\Statements\\LockStatement',
        'UNLOCK' => 'PhpMyAdmin\\SqlParser\\Statements\\LockStatement'
    );

    public static $KEYWORD_PARSERS = array(
        'PARTITION BY' => array(),
        'SUBPARTITION BY' => array(),

        '_OPTIONS' => array(
            'class' => 'PhpMyAdmin\\SqlParser\\Components\\OptionsArray',
            'field' => 'options',
        ),
        '_END_OPTIONS' => array(
            'class' => 'PhpMyAdmin\\SqlParser\\Components\\OptionsArray',
            'field' => 'end_options',
        ),

        'INTERSECT' => array(
            'class' => 'PhpMyAdmin\\SqlParser\\Components\\UnionKeyword',
            'field' => 'union',
        ),
        'EXCEPT' => array(
            'class' => 'PhpMyAdmin\\SqlParser\\Components\\UnionKeyword',
            'field' => 'union',
        ),
        'UNION' => array(
            'class' => 'PhpMyAdmin\\SqlParser\\Components\\UnionKeyword',
            'field' => 'union',
        ),
        'UNION ALL' => array(
            'class' => 'PhpMyAdmin\\SqlParser\\Components\\UnionKeyword',
            'field' => 'union',
        ),
        'UNION DISTINCT' => array(
            'class' => 'PhpMyAdmin\\SqlParser\\Components\\UnionKeyword',
            'field' => 'union',
        ),

        'ALTER' => array(
            'class' => 'PhpMyAdmin\\SqlParser\\Components\\Expression',
            'field' => 'table',
            'options' => array('parseField' => 'table'),
        ),
        'ANALYZE' => array(
            'class' => 'PhpMyAdmin\\SqlParser\\Components\\ExpressionArray',
            'field' => 'tables',
            'options' => array('parseField' => 'table'),
        ),
        'BACKUP' => array(
            'class' => 'PhpMyAdmin\\SqlParser\\Components\\ExpressionArray',
            'field' => 'tables',
            'options' => array('parseField' => 'table'),
        ),
        'CALL' => array(
            'class' => 'PhpMyAdmin\\SqlParser\\Components\\FunctionCall',
            'field' => 'call',
        ),
        'CHECK' => array(
            'class' => 'PhpMyAdmin\\SqlParser\\Components\\ExpressionArray',
            'field' => 'tables',
            'options' => array('parseField' => 'table'),
        ),
        'CHECKSUM' => array(
            'class' => 'PhpMyAdmin\\SqlParser\\Components\\ExpressionArray',
            'field' => 'tables',
            'options' => array('parseField' => 'table'),
        ),
        'CROSS JOIN' => array(
            'class' => 'PhpMyAdmin\\SqlParser\\Components\\JoinKeyword',
            'field' => 'join',
        ),
        'DROP' => array(
            'class' => 'PhpMyAdmin\\SqlParser\\Components\\ExpressionArray',
            'field' => 'fields',
            'options' => array('parseField' => 'table'),
        ),
        'FORCE' => array(
            'class' => 'PhpMyAdmin\\SqlParser\\Components\\IndexHint',
            'field' => 'index_hints',
        ),
        'FROM' => array(
            'class' => 'PhpMyAdmin\\SqlParser\\Components\\ExpressionArray',
            'field' => 'from',
            'options' => array('field' => 'table'),
        ),
        'GROUP BY' => array(
            'class' => 'PhpMyAdmin\\SqlParser\\Components\\GroupKeyword',
            'field' => 'group',
        ),
        'HAVING' => array(
            'class' => 'PhpMyAdmin\\SqlParser\\Components\\Condition',
            'field' => 'having',
        ),
        'IGNORE' => array(
            'class' => 'PhpMyAdmin\\SqlParser\\Components\\IndexHint',
            'field' => 'index_hints',
        ),
        'INTO' => array(
            'class' => 'PhpMyAdmin\\SqlParser\\Components\\IntoKeyword',
            'field' => 'into',
        ),
        'JOIN' => array(
            'class' => 'PhpMyAdmin\\SqlParser\\Components\\JoinKeyword',
            'field' => 'join',
        ),
        'LEFT JOIN' => array(
            'class' => 'PhpMyAdmin\\SqlParser\\Components\\JoinKeyword',
            'field' => 'join',
        ),
        'LEFT OUTER JOIN' => array(
            'class' => 'PhpMyAdmin\\SqlParser\\Components\\JoinKeyword',
            'field' => 'join',
        ),
        'ON' => array(
            'class' => 'PhpMyAdmin\\SqlParser\\Components\\Expression',
            'field' => 'table',
            'options' => array('parseField' => 'table'),
        ),
        'RIGHT JOIN' => array(
            'class' => 'PhpMyAdmin\\SqlParser\\Components\\JoinKeyword',
            'field' => 'join',
        ),
        'RIGHT OUTER JOIN' => array(
            'class' => 'PhpMyAdmin\\SqlParser\\Components\\JoinKeyword',
            'field' => 'join',
        ),
        'INNER JOIN' => array(
            'class' => 'PhpMyAdmin\\SqlParser\\Components\\JoinKeyword',
            'field' => 'join',
        ),
        'FULL JOIN' => array(
            'class' => 'PhpMyAdmin\\SqlParser\\Components\\JoinKeyword',
            'field' => 'join',
        ),
        'FULL OUTER JOIN' => array(
            'class' => 'PhpMyAdmin\\SqlParser\\Components\\JoinKeyword',
            'field' => 'join',
        ),
        'NATURAL JOIN' => array(
            'class' => 'PhpMyAdmin\\SqlParser\\Components\\JoinKeyword',
            'field' => 'join',
        ),
        'NATURAL LEFT JOIN' => array(
            'class' => 'PhpMyAdmin\\SqlParser\\Components\\JoinKeyword',
            'field' => 'join',
        ),
        'NATURAL RIGHT JOIN' => array(
            'class' => 'PhpMyAdmin\\SqlParser\\Components\\JoinKeyword',
            'field' => 'join',
        ),
        'NATURAL LEFT OUTER JOIN' => array(
            'class' => 'PhpMyAdmin\\SqlParser\\Components\\JoinKeyword',
            'field' => 'join',
        ),
        'NATURAL RIGHT OUTER JOIN' => array(
            'class' => 'PhpMyAdmin\\SqlParser\\Components\\JoinKeyword',
            'field' => 'join',
        ),
        'STRAIGHT_JOIN' => array(
            'class' => 'PhpMyAdmin\\SqlParser\\Components\\JoinKeyword',
            'field' => 'join',
        ),
        'LIMIT' => array(
            'class' => 'PhpMyAdmin\\SqlParser\\Components\\Limit',
            'field' => 'limit',
        ),
        'OPTIMIZE' => array(
            'class' => 'PhpMyAdmin\\SqlParser\\Components\\ExpressionArray',
            'field' => 'tables',
            'options' => array('parseField' => 'table'),
        ),
        'ORDER BY' => array(
            'class' => 'PhpMyAdmin\\SqlParser\\Components\\OrderKeyword',
            'field' => 'order',
        ),
        'PARTITION' => array(
            'class' => 'PhpMyAdmin\\SqlParser\\Components\\ArrayObj',
            'field' => 'partition',
        ),
        'PROCEDURE' => array(
            'class' => 'PhpMyAdmin\\SqlParser\\Components\\FunctionCall',
            'field' => 'procedure',
        ),
        'RENAME' => array(
            'class' => 'PhpMyAdmin\\SqlParser\\Components\\RenameOperation',
            'field' => 'renames',
        ),
        'REPAIR' => array(
            'class' => 'PhpMyAdmin\\SqlParser\\Components\\ExpressionArray',
            'field' => 'tables',
            'options' => array('parseField' => 'table'),
        ),
        'RESTORE' => array(
            'class' => 'PhpMyAdmin\\SqlParser\\Components\\ExpressionArray',
            'field' => 'tables',
            'options' => array('parseField' => 'table'),
        ),
        'SET' => array(
            'class' => 'PhpMyAdmin\\SqlParser\\Components\\SetOperation',
            'field' => 'set',
        ),
        'SELECT' => array(
            'class' => 'PhpMyAdmin\\SqlParser\\Components\\ExpressionArray',
            'field' => 'expr',
        ),
        'TRUNCATE' => array(
            'class' => 'PhpMyAdmin\\SqlParser\\Components\\Expression',
            'field' => 'table',
            'options' => array('parseField' => 'table'),
        ),
        'UPDATE' => array(
            'class' => 'PhpMyAdmin\\SqlParser\\Components\\ExpressionArray',
            'field' => 'tables',
            'options' => array('parseField' => 'table'),
        ),
        'USE' => array(
            'class' => 'PhpMyAdmin\\SqlParser\\Components\\IndexHint',
            'field' => 'index_hints',
        ),
        'VALUE' => array(
            'class' => 'PhpMyAdmin\\SqlParser\\Components\\Array2d',
            'field' => 'values',
        ),
        'VALUES' => array(
            'class' => 'PhpMyAdmin\\SqlParser\\Components\\Array2d',
            'field' => 'values',
        ),
        'WHERE' => array(
            'class' => 'PhpMyAdmin\\SqlParser\\Components\\Condition',
            'field' => 'where',
        )
    );

    public $list;

    public $statements = array();

    public $brackets = 0;

    public function __construct($list = null, $strict = false)
    {
        if (is_string($list) || ($list instanceof UtfString)) {
            $lexer = new Lexer($list, $strict);
            $this->list = $lexer->list;
        } elseif ($list instanceof TokensList) {
            $this->list = $list;
        }

        $this->strict = $strict;

        if ($list !== null) {
            $this->parse();
        }
    }

    public function parse()
    {
        $lastTransaction = null;

        $lastStatement = null;

        $unionType = false;

        $prevLastIdx = -1;

        $list = &$this->list;

        for (; $list->idx < $list->count; ++$list->idx) {
            $token = $list->tokens[$list->idx];

            if (($token->type === Token::TYPE_NONE)
                && (strtoupper($token->token) === 'DELIMITER')
            ) {
                $list->getNextOfType(Token::TYPE_DELIMITER);
                $prevLastIdx = $list->idx;
                continue;
            }

            if ($token->value === '(') {
                ++$this->brackets;
                continue;
            }

            if ($token->type !== Token::TYPE_KEYWORD) {
                if (($token->type !== Token::TYPE_COMMENT)
                    && ($token->type !== Token::TYPE_WHITESPACE)
                    && ($token->type !== Token::TYPE_OPERATOR)
                    && ($token->type !== Token::TYPE_DELIMITER)
                ) {
                    $this->error(
                        'Unexpected beginning of statement.',
                        $token
                    );
                }
                continue;
            }

            if (($token->keyword === 'UNION') ||
                    ($token->keyword === 'UNION ALL') ||
                    ($token->keyword === 'UNION DISTINCT') ||
                    ($token->keyword === 'EXCEPT') ||
                    ($token->keyword === 'INTERSECT')
            ) {
                $unionType = $token->keyword;
                continue;
            }

            if (empty(static::$STATEMENT_PARSERS[$token->keyword])) {
                if (! isset(static::$STATEMENT_PARSERS[$token->keyword])) {
                    $this->error(
                        'Unrecognized statement type.',
                        $token
                    );
                }
                $list->getNextOfType(Token::TYPE_DELIMITER);
                $prevLastIdx = $list->idx;
                continue;
            }

            $class = static::$STATEMENT_PARSERS[$token->keyword];

            $statement = new $class($this, $this->list);

            $statement->first = $prevLastIdx + 1;

            $statement->last = $list->idx;
            $prevLastIdx = $list->idx;

            if (! empty($unionType)
                && ($lastStatement instanceof SelectStatement)
                && ($statement instanceof SelectStatement)
            ) {

                $lastStatement->union[] = array(
                    $unionType,
                    $statement
                );

                $lastStatement->order = $statement->order;
                $lastStatement->limit = $statement->limit;
                $statement->order = array();
                $statement->limit = null;

                $lastStatement->last = $statement->last;

                $unionType = false;

                $statement->validateClauseOrder($this, $list);
                continue;
            }

            if ($statement instanceof TransactionStatement) {
                if ($statement->type === TransactionStatement::TYPE_BEGIN) {
                    $lastTransaction = $statement;
                    $this->statements[] = $statement;
                } elseif ($statement->type === TransactionStatement::TYPE_END) {
                    if ($lastTransaction === null) {
                        $this->statements[] = $statement;
                        $this->error(
                            'No transaction was previously started.',
                            $token
                        );
                    } else {
                        $lastTransaction->end = $statement;
                    }
                    $lastTransaction = null;
                }

                $statement->validateClauseOrder($this, $list);
                continue;
            }

            $statement->validateClauseOrder($this, $list);

            if ($lastTransaction !== null) {
                $lastTransaction->statements[] = $statement;
            } else {
                $this->statements[] = $statement;
            }
            $lastStatement = $statement;
        }
    }

    public function error($msg, ?Token $token = null, $code = 0)
    {
        $error = new ParserException(
            Translator::gettext($msg),
            $token,
            $code
        );
        parent::error($error);
    }
}

<?php


namespace PhpMyAdmin\SqlParser\Statements;

use PhpMyAdmin\SqlParser\Components\ArrayObj;
use PhpMyAdmin\SqlParser\Components\Expression;
use PhpMyAdmin\SqlParser\Components\ExpressionArray;
use PhpMyAdmin\SqlParser\Components\OptionsArray;
use PhpMyAdmin\SqlParser\Components\SetOperation;
use PhpMyAdmin\SqlParser\Parser;
use PhpMyAdmin\SqlParser\Statement;
use PhpMyAdmin\SqlParser\Token;
use PhpMyAdmin\SqlParser\TokensList;

/**
 * `LOAD` statement.
 *
 * LOAD DATA [LOW_PRIORITY | CONCURRENT] [LOCAL] INFILE 'file_name'
 *   [REPLACE | IGNORE]
 *   INTO TABLE tbl_name
 *   [PARTITION (partition_name,...)]
 *   [CHARACTER SET charset_name]
 *   [{FIELDS | COLUMNS}
 *       [TERMINATED BY 'string']
 *       [[OPTIONALLY] ENCLOSED BY 'char']
 *       [ESCAPED BY 'char']
 *   ]
 *   [LINES
 *       [STARTING BY 'string']
 *       [TERMINATED BY 'string']
 *  ]
 *   [IGNORE number {LINES | ROWS}]
 *   [(col_name_or_user_var,...)]
 *   [SET col_name = expr,...]
 *
 *
 * @category   Statements
 *
 * @license    https://www.gnu.org/licenses/gpl-2.0.txt GPL-2.0+
 */
class LoadStatement extends Statement
{
    public static $OPTIONS = array(
        'LOW_PRIORITY' => 1,
        'CONCURRENT' => 1,
        'LOCAL' => 2
    );

    public static $FIELDS_OPTIONS = array(
        'TERMINATED BY' => array(
            1,
            'expr',
        ),
        'OPTIONALLY' => 2,
        'ENCLOSED BY' => array(
            3,
            'expr',
        ),
        'ESCAPED BY' => array(
            4,
            'expr',
        )
    );

    public static $LINES_OPTIONS = array(
        'STARTING BY' => array(
            1,
            'expr',
        ),
        'TERMINATED BY' => array(
            2,
            'expr',
        )
    );

    public $file_name;

    public $table;

    public $partition;

    public $charset_name;

    public $fields_options;

    public $fields_keyword;

    public $lines_options;

    public $col_name_or_user_var;

    public $set;

    public $ignore_number;

    public $replace_ignore;

    public $lines_rows;

    public function build()
    {
        $ret = 'LOAD DATA ' . $this->options
            . ' INFILE ' . $this->file_name;

        if ($this->replace_ignore !== null) {
            $ret .= ' ' . trim($this->replace_ignore);
        }

        $ret .= ' INTO TABLE ' . $this->table;

        if ($this->partition !== null && strlen($this->partition) > 0) {
            $ret .= ' PARTITION ' . ArrayObj::build($this->partition);
        }

        if ($this->charset_name !== null) {
            $ret .= ' CHARACTER SET ' . $this->charset_name;
        }

        if ($this->fields_keyword !== null) {
            $ret .= ' ' . $this->fields_keyword . ' ' . $this->fields_options;
        }

        if ($this->lines_options !== null && strlen($this->lines_options) > 0) {
            $ret .= ' LINES ' . $this->lines_options;
        }

        if ($this->ignore_number !== null) {
            $ret .= ' IGNORE ' . $this->ignore_number . ' ' . $this->lines_rows;
        }

        if ($this->col_name_or_user_var !== null && count($this->col_name_or_user_var) > 0) {
            $ret .= ' ' . ExpressionArray::build($this->col_name_or_user_var);
        }

        if ($this->set !== null && count($this->set) > 0) {
            $ret .= ' SET ' . SetOperation::build($this->set);
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

        for (; $list->idx < $list->count; ++$list->idx) {
            $token = $list->tokens[$list->idx];

            if ($token->type === Token::TYPE_DELIMITER) {
                break;
            }

            if (($token->type === Token::TYPE_WHITESPACE) || ($token->type === Token::TYPE_COMMENT)) {
                continue;
            }

            if ($state === 0) {
                if ($token->type === Token::TYPE_KEYWORD
                    && $token->keyword !== 'INFILE'
                ) {
                    $parser->error('Unexpected keyword.', $token);
                    break;
                } elseif ($token->type !== Token::TYPE_KEYWORD) {
                    $parser->error('Unexpected token.', $token);
                    break;
                }

                ++$list->idx;
                $this->file_name = Expression::parse(
                    $parser,
                    $list,
                    array('parseField' => 'file')
                );
                $state = 1;
            } elseif ($state === 1) {
                if ($token->type === Token::TYPE_KEYWORD) {
                    if ($token->keyword === 'REPLACE'
                     || $token->keyword === 'IGNORE') {
                        $this->replace_ignore = trim($token->keyword);
                    } elseif ($token->keyword === 'INTO') {
                        $state = 2;
                    }
                }
            } elseif ($state === 2) {
                if ($token->type === Token::TYPE_KEYWORD
                    && $token->keyword === 'TABLE'
                ) {
                    ++$list->idx;
                    $this->table = Expression::parse($parser, $list, array('parseField' => 'table'));
                    $state = 3;
                } else {
                    $parser->error('Unexpected token.', $token);
                    break;
                }
            } elseif ($state >= 3 && $state <= 7) {
                if ($token->type === Token::TYPE_KEYWORD) {
                    $newState = $this->parseKeywordsAccordingToState(
                        $parser,
                        $list,
                        $state
                    );
                    if ($newState === $state) {
                        break;
                    }
                } elseif ($token->type === Token::TYPE_OPERATOR
                    && $token->token === '('
                ) {
                    $this->col_name_or_user_var
                        = ExpressionArray::parse($parser, $list);
                    $state = 7;
                } else {
                    $parser->error('Unexpected token.', $token);
                    break;
                }
            }
        }

        --$list->idx;
    }

    public function parseFileOptions(Parser $parser, TokensList $list, $keyword = 'FIELDS')
    {
        ++$list->idx;

        if ($keyword === 'FIELDS' || $keyword === 'COLUMNS') {
            $this->fields_options = OptionsArray::parse(
                $parser,
                $list,
                static::$FIELDS_OPTIONS
            );

            $this->fields_keyword = $keyword;
        } else {
            $this->lines_options = OptionsArray::parse(
                $parser,
                $list,
                static::$LINES_OPTIONS
            );
        }
    }

    public function parseKeywordsAccordingToState($parser, $list, $state)
    {
        $token = $list->tokens[$list->idx];

        switch ($state) {
            case 3:
                if ($token->keyword === 'PARTITION') {
                    ++$list->idx;
                    $this->partition = ArrayObj::parse($parser, $list);
                    $state = 4;

                    return $state;
                }
            case 4:
                if ($token->keyword === 'CHARACTER SET') {
                    ++$list->idx;
                    $this->charset_name = Expression::parse($parser, $list);
                    $state = 5;

                    return $state;
                }
            case 5:
                if ($token->keyword === 'FIELDS'
                    || $token->keyword === 'COLUMNS'
                    || $token->keyword === 'LINES'
                ) {
                    $this->parseFileOptions($parser, $list, $token->value);
                    $state = 6;

                    return $state;
                }
            case 6:
                if ($token->keyword === 'IGNORE') {
                    ++$list->idx;

                    $this->ignore_number = Expression::parse($parser, $list);
                    $nextToken = $list->getNextOfType(Token::TYPE_KEYWORD);

                    if ($nextToken->type === Token::TYPE_KEYWORD
                        && (($nextToken->keyword === 'LINES')
                        || ($nextToken->keyword === 'ROWS'))
                    ) {
                        $this->lines_rows = $nextToken->token;
                    }
                    $state = 7;

                    return $state;
                }
            case 7:
                if ($token->keyword === 'SET') {
                    ++$list->idx;
                    $this->set = SetOperation::parse($parser, $list);
                    $state = 8;

                    return $state;
                }
            default:
        }

        return $state;
    }
}

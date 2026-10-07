<?php


namespace PhpMyAdmin\SqlParser\Statements;

use PhpMyAdmin\SqlParser\Components\Array2d;
use PhpMyAdmin\SqlParser\Components\IntoKeyword;
use PhpMyAdmin\SqlParser\Components\OptionsArray;
use PhpMyAdmin\SqlParser\Components\SetOperation;
use PhpMyAdmin\SqlParser\Parser;
use PhpMyAdmin\SqlParser\Statement;
use PhpMyAdmin\SqlParser\Token;
use PhpMyAdmin\SqlParser\TokensList;

/**
 * `REPLACE` statement.
 *
 * REPLACE [LOW_PRIORITY | DELAYED]
 *     [INTO] tbl_name [(col_name,...)]
 *     {VALUES | VALUE} ({expr | DEFAULT},...),(...),...
 *
 * or
 *
 * REPLACE [LOW_PRIORITY | DELAYED]
 *     [INTO] tbl_name
 *     SET col_name={expr | DEFAULT}, ...
 *
 * or
 *
 * REPLACE [LOW_PRIORITY | DELAYED]
 *   [INTO] tbl_name
 *   [PARTITION (partition_name,...)]
 *   [(col_name,...)]
 *   SELECT ...
 *
 * @category   Statements
 *
 * @license    https://www.gnu.org/licenses/gpl-2.0.txt GPL-2.0+
 */
class ReplaceStatement extends Statement
{
    public static $OPTIONS = array(
        'LOW_PRIORITY' => 1,
        'DELAYED' => 1
    );

    public $into;

    public $values;

    public $set;

    public $select;

    public function build()
    {
        $ret = 'REPLACE ' . $this->options;
        $ret = trim($ret) . ' INTO ' . $this->into;

        if (! is_null($this->values) && count($this->values) > 0) {
            $ret .= ' VALUES ' . Array2d::build($this->values);
        } elseif (! is_null($this->set) && count($this->set) > 0) {
            $ret .= ' SET ' . SetOperation::build($this->set);
        } elseif (! is_null($this->select) && strlen($this->select) > 0) {
            $ret .= ' ' . $this->select->build();
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
                    && $token->keyword !== 'INTO'
                ) {
                    $parser->error('Unexpected keyword.', $token);
                    break;
                }
                ++$list->idx;
                $this->into = IntoKeyword::parse(
                    $parser,
                    $list,
                    array('fromReplace' => true)
                );

                $state = 1;
            } elseif ($state === 1) {
                if ($token->type === Token::TYPE_KEYWORD) {
                    if ($token->keyword === 'VALUE'
                        || $token->keyword === 'VALUES'
                    ) {
                        ++$list->idx;

                        $this->values = Array2d::parse($parser, $list);
                    } elseif ($token->keyword === 'SET') {
                        ++$list->idx;

                        $this->set = SetOperation::parse($parser, $list);
                    } elseif ($token->keyword === 'SELECT') {
                        $this->select = new SelectStatement($parser, $list);
                    } else {
                        $parser->error(
                            'Unexpected keyword.',
                            $token
                        );
                        break;
                    }
                    $state = 2;
                } else {
                    $parser->error(
                        'Unexpected token.',
                        $token
                    );
                    break;
                }
            }
        }

        --$list->idx;
    }
}

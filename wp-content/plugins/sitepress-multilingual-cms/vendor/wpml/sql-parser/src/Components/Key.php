<?php


namespace PhpMyAdmin\SqlParser\Components;

use PhpMyAdmin\SqlParser\Component;
use PhpMyAdmin\SqlParser\Context;
use PhpMyAdmin\SqlParser\Parser;
use PhpMyAdmin\SqlParser\Token;
use PhpMyAdmin\SqlParser\TokensList;

/**
 * Parses the definition of a key.
 *
 * Used for parsing `CREATE TABLE` statement.
 *
 * @category   Components
 *
 * @license    https://www.gnu.org/licenses/gpl-2.0.txt GPL-2.0+
 */
class Key extends Component
{
    public static $KEY_OPTIONS = array(
        'KEY_BLOCK_SIZE' => array(
            1,
            'var=',
        ),
        'USING' => array(
            2,
            'var',
        ),
        'WITH PARSER' => array(
            3,
            'var',
        ),
        'COMMENT' => array(
            4,
            'var',
        ),
        'CLUSTERING' => array(
            4,
            'var=',
        ),
        'ENGINE_ATTRIBUTE' => array(
            5,
            'var=',
        ),
        'SECONDARY_ENGINE_ATTRIBUTE' => array(
            5,
            'var=',
        ),
        'VISIBLE' => 6,
        'INVISIBLE' => 6,
        'IGNORED' => 10,
        'NOT IGNORED' => 10,
    );

    public $name;

    public $columns;

    public $type;

    public $expr = null;

    public $options;

    public function __construct(
        $name = null,
        array $columns = array(),
        $type = null,
        $options = null
    ) {
        $this->name = $name;
        $this->columns = $columns;
        $this->type = $type;
        $this->options = $options;
    }

    public static function parse(Parser $parser, TokensList $list, array $options = array())
    {
        $ret = new self();

        $lastColumn = array();

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
                $ret->type = $token->value;
                $state = 1;
            } elseif ($state === 1) {
                if (($token->type === Token::TYPE_OPERATOR) && ($token->value === '(')) {
                    $positionBeforeSearch = $list->idx;
                    $list->idx++;
                    $nextToken = $list->getNext();
                    $list->idx = $positionBeforeSearch;

                    if (
                        $nextToken !== null && $nextToken->value === '('
                    ) {
                        $state = 5;
                    } else {
                        $state = 2;
                    }
                } else {
                    $ret->name = $token->value;
                }
            } elseif ($state === 2) {
                if ($token->type === Token::TYPE_OPERATOR) {
                    if ($token->value === '(') {
                        $state = 3;
                    } elseif (($token->value === ',') || ($token->value === ')')) {
                        $state = ($token->value === ',') ? 2 : 4;
                        if (! empty($lastColumn)) {
                            $ret->columns[] = $lastColumn;
                            $lastColumn = array();
                        }
                    }
                } elseif (
                    (
                        $token->type === Token::TYPE_KEYWORD
                    )
                    &&
                    (
                        ($token->keyword === 'ASC') || ($token->keyword === 'DESC')
                    )
                ) {
                    $lastColumn['order'] = $token->keyword;
                } else {
                    $lastColumn['name'] = $token->value;
                }
            } elseif ($state === 3) {
                if (($token->type === Token::TYPE_OPERATOR) && ($token->value === ')')) {
                    $state = 2;
                } else {
                    $lastColumn['length'] = $token->value;
                }
            } elseif ($state === 4) {
                $ret->options = OptionsArray::parse($parser, $list, static::$KEY_OPTIONS);
                ++$list->idx;
                break;
            } elseif ($state === 5) {
                if ($token->type === Token::TYPE_OPERATOR) {
                    if ($token->value === ')') {
                        $state = 4;
                        continue;
                    }
                    if ($token->value === ',') {
                        $ret->expr .= ', ';
                        continue;
                    }
                    if ($token->value === '(') {
                        if ($ret->expr === null) {
                            $ret->expr = '';
                        }

                        $ret->expr .= Expression::parse(
                            $parser,
                            $list,
                            array(
                                'parenthesesDelimited' => true
                            )
                        );
                        continue;
                    }
                }
                $parser->error('Unexpected token.', $token);
            }
        }

        --$list->idx;

        return $ret;
    }

    public static function build($component, array $options = array())
    {
        $ret = $component->type . ' ';
        if (! empty($component->name)) {
            $ret .= Context::escape($component->name) . ' ';
        }

        if ($component->expr !== null) {
            return $ret . '(' . $component->expr . ')' . ' ' . $component->options;
        }

        $columns = array();
        foreach ($component->columns as $column) {
            $tmp = '';
            if (isset($column['name'])) {
                $tmp .= Context::escape($column['name']);
            }

            if (isset($column['length'])) {
                $tmp .= '(' . $column['length'] . ')';
            }

            if (isset($column['order'])) {
                $tmp .= ' ' . $column['order'];
            }

            $columns[] = $tmp;
        }

        $ret .= '(' . implode(',', $columns) . ') ' . $component->options;

        return trim($ret);
    }
}

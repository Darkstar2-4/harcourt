<?php


namespace PhpMyAdmin\SqlParser\Components;

use PhpMyAdmin\SqlParser\Component;
use PhpMyAdmin\SqlParser\Context;
use PhpMyAdmin\SqlParser\Parser;
use PhpMyAdmin\SqlParser\Token;
use PhpMyAdmin\SqlParser\TokensList;

/**
 * Parses the create definition of a column or a key.
 *
 * Used for parsing `CREATE TABLE` statement.
 *
 * @category   Components
 *
 * @license    https://www.gnu.org/licenses/gpl-2.0.txt GPL-2.0+
 */
class CreateDefinition extends Component
{
    public static $FIELD_OPTIONS = array(
        '_UNSORTED' => true,

        'NOT NULL' => 1,
        'NULL' => 1,
        'DEFAULT' => array(
            2,
            'expr',
            array('breakOnAlias' => true)
        ),
        'CHARSET' => array(
            2,
            'var',
        ),
        'COLLATE' => array(
            3,
            'var',
        ),
        'AUTO_INCREMENT' => 3,
        'PRIMARY' => 4,
        'PRIMARY KEY' => 4,
        'UNIQUE' => 4,
        'UNIQUE KEY' => 4,
        'COMMENT' => array(
            5,
            'var',
        ),
        'COLUMN_FORMAT' => array(
            6,
            'var',
        ),
        'ON UPDATE' => array(
            7,
            'expr',
        ),

        'GENERATED ALWAYS' => 8,
        'AS' => array(
            9,
            'expr',
            array('parenthesesDelimited' => true)
        ),
        'VIRTUAL' => 10,
        'PERSISTENT' => 11,
        'STORED' => 11,
        'CHECK' => array(
            12,
            'expr',
            array('parenthesesDelimited' => true),
        ),
        'INVISIBLE' => 13,
        'ENFORCED' => 14,
        'NOT' => 15,
    );

    public $name;

    public $isConstraint;

    public $type;

    public $key;

    public $references;

    public $options;

    public function __construct(
        $name = null,
        $options = null,
        $type = null,
        $isConstraint = false,
        $references = null
    ) {
        $this->name = $name;
        $this->options = $options;
        if ($type instanceof DataType) {
            $this->type = $type;
        } elseif ($type instanceof Key) {
            $this->key = $type;
            $this->isConstraint = $isConstraint;
            $this->references = $references;
        }
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
                if (($token->type === Token::TYPE_OPERATOR) && ($token->value === '(')) {
                    $state = 1;
                } else {
                    $parser->error(
                        'An opening bracket was expected.',
                        $token
                    );

                    break;
                }
            } elseif ($state === 1) {
                if ($token->type === Token::TYPE_KEYWORD && $token->keyword === 'CONSTRAINT') {
                    $expr->isConstraint = true;
                } elseif (($token->type === Token::TYPE_KEYWORD) && ($token->flags & Token::FLAG_KEYWORD_KEY)) {
                    $expr->key = Key::parse($parser, $list);
                    $state = 4;
                } elseif ($token->type === Token::TYPE_SYMBOL || $token->type === Token::TYPE_NONE) {
                    $expr->name = $token->value;
                    if (! $expr->isConstraint) {
                        $state = 2;
                    }
                } elseif ($token->type === Token::TYPE_KEYWORD) {
                    if ($token->flags & Token::FLAG_KEYWORD_RESERVED) {
                        $parser->error(
                            'A symbol name was expected! '
                            . 'A reserved keyword can not be used '
                            . 'as a column name without backquotes.',
                            $token
                        );

                        return $ret;
                    }

                    $expr->name = $token->value;
                    $state = 2;
                } else {
                    $parser->error(
                        'A symbol name was expected!',
                        $token
                    );

                    return $ret;
                }
            } elseif ($state === 2) {
                $expr->type = DataType::parse($parser, $list);
                $state = 3;
            } elseif ($state === 3) {
                $expr->options = OptionsArray::parse($parser, $list, static::$FIELD_OPTIONS);
                $state = 4;
            } elseif ($state === 4) {
                if ($token->type === Token::TYPE_KEYWORD && $token->keyword === 'REFERENCES') {
                    ++$list->idx;
                    $expr->references = Reference::parse($parser, $list);
                } else {
                    --$list->idx;
                }
                $state = 5;
            } elseif ($state === 5) {
                if (! empty($expr->type) || ! empty($expr->key)) {
                    $ret[] = $expr;
                }
                $expr = new self();
                if ($token->value === ',') {
                    $state = 1;
                } elseif ($token->value === ')') {
                    $state = 6;
                    ++$list->idx;
                    break;
                } else {
                    $parser->error(
                        'A comma or a closing bracket was expected.',
                        $token
                    );
                    $state = 0;
                    break;
                }
            }
        }

        if (! empty($expr->type) || ! empty($expr->key)) {
            $ret[] = $expr;
        }

        if (($state !== 0) && ($state !== 6)) {
            $parser->error(
                'A closing bracket was expected.',
                $list->tokens[$list->idx - 1]
            );
        }

        --$list->idx;

        return $ret;
    }

    public static function build($component, array $options = array())
    {
        if (is_array($component)) {
            return "(\n  " . implode(",\n  ", $component) . "\n)";
        }

        $tmp = '';

        if ($component->isConstraint) {
            $tmp .= 'CONSTRAINT ';
        }

        if (isset($component->name) && ($component->name !== '')) {
            $tmp .= Context::escape($component->name) . ' ';
        }

        if (! empty($component->type)) {
            $tmp .= DataType::build(
                $component->type,
                array('lowercase' => true)
            ) . ' ';
        }

        if (! empty($component->key)) {
            $tmp .= $component->key . ' ';
        }

        if (! empty($component->references)) {
            $tmp .= 'REFERENCES ' . $component->references . ' ';
        }

        $tmp .= $component->options;

        return trim($tmp);
    }
}

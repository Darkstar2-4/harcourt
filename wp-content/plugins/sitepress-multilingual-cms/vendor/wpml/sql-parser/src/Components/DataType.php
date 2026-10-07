<?php


namespace PhpMyAdmin\SqlParser\Components;

use PhpMyAdmin\SqlParser\Component;
use PhpMyAdmin\SqlParser\Parser;
use PhpMyAdmin\SqlParser\Token;
use PhpMyAdmin\SqlParser\TokensList;

/**
 * Parses a data type.
 *
 * @category   Components
 *
 * @license    https://www.gnu.org/licenses/gpl-2.0.txt GPL-2.0+
 */
class DataType extends Component
{
    public static $DATA_TYPE_OPTIONS = array(
        'BINARY' => 1,
        'CHARACTER SET' => array(
            2,
            'var',
        ),
        'CHARSET' => array(
            2,
            'var',
        ),
        'COLLATE' => array(
            3,
            'var',
        ),
        'UNSIGNED' => 4,
        'ZEROFILL' => 5
    );

    public $name;

    public $parameters = array();

    public $options;

    public function __construct(
        $name = null,
        array $parameters = array(),
        $options = null
    ) {
        $this->name = $name;
        $this->parameters = $parameters;
        $this->options = $options;
    }

    public static function parse(Parser $parser, TokensList $list, array $options = array())
    {
        $ret = new self();

        $state = 0;

        for (; $list->idx < $list->count; ++$list->idx) {
            $token = $list->tokens[$list->idx];

            if (($token->type === Token::TYPE_WHITESPACE) || ($token->type === Token::TYPE_COMMENT)) {
                continue;
            }

            if ($state === 0) {
                $ret->name = strtoupper($token->value);
                if (($token->type !== Token::TYPE_KEYWORD) || (! ($token->flags & Token::FLAG_KEYWORD_DATA_TYPE))) {
                    $parser->error('Unrecognized data type.', $token);
                }
                $state = 1;
            } elseif ($state === 1) {
                if (($token->type === Token::TYPE_OPERATOR) && ($token->value === '(')) {
                    $parameters = ArrayObj::parse($parser, $list);
                    ++$list->idx;
                    $ret->parameters = (($ret->name === 'ENUM') || ($ret->name === 'SET')) ?
                        $parameters->raw : $parameters->values;
                }
                $ret->options = OptionsArray::parse($parser, $list, static::$DATA_TYPE_OPTIONS);
                ++$list->idx;
                break;
            }
        }

        if (empty($ret->name)) {
            return null;
        }

        --$list->idx;

        return $ret;
    }

    public static function build($component, array $options = array())
    {
        $name = empty($options['lowercase']) ?
            $component->name : strtolower($component->name);

        $parameters = '';
        if (! empty($component->parameters)) {
            $parameters = '(' . implode(',', $component->parameters) . ')';
        }

        return trim($name . $parameters . ' ' . $component->options);
    }
}

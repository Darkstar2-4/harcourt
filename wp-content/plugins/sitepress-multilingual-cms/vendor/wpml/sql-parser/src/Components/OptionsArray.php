<?php


namespace PhpMyAdmin\SqlParser\Components;

use PhpMyAdmin\SqlParser\Component;
use PhpMyAdmin\SqlParser\Parser;
use PhpMyAdmin\SqlParser\Token;
use PhpMyAdmin\SqlParser\TokensList;
use PhpMyAdmin\SqlParser\Translator;

/**
 * Parses a list of options.
 *
 * @category   Components
 *
 * @license    https://www.gnu.org/licenses/gpl-2.0.txt GPL-2.0+
 */
class OptionsArray extends Component
{
    public $options = array();

    public function __construct(array $options = array())
    {
        $this->options = $options;
    }

    public static function parse(Parser $parser, TokensList $list, array $options = array())
    {
        $ret = new self();

        $lastAssignedId = count($options) + 1;

        $lastOption = null;

        $lastOptionId = 0;

        $brackets = 0;

        $state = 0;

        for (; $list->idx < $list->count; ++$list->idx) {
            $token = $list->tokens[$list->idx];

            if ($token->type === Token::TYPE_DELIMITER) {
                break;
            }

            if ($token->type === Token::TYPE_COMMENT) {
                continue;
            }

            if (($token->type === Token::TYPE_WHITESPACE) && ($brackets === 0)) {
                continue;
            }

            if ($lastOption === null) {
                $upper = strtoupper($token->token);
                if (isset($options[$upper])) {
                    $lastOption = $options[$upper];
                    $lastOptionId = is_array($lastOption) ?
                        $lastOption[0] : $lastOption;
                    $state = 0;

                    if (isset($ret->options[$lastOptionId])) {
                        $parser->error(
                            sprintf(
                                Translator::gettext('This option conflicts with "%1$s".'),
                                is_array($ret->options[$lastOptionId])
                                ? $ret->options[$lastOptionId]['name']
                                : $ret->options[$lastOptionId]
                            ),
                            $token
                        );
                        $lastOptionId = $lastAssignedId++;
                    }
                } else {
                    break;
                }
            }

            if ($state === 0) {
                if (! is_array($lastOption)) {
                    $ret->options[$lastOptionId] = $token->value;
                    $lastOption = null;
                    $state = 0;
                } elseif (($lastOption[1] === 'var') || ($lastOption[1] === 'var=')) {
                    $ret->options[$lastOptionId] = array(
                        'name' => $token->value,
                        'equals' => $lastOption[1] === 'var=',
                        'expr' => '',
                        'value' => ''
                    );
                    $state = 1;
                } elseif ($lastOption[1] === 'expr' || $lastOption[1] === 'expr=') {

                    ++$list->idx;
                    $ret->options[$lastOptionId] = array(
                        'name' => $token->value,
                        'equals' => $lastOption[1] === 'expr=',
                        'expr' => ''
                    );
                    $state = 1;
                }
            } elseif ($state === 1) {
                $state = 2;
                if ($token->token === '=') {
                    $ret->options[$lastOptionId]['equals'] = true;
                    continue;
                }
            }

            if ($state === 2) {
                if ($lastOption[1] === 'expr' || $lastOption[1] === 'expr=') {
                    $ret->options[$lastOptionId]['expr'] = Expression::parse(
                        $parser,
                        $list,
                        empty($lastOption[2]) ? array() : $lastOption[2]
                    );
                    $ret->options[$lastOptionId]['value']
                        = $ret->options[$lastOptionId]['expr']->expr;
                    $lastOption = null;
                    $state = 0;
                } else {
                    if ($token->token === '(') {
                        ++$brackets;
                    } elseif ($token->token === ')') {
                        --$brackets;
                    }

                    $ret->options[$lastOptionId]['expr'] .= $token->token;

                    if (! ((($token->token === '(') && ($brackets === 1))
                        || (($token->token === ')') && ($brackets === 0)))
                    ) {
                        $ret->options[$lastOptionId]['value'] .= $token->value;
                    }

                    if ($brackets === 0) {
                        $lastOption = null;
                    }
                }
            }
        }

        if ($state === 1
            && $lastOption
            && ($lastOption[1] === 'expr'
            || $lastOption[1] === 'var'
            || $lastOption[1] === 'var='
            || $lastOption[1] === 'expr=')
        ) {
            $parser->error(
                sprintf(
                    'Value/Expression for the option %1$s was expected.',
                    $ret->options[$lastOptionId]['name']
                ),
                $list->tokens[$list->idx - 1]
            );
        }

        if (empty($options['_UNSORTED'])) {
            ksort($ret->options);
        }

        --$list->idx;

        return $ret;
    }

    public static function build($component, array $options = array())
    {
        if (empty($component->options)) {
            return '';
        }

        $options = array();
        foreach ($component->options as $option) {
            if (! is_array($option)) {
                $options[] = $option;
            } else {
                $options[] = $option['name']
                    . ((! empty($option['equals']) && $option['equals']) ? '=' : ' ')
                    . (! empty($option['expr']) ? $option['expr'] : $option['value']);
            }
        }

        return implode(' ', $options);
    }

    public function has($key, $getExpr = false)
    {
        foreach ($this->options as $option) {
            if (is_array($option)) {
                if (! strcasecmp($key, $option['name'])) {
                    return $getExpr ? $option['expr'] : $option['value'];
                }
            } elseif (! strcasecmp($key, $option)) {
                return true;
            }
        }

        return false;
    }

    public function remove($key)
    {
        foreach ($this->options as $idx => $option) {
            if (is_array($option)) {
                if (! strcasecmp($key, $option['name'])) {
                    unset($this->options[$idx]);

                    return true;
                }
            } elseif (! strcasecmp($key, $option)) {
                unset($this->options[$idx]);

                return true;
            }
        }

        return false;
    }

    public function merge($options)
    {
        if (is_array($options)) {
            $this->options = array_merge_recursive($this->options, $options);
        } elseif ($options instanceof self) {
            $this->options = array_merge_recursive($this->options, $options->options);
        }
    }

    public function isEmpty()
    {
        return empty($this->options);
    }
}

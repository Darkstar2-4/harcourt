<?php


namespace PhpMyAdmin\SqlParser\Statements;

use PhpMyAdmin\SqlParser\Components\OptionsArray;
use PhpMyAdmin\SqlParser\Components\SetOperation;
use PhpMyAdmin\SqlParser\Statement;

/**
 * `SET` statement.
 *
 * @category   Statements
 *
 * @license    https://www.gnu.org/licenses/gpl-2.0.txt GPL-2.0+
 */
class SetStatement extends Statement
{
    public static $CLAUSES = array(
        'SET' => array(
            'SET',
            3
        ),
        '_END_OPTIONS' => array(
            '_END_OPTIONS',
            1
        )
    );

    public static $OPTIONS = array(
        'CHARSET' => array(
            3,
            'var',
        ),
        'CHARACTER SET' => array(
            3,
            'var',
        ),
        'NAMES' => array(
            3,
            'var',
        ),
        'PASSWORD' => array(
            3,
            'expr',
        ),
        'SESSION' => 3,
        'GLOBAL' => 3,
        'PERSIST' => 3,
        'PERSIST_ONLY' => 3,
        '@@SESSION' => 3,
        '@@GLOBAL' => 3,
        '@@PERSIST' => 3,
        '@@PERSIST_ONLY' => 3,
    );

    public static $END_OPTIONS = array(
        'COLLATE' => array(
            1,
            'var',
        ),
        'DEFAULT' => 1
    );

    public $options;

    public $end_options;

    public $set;

    public function build()
    {
        $ret = 'SET ' . OptionsArray::build($this->options)
            . ' ' . SetOperation::build($this->set)
            . ' ' . OptionsArray::build($this->end_options);

        return trim($ret);
    }
}

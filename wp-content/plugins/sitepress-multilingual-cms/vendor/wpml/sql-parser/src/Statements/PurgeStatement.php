<?php


namespace PhpMyAdmin\SqlParser\Statements;

use PhpMyAdmin\SqlParser\Components\Expression;
use PhpMyAdmin\SqlParser\Components\OptionsArray;
use PhpMyAdmin\SqlParser\Parser;
use PhpMyAdmin\SqlParser\Statement;
use PhpMyAdmin\SqlParser\Token;
use PhpMyAdmin\SqlParser\TokensList;

/**
 * `PURGE` statement.
 *
 * PURGE { BINARY | MASTER } LOGS
 *   { TO 'log_name' | BEFORE datetime_expr }
 *
 * @category   Statements
 *
 * @license    https://www.gnu.org/licenses/gpl-2.0.txt GPL-2.0+
 */
class PurgeStatement extends Statement
{
    public $log_type;

    public $end_option;

    public $end_expr;

    public function build()
    {
        $ret = 'PURGE ' . $this->log_type . ' ' . 'LOGS '
            . ($this->end_option !== null ? ($this->end_option . ' ' . $this->end_expr) : '');
        return trim($ret);
    }

    public function parse(Parser $parser, TokensList $list)
    {
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

            switch ($state) {
                case 0:
                    $this->log_type = self::parseExpectedKeyword($parser, $token, array('BINARY', 'MASTER'));
                    break;
                case 1:
                    self::parseExpectedKeyword($parser, $token, array('LOGS'));
                    break;
                case 2:
                    $this->end_option = self::parseExpectedKeyword($parser, $token, array('TO', 'BEFORE'));
                    break;
                case 3:
                    $this->end_expr = Expression::parse($parser, $list, array());
                    break;
                default:
                    $parser->error('Unexpected token.', $token);
                    break;
            }
            $state++;
            $prevToken = $token;
        }

        if ($state != 4) {
            $parser->error('Unexpected token.', $prevToken);
        }
    }

    private static function parseExpectedKeyword($parser, $token, $expected_keywords)
    {
        if ($token->type === Token::TYPE_KEYWORD) {
            if (in_array($token->keyword, $expected_keywords)) {
                return $token->keyword;
            } else {
                $parser->error('Unexpected keyword', $token);
            }
        } else {
            $parser->error('Unexpected token.', $token);
        }
        return null;
    }
}

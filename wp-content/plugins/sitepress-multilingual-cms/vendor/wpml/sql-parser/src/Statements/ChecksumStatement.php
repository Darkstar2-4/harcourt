<?php


namespace PhpMyAdmin\SqlParser\Statements;

/**
 * `CHECKSUM` statement.
 *
 * CHECKSUM TABLE tbl_name array(, tbl_name] ... array( QUICK | EXTENDED ]
 *
 * @category   Statements
 *
 * @license    https://www.gnu.org/licenses/gpl-2.0.txt GPL-2.0+
 */
class ChecksumStatement extends MaintenanceStatement
{
    public static $OPTIONS = array(
        'TABLE' => 1,

        'QUICK' => 2,
        'EXTENDED' => 3
    );
}

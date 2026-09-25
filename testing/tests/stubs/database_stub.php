<?php

/**
 * Shared mock of database_class for unit tests covering simple CRUD
 * *_class models (id, a unique lookup column, comment, created) such as
 * blacklist_class and captcha_bypass_class. Table/column names are parsed
 * out of the SQL text itself so one stub serves every such model without
 * per-test-file duplication — each test's setUp() calls resetState()
 * before running, so sharing the row store across test files is safe
 * since tests execute sequentially, never concurrently.
 */

if (!class_exists('database_meta_class')) {
    class database_meta_class {
        public $rows       = 0;
        public $error      = '';
        public $found_rows = 0;
    }
}

if (!function_exists('fn_escape')) {
    function fn_escape($value, $quote = TRUE) {
        if ($quote === FALSE) return (strlen($value)) ? $value : "0";
        if ($quote === "null" && !strlen($value)) return "null";
        return "'" . addslashes($value) . "'";
    }
}

if (!class_exists('database_class')) {
    class database_class {
        public $meta;
        public $queryLog = [];
        private $_queryResult = [];
        private $_cursor      = 0;
        private $_lastInsertId = 0;

        // Generic builder for query_where-shaped objects (field/operator/value,
        // "op" also accepted since not every test file's query_where stub uses
        // the same property name). Only "in"/"not in" are exercised so far
        // (classes/search.php's category-block filter); other operators fall
        // back to a simple equality fragment.
        public function where($conditions) {
            if (!is_array($conditions)) return '';
            $parts = [];
            foreach ($conditions as $item) {
                $field    = isset($item->field) ? $item->field : null;
                $operator = strtolower(isset($item->operator) ? $item->operator : (isset($item->op) ? $item->op : ''));
                $value    = isset($item->value) ? $item->value : null;
                if (!$field) continue;
                switch (TRUE) {
                  case (in_array($operator, ['in', 'not in'])):
                    if (!is_array($value) || !sizeof($value)) continue 2;
                    $parts[] = "{$field} {$operator} (" . implode(', ', $value) . ")";
                    break;
                  case ($operator !== ''):
                    $parts[] = "{$field} {$operator} '{$value}'";
                    break;
                  default:
                    $parts[] = (string) $field;
                }
            }
            return sizeof($parts) ? 'where ' . implode(' and ', $parts) . "\n" : '';
        }

        public function query($q) {
            $this->meta = new database_meta_class();
            if (is_array($q)) $q = implode(' ', $q);
            $q = trim($q);
            $this->queryLog[] = $q;

            if (preg_match("/^SHOW TABLES LIKE '([a-zA-Z_]+)'/i", $q, $m)) {
                $exists = !empty(self::$tableExists);
                $this->meta->rows   = $exists ? 1 : 0;
                $this->_queryResult = $exists ? [['Tables_in_db' => $m[1]]] : [];
                $this->_cursor = 0;
                return;
            }

            if (stripos($q, 'CREATE TABLE') !== false) {
                self::$tableExists = true;
                return;
            }

            if (preg_match('/^select .*? from ([a-zA-Z_]+)/i', $q, $m)) {
                $matches = self::$rows;
                if (preg_match("/where (\w+)='([^']*)'/i", $q, $wm)) {
                    $col = $wm[1];
                    $val = strtolower($wm[2]);
                    $matches = array_values(array_filter($matches, function ($r) use ($col, $val) {
                        return isset($r[$col]) && strtolower((string) $r[$col]) === $val;
                    }));
                } elseif (preg_match("/where id='?(\d+)'?/i", $q, $wm)) {
                    $id = (int) $wm[1];
                    $matches = array_values(array_filter($matches, function ($r) use ($id) {
                        return isset($r['id']) && (int) $r['id'] === $id;
                    }));
                }
                $this->meta->rows   = count($matches);
                $this->_queryResult = $matches;
                $this->_cursor = 0;
                return;
            }

            if (stripos($q, 'insert into log') !== false) {
                self::$insertCounter++;
                $this->_lastInsertId = self::$insertCounter;
                self::$insertedRows[] = $q;
                return;
            }

            if (preg_match('/^insert into ([a-zA-Z_]+)/i', $q, $m)) {
                self::$lastInsertCounter++;
                $this->_lastInsertId = self::$lastInsertCounter;
                $row = ['id' => $this->_lastInsertId];
                if (preg_match_all("/([a-zA-Z_]+)=('([^']*)'|(\d+)|null)/i", $q, $pairs, PREG_SET_ORDER)) {
                    foreach ($pairs as $pair) {
                        $col = $pair[1];
                        if ($col === 'id') continue;
                        if (strtolower($pair[2]) === 'null') {
                            $row[$col] = null;
                        } elseif (isset($pair[3]) && $pair[3] !== '') {
                            $row[$col] = $pair[3];
                        } elseif ($pair[2] === "''") {
                            $row[$col] = '';
                        } else {
                            $row[$col] = (int) $pair[4];
                        }
                    }
                }
                self::$rows[] = $row;
                return;
            }

            if (preg_match('/^delete from ([a-zA-Z_]+)/i', $q, $m)) {
                if (preg_match("/where id='?(\d+)'?/i", $q, $wm)) {
                    $id = (int) $wm[1];
                    self::$rows = array_values(array_filter(self::$rows, function ($r) use ($id) {
                        return (int) $r['id'] !== $id;
                    }));
                }
                return;
            }
        }

        public function fetch_array() {
            if ($this->_cursor < count($this->_queryResult)) {
                return $this->_queryResult[$this->_cursor++];
            }
            return null;
        }
        public function free_result() {}
        public function insert_id()   { return $this->_lastInsertId; }

        public static $tableExists       = true;
        public static $rows              = [];
        public static $lastInsertCounter = 0;
        public static $insertedRows      = [];
        public static $insertCounter     = 0;

        public static function resetState() {
            self::$tableExists       = true;
            self::$rows              = [];
            self::$lastInsertCounter = 0;
            self::$insertedRows      = [];
            self::$insertCounter     = 0;
        }
    }
}

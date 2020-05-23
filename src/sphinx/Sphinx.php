<?php declare(strict_types=1);

namespace mii\search\sphinx;

use mii\core\Component;


class Sphinx extends Component
{
    // Query types
    public const RAW    = 0;
    public const SELECT = 1;
    public const INSERT = 2;
    public const REPLACE = 3;
    public const UPDATE = 4;
    public const DELETE = 5;

    public const MULTI = 6;
    public const MULTI_SELECT = 7;

    protected string $hostname = '127.0.0.1';
    protected int $port = 9306;

    protected static array $escape_from = ['\\', '(', ')', '|', '-', '!', '@', '~', '"', '&', '/', '^', '$', '=', '<'];
    protected static array $escape_to = ['\\\\', '\(', '\)', '\|', '\-', '\!', '\@', '\~', '\"', '\&', '\/', '\^', '\$', '\=', '\<'];


    /**
     * @var  string  the last query executed
     */
    public string $last_query;

    protected ?\mysqli $conn = null;

    /**
     * Connect to the sphinx. This is called automatically when the first query is executed.
     *
     * @return  void
     * @throws SphinxqlException
     */
    public function connect(): void
    {
        if ($this->conn)
            return;

        try {
            $this->conn = new \mysqli($this->hostname, null, null, null, $this->port);
        } catch (\Throwable $e) {
            $this->conn = null;
            throw new SphinxqlException($e->getMessage(), $e->getCode(), $e);
        }

        $this->conn->options(\MYSQLI_OPT_INT_AND_FLOAT_NATIVE, 1);
    }


    public function __destruct()
    {
        $this->disconnect();
    }


    public function disconnect(): bool
    {
        try {
            // Database is assumed disconnected
            $status = true;

            if (is_resource($this->conn)) {
                if ($status = $this->conn->close()) {
                    // Clear the connection
                    $this->conn = NULL;
                }
            }
        } catch (\Throwable $e) {
            // Database is probably not disconnected
            $status = !is_resource($this->conn);
        }

        return $status;
    }


    public function query(int $type, string $sql)
    {
        $this->conn or $this->connect();
        assert((config('debug') && ($benchmark = \mii\util\Profiler::start("Database", $sql))) || 1);

        $result = $this->conn->query($sql);

        if ($result === false || $this->conn->errno) {
            assert((isset($benchmark) && \mii\util\Profiler::delete($benchmark)) || 1);

            throw new SphinxqlException($this->conn->error . " [ $sql ]", $this->conn->errno);
        }

        assert((isset($benchmark) && \mii\util\Profiler::stop($benchmark)) || 1);

        // Set the last query
        $this->last_query = $sql;

        switch ($type) {
            case self::SELECT:
                return $result->fetch_all(\MYSQLI_ASSOC);
            case self::INSERT:
                return $this->inserted_id();
            default:
                return $this->affected_rows();
        }
    }

    /**
     * @param string $sql
     * @return array|null
     * @throws SphinxqlException
     */
    public function multi_query(string $sql): ?array
    {
        $this->conn or $this->connect();
        assert((config('debug') && ($benchmark = \mii\util\Profiler::start("Database", $sql))) || 1);

        $results = [];

        if (false !== ($succeed = $this->conn->multi_query($sql))) {
            do {

                if ($result = $this->conn->store_result()) {
                    $results[] = $result->fetch_all(\MYSQLI_ASSOC);
                    $result->free_result();
                }

            } while ($this->conn->more_results() && $this->conn->next_result());
        }

        if ($succeed === false || $this->conn->errno) {
            throw new SphinxqlException("{$this->conn->error} [ $sql ]", $this->conn->errno);
        }

        assert((isset($benchmark) && \mii\util\Profiler::stop($benchmark)) || 1);

        return $results;
    }

    public function update(string $query) : int {
        $this->query(self::UPDATE, $query);
        return $this->affected_rows();
    }

    public function optimize(string $index) : int {
        return $this->query(self::RAW, "OPTIMIZE INDEX $index");
    }


    public function flush_rtindex(string $index) : int {
        return $this->query(self::RAW, "FLUSH RTINDEX $index");
    }

    public function truncate_rtindex(string $index) : int {
        return $this->query(self::RAW, "TRUNCATE RTINDEX $index");
    }


    public function inserted_id()
    {
        return $this->conn->insert_id;
    }

    public function affected_rows(): int
    {
        return $this->conn->affected_rows;
    }

    /**
     * Start a SQL transaction
     *
     * @return  boolean
     * @throws SphinxqlException
     */
    public function begin($mode = NULL): bool
    {
        // Make sure the database is connected
        $this->conn or $this->connect();

        if ($mode and !$this->conn->query("SET TRANSACTION ISOLATION LEVEL $mode")) {
            throw new SphinxqlException($this->conn->error, $this->conn->errno);
        }

        return (bool)$this->conn->query('START TRANSACTION');
    }

    /**
     * Commit the current transaction
     *
     *     // Commit the database changes
     *     $db->commit();
     *
     * @return  boolean
     * @throws SphinxqlException
     */
    public function commit(): bool
    {
        // Make sure the database is connected
        $this->conn or $this->connect();

        return (bool)$this->conn->query('COMMIT');
    }

    /**
     * Abort the current transaction
     *
     *     // Undo the changes
     *     $db->rollback();
     *
     * @return  boolean
     */
    public function rollback(): bool
    {
        // Make sure the database is connected
        $this->conn or $this->connect();

        return (bool)$this->conn->query('ROLLBACK');
    }


    /**
     * @param int|null $type
     * @return QueryBuilder
     */
    public function query_builder(int $type = null) : QueryBuilder
    {
        return new QueryBuilder($type, $this);
    }

    /**
     * Quote a database column name
     *
     * @param mixed $column column name or array(column, alias)
     * @return  string
     * @uses    Sphinx::quote_identifier
     */
    public static function quote_column($column): string
    {
        if (is_array($column)) {
            [$column, $alias] = $column;
            $alias = str_replace('`', '``', $alias);
        }

        if ($column instanceof QueryBuilder) {
            // Create a sub-query
            $column = '(' . $column->compile() . ')';
        } elseif ($column instanceof Expression) {
            // Compile the expression
            $column = $column->compile();
        } else {
            // Convert to a string
            $column = (string)$column;

            $column = str_replace('`', '``', $column);

            if ($column === '*') {
                return $column;
            } elseif (strpos($column, '.') !== false) {
                $parts = explode('.', $column);

                foreach ($parts as & $part) {
                    if ($part !== '*') {
                        // Quote each of the parts
                        $part = "`$part`";
                    }
                }

                $column = implode('.', $parts);
            } else {
                $column = "`$column`";
            }
        }

        if (isset($alias)) {
            $column .= " AS `$alias`";
        }

        return $column;
    }

    public static function quote_index($index) : string
    {
        if (is_array($index)) {
            [$index, $alias] = $index;
            $alias = \str_replace('`', '``', $alias);
        }

        if ($index instanceof Expression) {
            // Compile the expression
            $index = $index->compile();
        } else {
            // Convert to a string
            $index = (string)$index;
            $index = str_replace('`', '``', $index);
            $index = "`$index`";
        }

        if (isset($alias)) {
            // Attach table prefix to alias
            $index .= " AS `$alias`";
        }

        return $index;
    }

    public static function escape_match($string): string
    {
        if ($string instanceof Expression) {
            return $string->value();
        }

        return \str_replace(self::$escape_from, self::$escape_to, (string)$string);
    }

    /**
     * Quote a value for an SQL query.
     *
     *
     * Objects passed to this function will be converted to strings.
     * [Expression] objects will be compiled.
     * [Query] objects will be compiled and converted to a sub-query.
     * All other objects will be converted using the `__toString` method.
     *
     * @param mixed $value any value to quote
     * @return  string
     * @uses    Sphinx::escape
     */
    public static function quote($value): string
    {
        if (\is_null($value)) {
            return 'NULL';
        } elseif ($value === true) {
            return "'1'";
        } elseif ($value === false) {
            return "'0'";
        } elseif (\is_int($value)) {
            return (string)$value;
        } elseif (is_float($value)) {
            // Convert to non-locale aware float to prevent possible commas
            return sprintf('%F', $value);
        } elseif (is_array($value)) {
            return '(' . implode(', ', array_map([static::class, __FUNCTION__], $value)) . ')';
        } elseif (is_object($value)) {
            if ($value instanceof QueryBuilder) {
                // Create a sub-query
                return '(' . $value->compile() . ')';
            } elseif ($value instanceof Expression) {
                // Compile the expression
                return $value->compile();
            }
        }

        return self::escape((string)$value);
    }

    /**
     * Sanitize a string by escaping characters that could cause an SQL
     * injection attack.
     *
     * @param string $value value to quote
     * @return  string
     */
    public static function escape(string $value): string
    {
        static $patterns     =	['/[\x27\x22\x5C]/u', '/\x0A/u', '/\x0D/u', '/\x00/u', '/\x1A/u'];
        static $replacements =	['\\\$0', '\n', '\r', '\0', '\Z'];

        $value = preg_replace($patterns, $replacements, $value);

        // SQL standard is to use single-quotes for all values
        return "'$value'";
    }


    /**
     * Quote a database identifier
     *
     * Objects passed to this function will be converted to strings.
     * [Database_Expression] objects will be compiled.
     * [Database_Query] objects will be compiled and converted to a sub-query.
     * All other objects will be converted using the `__toString` method.
     *
     * @param mixed $value any identifier
     * @return  string
     */
    public function quote_identifier($value): string
    {

        if (is_array($value)) {
            list($value, $alias) = $value;
            $alias = str_replace('`', '``', $alias);
        }

        if ($value instanceof QueryBuilder) {
            // Create a sub-query
            $value = '(' . $value->compile() . ')';
        } elseif ($value instanceof Expression) {
            // Compile the expression
            $value = $value->compile();
        } else {
            // Convert to a string
            $value = (string)$value;

            $value = str_replace('`', '``', $value);

            if (strpos($value, '.') !== false) {
                $parts = explode('.', $value);

                foreach ($parts as & $part) {
                    // Quote each of the parts
                    $part = '`' . $part . '`';
                }

                $value = implode('.', $parts);
            } else {
                $value = '`' . $value . '`';
            }
        }

        if (isset($alias)) {
            $value .= " AS `$alias`";
        }

        return $value;
    }


    public function call_keywords(string $text, string $index, array $options = null): array
    {
        if (!$options) {
            $opts = '1 as fold_wildcards,1 as fold_lemmas,1 as fold_blended,1 as expansion_limit,1 as stats';
        } else {
            $opts = [];
            foreach ($options as $opt_name) {
                $opts[] = "1 as $opt_name";
            }
            $opts = implode(',', $opts);
        }

        return $this->query(self::SELECT, "CALL KEYWORDS(" . self::escape($text) . ", '$index', $opts");
    }

}

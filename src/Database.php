<?php

namespace levmorozov\sphinxql;


class Database
{

    // Query types
    public const SELECT = 1;
    public const INSERT = 2;
    public const UPDATE = 3;
    public const DELETE = 4;

    public const MULTI = 5;

    public const MULTI_SELECT = 6;

    protected $escape_chars = array(
        '\\' => '\\\\',
        '(' => '\(',
        ')' => '\)',
        '|' => '\|',
        '-' => '\-',
        '!' => '\!',
        '@' => '\@',
        '~' => '\~',
        '"' => '\"',
        '&' => '\&',
        '/' => '\/',
        '^' => '\^',
        '$' => '\$',
        '=' => '\=',
        '<' => '\<',
    );

    /**
     * @var  string  the last query executed
     */
    public $last_query;

    /**
     * @var \mysqli Raw server connection
     */
    protected $_connection;

    /**
     * @var array configuration array
     */
    protected $_config;


    public function __construct(array $config)
    {
        // Store the config locally
        $this->_config = $config;
    }

    public function __destruct()
    {
        $this->disconnect();
    }


    public function disconnect()
    {
        try {
            // Database is assumed disconnected
            $status = true;

            if (is_resource($this->_connection)) {
                if ($status = $this->_connection->close()) {
                    // Clear the connection
                    $this->_connection = NULL;
                }
            }
        } catch (\Throwable $e) {
            // Database is probably not disconnected
            $status = !is_resource($this->_connection);
        }

        return $status;
    }

    /**
     * Connect to the sphinx. This is called automatically when the first query is executed.
     *
     * @return  void
     */
    public function connect() : void
    {
        if ($this->_connection)
            return;

        try {
            $this->_connection = new \mysqli($this->_config['host'], '', '', '', $this->_config['port'], '');
        } catch (\Throwable $e) {
            $this->_connection = null;
            throw new SphinxqlException($e->getMessage(), $e->getCode(), $e);
        }
    }


    public function query(?int $type, string $sql) : ?array  {
        // Make sure the database is connected
        $this->_connection or $this->connect();

        $benchmark = false;
        if (config('debug')) {
            // Benchmark this query for the current instance
            $benchmark = \mii\util\Profiler::start("Sphinx", $sql);
        }

        // Execute the query
        if($type === Database::MULTI_SELECT) {

            $this->_connection->multi_query($sql);
            $results = [];
            do {

                if($result = $this->_connection->store_result()) {
                    $results[] = $result->fetch_all(MYSQLI_ASSOC);
                    $result->free_result();
                }

            } while ($this->_connection->more_results() && $this->_connection->next_result());


            return $results;
        } else {
            $result = $this->_connection->query($sql);
        }

        if ( $result === false || $this->_connection->errno ) {
            if ($benchmark) {
                // This benchmark is worthless
                \mii\util\Profiler::delete($benchmark);
            }

            throw new SphinxqlException($this->_connection->error. " [ $sql ]", $this->_connection->errno);
        }

        if ($benchmark) {
            \mii\util\Profiler::stop($benchmark);
        }

        // Set the last query
        $this->last_query = $sql;

        if ($type === Database::SELECT) {

            return $result->fetch_all(MYSQLI_ASSOC);
        }
        return null;
    }

    public function inserted_id() {
        return $this->_connection->insert_id;
    }

    public function affected_rows() : int {
        return $this->_connection->affected_rows;
    }

    public function quote_index($index) {
        if (is_array($index)) {
            list($index, $alias) = $index;
            $alias = str_replace('`', '``', $alias);
        }

        if ($index instanceof Expression) {
            // Compile the expression
            $index = $index->compile($this);
        } else {
            // Convert to a string
            $index = (string)$index;
            $index = str_replace('`', '``', $index);
            $index = '`' . $index . '`';
        }

        if (isset($alias)) {
            // Attach table prefix to alias
            $index .= ' AS ' . '`' . $alias . '`';
        }

        return $index;
    }

    public function escape_match($string) {
        if ($string instanceof Expression) {
            return $string->value();
        }

        // Make sure the database is connected
        $this->_connection or $this->connect();

        $string = mb_strtolower(str_replace(array_keys($this->escape_chars), array_values($this->escape_chars), $string), 'utf8');


        if (($string = $this->_connection->real_escape_string((string)$string)) === false) {
            throw new SphinxqlException($this->_connection->error, $this->_connection->errno);
        }

        return $string;

    }

    /**
     * Quote a value for an SQL query.
     *
     *     $db->quote(NULL);   // 'NULL'
     *     $db->quote(10);     // 10
     *     $db->quote('fred'); // 'fred'
     *
     * Objects passed to this function will be converted to strings.
     * [Database_Expression] objects will be compiled.
     * [Database_Query] objects will be compiled and converted to a sub-query.
     * All other objects will be converted using the `__toString` method.
     *
     * @param   mixed $value any value to quote
     * @return  string
     * @uses    Database::escape
     */
    public function quote($value) : string
    {
        if ($value === NULL) {
            return 'NULL';
        } elseif ($value === true) {
            return "'1'";
        } elseif ($value === false) {
            return "'0'";
        } elseif (is_int($value)) {
            return (int)$value;
        } elseif (is_float($value)) {
            // Convert to non-locale aware float to prevent possible commas
            return sprintf('%F', $value);
        } elseif (is_array($value)) {
            return '(' . implode(', ', array_map([$this, __FUNCTION__], $value)) . ')';
        } elseif (is_object($value)) {
            if ($value instanceof Query) {
                // Create a sub-query
                return '(' . $value->compile($this) . ')';
            } elseif ($value instanceof Expression) {
                // Compile the expression
                return $value->compile($this);
            } else {
                // Convert the object to a string
                return $this->quote((string)$value);
            }
        }

        return $this->escape($value);
    }

    /**
     * Sanitize a string by escaping characters that could cause an SQL
     * injection attack.
     *
     *     $value = $db->escape('any string');
     *
     * @param   string $value value to quote
     * @return  string
     */
    public function escape($value) : string
    {
        // Make sure the database is connected
        $this->_connection or $this->connect();

        if (($value = $this->_connection->real_escape_string((string)$value)) === false) {
            throw new SphinxqlException($this->_connection->error, $this->_connection->errno);
        }

        // SQL standard is to use single-quotes for all values
        return "'$value'";
    }

    /**
     * Start a SQL transaction
     *
     *     // Start the transactions
     *     $db->begin();
     *
     *     try {
     *          DB::insert('users')->values($user1)...
     *          DB::insert('users')->values($user2)...
     *          // Insert successful commit the changes
     *          $db->commit();
     *     }
     *     catch (Database_Exception $e)
     *     {
     *          // Insert failed. Rolling back changes...
     *          $db->rollback();
     *      }
     *
     * @param string $mode transaction mode
     * @return  boolean
     */
    public function begin($mode = NULL)
    {
        // Make sure the database is connected
        $this->_connection or $this->connect();

        if ($mode AND !$this->_connection->query("SET TRANSACTION ISOLATION LEVEL $mode")) {
            throw new SphinxqlException($this->_connection->error, $this->_connection->errno);
        }

        return (bool)$this->_connection->query('START TRANSACTION');
    }

    /**
     * Commit the current transaction
     *
     *     // Commit the database changes
     *     $db->commit();
     *
     * @return  boolean
     */
    public function commit()
    {
        // Make sure the database is connected
        $this->_connection or $this->connect();

        return (bool)$this->_connection->query('COMMIT');
    }

    /**
     * Abort the current transaction
     *
     *     // Undo the changes
     *     $db->rollback();
     *
     * @return  boolean
     */
    public function rollback()
    {
        // Make sure the database is connected
        $this->_connection or $this->connect();

        return (bool)$this->_connection->query('ROLLBACK');
    }

    /**
     * Quote a database column name and add the table prefix if needed.
     *
     *     $column = $db->quote_column($column);
     *
     * You can also use SQL methods within identifiers.
     *
     *     $column = $db->quote_column(DB::expr('COUNT(`column`)'));
     *
     * Objects passed to this function will be converted to strings.
     * [Database_Expression] objects will be compiled.
     * [Database_Query] objects will be compiled and converted to a sub-query.
     * All other objects will be converted using the `__toString` method.
     *
     * @param   mixed $column column name or array(column, alias)
     * @return  string
     * @uses    Database::quote_identifier
     * @uses    Database::table_prefix
     */
    public function quote_column($column) : string
    {

        if (is_array($column)) {
            list($column, $alias) = $column;
            $alias = str_replace('`', '``', $alias);
        }

        if ($column instanceof Query) {
            // Create a sub-query
            $column = '(' . $column->compile($this) . ')';
        } elseif ($column instanceof Expression) {
            // Compile the expression
            $column = $column->compile($this);
        } else {
            // Convert to a string
            $column = (string)$column;

            $column = str_replace('`', '``', $column);

            if ($column === '*') {
                return $column;
            } elseif (strpos($column, '.') !== false) {
                $parts = explode('.', $column);

                if ($prefix = $this->table_prefix()) {
                    // Get the offset of the table name, 2nd-to-last part
                    $offset = count($parts) - 2;

                    // Add the table prefix to the table name
                    $parts[$offset] = $prefix . $parts[$offset];
                }

                foreach ($parts as & $part) {
                    if ($part !== '*') {
                        // Quote each of the parts
                        $part = '`' . $part . '`';
                    }
                }

                $column = implode('.', $parts);
            } else {
                $column = '`' . $column . '`';
            }
        }

        if (isset($alias)) {
            $column .= ' AS ' . '`' . $alias . '`';
        }

        return $column;
    }

    /**
     * Quote a database identifier
     *
     * Objects passed to this function will be converted to strings.
     * [Database_Expression] objects will be compiled.
     * [Database_Query] objects will be compiled and converted to a sub-query.
     * All other objects will be converted using the `__toString` method.
     *
     * @param   mixed $value any identifier
     * @return  string
     */
    public function quote_identifier($value) : string
    {

        if (is_array($value)) {
            list($value, $alias) = $value;
            $alias = str_replace('`', '``', $alias);
        }

        if ($value instanceof Query) {
            // Create a sub-query
            $value = '(' . $value->compile($this) . ')';
        } elseif ($value instanceof Expression) {
            // Compile the expression
            $value = $value->compile($this);
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
            $value .= ' AS ' . '`' . $alias . '`';
        }

        return $value;
    }

}
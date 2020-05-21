<?php declare(strict_types=1);

namespace mii\search\sphinx;

use mii\valid\Rules;
use mii\web\Exception;

/**
 * Database Query Builder
 *
 * @copyright  (c) 2015 Lev Morozov
 * @copyright  (c) 2008-2009 Kohana Team
 */
class QueryBuilder
{
    protected Sphinx $db;

    // Query type
    protected ?int $_type;

    protected $_index;

    // (...)
    protected array $_columns = [];

    // VALUES (...)
    protected array $_values = [];

    // SET ...
    protected array $_set = [];


    // SELECT ...
    protected array $_select = [];

    // DISTINCT
    protected bool $_distinct = false;

    // FROM ...
    protected array $_from = [];

    // GROUP BY ...
    protected array $_group_by = [];

    // HAVING ...
    protected array $_having = [];

    // OFFSET ...
    protected ?int $_offset = null;

    // WHERE ...
    protected array $_where = [];

    protected $_last_condition_where;

    // ORDER BY ...
    protected array $_order_by = [];

    // LIMIT ...
    protected ?int $_limit = null;

    // MATCH
    protected array $_match = [];

    protected array $_facets = [];

    // OPTION ...
    protected array $_option = [];

    /**
     * Creates a new SQL query of the specified type.
     *
     * @param integer     $type query type: Sphinx::SELECT, Sphinx::INSERT, etc
     * @param Sphinx|null $db
     */
    public function __construct(int $type = null, Sphinx $db = null)
    {
        $this->_type = $type;
        $this->db = $db ?? \Mii::$app->get('sphinx');
    }

    /**
     * Return the SQL query string.
     *
     * @return  string
     */
    public function __toString()
    {
        return $this->compile();
    }


    /**** SELECT ****/


    /**
     * Sets the initial columns to select
     *
     * @param array $columns column list
     * @return QueryBuilder
     */
    public function select(array $columns = null): QueryBuilder
    {
        $this->_type = Sphinx::SELECT;

        if (!empty($columns)) {
            $this->_select = $columns;
        }

        return $this;
    }

    /**
     * Enables or disables selecting only unique columns using "SELECT DISTINCT"
     *
     * @param boolean $value enable or disable distinct columns
     * @return  $this
     */
    public function distinct(bool $value): self
    {
        $this->_distinct = $value;

        return $this;
    }


    /**
     * Choose the tables to select "FROM ..."
     *
     * @param mixed $table table name or array($table, $alias) or object
     * @return  $this
     */
    public function from($table): self
    {
        $this->_from[] = $table;

        return $this;
    }


    /**
     * Creates a "GROUP BY ..." filter.
     *
     * @param mixed $columns column name or alias
     * @return  $this
     */
    public function group_by(...$columns) : self
    {
        $this->_group_by = array_merge($this->_group_by, $columns);

        return $this;
    }

    /**
     * Alias of and_having()
     *
     * @param mixed  $column column name or array($column, $alias) or object
     * @param string $op logic operator
     * @param mixed  $value column value
     * @return  $this
     */
    public function having($column = null, $op = null, $value = null) : self
    {
        return $this->and_having($column, $op, $value);
    }

    /**
     * Creates a new "AND HAVING" condition for the query.
     *
     * @param mixed  $column column name or array($column, $alias) or object
     * @param string $op logic operator
     * @param mixed  $value column value
     * @return  $this
     */
    public function and_having($column, $op, $value = NULL) : self
    {
        if ($column === null) {
            $this->_having[] = ['AND' => '('];
            $this->_last_condition_where = false;
        } elseif (\is_array($column)) {
            foreach ($column as $row) {
                $this->_having[] = ['AND' => $row];
            }
        } else {
            $this->_having[] = ['AND' => [$column, $op, $value]];
        }

        return $this;
    }

    /**
     * Creates a new "OR HAVING" condition for the query.
     *
     * @param mixed  $column column name or array($column, $alias) or object
     * @param string $op logic operator
     * @param mixed  $value column value
     * @return  $this
     */
    public function or_having($column = null, $op = null, $value = null) : self
    {
        if ($column === null) {
            $this->_having[] = ['OR' => '('];
            $this->_last_condition_where = false;
        } elseif (\is_array($column)) {
            foreach ($column as $row) {
                $this->_having[] = ['OR' => $row];
            }
        } else {
            $this->_having[] = ['OR' => [$column, $op, $value]];
        }

        return $this;
    }


    /**
     * Start returning results after "OFFSET ..."
     *
     * @param integer $number starting result number or NULL to reset
     * @return  $this
     */
    public function offset(int $number) : self
    {
        $this->_offset = $number;

        return $this;
    }


    /***** WHERE ****/

    /**
     * Alias of and_where()
     *
     * @param mixed  $column column name or array($column, $alias) or object
     * @param string $op logic operator
     * @param mixed  $value column value
     * @return  $this
     */
    public function where($column = null, $op = null, $value = null) : self
    {
        return $this->and_where($column, $op, $value);
    }

    /**
     * Alias of and_filter()
     *
     * @param mixed  $column column name or array($column, $alias) or object
     * @param string $op logic operator
     * @param mixed  $value column value
     * @return  $this
     */
    public function filter($column, $op, $value)
    {
        return $this->and_filter($column, $op, $value);
    }

    /**
     * Creates a new "AND WHERE" condition for the query.
     *
     * @param mixed  $column column name or array($column, $alias) or object
     * @param string $op logic operator
     * @param mixed  $value column value
     * @return  $this
     */
    public function and_where($column, $op = null, $value = null)
    {

        if ($column === null) {
            $this->_where[] = ['AND' => '('];
            $this->_last_condition_where = true;
        } elseif (\is_array($column)) {
            foreach ($column as $row) {
                $this->_where[] = ['AND' => $row];
            }
        } else {
            $this->_where[] = ['AND' => [$column, $op, $value]];
        }

        return $this;
    }


    /**
     * Creates a new "AND WHERE" condition for the query. But only for not empty values.
     *
     * @param mixed  $column column name or array($column, $alias) or object
     * @param string $op logic operator
     * @param mixed  $value column value
     * @return  $this
     */
    public function and_filter($column, $op, $value)
    {
        if ($value === null || $value === "" || !Rules::not_empty((\is_string($value) ? trim($value) : $value)))
            return $this;

        return $this->and_where($column, $op, $value);
    }


    /**
     * Creates a new "OR WHERE" condition for the query.
     *
     * @param mixed  $column column name or array($column, $alias) or object
     * @param string $op logic operator
     * @param mixed  $value column value
     * @return  $this
     */
    public function or_where($column = null, $op = null, $value = null) : self
    {
        if ($column === null) {

            $this->_where[] = ['OR' => '('];
            $this->_last_condition_where = true;

        } elseif (\is_array($column)) {

            foreach ($column as $row) {
                $this->_where[] = ['OR' => $row];
            }

        } else {
            $this->_where[] = ['OR' => [$column, $op, $value]];
        }

        return $this;
    }

    /**
     * Creates a new "OR WHERE" condition for the query.
     *
     * @param mixed  $column column name or array($column, $alias) or object
     * @param string $op logic operator
     * @param mixed  $value column value
     * @return  $this
     */
    public function or_filter($column, $op, $value)
    {
        if ($value === null || $value === "" || !Rules::not_empty((\is_string($value) ? trim($value) : $value)))
            return $this;

        return $this->or_where($column, $op, $value);
    }

    public function end(bool $check_for_empty = false) : self
    {

        if ($this->_last_condition_where) {
            if ($check_for_empty !== false) {
                $group = \end($this->_where);

                if ($group and \reset($group) === '(') {
                    \array_pop($this->_where);
                    return $this;
                }
            }

            $this->_where[] = ['' => ')'];

        } else {

            if ($check_for_empty !== false) {
                $group = \end($this->_having);

                if ($group and \reset($group) === '(') {
                    \array_pop($this->_having);
                    return $this;
                }
            }

            $this->_having[] = ['' => ')'];

        }

        return $this;
    }

    /**
     * Closes an open "WHERE (...)" grouping.
     *
     * @return  $this
     */
    public function and_where_close() : self
    {
        $this->_where[] = ['AND' => ')'];

        return $this;
    }

    /**
     * Closes an open "WHERE (...)" grouping.
     *
     * @return  $this
     */
    public function or_where_close() : self
    {
        $this->_where[] = ['OR' => ')'];

        return $this;
    }


    /**
     * Applies sorting with "ORDER BY ..."
     *
     * @param mixed  $column column name or array([$column, $direction], [$column, $direction], ...)
     * @param string $direction direction of sorting
     * @return  $this
     */
    public function order_by($column, $direction = null) : self
    {
        if (\is_array($column) && $direction === null) {
            $this->_order_by = $column;
        } elseif ($column !== null) {
            $this->_order_by[] = [$column, $direction];
        } else {
            $this->_order_by = [];
        }

        return $this;
    }


    /**
     * Return up to "LIMIT ..." results
     *
     * @param integer $number maximum results to return or NULL to reset
     * @return  $this
     */
    public function limit(int $number) : self
    {
        $this->_limit = $number;

        return $this;
    }


    public function match($column, $value = null) : self
    {
        $this->_match[] = [$column, $value];

        return $this;
    }


    public function facet($query) : self
    {
        $this->_facets[] = $query;

        $this->_type = Sphinx::MULTI_SELECT;

        return $this;
    }


    /**** INSERT ****/


    /**
     * Sets the table to insert into.
     *
     * @param mixed $table table name or array($table, $alias) or object
     * @return  $this
     */
    public function index($table) : self
    {
        $this->_index = $table;

        return $this;
    }

    /**
     * Set the columns that will be inserted.
     *
     * @param array $columns column names
     * @return  $this
     */
    public function columns(array $columns) : self
    {
        $this->_columns = $columns;

        return $this;
    }

    /**
     * Adds or overwrites values. Multiple value sets can be added.
     *
     * @param array $values values list
     * @param   ...
     * @return  $this
     */
    public function values(...$values) : self
    {
        if (!\is_array($this->_values)) {
            throw new Exception('INSERT INTO ... SELECT statements cannot be combined with INSERT INTO ... VALUES');
        }

        $this->_values = array_merge($this->_values, $values);

        return $this;
    }


    /**
     * Set the values to update with an associative array.
     *
     * @param array $pairs associative (column => value) list
     * @return  $this
     */
    public function set(array $pairs)
    {
        foreach ($pairs as $column => $value) {
            $this->_set[] = [$column, $value];
        }

        return $this;
    }

    /**
     * Use a sub-query to for the inserted values.
     *
     * @param Query $query Database_Query of SELECT type
     * @return  $this
     */
    public function subselect(Query $query) : self
    {
        $this->_values = $query;

        return $this;
    }


    public function reset()
    {
        $this->_select =
        $this->_from =
        $this->_where =
        $this->_group_by =
        $this->_having =
        $this->_order_by = [];

        $this->_distinct = false;

        $this->_limit =
        $this->_offset = NULL;

        $this->_index = NULL;
        $this->_columns =
        $this->_values = [];

        return $this;
    }

    /**
     * Compile the SQL query and return it.
     *
     * @return  string
     */
    public function compile_insert(): string
    {
        // Start an insertion query

        $query = ($this->_type === Sphinx::REPLACE) ? 'INSERT INTO ' : 'REPLACE INTO ';

        $query .= Sphinx::quote_index($this->_index);

        // Add the column names
        $query .= ' (' . implode(', ', array_map([$this->db, 'quote_column'], $this->_columns)) . ') ';

        if (\is_array($this->_values)) {

            $groups = [];

            foreach ($this->_values as $group) {
                foreach ($group as $offset => $value) {
                    $group[$offset] = Sphinx::quote($value);
                }

                $groups[] = '(' . implode(', ', $group) . ')';
            }

            // Add the values
            $query .= 'VALUES ' . implode(', ', $groups);
        } else {
            // Add the sub-query
            $query .= (string)$this->_values;
        }

        return $query;
    }

    /**
     * Compile the SQL query and return it.
     */
    public function compile_update(): string
    {
        $query = 'UPDATE ' . Sphinx::quote_index($this->_index);

        // Add the columns to update
        $set = [];
        foreach ($this->_set as [$column, $value]) {

            $column = Sphinx::quote_column($column);

            $value = Sphinx::quote($value);

            $set[] = $column . ' = ' . $value;
        }

        $query .= ' SET ' . implode(', ', $set);

        if (!empty($this->_where)) {
            // Add selection conditions
            $query .= ' WHERE ' . $this->_compile_conditions($this->_where);
        }

        if (!empty($this->_order_by)) {
            $query .= ' ' . $this->_compile_order_by();
        }

        if ($this->_limit !== NULL) {
            $query .= ' LIMIT ' . $this->_limit;
        }

        return $query;
    }


    public function compile_delete(): string
    {
        $query = 'DELETE FROM ' . Sphinx::quote_index($this->_index);

        if (!empty($this->_where)) {
            $query .= ' WHERE ' . $this->_compile_conditions($this->_where);
        }

        if (!empty($this->_order_by)) {
            // Add sorting
            $query .= ' ' . $this->_compile_order_by();
        }

        if ($this->_limit !== NULL) {
            // Add limiting
            $query .= ' LIMIT ' . $this->_limit;
        }

        return $query;
    }

    /**
     * Compile the SQL query and return it.
     *
     * @return  string
     */
    public function compile_select(): string
    {

        // Start a selection query
        $query = 'SELECT ';

        if ($this->_distinct === true) {
            // Select only unique results
            $query .= 'DISTINCT ';
        }

        $columns = [];
        foreach ($this->_select as $column) {
            $columns[] = Sphinx::quote_column($column);
        }
        $query .= \implode(', ', \array_unique($columns));

        if (\count($this->_from) === 1) {
            $query .= ' FROM ' . Sphinx::quote_index($this->_from[0]);
        } else if (!empty($this->_from)) {
            $query .= ' FROM ' . \implode(', ', \array_map([Sphinx::class, 'quote_index'], $this->_from));
        }

        if (!empty($this->_where) || !empty($this->_match)) {
            // Add selection conditions

            if (empty($this->_match)) {
                $query .= ' WHERE ';
            } else {
                $query .= ' WHERE MATCH(' . $this->_compile_match($this->_match) . ')';

                if (!empty($this->_where))
                    $query .= ' AND ';
            }
            $query .= $this->_compile_conditions($this->_where);
        }

        if (!empty($this->_group_by)) {
            // Add grouping

            $group = [];

            foreach ($this->_group_by as $column) {
                $group[] = Sphinx::quote_column($column);
            }

            $query .= ' GROUP BY ' . \implode(', ', $group);
        }

        if (!empty($this->_having)) {
            // Add filtering conditions
            $query .= ' HAVING ' . $this->_compile_conditions($this->_having);
        }

        if (!empty($this->_order_by)) {
            // Add sorting
            $query .= ' ' . $this->_compile_order_by();
        }

        if ($this->_limit !== NULL) {
            // Add limiting

            $query .= $this->_offset !== null
                ? " LIMIT {$this->_limit}"
                : " LIMIT {$this->_offset}, {$this->_limit}";
        }

        if ($this->_option) {
            $query .= ' OPTION ' . implode(', ', $this->_option);
        }

        if ($this->_facets) {
            foreach ($this->_facets as $facet) {
                $query .= ' FACET ' . $facet;
            }
        }

        return $query;
    }

    protected function _compile_match(array $values) : string
    {
        $set = [];
        foreach ($values as [$column, $value]) {

            $column = Sphinx::escape_match($column);

            if ($value === null) {
                $set[] = $column;
            } else {
                $set[] = $column . ' ' . Sphinx::escape_match($value);
            }
        }

        return Sphinx::escape(implode(' ', $set));
    }


    /**
     * Compiles an array of conditions into an SQL partial. Used for WHERE
     * and HAVING.
     *
     * @param array $conditions condition statements
     * @return  string
     */
    protected function _compile_conditions(array $conditions): string
    {
        $last_condition = NULL;

        $sql = '';
        foreach ($conditions as $group) {
            // Process groups of conditions
            foreach ($group as $logic => $condition) {
                if ($condition === '(') {
                    if (!empty($sql) && $last_condition !== '(') {
                        // Include logic operator
                        $sql .= " $logic ";
                    }

                    $sql .= '(';
                } elseif ($condition === ')') {
                    $sql .= ')';
                } else {
                    if (!empty($sql) && $last_condition !== '(') {
                        // Add the logic operator
                        $sql .= " $logic ";
                    }

                    [$column, $op, $value] = $condition;

                    if ($value === null) {
                        if ($op === '=') {
                            // Convert "val = NULL" to "val IS NULL"
                            $op = 'IS ';
                        } elseif ($op === '!=') {
                            // Convert "val != NULL" to "val IS NOT NULL"
                            $op = 'IS NOT ';
                        }
                    }

                    if ($op === 'BETWEEN' && \is_array($value)) {
                        // BETWEEN always has exactly two arguments
                        list($min, $max) = $value;

                        if (\is_string($min)) {
                            $min = Sphinx::quote($min);
                        }

                        if (\is_string($max)) {
                            $max = Sphinx::quote($max);
                        }

                        // Quote the min and max value
                        $value = $min . ' AND ' . $max;
                    } elseif ($op === 'IN' && \is_array($value)) {
                        $value = '(' . implode(',', array_map([$this->db, 'quote'], $value)) . ')';

                    } elseif ($op === 'NOT IN' && \is_array($value)) {
                        $value = '(' . implode(',', array_map([$this->db, 'quote'], $value)) . ')';

                    } else {
                        $value = \is_int($value) ? $value : Sphinx::quote($value);
                    }

                    if ($column) {
                        if (\is_array($column)) {
                            // Use the column name
                            $column = Sphinx::quote_identifier(\reset($column));
                        } else {
                            // Apply proper quoting to the column
                            $column = Sphinx::quote_column($column);
                        }
                    }

                    // Append the statement to the query
                    $sql .= \trim($column . ' ' . $op . ' ' . $value);
                }

                $last_condition = $condition;
            }
        }

        return $sql;
    }


    /**
     * Compiles an array of ORDER BY statements into an SQL partial.
     *
     * @return  string
     */
    protected function _compile_order_by()
    {
        $sort = [];
        foreach ($this->_order_by as [$column, $direction]) {
            $sort[] = Sphinx::quote_column($column) . ' ' . $direction;
        }

        return 'ORDER BY ' . \implode(', ', $sort);
    }


    /**
     * Compile the SQL query and return it. Replaces any parameters with their
     * given values.
     *
     * @param mixed $db Database instance or name of instance
     * @return  string
     */
    public function compile(): string
    {
        // Compile the SQL query
        switch ($this->_type) {
            case Sphinx::SELECT:
                $sql = $this->compile_select();
                break;
            case Sphinx::INSERT:
            case Sphinx::REPLACE:
                $sql = $this->compile_insert();
                break;
            case Sphinx::UPDATE:
                $sql = $this->compile_update();
                break;
            case Sphinx::DELETE:
                $sql = $this->compile_delete();
                break;
        }

        return $sql;
    }


    /**
     * Execute the current query on the given database.
     *
     * @param mixed $db Database instance or name of instance
     * @param mixed   result object classname or null for array
     * @param array    result object constructor arguments
     * @return  mixed    the insert id for INSERT queries
     * @return  integer  number of affected rows for all other queries
     */
    public function execute()
    {
        // Compile the SQL query
        switch ($this->_type) {
            case Sphinx::SELECT:
            case Sphinx::MULTI_SELECT:
                $sql = $this->compile_select();
                break;
            case Sphinx::INSERT:
            case Sphinx::REPLACE:
                $sql = $this->compile_insert();
                break;
            case Sphinx::UPDATE:
                $sql = $this->compile_update();
                break;
            case Sphinx::DELETE:
                $sql = $this->compile_delete();
                break;
        }

        // Execute the query
        return $this->db->query($this->_type, $sql);
    }


    /**
     * Set the table and columns for an insert.
     *
     * @param mixed $index index name or array($index, $alias) or object
     * @param array $insert_data "column name" => "value" assoc list
     * @return  $this
     */
    public function insert($index = NULL, array $insert_data = NULL) : self
    {
        $this->_type = Sphinx::INSERT;

        if ($index) {
            $this->_index = $index;
        }

        if ($insert_data) {
            $group = [];
            foreach ($insert_data as $key => $value) {
                $this->_columns[] = $key;
                $group[] = $value;
            }
            $this->_values[] = $group;
        }

        return $this;
    }

    /**
     * @param null       $index
     * @param array|null $insert_data
     * @return $this
     */
    public function replace($index = NULL, array $insert_data = NULL) : self
    {
        $this->insert($index, $insert_data);
        $this->_type = Sphinx::REPLACE;
        return $this;
    }

    /**
     *
     * @param string $index idnex name
     * @return  QueryBuilder
     */
    public function update($index = NULL) : self
    {
        $this->_type = Sphinx::UPDATE;

        if ($index !== NULL) {
            $this->index($index);
        }

        return $this;
    }


    public function delete($index = NULL) : self
    {
        $this->_type = Sphinx::DELETE;

        if ($index !== NULL) {
            $this->index($index);
        }

        return $this;
    }

    public function option($option) : self
    {
        $this->_option[] = $option;

        return $this;
    }


    public function get()
    {
        return $this->execute();
    }


}

<?php declare(strict_types=1);

namespace mii\search\sphinx;

/**
 * Database expressions can be used to add unescaped SQL fragments to a
 * [Query] object.
 *
 */

class Expression
{

    // Unquoted parameters
    protected $_parameters;

    // Raw expression string
    protected $_value;

    /**
     * Sets the expression string.
     *
     *     $expression = new Expression('COUNT(users.id)');
     *
     * @param   string  $value      raw SQL expression string
     * @param   array   $parameters unquoted parameter values
     * @return  void
     */
    public function __construct($value, $parameters = [])
    {
        // Set the expression string
        $this->_value = $value;
        $this->_parameters = $parameters;
    }

    /**
     * Bind a variable to a parameter.
     *
     * @param   string  $param  parameter key to replace
     * @param   mixed   $var    variable to use
     * @return  $this
     */
    public function bind($param, &$var)
    {
        $this->_parameters[$param] =&$var;

        return $this;
    }

    /**
     * Set the value of a parameter.
     *
     * @param   string  $param  parameter key to replace
     * @param   mixed   $value  value to use
     * @return  $this
     */
    public function param($param, $value): self
    {
        $this->_parameters[$param] = $value;

        return $this;
    }

    /**
     * Add multiple parameter values.
     *
     * @param   array   $params list of parameter values
     * @return  $this
     */
    public function parameters(array $params): self
    {
        $this->_parameters = $params + $this->_parameters;

        return $this;
    }

    /**
     * Get the expression value as a string.
     *
     *     $sql = $expression->value();
     *
     * @return  string
     */
    public function value() : string
    {
        return (string) $this->_value;
    }

    /**
     * Return the value of the expression as a string.
     *
     *     echo $expression;
     *
     * @return  string
     * @uses    Database_Expression::value
     */
    public function __toString()
    {
        return $this->value();
    }

    /**
     * Compile the SQL expression and return it. Replaces any parameters with
     * their given values.
     *
     * @return  string
     */
    public function compile() : string
    {
        $value = $this->value();

        if (!empty($this->_parameters)) {
            // Quote all of the parameter values
            $params = \array_map([Sphinx::class, 'quote'], $this->_parameters);

            // Replace the values in the expression
            $value = \strtr($value, $params);
        }

        return $value;
    }
}

<?php declare(strict_types=1);

namespace mii\search\sphinx;

class SphinxQL
{
    /**
     * @param string $q
     * @return array
     */
    public static function select($q, array $params = [])
    {
        return static::query(Sphinx::SELECT, $q, $params);
    }

    /**
     * @param int
     * @param string $q
     * @param array  $params
     */
    public static function query($type, $q, array $params = [], Sphinx $db = null)
    {
        if ($db === null) {
            $db = \Mii::$app->sphinx;
        }

        if (!empty($params)) {
            // Quote all of the values
            $values = \array_map([Sphinx::class, 'quote'], $params);

            // Replace the values in the SQL
            $q = \strtr($q, $values);
        }

        return $db->query($type, $q);
    }

    /**
     * @param string $q
     * @return array
     */
    public static function update($q, array $params = [])
    {
        return static::query(Sphinx::UPDATE, $q, $params);
    }

    /**
     * @param string $q
     * @return int
     */
    public static function insert($q, array $params = [])
    {
        return static::query(Sphinx::INSERT, $q, $params);
    }

    /**
     * @param string $q
     * @return int
     */
    public static function delete($q, array $params = [])
    {
        return static::query(Sphinx::DELETE, $q, $params);
    }

    /**
     * @param string $value
     * @param array  $params
     * @return Expression
     */
    public static function expr($value, array $params = [])
    {
        return new Expression($value, $params);
    }


    public static function meta($like = null)
    {
        $sql = 'SHOW META';

        if ($like !== null) {
            $sql .= ' LIKE ' . $like;
        }

        $db_result = static::query(Sphinx::SELECT, $sql);

        $result = [];
        foreach ($db_result as $value) {
            $result[$value['Variable_name']] = $value['Value'];
        }
        return $result;
    }
}

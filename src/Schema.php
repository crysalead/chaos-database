<?php
namespace Chaos\Database;

use Lead\Set\Set;

class Schema extends \Chaos\ORM\Schema
{
    /**
     * Class dependencies.
     *
     * @var array
     */
    protected $_classes = [
        'relationship'   => 'Chaos\ORM\Relationship',
        'belongsTo'      => 'Chaos\ORM\Relationship\BelongsTo',
        'hasOne'         => 'Chaos\ORM\Relationship\HasOne',
        'hasMany'        => 'Chaos\ORM\Relationship\HasMany',
        'hasManyThrough' => 'Chaos\ORM\Relationship\HasManyThrough',
        'query'          => 'Chaos\Database\Query'
    ];

    /**
     * The connection instance.
     *
     * @var object
     */
    protected $_connection = null;


    /**
     * Configures the meta for use.
     *
     * @param array $config Possible options are:
     *                      - `'connection'`  _object_ : The connection instance (defaults to `null`).
     */
    public function __construct($config = [])
    {
        $defaults = [
            'connection' => null
        ];

        $config = Set::merge($defaults, $config);
        parent::__construct($config);

        $this->connection($config['connection']);

    }

    /**
     * Gets/sets the connection object to which this schema is bound.
     *
     * @return object    Returns a connection instance.
     * @throws Exception Throws a `DatabaseException` if a connection isn't set.
     */
    public function connection($connection = null)
    {
        if (func_num_args()) {
            $this->_connection = $connection;
            if ($this->_connection) {
                $this->_formatters = Set::merge($this->_formatters, $this->_connection->formatters());
            }
            return $this;
        }
        if (!$this->_connection) {
            throw new DatabaseException("Error, missing connection for this schema.");
        }
        return $this->_connection;
    }

    /**
     * Returns a query to retrieve data from the connected data source.
     *
     * @param  array  $options Query options.
     * @return object          An instance of `Query`.
     */
    public function query($options = [])
    {
        $options += [
            'connection' => $this->connection(),
            'model'  => $this->reference()
        ];
        $query = $this->_classes['query'];
        if (!$options['model']) {
            throw new DatabaseException("Missing model for this schema, can't create a query.");
        }
        return new $query($options);
    }

    /**
     * Creates the schema.
     *
     * @param  array   $options An array of options.
     * @return boolean
     * @throws DatabaseException If no connection is defined or the schema name is missing.
     */
    public function create($options = [])
    {
        $defaults = [
            'soft' => true
        ];
        $options += $defaults;

        if (!isset($this->_source)) {
            throw new DatabaseException("Missing table name for this schema.");
        }

        $query = $this->connection()->dialect()->statement('create table', ['schema' => $this]);
        $query->ifNotExists($options['soft'])
              ->table($this->_source)
              ->columns($this->columns(true))
              ->constraints($this->meta('constraints'))
              ->meta($this->meta('table'));

        return $this->connection()->query($query->toString());
    }

    /**
     * Bulk inserts
     *
     * @param  array   $inserts An array of entities to insert.
     * @param  Closure $filter  The filter handler for which extract entities values for the insertion.
     * @param  array   $options The save option.
     * @return boolean          Returns `true` if insert operations succeeded, `false` otherwise.
     */
    public function bulkInsert($inserts, $filter, $options = [])
    {
        if (!$inserts) {
            return true;
        }
        $success = true;
        foreach ($inserts as $entity) {
            $this->insert($filter($entity));
            $success = $success && $this->connection()->errorCode() === null;
            $id = $entity->id();
            $id = $id === null ? $this->lastInsertId() : $id;
            $entity->amend([$this->key() => $id], ['exists' => true]);
        }
        return $success;
    }

    /**
     * Bulk updates
     *
     * @param  array   $updates An array of entities to update.
     * @param  Closure $filter  The filter handler for which extract entities values to update.
     * @param  array   $options The save option.
     * @return boolean          Returns `true` if update operations succeeded, `false` otherwise.
     */
    public function bulkUpdate($updates, $filter, $options = [])
    {
        if (!$updates) {
            return true;
        }
        $success = true;
        foreach ($updates as $entity) {
            $id = $entity->id();
            if ($id === null) {
                throw new DatabaseException("Can't update an existing entity with a missing ID.");
            }
            $this->update($filter($entity), [$this->key() => $id]);
            $success = $success && $this->connection()->errorCode() === null;
            $entity->amend();
        }
        return $success;
    }

    /**
     * Inserts a records  with the given data.
     *
     * @param  mixed   $data       Typically an array of key/value pairs that specify the new data with which
     *                             the records will be updated. For SQL databases, this can optionally be
     *                             an SQL fragment representing the `SET` clause of an `UPDATE` query.
     * @param  array   $options    Any database-specific options to use when performing the operation.
     * @return boolean             Returns `true` if the update operation succeeded, otherwise `false`.
     */
    public function insert($data, $options = [])
    {
        $key = $this->key();
        if (!array_key_exists($key, $data)) {
            $connection = $this->connection();
            $data[$key] = $connection::enabled('default') ? [':plain' => 'default'] : null;
        }
        $insert = $this->connection()->dialect()->statement('insert', ['schema' => $this]);
        $insert->into($this->source())
               ->values($data);

        return $this->connection()->query($insert->toString());
    }

    /**
     * Updates multiple records with the given data, restricted by the given set of criteria (optional).
     *
     * @param  mixed $data       Typically an array of key/value pairs that specify the new data with which
     *                           the records will be updated. For SQL databases, this can optionally be
     *                           an SQL fragment representing the `SET` clause of an `UPDATE` query.
     * @param  mixed $conditions An array of key/value pairs representing the scope of the records
     *                           to be updated.
     * @param  array $options    Any database-specific options to use when performing the operation.
     * @return boolean           Returns `true` if the update operation succeeded, otherwise `false`.
     */
    public function update($data, $conditions = [], $options = [])
    {
        $update = $this->connection()->dialect()->statement('update', ['schema' => $this]);
        $update->table($this->source())
               ->where($conditions)
               ->values($data);

        return $this->connection()->query($update->toString());
    }

    /**
     * Removes multiple documents or records based on a given set of criteria. **WARNING**: If no
     * criteria are specified, or if the criteria (`$conditions`) is an empty value (i.e. an empty
     * array or `null`), all the data in the backend data source (i.e. table or collection) _will_
     * be deleted.
     *
     * @param mixed    $conditions An array of key/value pairs representing the scope of the records or
     *                             documents to be deleted.
     * @param array    $options    Any database-specific options to use when performing the operation. See
     *                             the `truncate()` method of the corresponding backend database for available
     *                             options.
     * @return boolean             Returns `true` if the remove operation succeeded, otherwise `false`.
     */
    public function remove($conditions = [], $options = [])
    {
        $delete = $this->connection()->dialect()->statement('delete');

        $delete->from($this->source())
               ->where($conditions);

        return $this->connection()->query($delete->toString());
    }

    /**
     * Truncate a table.
     *
     * @return boolean Returns `true` if the remove operation succeeded, otherwise `false`.
     */
    public function truncate($conditions = [], $options = [])
    {
        $truncate = $this->connection()->dialect()->statement('truncate');

        $truncate->table($this->source());

        return $this->connection()->query($truncate->toString());
    }

    /**
     * Drops the schema
     *
     * @param  array   $options An array of options.
     * @return boolean
     * @throws DatabaseException If no connection is defined or the schema name is missing.
     */
    public function drop($options = [])
    {
        $defaults = [
            'soft'     => true,
            'cascade'  => false,
            'restrict' => false
        ];
        $options += $defaults;

        if (!isset($this->_source)) {
            throw new DatabaseException("Missing table name for this schema.");
        }
        $query = $this->connection()->dialect()->statement('drop table');
        $query->ifExists($options['soft'])
              ->table($this->_source)
              ->cascade($options['cascade'])
              ->restrict($options['restrict']);

        return $this->connection()->query($query->toString());
    }

    /**
     * Returns the last insert id from the database.
     *
     * @return mixed Returns the last insert id.
     */
    public function lastInsertId()
    {
        $sequence = $this->source(). '_' . $this->key() . '_seq';
        return $this->connection()->lastInsertId($sequence);
    }

    /**
     * Returns the schema default values.
     *
     * @param  array $basePath The basePath to extract default values from.
     * @return mixed           Returns all default values .
     */
    public function defaults($basePath = null)
    {
        $defaults = [];
        foreach ($this->_columns as $key => $value) {
            if ($basePath) {
                $fieldName = strpos($key, $basePath . '.') === 0 ? substr($key, strlen($basePath) + 1) : null;
            } else {
                $fieldName = $key;
            }
            if (!$fieldName || $fieldName === '*' || strpos($fieldName, '.') !== false) {
                continue;
            }
            if (array_key_exists('default', $value)) {
                if (is_array($value['default'])) {
                    $operator = key($value['default']);
                    $connection = $this->_connection;
                    if (!$connection || !$connection->dialect()->isOperator($operator)) {
                        $defaults[$fieldName] = $value['default'];
                    }
                } else {
                    $defaults[$fieldName] = $value['default'];
                }
            }
        }
        if (!$basePath) {
            foreach($this->_relations as $name => $value) {
                $rel = $this->relation($name);
                if ($rel->type() !== 'belongsTo') {
                    continue;
                }
                if (!empty($value['null']) && empty($value['array'])) {
                    $defaults[$rel->keys('from')] = null;
                }
            }
        }
        return $defaults;
    }

    /**
     * Formats a value according to its type.
     *
     * @param  string $mode    The format mode (i.e. `'cast'` or `'datasource'`).
     * @param  string $type    The field name.
     * @param  mixed  $value   The value to format.
     * @param  array  $column  The column options to pass the the formatter handler.
     * @param  array  $options The options to pass the the formatter handler (for `'cast'` mode only).
     * @return mixed           The formated value.
     */
    public function convert($mode, $type, $value, $column = [], $options = [])
    {
        $formatter = null;
        $type = $value === null ? 'null' : $type;
        if (is_array($value)) {
            $operator = key($value);
            $connection = $this->_connection;
            if ($connection && $connection->dialect()->isOperator($operator)) {
               return $connection->dialect()->format($operator, $value[$operator]);
            }
        }
        if (isset($this->_formatters[$mode][$type])) {
            $formatter = $this->_formatters[$mode][$type];
        } elseif (isset($this->_formatters[$mode]['_default_'])) {
            $formatter = $this->_formatters[$mode]['_default_'];
        }
        return $formatter ? $formatter($value, $column, $options) : $value;
    }
}

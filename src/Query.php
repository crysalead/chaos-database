<?php
namespace Chaos\Database;

use PDO;
use IteratorAggregate;
use Lead\Set\Set;
use Chaos\Database\DatabaseException;

/**
 * The Query wrapper.
 */
class Query implements IteratorAggregate
{
    /**
     * Class dependencies.
     *
     * @var array
     */
    protected $_classes = [
        'collector' => 'Chaos\Collector'
    ];

    /**
     * The connection to the datasource.
     *
     * @var object
     */
    protected $_connection = null;

    /**
     * The fully namespaced model class name on which this query is starting.
     *
     * @var string
     */
    protected $_model = null;

    /**
     * A finders instance.
     *
     * @var string
     */
    protected $_finders = null;

    /**
     * The finder statement instance.
     *
     * @var string
     */
    protected $_statement = null;

    /**
     * Count the number of identical aliases in a query for building unique aliases.
     *
     * @var array
     */
    protected $_aliasCounter = [];

    /**
     * Map beetween relation pathsand corresponding aliases.
     *
     * @var array
     */
    protected $_aliases = [];

    /**
     * Map beetween generated aliases and corresponding schema.
     *
     * @var array
     */
    protected $_schemas = [];

    /**
     * The relations to include.
     *
     * @var array
     */
    protected $_embed = [];

    /**
     * Some conditions over some relations.
     *
     * @var array
     */
    protected $_has = [];

    /**
     * Pagination
     *
     * @var array
     */
    protected $_page = [];

    /**
     * Creates a new record object with default values.
     *
     * @param array $config Possible options are:
     *                      - `'type'`       _string_ : The type of query.
     *                      - `'connection'` _object_ : The connection instance.
     *                      - `'model'`      _string_ : The model class.
     */
    public function __construct($config = [])
    {
        $defaults = [
            'connection' => null,
            'model'      => null,
            'finders'    => null,
            'query'      => []
        ];
        $config = Set::merge($defaults, $config);
        $model = $this->_model = $config['model'];
        $this->_finders = $config['finders'];
        $this->_connection = $config['connection'];

        $this->_statement = $this->connection()->dialect()->statement('select');
        if ($model) {
            $schema = $model::schema();
            $source = $schema->source();
            $this->statement()->from([$source => $this->alias('', $schema)]);
        }
        foreach ($config['query'] as $key => $value) {
            $this->{$key}($value);
        }
    }

    /**
     * When not supported, delegates the call to the finders instance.
     *
     * @param  string $name   The name of the finder to execute.
     * @param  array  $params The parameters to pass to the finder.
     * @return object         Returns `$this`.
     */
    public function __call($name, $params = [])
    {
        if (!$this->_finders) {
            throw new DatabaseException("No finders instance has been defined.");
        }
        array_unshift($params, $this);
        call_user_func_array([$this->_finders, $name], $params);
        return $this;
    }

    /**
     * Gets the connection object to which this query is bound.
     *
     * @return object    Returns a connection instance.
     * @throws Exception Throws a `DatabaseException` if a connection isn't set.
     */
    public function connection()
    {
        if (!$this->_connection) {
            throw new DatabaseException("Error, missing connection for this query.");
        }
        return $this->_connection;
    }

    /**
     * Gets the model.
     *
     * @return object Returns the fully namespaced model class name.
     */
    public function model()
    {
        return $this->_model;
    }

    /**
     * Gets the query statement.
     *
     * @return object Returns a connection instance.
     */
    public function statement()
    {
        return $this->_statement;
    }

    /**
     * Executes the query and returns the result (must implements the `Iterator` interface).
     *
     * (Automagically called on `foreach`)
     *
     * @return object An iterator instance.
     */
    public function getIterator()
    {
        return $this->get();
    }

    /**
     * Executes the query and returns the result.
     *
     * @param  array  $options The fetching options.
     * @return object          An iterator instance.
     */
    public function get($options = [])
    {
        $defaults = [
            'collector' => null,
            'return'    => 'entity',
            'fetch'     => PDO::FETCH_ASSOC
        ];
        $options += $defaults;

        $class = $this->_classes['collector'];
        $collector = $options['collector'] = $options['collector'] ?: new $class();

        $this->_applyHas();
        $this->_applyLimit();

        if ($noFields = !$this->statement()->data('fields')) {
            $this->statement()->fields([$this->alias() => ['*']]);
        }

        $collection = [];
        $return = $options['return'];

        $cursor = $this->connection()->query($this->statement()->toString(), [], [
            'fetch' => $return === 'object' ? PDO::FETCH_OBJ : $options['fetch']
        ]);

        $model = $this->model();

        switch ($return) {
            case 'entity':
                $schema = $model::schema();
                $source = $schema->source();
                $key = $schema->key();
                $collection = $model::create($collection, ['collector' => $collector, 'type' => 'set']);
                foreach ($cursor as $record) {
                    if (!empty($record[$key]) && $collector->exists($source, $record[$key])) {
                        $collection[] = $collector->get($source, $record[$key]);
                    } else {
                        $collection[] = $model::create($record, [
                            'collector' => $collector,
                            'exists' => $noFields ? true : null,
                            'autoreload' => false
                        ]);
                    }
                }
                break;
            case 'array':
            case 'object':
                foreach ($cursor as $record) {
                    $collection[] = $record;
                }
                break;
            default:
                throw new DatabaseException("Invalid `'{$options['return']}'` mode as `'return'` value");
                break;
        }

        $model::schema()->embed($collection, $this->_embed, ['fetchOptions' => $options]);
        return $collection;
    }

    /**
     * Alias for `get()`
     *
     * @return object An iterator instance.
     */
    public function all($options = [])
    {
        return $this->get($options);
    }

    /**
     * Executes the query and returns the first result only.
     *
     * @return object An entity instance.
     */
    public function first($options = [])
    {
        $result = $this->get($options);
        return is_object($result) ? $result->rewind() : $result;
    }

    /**
     * Executes the query and returns the count number.
     *
     * @return integer The number of rows in result.
     */
    public function count()
    {
        $model = $this->model();
        $schema = $model::schema();
        $this->statement()->fields([':plain' => 'COUNT(*)']);
        $cursor = $this->connection()->query($this->statement()->toString());
        $result = $cursor->current();
        return (int) current($result);
    }

    /**
     * Adds some fields to the query
     *
     * @param  string|array $fields The fields.
     * @return string               Formatted fields list.
     */
    public function fields($fields)
    {
        $fields = is_array($fields) && func_num_args() === 1 ? $fields : func_get_args();

        $model = $this->model();
        $schema = $model::schema();

        foreach ($fields as $key => $value) {
            if (is_string($value) && is_numeric($key) && $schema->has($value)) {
                $this->statement()->fields([$this->alias() => [$value]]);
            } else {
                $this->statement()->fields([$key => $value]);
            }
        }
        return $this;
    }

    /**
     * Adds some where conditions to the query
     *
     * @param  string|array $conditions The conditions for this query.
     * @return object                   Returns `$this`.
     */
    public function where($conditions, $alias = null)
    {
        $conditions = $this->statement()->dialect()->prefix($conditions, $alias ?: $this->alias(), false);
        $this->statement()->where($conditions);
        return $this;
    }

    /**
     * Alias for `where()`.
     *
     * @param  string|array $conditions The conditions for this query.
     * @return object                   Returns `$this`.
     */
    public function conditions($conditions)
    {
        return $this->where($conditions);
    }

    /**
     * Adds some group by fields to the query
     *
     * @param  string|array $fields The fields.
     * @return object               Returns `$this`.
     */
    public function group($fields)
    {
        $fields = is_array($fields) ? $fields : func_get_args();
        $fields = $this->statement()->dialect()->prefix($fields, $this->alias());
        $this->statement()->group($fields);
        return $this;
    }

    /**
     * Adds some having conditions to the query
     *
     * @param  string|array $conditions The conditions for this query.
     * @return object                   Returns `$this`.
     */
    public function having($conditions)
    {
        $conditions = $this->statement()->dialect()->prefix($conditions, $this->alias());
        $this->statement()->having($conditions);
        return $this;
    }

    /**
     * Adds some order by fields to the query
     *
     * @param  string|array $fields The fields.
     * @return object               Returns `$this`.
     */
    public function order($fields)
    {
        $fields = is_array($fields) ? $fields : func_get_args();
        $fields = $this->statement()->dialect()->prefix($fields, $this->alias());
        $this->statement()->order($fields);
        return $this;
    }

    /**
     * Sets page number.
     *
     * @param  integer $page The page number
     * @return self
     */
    public function page($page)
    {
        $this->_page['page'] = $page;
        return $this;
    }

    /**
     * Sets offset value.
     *
     * @param  integer $offset The offset value.
     * @return self
     */
    public function offset($offset)
    {
        $this->_page['offset'] = $offset;
        return $this;
    }

    /**
     * Sets limit value.
     *
     * @param  integer $limit The number of results to limit or `0` for no limit at all.
     * @return self
     */
    public function limit($limit)
    {
        $this->_page['limit'] = (integer) $limit;
        return $this;
    }

    /**
     * Applies a query handler
     *
     * @param  Closure $closure A closure.
     * @return object           Returns `$this`.
     */
    public function handler($closure)
    {
        if ($closure && is_callable($closure)) {
            $closure($this);
        }
        return $this;
    }

    /**
     * Sets the relations to retrieve.
     *
     * @param  array  $embed The relations to load with the query.
     * @return object        Returns `$this`.
     */
    public function embed($embed = null, $conditions = [])
    {
        if (!$embed) {
            return $this->_embed;
        }
        if (!is_array($embed)) {
            $embed = [$embed => $conditions];
        }
        $embed = Set::normalize($embed);
        $this->_embed = Set::merge($this->_embed, $embed);
        return $this;
    }

    /**
     * Sets the conditionnal dependency over some relations.
     *
     * @param array The conditionnal dependency.
     */
    public function has($has = null, $conditions = [])
    {
        if (!$has) {
            return $this->_has;
        }
        if (!is_array($has)) {
            $has = [$has => $conditions];
        }
        $this->_has = array_merge($this->_has, $has);
        return $this;
    }

    /**
     * Gets a unique alias for the query or a query's relation if `$relpath` is set.
     *
     * @param  string $path   A dotted relation name or for identifying the query's relation.
     * @param  object $schema The corresponding schema to alias.
     * @return string         A string alias.
     */
    public function alias($path = '', $schema = null)
    {
        if (func_num_args() < 2) {
            if (isset($this->_aliases[$path])) {
                return $this->_aliases[$path];
            } else {
                throw new DatabaseException("No alias has been defined for `'{$path}'`.");
            }
        }

        $alias = $schema->source();
        if (!isset($this->_aliasCounter[$alias])) {
            $this->_aliasCounter[$alias] = 0;
            $this->_aliases[$path] = $alias;
        } else {
            $alias = $this->_aliases[$path] = $alias . '__' . $this->_aliasCounter[$alias]++;
        }
        $this->_schemas[$alias] = $schema;
        return $alias;
    }

    /**
     * Applies the has conditions.
     */
    protected function _applyHas()
    {
        $tree = Set::expand(array_fill_keys(array_keys($this->has()), false));
        $this->_applyJoins($this->model(), $tree, '', $this->alias());
        foreach ($this->has() as $path => $conditions) {
            $this->where($conditions, $this->alias($path));
        }
    }

    /**
     * Applies the limit range when applicable.
     */
    protected function _applyLimit()
    {
        if (empty($this->_page['limit'])) {
            return;
        }
        if (!empty($this->_page['offset'])) {
            $offset = $this->_page['offset'];
        } else {
            $page = !empty($this->_page['page']) ? $this->_page['page'] : 1;
            $offset = ($page - 1) * $this->_page['limit'];
        }
        $this->statement()->limit($this->_page['limit'], $offset);
    }

    /**
     * Applies joins.
     *
     * @param object $model     The model to perform joins on.
     * @param array  $tree      The tree of relations to join.
     * @param array  $basePath  The base relation path.
     * @param string $aliasFrom The alias name of the from model.
     */
    protected function _applyJoins($model, $tree, $basePath, $aliasFrom)
    {
        foreach ($tree as $name => $childs) {
            $rel = $model::relation($name);
            $path = $basePath ? $basePath . '.' . $name : $name;

            if ($rel->type() !== 'hasManyThrough') {
                $to = $this->_join($path, $rel, $aliasFrom);
            } else {
                $name = $rel->using();
                $nameThrough = $rel->through();
                $pathThrough = $path ? $path . '.' . $nameThrough : $nameThrough;
                $model = $rel->from();

                $relThrough = $model::relation($nameThrough);
                $aliasThrough = $this->_join($pathThrough, $relThrough, $aliasFrom);

                $modelThrough = $relThrough->to();
                $relTo = $modelThrough::relation($name);
                $to = $this->_join($path, $relTo, $aliasThrough);
            }

            if (!empty($childs)) {
                $this->_applyJoins($rel->to(), $childs, $path, $to);
            }
        }
    }

    /**
     * Set a query's join according a Relationship.
     *
     * @param  string $path      The relation path.
     * @param  object $rel       A Relationship instance.
     * @param  string $fromAlias The "from" model alias.
     * @return string            The "to" model alias.
     */
    protected function _join($path, $rel, $fromAlias)
    {
        if (isset($this->_aliases[$path])) {
            return $this->_aliases[$path];
        }

        $model = $rel->to();
        $schema = $model::schema();
        $source = $schema->source();
        $toAlias = $this->alias($path, $schema);

        $this->statement()->join(
            [$source => $toAlias],
            $this->_on($rel, $fromAlias, $toAlias),
            'LEFT'
        );
        return $toAlias;
    }

    /**
     * Build the `ON` constraints from a `Relationship` instance.
     *
     * @param  object $rel       A Relationship instance.
     * @param  string $fromAlias The "from" model alias.
     * @param  string $toAlias   The "to" model alias.
     * @return array             A constraints array.
     */
    protected function _on($rel, $fromAlias, $toAlias)
    {
        if ($rel->type() === 'hasManyThrough') {
            return [];
        }
        $keys = $rel->keys();
        list($fromField, $toField) = each($keys);
        return ['=' => [[':name' =>"{$fromAlias}.{$fromField}"], [':name' => "{$toAlias}.{$toField}"]]];
    }
}

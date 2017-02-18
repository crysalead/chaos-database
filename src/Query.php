<?php
namespace Chaos\Database;

use PDO;
use Exception;
use ArrayIterator;
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
    protected $_classes = [];

    /**
     * The fully namespaced model class name on which this query is starting.
     *
     * @var string
     */
    protected $_model = null;

    /**
     * The schema instance.
     *
     * @var object
     */
    protected $_schema = null;

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
     * Map beetween relation paths and corresponding aliases.
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
     *                      - `'model'`   _string_ : The model class.
     *                      - `'schema'`  _object_ : Alternatively a schema instance can be provided instead of the model.
     *                      - `'finders'` _array_  : Handy finders.
     *                      - `'query'`   _array_  : The query.
     */
    public function __construct($config = [])
    {
        $defaults = [
            'model'   => null,
            'schema'  => null,
            'finders' => null,
            'query'   => []
        ];
        $config = Set::merge($defaults, $config);

        if ($config['model']) {
            $this->_model = $config['model'];
            $model = $this->_model;
            $this->_schema = $model::definition();
        } else {
            $this->_schema = $config['schema'];
        }

        $this->_finders = $config['finders'];

        $schema = $this->schema();
        $this->_statement = $schema->connection()->dialect()->statement('select');
        $source = $schema->source();
        $this->statement()->from([$source => $this->alias('', $schema)]);

        foreach ($config['query'] as $key => $value) {
            if (!method_exists($this, $key)) {
                throw new Exception("Invalid option `'" . $key . "'` as query option.");
            }
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
     * Gets the schema object to which this query is bound.
     *
     * @return object    Returns a schema instance.
     * @throws Exception Throws a `DatabaseException` if a schema isn't set.
     */
    public function schema()
    {
        if (!$this->_schema) {
            throw new DatabaseException("Error, missing schema for this query.");
        }
        return $this->_schema;
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
        $data = $this->get();
        return is_array($data) ? new ArrayIterator($data) : $data;
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
            'return'    => 'entity',
            'fetch'     => PDO::FETCH_ASSOC
        ];
        $options += $defaults;

        $this->_applyHas();
        $this->_applyLimit();

        $schema = $this->schema();
        $statement = $this->statement();

        if ($allFields = !$statement->data('fields')) {
            $statement->fields([$this->alias() => ['*']]);
        }

        if ($statement->data('joins')) {
            $this->group($schema->key());
        }

        $collection = [];
        $return = $options['return'];

        $cursor = $schema->connection()->query($statement->toString($this->_schemas, $this->_aliases), [], [
            'fetch' => $return === 'object' ? PDO::FETCH_OBJ : $options['fetch']
        ]);

        switch ($return) {
            case 'entity':
                $model = $this->model();
                if (!$model) {
                    throw new DatabaseException("Missing model for this query, set `'return'` to `'array'` to get row data.");
                }
                $collection = $model::create($collection, [
                    'type' => 'set',
                    'exists' => true
                ]);

                if ($this->statement()->data('limit')) {
                    $count = $this->count();
                    $collection->meta(['count' => $count]);
                }

                foreach ($cursor as $record) {
                    $collection[] = $model::create($record, [
                        'exists' => $allFields ? true : null
                    ]);
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
        $schema->embed($collection, $this->_embed, ['fetchOptions' => $options]);
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
        return is_object($result) ? $result->rewind() : reset($result);
    }

    /**
     * Executes the query and returns the count number.
     *
     * @return integer The number of rows in result.
     */
    public function count()
    {
        $connection = $this->schema()->connection();
        $this->_applyHas();

        $statement = $this->statement();
        $counter = $connection->dialect()->statement('select');

        $primaryKey = $statement->dialect()->name($this->alias() . '.' .  $this->schema()->key());
        $counter->fields([':plain' => 'COUNT(DISTINCT ' . $primaryKey . ')']);
        $counter->data('from', $statement->data('from'));
        $counter->data('joins', $statement->data('joins'));
        $counter->data('where', $statement->data('where'));
        $counter->data('group', $statement->data('group'));
        $counter->data('having', $statement->data('having'));

        $cursor = $connection->query($counter->toString($this->_schemas, $this->_aliases));
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

        $schema = $this->schema();

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
        if (!func_num_args()) {
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
        $this->_applyJoins($this->schema(), $tree, '', $this->alias());
        foreach ($this->has() as $path => $conditions) {
            $this->where($conditions, $this->alias($path));
        }
        $this->_has = [];
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
     * @param object $schema    The schema to perform joins on.
     * @param array  $tree      The tree of relations to join.
     * @param array  $basePath  The base relation path.
     * @param string $aliasFrom The alias name of the from model.
     */
    protected function _applyJoins($schema, $tree, $basePath, $aliasFrom)
    {
        foreach ($tree as $name => $childs) {
            $rel = $schema->relation($name);
            $path = $basePath ? $basePath . '.' . $name : $name;

            if ($rel->type() !== 'hasManyThrough') {
                $to = $this->_join($path, $rel, $aliasFrom);
            } else {
                $name = $rel->using();
                $nameThrough = $rel->through();
                $pathThrough = $path ? $path . '.' . $nameThrough : $nameThrough;
                $model = $rel->from();

                $relThrough = $model::definition()->relation($nameThrough);
                $aliasThrough = $this->_join($pathThrough, $relThrough, $aliasFrom);

                $modelThrough = $relThrough->to();
                $relTo = $modelThrough::definition()->relation($name);
                $to = $this->_join($path, $relTo, $aliasThrough);
            }

            if (!empty($childs)) {
                $model = $rel->to();
                $this->_applyJoins($model::definition(), $childs, $path, $to);
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
        $schema = $model::definition();
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

    /**
     * Return the SQL string.
     *
     * @return string
     */
    public function toString()
    {
        $save = $this->_statement;
        $this->_statement = clone $save;
        $this->_applyHas();
        $this->_applyLimit();

        if (!$this->statement()->data('fields')) {
            $this->statement()->fields([$this->alias() => ['*']]);
        }
        $sql = $this->statement()->toString($this->_schemas, $this->_aliases);
        $this->_statement = $save;
        return $sql;
    }
}

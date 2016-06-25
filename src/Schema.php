<?php
namespace Chaos\Database;

use Chaos\Database\DatabaseException;

class Schema extends \Chaos\Schema
{
    /**
     * Class dependencies.
     *
     * @var array
     */
    protected $_classes = [
        'relationship'   => 'Chaos\Relationship',
        'belongsTo'      => 'Chaos\Relationship\BelongsTo',
        'hasOne'         => 'Chaos\Relationship\HasOne',
        'hasMany'        => 'Chaos\Relationship\HasMany',
        'hasManyThrough' => 'Chaos\Relationship\HasManyThrough',
        'query'          => 'Chaos\Database\Query'
    ];

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
            'model' => $this->model()
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

        $query = $this->connection()->dialect()->statement('create table');
        $query->ifNotExists($options['soft'])
              ->table($this->_source)
              ->columns($this->columns())
              ->constraints($this->meta('constraints'))
              ->meta($this->meta('table'));

        return $this->connection()->query($query->toString());
    }

    /**
     * Inserts and/or updates an entity and its direct relationship data in the datasource.
     *
     * @param object   $entity  The entity instance to save.
     * @param array    $options Options:
     *                          - `'whitelist'` _array_  : An array of fields that are allowed to be saved to this record.
     *                          - `'locked'`    _boolean_: Lock data to the schema fields.
     *                          - `'embed'`     _array_  : List of relations to save.
     * @return boolean          Returns `true` on a successful save operation, `false` otherwise.
     */
    public function save($entity, $options = [])
    {
        $defaults = [
            'whitelist' => null,
            'locked' => $this->locked(),
            'embed' => $entity->schema()->relations()
        ];
        $options += $defaults;

        $options['validate'] = false;

        if ($options['embed'] === true) {
            $options['embed'] = $entity->hierarchy();
        }

        $options['embed'] = $this->treeify($options['embed']);

        if (!$this->_save($entity, 'belongsTo', $options)) {
            return false;
        }

        $hasRelations = ['hasMany', 'hasOne'];

        if (!$entity->modified()) {
            return $this->_save($entity, $hasRelations, $options);
        }

        if (($whitelist = $options['whitelist']) || $options['locked']) {
            $whitelist = $whitelist ?: $this->fields();
        }

        $exclude = array_diff($this->relations(false), $this->fields());
        $values = array_diff_key($entity->get(), array_fill_keys($exclude, true));

        if ($entity->exists() === false) {
            $success = $this->insert($values);
        } else {
            $id = $entity->id();
            if ($id === null) {
                throw new DatabaseException("Can't update an entity missing ID data.");
            }
            $success = $this->update($values, [$this->key() => $id]);
        }

        if ($entity->exists() === false) {
            $id = $entity->id() === null ? $this->lastInsertId() : null;
            $entity->sync($id, [], ['exists' => true]);
        }
        return $success && $this->_save($entity, $hasRelations, $options);
    }

    /**
     * Save relations helper.
     *
     * @param  object  $entity  The entity instance.
     * @param  array   $types   Type of relations to save.
     * @param  array   $options Options array.
     * @return boolean          Returns `true` on a successful save operation, `false` on failure.
     */
    protected function _save($entity, $types, $options = [])
    {
        $defaults = ['embed' => []];
        $options += $defaults;
        $types = (array) $types;

        $success = true;
        foreach ($types as $type) {
            foreach ($options['embed'] as $relName => $value) {
                if (!($rel = $this->relation($relName)) || $rel->type() !== $type) {
                    continue;
                }
                $success = $success && $rel->save($entity, ['embed' => $value] + $options);
            }
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
            $data[$key] = $connection::enabled('default') ? (object) 'default' : null;
        }
        $insert = $this->connection()->dialect()->statement('insert');
        $insert->into($this->source())
               ->values($data, [$this, 'type']);

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
        $update = $this->connection()->dialect()->statement('update');
        $update->table($this->source())
               ->where($conditions)
               ->values($data, [$this, 'type']);

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
     *                             the `delete()` method of the corresponding backend database for available
     *                             options.
     * @return boolean             Returns `true` if the remove operation succeeded, otherwise `false`.
     */
    public function delete($conditions = [], $options = [])
    {
        $delete = $this->connection()->dialect()->statement('delete');

        $delete->from($this->source())
               ->where($conditions);

        return $this->connection()->query($delete->toString());
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
}

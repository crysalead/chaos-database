<?php
namespace chaos\database\adapter;

use PDOException;
use set\Set;
use chaos\database\DatabaseException;

/**
 * PostgreSQL adapter
 *
 * Possible approach to load datas
 * select row_to_json(t)
 * from (
 *   select id, text from words
 * ) t
 *
 */
class PostgreSql extends \chaos\database\Database
{
    /**
     * Check for required PHP extension, or supported database feature.
     *
     * @param  string  $feature Test for support for a specific feature, i.e. `"transactions"`
     *                          or `"arrays"`.
     * @return boolean          Returns `true` if the particular feature (or if MySQL) support
     *                          is enabled, otherwise `false`.
     */
    public static function enabled($feature = null)
    {
        if (!$feature) {
            return extension_loaded('pdo_pgsql');
        }
        $features = [
            'arrays' => true,
            'transactions' => true,
            'booleans' => true
        ];
        return isset($features[$feature]) ? $features[$feature] : null;
    }

    /**
     * Constructs the PostgreSQL adapter and sets the default port to 5432.
     *
     * @param array $config Configuration options for this class. Available options
     *                      defined by this class:
     *                      - `'host'`    : _string_ The IP or machine name where PostgreSQL is running,
     *                                      followed by a colon, followed by a port number or socket.
     *                                      Defaults to `'localhost:5432'`.
     *                      - `'schema'`  : _string_ The name of the database schema to use. Defaults to 'public'
     *                      - `'timezone'`: _stirng_ The database timezone. Defaults to `null`.
     */
    public function __construct($config = [])
    {
        $defaults = [
            'host' => 'localhost:5432',
            'schema' => 'public',
            'timezone' => null,
            'classes' => [
                'dialect' => 'sql\dialect\PostgreSql'
            ],
            'handlers' => [
                'cast' => [
                    'boolean' => function($value, $options = []) {
                        return $value === 't';
                    }
                ],
                'datasource' => [
                    'boolean' => function($value, $options = []) {
                        return $value ? 'true' : 'false';
                    },
                    'array' => function($data) {
                        $data = (array) $data;
                        $result = [];
                        foreach ($data as $value) {
                            if (is_array($value)) {
                                $result[] = $this->_handlers['datasource']['array']($value);
                            } else {
                                $value = str_replace('"', '\\"', $value);
                                if (!is_numeric($value)) {
                                    $value = '"' . $value . '"';
                                }
                                $result[] = $value;
                            }
                        }
                        return '{' . join(",", $result) . '}';
                    }
                ]
            ]
        ];

        $config = Set::merge($defaults, $config);
        parent::__construct($config + $defaults);
        $this->formatter('datasource', 'array', $this->_handlers['datasource']['array']);
    }

    /**
     * Connects to the database using the options provided to the class constructor.
     *
     * @return boolean Returns `true` if a database connection could be established,
     *                 otherwise `false`.
     */
    public function connect()
    {
        if (!$this->_config['database']) {
            throw new DatabaseException('Error, no database name has been configured.');
        }

        if (!$this->_config['dsn']) {
            $host = $this->_config['host'];
            list($host, $port) = explode(':', $host) + [1 => "5432"];
            $dsn = "pgsql:host=%s;port=%s;dbname=%s";
            $this->_config['dsn'] = sprintf($dsn, $host, $port, $this->_config['database']);
        }

        if (!parent::connect()) {
            return false;
        }

        if ($this->_config['schema']) {
            $this->searchPath($this->_config['schema']);
        }

        if ($this->_config['timezone']) {
            $this->timezone($this->_config['timezone']);
        }
        return true;
    }

    /**
     * Returns the list of tables in the currently-connected database.
     *
     * @return array Returns an array of sources.
     */
    public function sources() {
        $select = $this->dialect()->statement('select');
        $select->fields('table_name')
            ->from(['information_schema' => ['tables']])
            ->where([
                'table_type'   => 'BASE TABLE',
                'table_schema' => $this->_config['schema']
            ]);
        return $this->_sources($select);
    }

    /**
     * Gets the column schema for a given PostgreSQL table.
     *
     * @param  mixed  $name   Specifies the table name for which the schema should be returned.
     * @param  array  $fields Any schema data pre-defined by the model.
     * @param  array  $meta
     * @return object         Returns a shema definition.
     */
    public function describe($name,  $fields = [], $meta = []) {
        $class = $this->_classes['schema'];

        $schema = new $class([
            'connection' => $this,
            'source'     => $name,
            'meta'       => $meta
        ]);

        if (func_num_args() === 1) {

            $select = $this->dialect()->statement('select');
            $select->fields([
                'column_name' => 'name',
                'data_type'   => 'use',
                'is_nullable' => 'null',
                'column_default' => 'default',
                'character_maximum_length' => 'length',
                'numeric_precision' => 'numeric_length',
                'numeric_scale' => 'precision',
                'datetime_precision' => 'date_length'
            ])
            ->from(['information_schema' => ['columns']])
            ->where([
               'table_name'   => $name,
               'table_schema' => $this->_config['schema']
            ]);

            $columns = $this->query($select->toString());

            foreach ($columns as $row) {
                $name = $row['name'];
                $use = $row['use'];
                $field = $this->_column($row['use']);
                $default = $row['default'];

                if ($row['length']) {
                    $field['length'] = $row['length'];
                } else if ($row['date_length']) {
                    $field['length'] = $row['date_length'];
                } else if ($use === 'numeric' && $row['numeric_length']) {
                    $field['length'] = $row['numeric_length'];
                }
                if ($row['precision']) {
                    $field['precision'] = $row['precision'];
                }

                switch ($field['type']) {
                    case 'string':
                        if (preg_match("/^'(.*)'::/", $default, $match)) {
                            $default = $match[1];
                        }
                        break;
                    case 'boolean':
                        if ($default === 'true') {
                            $default = true;
                        }
                        if ($default === 'false') {
                            $default = false;
                        }
                        break;
                    case 'integer':
                        $default = is_numeric($default) ? $default : null;
                        break;
                    case 'datetime':
                        $default = $default !== 'now()' ? $default : null;
                        break;
                }

                $schema->set($name, $field + [
                    'null'     => ($row['null'] === 'YES' ? true : false),
                    'default'  => $default
                ]);
            }
        }
        return $schema;
    }

    /**
     * Converts database-layer column types to basic types.
     *
     * @param  string $use Real database-layer column type (i.e. `"varchar(255)"`)
     * @return array       Column type (i.e. "string") plus 'length' when appropriate.
     */
    protected function _column($use)
    {
        $column = ['use' => $use];
        $column['type'] = $this->dialect()->mapped($column);
        return $column;
    }

    /**
     * Gets or sets the search path for the connection
     *
     * @param  $searchPath
     * @return mixed       If setting the searchPath; returns ture on success, else false
     *                     When getting, returns the searchPath
     */
    public function searchPath($searchPath = null)
    {
        if (!func_num_args()) {
            $query = $this->driver()->query('SHOW search_path');
            $searchPath = $query->fetch();
            return explode(",", $searchPath['search_path']);
        }
        try{
            $this->driver()->exec("SET search_path TO ${searchPath}");
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }

    /**
     * Returns the last insert id from the database.
     *
     * @return mixed Returns the last insert id.
     */
    public function lastInsertId($sequence = null)
    {
        $id = $this->driver()->lastInsertId($sequence);
        return ($id && $id !== '0') ? $id : null;
    }

    /**
     * Gets or sets the time zone for the connection
     *
     * @param  $timezone
     * @return mixed     If setting the time zone; returns true on success, else false
     *                   When getting, returns the time zone
     */
    public function timezone($timezone = null)
    {
        if (empty($timezone)) {
            $query = $this->driver()->query('SHOW TIME ZONE');
            return $query->fetchColumn();
        }
        try {
            $this->driver()->exec("SET TIME ZONE '{$timezone}'");
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }

    /**
     * Gets or sets the encoding for the connection.
     *
     * @param  $encoding
     * @return mixed     If setting the encoding; returns true on success, else false.
     *                   When getting, returns the encoding.
     */
    public function encoding($encoding = null)
    {
        if (empty($encoding)) {
            $query = $this->driver()->query("SHOW client_encoding");
            $encoding = $query->fetchColumn();
            return strtolower($encoding);
        }

        try {
            $this->driver()->exec("SET NAMES '{$encoding}'");
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }
}

<?php
namespace Chaos\Database\Adapter;

use PDOException;
use Lead\Set\Set;
use Chaos\Database\DatabaseException;

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
class PostgreSql extends \Chaos\Database\Database
{
    /**
     * The protocol.
     *
     * @var string
     */
    protected $_protocol = 'pgsql';

    /**
     * Check for required PHP extension, or supported database feature.
     *
     * @param  string  $feature Test for support for a specific feature, i.e. `"transactions"`
     *                          or `"arrays"`.
     * @return mixed            Returns a boolean value indicating if a particular feature is supported or not.
     *                          Returns an array of all supported features when called with no parameter.
     */
    public static function enabled($feature = null)
    {
        if (!extension_loaded('pdo_pgsql')) {
            throw new DatabaseException("The PDO PostgreSQL extension is not installed.");
        }

        $features = [
            'arrays' => true,
            'transactions' => true,
            'savepoints' => true,
            'booleans' => true,
            'default' => true
        ];

        if (!func_num_args()) {
            return $features;
        }
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
                'dialect' => 'Lead\Sql\Dialect\Dialect\PostgreSql'
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
    public function sources()
    {
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
     * Extracts fields definitions of a table.
     *
     * @param  string $name The table name.
     * @return array        The fields definitions.
     */
    public function fields($name)
    {
        $fields = [];
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

        foreach ($columns as $column) {
            $default = $column['default'];
            $name = $column['name'];
            $field = $this->_field($column);

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
                case 'date':
                case 'datetime':
                    $default = null;
                    break;
            }

            $fields[$name] = $field + [
                'null'     => ($column['null'] === 'YES' ? true : false),
                'default'  => $default
            ];
        }
        return $fields;
    }

    /**
     * Converts database-layer column types to basic types.
     *
     * @param  string $use Real database-layer column type (i.e. `"varchar(255)"`)
     * @return array       Column type (i.e. "string") plus 'length' when appropriate.
     */
    protected function _field($column)
    {
        $use = $column['use'];
        $field = ['use' => $use];

        if ($column['length']) {
            $field['length'] = $column['length'];
        } else if ($column['date_length']) {
            $field['length'] = $column['date_length'];
        } else if ($use === 'numeric' && $column['numeric_length']) {
            $field['length'] = $column['numeric_length'];
        }
        if ($column['precision']) {
            $field['precision'] = $column['precision'];
        }

        $field['type'] = $this->dialect()->mapped($field);
        return $field;
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
            $query = $this->client()->query('SHOW search_path');
            $searchPath = $query->fetch();
            return explode(",", $searchPath['search_path']);
        }
        try{
            $this->client()->exec("SET search_path TO ${searchPath}");
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
        $id = $this->client()->lastInsertId($sequence);
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
            $query = $this->client()->query('SHOW TIME ZONE');
            return $query->fetchColumn();
        }
        try {
            $this->client()->exec("SET TIME ZONE '{$timezone}'");
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
            $query = $this->client()->query("SHOW client_encoding");
            $encoding = $query->fetchColumn();
            return strtolower($encoding);
        }

        try {
            $this->client()->exec("SET NAMES '{$encoding}'");
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }
}

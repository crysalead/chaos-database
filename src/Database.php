<?php
namespace chaos\database;

use PDO;
use PDOException;
use PDOStatement;
use DateTime;
use set\Set;
use chaos\database\DatabaseException;

/**
 * PDO driver adapter base class
 */
abstract class Database
{
    /**
     * Default entity and set classes used by subclasses of `Source`.
     *
     * @var array
     */
    protected $_classes = [];

    /**
     * Stores configuration information for object instances at time of construction.
     *
     * @var array
     */
    protected $_config = [];

    /**
     * Stores a connection to a remote resource.
     *
     * @var mixed
     */
    protected $_pdo = null;

    /**
     * Specific value denoting whether or not table aliases should be used in DELETE and UPDATE queries.
     *
     * @var boolean
     */
    protected $_alias = false;

    /**
     * The SQL dialect instance.
     *
     * @var object
     */
    protected $_dialect = null;

    /**
     * Type conversion definitions.
     *
     * @var array
     */
    protected $_handlers = [];

    /**
     * Import/export casting definitions.
     *
     * @var array
     */
    protected $_formatters = [];

    /**
     * ISO 8601 date format.
     */
    protected $_dateFormat = 'Y-m-d H:i:s';

    /**
     * Creates the database object and set default values for it.
     *
     * Options defined:
     *  - `'dns'`       : _string_ The full dsn connection url. Defaults to `null`.
     *  - `'database'`  : _string_ Name of the database to use. Defaults to `null`.
     *  - `'host'`      : _string_ Name/address of server to connect to. Defaults to 'localhost'.
     *  - `'username'`  : _string_ Username to use when connecting to server. Defaults to 'root'.
     *  - `'password'`  : _string_ Password to use when connecting to server. Defaults to `''`.
     *  - `'encoding'`  : _string_ The database character encoding.
     *  - `'persistent'`: _boolean_ If true a persistent connection will be attempted, provided the
     *                    adapter supports it. Defaults to `true`.
     *  - `'dialect'`       : _object_ A SQL dialect adapter
     *
     * @param  $config array Array of configuration options.
     * @return Database object.
     */
    public function __construct($config = [])
    {
        $defaults = [
            'classes' => [
                'cursor'  => 'chaos\database\Cursor',
                'schema'  => 'chaos\database\Schema',
                'dialect' => 'sql\Dialect'
            ],
            'pdo'        => null,
            'connect'    => true,
            'meta'       => ['key' => 'id', 'locked' => true],
            'persistent' => true,
            'host'       => 'localhost',
            'username'   => 'root',
            'password'   => '',
            'database'   => null,
            'encoding'   => null,
            'dsn'        => null,
            'options'    => [],
            'dialect'    => null,
            'handlers'   => []
        ];
        $config = Set::merge($defaults, $config);
        $this->_config = $config + $defaults;

        $this->_classes = $this->_config['classes'] + $this->_classes;
        $this->_pdo = $this->_config['pdo'];
        unset($this->_config['pdo']);

        $this->_dialect = $config['dialect'];
        unset($this->_config['dialect']);
        $this->_handlers = Set::merge($config['handlers'], $this->_handlers());

        if ($this->_dialect === null) {
            $dialect = $this->_classes['dialect'];
            $this->_dialect = new $dialect([
                'quoter' => function($string) {
                    return $this->quote($string);
                },
                'caster' => function($value, $states = []) {
                    if (!empty($states['name']) && !empty($states['schema'])) {
                        return $states['schema']->format('datasource', $states['name'], $value);
                    }
                    $type = isset($states['type']) ? $states['type'] : gettype($value);
                    if (is_array($type)) {
                        $type = call_user_func($type, $states['name']);
                    }
                    return $this->format('datasource', $type, $value);
                }
            ]);
        }

        if ($this->_config['connect']) {
            $this->connect();
        }

        $handlers = $this->_handlers;

        $this->formatter('cast', 'id',        $handlers['cast']['integer']);
        $this->formatter('cast', 'serial',    $handlers['cast']['integer']);
        $this->formatter('cast', 'integer',   $handlers['cast']['integer']);
        $this->formatter('cast', 'float',     $handlers['cast']['float']);
        $this->formatter('cast', 'decimal',   $handlers['cast']['decimal']);
        $this->formatter('cast', 'date',      $handlers['cast']['date']);
        $this->formatter('cast', 'datetime',  $handlers['cast']['date']);
        $this->formatter('cast', 'boolean',   $handlers['cast']['boolean']);
        $this->formatter('cast', 'null',      $handlers['cast']['null']);
        $this->formatter('cast', '_default_', $handlers['cast']['string']);

        $this->formatter('datasource', 'id',        $handlers['datasource']['string']);
        $this->formatter('datasource', 'serial',    $handlers['datasource']['string']);
        $this->formatter('datasource', 'integer',   $handlers['datasource']['string']);
        $this->formatter('datasource', 'float',     $handlers['datasource']['string']);
        $this->formatter('datasource', 'decimal',   $handlers['datasource']['string']);
        $this->formatter('datasource', 'date',      $handlers['datasource']['date']);
        $this->formatter('datasource', 'datetime',  $handlers['datasource']['date']);
        $this->formatter('datasource', 'boolean',   $handlers['datasource']['boolean']);
        $this->formatter('datasource', 'null',      $handlers['datasource']['null']);
        $this->formatter('datasource', '_default_', $handlers['datasource']['quote']);
    }

    /**
     * When not supported, delegate the call to the connection.
     *
     * @param string $name   The name of the matcher.
     * @param array  $options The parameters to pass to the matcher.
     */
    public function __call($name, $params = [])
    {
        return call_user_func_array([$this->_pdo, $name], $params);
    }

    /**
     * Return the source configuration.
     *
     * @return array.
     */
    public function config()
    {
        return $this->_config;
    }

    /**
     * Get database connection.
     *
     * @return object PDO
     */
    public function connect()
    {
        if ($this->_pdo) {
            return $this->_pdo;
        }
        $config = $this->_config;

        if (!$config['database']) {
            throw new PDOException('No Database configured');
        }
        if (!$config['dsn']) {
            throw new PDOException('No DSN setup for DB Connection');
        }
        $dsn = $config['dsn'];

        $options = $config['options'] + [
            PDO::ATTR_PERSISTENT => $config['persistent'],
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ];

        try {
            $this->_pdo = new PDO($dsn, $config['username'], $config['password'], $options);
        } catch (PDOException $e) {
            $this->_exception($e);
        }

        if ($config['encoding']) {
            $this->encoding($config['encoding']);
        }

        return $this->_pdo;
    }

    /**
     * Returns the SQL dialect instance.
     *
     * @return object.
     */
    public function dialect() {
        return $this->_dialect;
    }

    /**
     * Returns the pdo connection instance.
     *
     * @return mixed
     */
    public function driver() {
        return $this->_pdo;
    }

    /**
     * Checks the connection status of this data source.
     *
     * @return boolean Returns a boolean indicating whether or not the connection is currently active.
     *                 This value may not always be accurate, as the connection could have timed out or
     *                 otherwise been dropped by the remote resource during the course of the request.
     */
    public function connected() {
        return !!$this->_pdo;
    }

    /**
     * PDOException wrapper
     *
     * @param  PDOException $e A PDOException.
     * @throws DatabaseException
     */
    protected function _exception($e)
    {
        $config = $this->_config;
        $code = $e->getCode();
        $msg = $e->getMessage();
        switch (true) {
            case $code === 'HY000' || substr($code, 0, 2) === '08':
                $msg = "Unable to connect to host `{$config['host']}`.";
            break;
            case in_array($code, array('28000', '42000')):
                $msg = "Host connected, but could not access database `{$config['database']}`.";
            break;
        }
        throw new DatabaseException($msg, $code, $e);
    }

    /**
     * Returns the list of tables in the currently-connected database.
     *
     * @return array Returns an array of sources to which models can connect.
     */
    protected function _sources($sql)
    {
        $result = $this->query($sql->toString());

        $sources = [];
        foreach($result as $source) {
            $name = reset($source);
            $sources[$name] = $name;
        }
        return $sources;
    }

    /**
     * Return default cast handlers
     *
     * @return array
     */
    protected function _handlers()
    {
        return [
            'cast' => [
                'string' => function($value, $options = []) {
                    return (string) $value;
                },
                'integer' => function($value, $options = []) {
                    return (integer) $value;
                },
                'float'   => function($value, $options = []) {
                    return (float) $value;
                },
                'decimal' => function($value, $options = []) {
                    $options += ['precision' => 2];
                    return number_format($number, $options['precision']);
                },
                'boolean' => function($value, $options = []) {
                    return !!$value;
                },
                'date'    => function($value, $options = []) {
                    if (is_numeric($value)) {
                        return new DateTime('@' . $value);
                    }
                    if ($value instanceof DateTime) {
                        return $value;
                    }
                    return DateTime::createFromFormat(date($this->_dateFormat, strtotime($value)), $value);
                },
                'null'    => function($value, $options = []) {
                    return null;
                }
            ],
            'datasource' => [
                'string' => function($value, $options = []) {
                    return (string) $value;
                },
                'quote' => function($value, $options = []) {
                    return $this->dialect()->quote((string) $value);
                },
                'date' => function($value, $options = []) {
                    if ($value instanceof DateTime) {
                        $date = $value->format($this->_dateFormat);
                    } else {
                        $date = date($this->_dateFormat, is_numeric($value) ? $value : strtotime($value));
                    }
                    return $this->dialect()->quote((string) $date);
                },
                'boolean' => function($value, $options = []) {
                    return $value ? 'TRUE' : 'FALSE';
                },
                'null'    => function($value, $options = []) {
                    return 'NULL';
                }
            ]
        ];
    }

    /**
     * Gets/sets a formatter handler.
     *
     * @param  string   $type          The type name.
     * @param  callable $importHandler The callable import handler.
     * @param  callable $exportHandler The callable export handler. If not set use `$importHandler`.
     */
    public function formatter($mode, $type, $handler = null)
    {
        if (func_num_args() === 2) {
            return isset($this->_formatters[$mode][$type]) ? $this->_formatters[$mode][$type] : $this->_formatters[$mode]['_default_'];
        }
        $this->_formatters[$mode][$type] = $handler;
        return $this;
    }

    /**
     * Gets/sets all formatters.
     *
     */
    public function formatters($formatters = null)
    {
        if (!func_num_args()) {
            return $this->_formatters;
        }
        $this->_formatters = $formatters;
        return $this;
    }

    /**
     * Formats a value according to its definition.
     *
     * @param   string $mode  The format mode (i.e. `'cast'` or `'datasource'`).
     * @param   string $type  The type name.
     * @param   mixed  $value The value to format.
     * @return  mixed         The formated value.
     */
    public function format($mode, $type, $value, $options = [])
    {
        $type = $value === null ? 'null' : $type;

        $formatter = null;

        if (isset($this->_formatters[$mode][$type])) {
            $formatter = $this->_formatters[$mode][$type];
        } elseif (isset($this->_formatters[$mode]['_default_'])) {
            $formatter = $this->_formatters[$mode]['_default_'];
        }
        return $formatter ? $formatter($value, $options) : $value;
    }

    /**
     * Finds records using a SQL query.
     *
     * @param  string $sql  SQL query to execute.
     * @param  array  $data Array of bound parameters to use as values for query.
     * @return object       A `Cursor` instance.
     */
    public function query($sql, $data = [], $options = [])
    {
        $statement = $this->_pdo->prepare($sql);

        try {
            $error = !$statement->execute($data);
        } catch (Exception $e) {
            $error = true;
        }

        $err = $statement->errorInfo();
        $errmsg = $err[0] === '0000' ? '' : $err[0] . ($err[1] ? ' (' . $err[1] . ')' : '') . ':' . $err[2];

        $cursor = $this->_classes['cursor'];

        return new $cursor($options + [
            'resource' => $statement,
            'failed'   => $error,
            'errno'    => $err[0],
            'errmsg'   => $errmsg
        ]);
    }

    /**
     * Returns the last insert id from the database.
     *
     * @return mixed Returns the last insert id.
     */
    public function lastInsertId()
    {
        return $this->_pdo->lastInsertId();
    }

    /**
     * Retrieves database error message and error code.
     *
     * @return array
     */
    public function error()
    {
        if ($error = $this->_pdo->errorInfo()) {
            return [$error[1], $error[2]];
        }
    }

    /**
     * Disconnects the adapter from the database.
     *
     * @return boolean Returns `true` on success, else `false`.
     */
    public function disconnect()
    {
        $this->_pdo = null;
        return true;
    }

    /**
     * Getter/Setter for the connection's encoding
     * Abstract. Must be defined by child class.
     *
     * @param mixed $encoding
     * @return mixed.
     */
    abstract public function encoding($encoding = null);
}

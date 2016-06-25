<?php
namespace Chaos\Database;

use PDO;
use PDOException;
use PDOStatement;
use DateTime;
use Lead\Set\Set;
use Chaos\Source;
use Chaos\Database\DatabaseException;

/**
 * PDO driver adapter base class
 */
class Database extends Source
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
    protected $_client = null;

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
     * Constructor.
     *
     * @param  $config array Configuration options. Allowed options:
     *                       - `'classes'`   : _array_   Some classes dependencies.
     *                       - `'meta  '`    : _array_   Some meta data.
     *                       - `'client'`    : _object_  The PDO instance (optionnal).
     *                       - `'dialect'`   : _object_  A SQL dialect adapter
     *                       - `'dns'`       : _string_  The full dsn connection url. Defaults to `null`.
     *                       - `'host'`      : _string_  Name/address of server to connect to. Defaults to 'localhost'.
     *                       - `'database'`  : _string_  Name of the database to use. Defaults to `null`.
     *                       - `'username'`  : _string_  Username to use when connecting to server. Defaults to 'root'.
     *                       - `'password'`  : _string_  Password to use when connecting to server. Defaults to `''`.
     *                       - `'encoding'`  : _string_  The database character encoding.
     *                       - `'connect'`   : _boolean_ Autoconnect on construct if `true`. Defaults to `true`.
     *                       - `'persistent'`: _boolean_ If true a persistent connection will be attempted, provided the
     *                                         adapter supports it. Defaults to `true`.
     *                       - `'options'`   : _array_   Some PDO connection options to set.
     */
    public function __construct($config = [])
    {
        parent::__construct($config);
        $defaults = [
            'classes' => [
                'cursor'  => 'Chaos\Database\Cursor',
                'schema'  => 'Chaos\Database\Schema',
                'dialect' => 'Lead\Sql\Dialect'
            ],
            'meta'       => ['key' => 'id', 'locked' => true],
            'client'     => null,
            'dialect'    => null,
            'dsn'        => null,
            'host'       => 'localhost',
            'username'   => 'root',
            'password'   => '',
            'database'   => null,
            'encoding'   => null,
            'connect'    => true,
            'persistent' => true,
            'options'    => []
        ];
        $config = Set::merge($defaults, $config);
        $this->_config = $config;

        $this->_classes = $this->_config['classes'] + $this->_classes;
        $this->_client = $this->_config['client'];
        unset($this->_config['client']);

        $this->_dialect = $config['dialect'];
        unset($this->_config['dialect']);

        if ($this->_dialect === null) {
            $dialect = $this->_classes['dialect'];
            $this->_dialect = new $dialect([
                'quoter' => function($string) {
                    return $this->quote($string);
                },
                'caster' => function($value, $states = []) {
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

        $this->formatter('datasource', 'id',        $handlers['datasource']['string']);
        $this->formatter('datasource', 'serial',    $handlers['datasource']['string']);
        $this->formatter('datasource', 'integer',   $handlers['datasource']['string']);
        $this->formatter('datasource', 'float',     $handlers['datasource']['string']);
        $this->formatter('datasource', 'decimal',   $handlers['datasource']['string']);
        $this->formatter('datasource', 'date',      $handlers['datasource']['date']);
        $this->formatter('datasource', 'datetime',  $handlers['datasource']['datetime']);
        $this->formatter('datasource', 'boolean',   $handlers['datasource']['boolean']);
        $this->formatter('datasource', 'null',      $handlers['datasource']['null']);
        $this->formatter('datasource', 'string',    $handlers['datasource']['quote']);
        $this->formatter('datasource', 'object',    $handlers['datasource']['object']);
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
        return call_user_func_array([$this->_client, $name], $params);
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
        if ($this->_client) {
            return $this->_client;
        }
        $config = $this->_config;

        if (!$config['dsn']) {
            throw new DatabaseException('Error, no DSN setup has been configured for database connection.');
        }
        $dsn = $config['dsn'];

        $options = $config['options'] + [
            PDO::ATTR_PERSISTENT => $config['persistent'],
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ];

        try {
            $this->_client = new PDO($dsn, $config['username'], $config['password'], $options);
        } catch (PDOException $e) {
            $this->_exception($e);
        }

        if ($config['encoding']) {
            $this->encoding($config['encoding']);
        }

        return $this->_client;
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
    public function client() {
        return $this->_client;
    }

    /**
     * Checks the connection status of this data source.
     *
     * @return boolean Returns a boolean indicating whether or not the connection is currently active.
     *                 This value may not always be accurate, as the connection could have timed out or
     *                 otherwise been dropped by the remote resource during the course of the request.
     */
    public function connected() {
        return !!$this->_client;
    }

    /**
     * Gets the column schema for a given table.
     *
     * @param  mixed  $name    Specifies the table name for which the schema should be returned.
     * @param  array  $columns Any schema data pre-defined by the model.
     * @param  array  $meta
     * @return object         Returns a shema definition.
     */
    public function describe($name,  $columns = [], $meta = [])
    {
        if (func_num_args() === 1) {
            $columns = $this->fields($name);
        }

        $schema = $this->_classes['schema'];
        return new $schema([
            'connection' => $this,
            'source'     => $name,
            'columns'    => $columns,
            'meta'       => $meta
        ]);
    }

    /**
     * PDOException wrapper
     *
     * @param  PDOException $e   A PDOException.
     * @param  string       $sql An optionnal SQL query.
     * @throws DatabaseException
     */
    protected function _exception($e, $sql = null)
    {
        $config = $this->_config;
        $code = $e->getCode();
        $msg = $e->getMessage();
        $exception = new DatabaseException("{$msg}" . ($sql ? " in {$sql}" : ''), (int) $code);
        throw $exception;
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
                    $options += ['precision' => 2, 'decimal' => '.', 'separator' => ''];
                    return number_format($value, $options['precision'], $options['decimal'], $options['separator']);
                },
                'boolean' => function($value, $options = []) {
                    return !!$value;
                },
                'date'    => function($value, $options = []) {
                    return $this->format('cast', 'datetime', $value, ['format' => 'Y-m-d']);
                },
                'datetime'    => function($value, $options = []) {
                    $options += ['format' => 'Y-m-d H:i:s'];
                    if (is_numeric($value)) {
                        return new DateTime('@' . $value);
                    }
                    if ($value instanceof DateTime) {
                        return $value;
                    }
                    return DateTime::createFromFormat($options['format'], date($options['format'], strtotime($value)));
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
                    return $this->format('datasource', 'datetime', $value, ['format' => 'Y-m-d']);
                },
                'datetime' => function($value, $options = []) {
                    $options += ['format' => 'Y-m-d H:i:s'];
                    if ($value instanceof DateTime) {
                        $date = $value->format($options['format']);
                    } else {
                        $date = date($options['format'], is_numeric($value) ? $value : strtotime($value));
                    }
                    return $this->dialect()->quote((string) $date);
                },
                'boolean' => function($value, $options = []) {
                    return $value ? 'TRUE' : 'FALSE';
                },
                'null'    => function($value, $options = []) {
                    return 'NULL';
                },
                'object'  => function($value, $options = []) {
                    if (isset($value->scalar)) {
                        return $value->scalar;
                    }
                    return (string) $value;
                }
            ]
        ];
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
        $type = ($mode === 'datasource' && $value === null) ? 'null' : $type;
        return parent::format($mode, $type, $value, $options);
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
        $defaults = ['exception' => true];
        $options += $defaults;
        $statement = null;

        try {
            $statement = $this->client()->prepare($sql);
            if ($statement->execute($data)) {
                $cursor = $this->_classes['cursor'];
                return new $cursor($options + ['resource' => $statement]);
            }
        } catch (PDOException $e) {
            $this->_exception($e, $sql);
        }
    }

    /**
     * Returns the last insert id from the database.
     *
     * @return mixed Returns the last insert id.
     */
    public function lastInsertId()
    {
        return $this->client()->lastInsertId();
    }

    /**
     * Retrieves database error message and error code.
     *
     * @return array
     */
    public function errmsg()
    {
        $err = $this->client()->errorInfo();
        if (!isset($err[0]) || !(int) $err[0]) {
            return '';
        }
        return $err[0] . ($err[1] ? ' (' . $err[1] . ')' : '') . ':' . $err[2];
    }

    /**
     * Disconnects the adapter from the database.
     *
     * @return boolean Returns `true` on success, else `false`.
     */
    public function disconnect()
    {
        $this->_client = null;
        return true;
    }

    /**
     * Getter/Setter for the connection's encoding
     * Abstract. Must be defined by child class.
     *
     * @param mixed $encoding
     * @return mixed.
     */
    public function encoding($encoding = null)
    {
        throw new DatabaseException('Encoding is not supported by this driver.');
    }
}

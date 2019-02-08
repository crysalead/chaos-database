<?php
namespace Chaos\Database;

use Exception;
use Throwable;
use InvalidArgumentException;
use PDO;
use PDOException;
use PDOStatement;
use DateTime;
use Lead\Set\Set;
use Chaos\ORM\Source;
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
     * The transaction level
     *
     */
    protected $_transactionLevel = 0;

    /**
     * The transaction current level
     */
    protected $_currentLevel = 0;

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
     *                                         adapter supports it. Defaults to `false`.
     *                       - `'options'`   : _array_   Some PDO connection options to set.
     */
    public function __construct($config = [])
    {
        parent::__construct($config);
        $defaults = [
            'classes' => [
                'cursor'  => 'Chaos\Database\Cursor',
                'schema'  => 'Chaos\Database\Schema',
                'dialect' => 'Lead\Sql\Dialect\Dialect'
            ],
            'meta'       => ['key' => 'id', 'locked' => true],
            'client'     => null,
            'dialect'    => null,
            'dsn'        => null,
            'host'       => 'localhost',
            'socket'     => null,
            'username'   => 'root',
            'password'   => '',
            'database'   => null,
            'encoding'   => null,
            'connect'    => true,
            'persistent' => false,
            'options'    => []
        ];
        $config = Set::merge($defaults, $config);
        $this->_config = $config;

        $this->_classes = $this->_config['classes'] + $this->_classes;
        $this->_client = $this->_config['client'];
        unset($this->_config['client']);

        $this->dialect($config['dialect']);
        unset($this->_config['dialect']);

        if ($this->_dialect === null) {
            $this->_initDialect();
        }

        if ($this->_config['connect']) {
            $this->connect();
        }

        $handlers = $this->_handlers;

        $this->formatter('datasource', 'id',        $handlers['datasource']['string']);
        $this->formatter('datasource', 'serial',    $handlers['datasource']['string']);
        $this->formatter('datasource', 'integer',   $handlers['datasource']['string']);
        $this->formatter('datasource', 'float',     $handlers['datasource']['string']);
        $this->formatter('datasource', 'decimal',   $handlers['datasource']['decimal']);
        $this->formatter('datasource', 'date',      $handlers['datasource']['date']);
        $this->formatter('datasource', 'datetime',  $handlers['datasource']['datetime']);
        $this->formatter('datasource', 'boolean',   $handlers['datasource']['boolean']);
        $this->formatter('datasource', 'null',      $handlers['datasource']['null']);
        $this->formatter('datasource', 'string',    $handlers['datasource']['quote']);
        $this->formatter('datasource', 'json',      $handlers['datasource']['json']);
        $this->formatter('datasource', '_default_', $handlers['datasource']['quote']);

        $this->formatter('array', 'id',     $handlers['array']['integer']);
        $this->formatter('array', 'serial', $handlers['array']['integer']);
        $this->formatter('cast', 'id',      $handlers['cast']['integer']);
        $this->formatter('cast', 'serial',  $handlers['cast']['integer']);
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

        $dsn = $this->dsn();
        if (!$dsn) {
            throw new DatabaseException('Error, no DSN setup has been configured for database connection.');
        }

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
     * Return the DSN connection string.
     *
     * @return string
     */
    public function dsn() {
        if ($this->_config['dsn']) {
            return $this->_config['dsn'];
        }
        if (!$this->_config['database']) {
            throw new DatabaseException('Error, no database name has been configured.');
        }
        if ($this->_config['socket']) {
            return sprintf($this->_protocol . ":unix_socket=%s;dbname=%s", $this->_config['socket'], $this->_config['database']);
        }

        $host = $this->_config['host'];
        list($host, $port) = explode(':', $host) + [1 => "3306"];
        return sprintf($this->_protocol . ":host=%s;port=%s;dbname=%s", $host, $port, $this->_config['database']);
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
     * Returns the pdo connection instance.
     *
     * @return mixed
     */
    public function client() {
        return $this->_client;
    }

    /**
     * Returns the SQL dialect instance.
     *
     * @return object.
     */
    public function dialect($dialect = null) {
        if (func_num_args()) {
            $this->_dialect = $dialect;
            return $this;
        }
        return $this->_dialect;
    }

    /**
     * Initialize a new dialect instance.
     */
    protected function _initDialect()
    {
        $Dialect = $this->_classes['dialect'];
        $dialect = new $Dialect([
            'quoter' => function($string) {
                return $this->quote($string);
            },
            'caster' => function($value, $states = []) {
                if (!empty($states['schema'])) {
                    return $states['schema']->format('datasource', $states['name'], $value);
                }
                return $this->convert('datasource', gettype($value), $value);
            }
        ]);
        $this->_dialect = $dialect;
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
     * Execute a raw query.
     *
     * @param  string  $sql SQL query to execute.
     * @return boolean
     */
    public function execute($sql)
    {
        return $this->client()->exec($sql);
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

    /**
     * Start a new database transaction.
     *
     * @throws Exception
     */
    public function beginTransaction()
    {
        if ($this->_transactionLevel === 0) {
            $this->client()->beginTransaction();
        } elseif ($this->_transactionLevel > 0 && $this->_transactionLevel === $this->_currentLevel && static::enabled('savepoints')) {
            $name = 'TRANS' . ($this->_transactionLevel + 1);
            $this->execute("SAVEPOINT {$name}");
        }
        $this->_transactionLevel++;
        $this->_currentLevel = $this->_transactionLevel;
    }

    /**
     * Execute a Closure within a transaction.
     *
     * @param  Closure $transaction
     * @param  integer $maxRepeat
     * @return mixed
     *
     * @throws Throwable
     */
    public function transaction($transaction, $maxRepeat = 1)
    {
        for ($count = 1; $count <= $maxRepeat; $count++) {
            $this->beginTransaction();
            try {
                $transaction($this);
                $this->commit();
            } catch (Exception $e) {
                $this->_transactionException($e, $count, $maxRepeat);
            } catch (Throwable $e) {
                $this->rollback();
                throw $e;
            }
        }
    }

    /**
     * Get the number of active transactions.
     *
     * @return integer
     */
    public function transactionLevel()
    {
        return $this->_transactionLevel;
    }

    /**
     * Commit the active database transaction.
     */
    public function commit()
    {
        if ($this->_transactionLevel > 0) {
            $this->_transactionLevel--;
        }

        if ($this->_transactionLevel === 0) {
            $this->client()->commit();
            $this->_currentLevel = 0;
        }
    }

    /**
     * Rollback the active database transaction.
     *
     * @param integer|null $toLevel
     */
    public function rollback($toLevel = null)
    {
        $toLevel = !func_num_args() ? $this->_transactionLevel - 1 : $toLevel;

        if ($toLevel < 0 || $toLevel >= $this->_transactionLevel) {
            return;
        }

        if ($toLevel === 0) {
            $this->client()->rollback();
        } elseif (static::enabled('savepoints')) {
            $name = 'TRANS' . ($toLevel + 1);
            $this->execute("ROLLBACK TO SAVEPOINT {$name}");
        }

        $this->_transactionLevel = $toLevel;
        $this->_currentLevel = $this->_transactionLevel;
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
     * Formats a value according to its definition.
     *
     * @param  string $mode  The format mode (i.e. `'cast'` or `'datasource'`).
     * @param  string $type  The type name.
     * @param  mixed  $value The value to format.
     * @param  array  $column  The column options to pass the the formatter handler.
     * @param  array  $options The options to pass the the formatter handler (for `'cast'` mode only).
     * @return mixed         The formated value.
     */
    public function convert($mode, $type, $value, $column = [], $options = [])
    {
        if (is_array($value)) {
            $key = key($value);
            $dialect = $this->dialect();
            if ($dialect && $dialect->isOperator($key)) {
               return $dialect->format($key, $value[$key]);
            }
        }
        return parent::convert($mode, $type, $value, $column, $options);
    }

    /**
     * Return default cast handlers
     *
     * @return array
     */
    protected function _handlers()
    {
        $gmstrtotime = function ($value) {
            $TZ = date_default_timezone_get();
            if ($TZ === 'UTC') {
                return strtotime($value);
            }
            date_default_timezone_set('UTC');
            $time = strtotime($value);
            date_default_timezone_set($TZ);
            return $time;
        };

        return Set::extend(parent::_handlers(), [
            'datasource' => [
                'decimal' => function($value, $column) {
                    $column += ['precision' => 2, 'decimal' => '.', 'separator' => ''];
                    return number_format($value, $column['precision'], $column['decimal'], $column['separator']);
                },
                'quote' => function($value, $column) {
                    return $this->dialect()->quote((string) $value);
                },
                'date' => function($value, $column) {
                    return $this->convert('datasource', 'datetime', $value, ['format' => 'Y-m-d']);
                },
                'datetime' => function($value, $column) use ($gmstrtotime) {
                    $column += ['format' => 'Y-m-d H:i:s'];
                    if ($value instanceof DateTime) {
                        $date = $value->format($column['format']);
                    } else {
                        $timestamp = is_numeric($value) ? $value : $gmstrtotime($value);
                        if ($timestamp < 0 || $timestamp === false) {
                            throw new InvalidArgumentException("Invalid date `{$value}`, can't be parsed.");
                        }
                        $date = gmdate($column['format'], $timestamp);
                    }
                    return $this->dialect()->quote((string) $date);
                },
                'boolean' => function($value, $column) {
                    return $value ? 'TRUE' : 'FALSE';
                },
                'null'    => function($value, $column) {
                    return 'NULL';
                },
                'json'    => function($value, $column) {
                    if (is_object($value)) {
                        $value = $value->data();
                    }
                    return $this->dialect()->quote((string) json_encode($value));
                }
            ]
        ]);
    }

    /**
     * Retrieves database error code.
     *
     * @return array
     */
    public function errorCode()
    {
        $code = $this->client()->errorCode();
        if (!(int) $code) {
            return null;
        }
        return $code;
    }

    /**
     * Retrieves database error message and error code.
     *
     * @return array
     */
    public function errorMsg()
    {
        $err = $this->client()->errorInfo();
        if (!isset($err[0]) || !(int) $err[0]) {
            return '';
        }
        return $err[0] . ($err[1] ? ' (' . $err[1] . ')' : '') . ':' . $err[2];
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
     * Handle an exception encountered when running a transacted statement.
     *
     * @param  Exception $e
     * @param  integer   $count
     * @param  integer   $maxRepeat
     *
     * @throws Exception
     */
    protected function _transactionException($e, $count, $maxRepeat)
    {
        if (static::isDeadlockException($e)) {
            $this->_transactionLevel--;
            throw $e;
        }
        $this->rollback();

        if ($count >= $maxRepeat) {
            throw $e;
        }
    }

    /**
     * Check a lost connection exception.
     *
     * @param  Exception $e
     * @return boolean
     */
    public static function isLostConnectionException($e)
    {
        $message = strtolower($e->getMessage());
        foreach ([
            'no connection to the server',                 // PDO
            'server has gone away',                        // MySQL
            'lost connection',                             // MySQL
            'resource deadlock avoided',                   // MySQL
            'Transaction() on null',                       // MySQL
            'decryption failed or bad record mac',         // PostgreSQL
            'server closed the connection unexpectedly',   // PostgreSQL
            'ssl connection has been closed unexpectedly', // PostgreSQL
            'is dead or not enabled'                       // SQL Server
        ] as $needle) {
            if (strpos($message, $needle) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Determine if the given exception was caused by a deadlock.
     *
     * @param  Exception $e
     * @return boolean
     */
    public static function isDeadlockException($e)
    {
        $message = strtolower($e->getMessage());
        foreach ([
            'deadlock found when trying to get lock', // MySQL
            'deadlock detected',                      // PostgreSQL
            'has been chosen as the deadlock victim', // SQL Server
            'the database file is locked',            // SQLite
            'database is locked',                     // SQLite
            'database table is locked',               // SQLite
            'a table in the database is locked'       // SQLite
        ] as $needle) {
            if (strpos($message, $needle) !== false) {
                return true;
            }
        }
        return false;
    }
}

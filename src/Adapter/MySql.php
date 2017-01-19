<?php
namespace Chaos\Database\Adapter;

use PDO;
use PDOException;
use Lead\Set\Set;
use Chaos\Database\DatabaseException;

/**
 * MySQL adapter
 */
class MySql extends \Chaos\Database\Database
{
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
        if (!extension_loaded('pdo_mysql')) {
            throw new DatabaseException("The PDO MySQL extension is not installed.");
        }

        $features = [
            'arrays' => false,
            'transactions' => true,
            'booleans' => true,
            'default' => false
        ];
        if (!func_num_args()) {
            return $features;
        }
        return isset($features[$feature]) ? $features[$feature] : null;
    }

    /**
     * Constructs the MySQL adapter and sets the default port to 3306.
     *
     * @param array $config Configuration options for this class. Available options
     *                      defined by this class:
     *                      - `'host'`: _string_ The IP or machine name where MySQL is running,
     *                                  followed by a colon, followed by a port number or socket.
     *                                  Defaults to `'localhost:3306'`.
     */
    public function __construct($config = [])
    {
        $defaults = [
            'host' => 'localhost:3306',
            'classes' => [
                'dialect' => 'Lead\Sql\Dialect\Dialect\MySql'
            ],
            'handlers' => [],
        ];
        $config = Set::merge($defaults, $config);
        parent::__construct($config);
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
            list($host, $port) = explode(':', $host) + [1 => "3306"];
            $dsn = "mysql:host=%s;port=%s;dbname=%s";
            $this->_config['dsn'] = sprintf($dsn, $host, $port, $this->_config['database']);
        }

        if (!parent::connect()) {
            return false;
        }

        $info = $this->client()->getAttribute(PDO::ATTR_SERVER_VERSION);
        $this->_alias = (boolean) version_compare($info, "4.1", ">=");
        return true;
    }

    /**
     * Returns the list of tables in the currently-connected database.
     *
     * @return array Returns an array of sources to which models can connect.
     */
    public function sources() {
        $select = $this->dialect()->statement('select');
        $select->fields('table_name')
            ->from(['information_schema' => ['tables']])
            ->where([
               'table_type' => 'BASE TABLE',
               'table_schema' => $this->_config['database']
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
        $columns = $this->query("DESCRIBE {$name}");

        foreach ($columns as $column) {
            $field = $this->_field($column);
            $default = $column['Default'];

            switch ($field['type']) {
                case 'boolean':
                    $default = $default === '1';
                    break;
                case 'datetime':
                    $default = $default !== 'CURRENT_TIMESTAMP' ? $default : null;
                    break;
            }

            $fields[$column['Field']] = $field + [
                'null'     => ($column['Null'] === 'YES' ? true : false),
                'default'  => $default
            ];
        }
        return $fields;
    }

    /**
     * Converts database-layer column types to basic types.
     *
     * @param  string $column Database-layer column.
     * @return array          A generic field.
     */
    protected function _field($column)
    {
        preg_match('/(?P<type>\w+)(?:\((?P<length>[\d,]+)\))?/', $column['Type'], $field);
        $field = array_intersect_key($field, ['type' => null, 'length' => null]);
        $field = array_merge(['use' => $field['type']], $field);

        if (isset($field['length']) && $field['length']) {
            $length = explode(',', $field['length']) + [null, null];
            $field['length'] = $length[0] ? intval($length[0]) : null;
            $length[1] ? $field['precision'] = intval($length[1]) : null;
        }

        $field['type'] = $this->dialect()->mapped($field);
        return $field;
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
        if (!$encoding) {
            $query = $this->client()->query("SHOW VARIABLES LIKE 'character_set_client'");
            $encoding = $query->fetchColumn(1);
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

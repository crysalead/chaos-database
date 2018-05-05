<?php
namespace Chaos\Database\Adapter;

use PDOException;
use Lead\Set\Set;
use Chaos\Database\DatabaseException;

/**
 * SQLite adapter
 */
class Sqlite extends \Chaos\Database\Database
{
    /**
     * The protocol.
     *
     * @var string
     */
    protected $_protocol = 'sqlite';

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
        if (!extension_loaded('pdo_sqlite')) {
            throw new DatabaseException("The PDO SQLite extension is not installed.");
        }

        $features = [
            'arrays' => false,
            'transactions' => true,
            'savepoints' => true,
            'booleans' => true,
            'default' => false
        ];

        if (!func_num_args()) {
            return $features;
        }
        return isset($features[$feature]) ? $features[$feature] : null;
    }

    /**
     * Constructs the Sqlite adapter and sets the default port to 3306.
     *
     * @param array $config Configuration options for this class. Available options:
     *                      - `'database'` _string_ : database path. Defaults to `':memory:'`.
     */
    public function __construct($config = [])
    {
        $defaults = [
            'database' => ':memory:',
            'classes' => [
                'dialect' => 'Lead\Sql\Dialect\Dialect\Sqlite'
            ],
            'handlers' => [],
        ];
        $config = Set::merge($defaults, $config);
        parent::__construct($config);

        $this->formatter('datasource', 'boolean', function($value, $options) {
            return $value ? '1' : '0';
        });
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
        return $this->_protocol . ':' . $this->_config['database'];
    }

    /**
     * Returns the list of tables in the currently-connected database.
     *
     * @return array Returns an array of sources to which models can connect.
     */
    public function sources() {
        $select = $this->dialect()->statement('select');
        $select->fields('name')
            ->from(['sqlite_master'])
            ->where([
               'type' => 'table'
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
        $columns = $this->query('PRAGMA table_info(' . $name . ')');

        foreach ($columns as $column) {
            $field = $this->_field($column);
            $default = $column['dflt_value'];

            switch ($field['type']) {
                case 'string':
                    if (is_string($default) && preg_match("~^'(.*)'~", $default, $matches)) {
                      $default = $matches[1];
                    }
                    break;
                case 'boolean':
                    $default = $default === '1';
                    break;
                case 'datetime':
                    $default = $default !== 'CURRENT_TIMESTAMP' ? $default : null;
                    break;
            }

            $fields[$column['name']] = $field + [
                'null'     => ($column['notnull'] === '0' ? true : false),
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
        preg_match('/(?P<type>\w+)(?:\((?P<length>[\d,]+)\))?/', $column['type'], $field);
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
        $encodingMap = [
            'utf8' => 'utf-8',
            'utf16' => 'utf-16',
            'utf16le' => 'utf-16le',
            'utf16be' => 'utf-16be'
        ];
        if (!$encoding) {
            $query = $this->client()->query('PRAGMA encoding');
            $encoding = strtolower($query->fetchColumn());
            return ($key = array_search($encoding, $encodingMap)) ? $key : $encoding;
        }
        $encoding = isset($encodingMap[$encoding]) ? $encodingMap[$encoding] : $encoding;
        try {
            $encoding = strtoupper($encoding);
            $this->client()->exec("PRAGMA encoding=\"{$encoding}\"");
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }
}

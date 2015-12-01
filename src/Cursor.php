<?php
namespace chaos\database;

use PDO;
use PDOStatement;
use PDOException;
use chaos\database\DatabaseException;

/**
 * This class is a wrapper around the `PDOStatement` returned and can be used to iterate over it.
 *
 * @link http://php.net/manual/de/class.pdostatement.php The PDOStatement class.
 */
class Cursor implements \Iterator
{
    /**
     * The current position of the iterator.
     *
     * @var integer
     */
    protected $_iterator = 0;

    /**
     * The fetch mode.
     *
     * @var integer
     */
    protected $_fetch = null;

    /**
     * The optionnal bound data.
     *
     * @var array
     */
    protected $_data = [];

    /**
     * The bound resource.
     *
     * @var resource
     */
    protected $_resource = null;

    /**
     * Indicates whether the fetching has been started.
     *
     * @var boolean
     */
    protected $_started = false;

    /**
     * Indicates whether the resource has been initialized.
     *
     * @var boolean
     */
    protected $_init = false;

    /**
     * Indicates whether the current position is valid or not.
     *
     * @var boolean
     */
    protected $_valid = false;

    /**
     * Indicates whether the cursor is valid or not.
     *
     * @var boolean
     */
    protected $_error = false;

    /**
     * Stores the error number.
     *
     * @var integer
     */
    protected $_errno = 0;

    /**
     * Stores the error message.
     *
     * @var string
     */
    protected $_errmsg = '';

    /**
     * Contains the current key of the cursor.
     *
     * @var mixed
     */
    protected $_key = null;

    /**
     * Contains the current value of the cursor.
     *
     * @var mixed
     */
    protected $_current = false;

    /**
     * `Cursor` constructor.
     *
     * @param array $config Possible values are:
     *                      - `'data'`     _array_   : A data array.
     *                      - `'resource'` _resource_: The resource to fetch on.
     *                      - `'error'`    _boolean_ : A error boolean flag.
     *                      - `'errno'`    _mixed_   : An error code number.
     *                      - `'errmsg'`   _string_  : A full string error message.
     */
    public function __construct($config = [])
    {
        $defaults = [
            'data'     => [],
            'resource' => null,
            'fetch' => PDO::FETCH_ASSOC
        ];
        $config += $defaults;
        $this->_resource = $config['resource'];
        $this->_data = $config['data'];
        $this->_fetch = $config['fetch'];
    }

    /**
     * Returns the bound data.
     *
     * @return array
     */
    public function data()
    {
        return $this->_data;
    }

    /**
     * Returns the bound resource.
     *
     * @return ressource
     */
    public function resource()
    {
        return $this->_resource;
    }

    /**
     * Checks if current position is valid.
     *
     * @return boolean `true` if valid, `false` otherwise.
     */
    public function valid()
    {
        if (!$this->_init) {
            $this->_valid = $this->_fetch();
        }
        return $this->_valid;
    }

    /**
     * Rewinds the cursor to its first position.
     */
    public function rewind()
    {
        if ($this->_started && $this->_resource) {
            throw new DatabaseException("PDO statement tesource doesn't support rewind operation.");
        }
        $this->_started = false;
        $this->_key = null;
        $this->_current = false;
        $this->_init = false;
        reset($this->_data);
    }

    /**
     * Returns the current value.
     *
     * @return mixed The current value (or `null` if there is none).
     */
    public function current()
    {
        if (!$this->_init) {
            $this->_fetch();
        }
        $this->_started = true;
        return $this->_current;
    }

    /**
     * Returns the current key value.
     *
     * @return integer The current key value.
     */
    public function key()
    {
        if (!$this->_init) {
            $this->_fetch();
        }
        $this->_started = true;
        return $this->_key;
    }

    /**
     * Fetches the next element from the resource.
     *
     * @return mixed The next result (or `false` if there is none).
     */
    public function next()
    {
        if ($this->_started === false) {
            return $this->current();
        }
        $this->_valid = $this->_fetch();
        if (!$this->_valid) {
            $this->_key = null;
            $this->_current = false;
        }
        return $this->current();
    }

    /**
     * Fetches the current element from the resource.
     *
     * @return boolean Return `true` on success or `false` otherwise.
     */
    protected function _fetch()
    {
        $this->_init = true;
        if($this->_resource) {
            return $this->_fetchResource();
        } else {
            return $this->_fetchArray();
        }
        return false;
    }

    /**
     * Fetches the result from the data array.
     *
     * @return boolean Return `true` on success or `false` otherwise.
     */
    protected function _fetchArray()
    {
        if (key($this->_data) === null) {
            return false;
        }
        $this->_current = current($this->_data);
        $this->_key = key($this->_data);
        next($this->_data);
        return true;
    }

    /**
     * Fetches the result from the resource.
     *
     * @return boolean Return `true` on success or `false` if it is not valid.
     */
    protected function _fetchResource()
    {
        try {
            if ($result = $this->_resource->fetch($this->_fetch)) {
                $this->_key = $this->_iterator++;
                $this->_current = $result;
                return true;
            }
        } catch (PDOException $e) {
            return false;
        }
        return false;
    }

    /**
     * Closes the resource.
     */
    public function close()
    {
        unset($this->_resource);
        $this->_resource = null;
        $this->_data = [];
    }

    /**
     * The destructor.
     */
    public function __destruct()
    {
        $this->close();
    }
}

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
class Cursor extends \chaos\Cursor
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
     * The constructor
     *
     * @param array $config
     */
    public function __construct($config = [])
    {
        parent::__construct($config);

        $defaults = ['fetch' => PDO::FETCH_ASSOC];
        $config += $defaults;

        $this->_fetch = $config['fetch'];
    }

    /**
     * Rewinds the cursor to its first position.
     */
    public function rewind()
    {
        if ($this->_started && $this->_resource) {
            throw new DatabaseException("PDO statement tesource doesn't support rewind operation.");
        }
        parent::rewind();
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
}

<?php
namespace Chaos\Database;

/**
 * The `DatabaseException` is thrown when a operation fails at the database level.
 */
class DatabaseException extends \Exception
{
    protected $code = 500;
}

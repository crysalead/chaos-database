<?php
namespace chaos\database\spec\mock;

use PDOException;

class PDO
{
    public function __construct($dsn, $username, $password, $options = []) {
        $defaults = ['error' => 'HY000'];
        $options += $defaults;
        $exception = new PDOException("Error, PDO mock class used can't connect.");
        throw $exception;
    }
}

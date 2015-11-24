<?php
use box\Box;
use chaos\database\adapter\MySql;
use chaos\database\adapter\PostgreSql;
use chaos\database\adapter\Sqlite;

date_default_timezone_set('UTC');

$args = $this->args();
$args->argument('coverage', 'default', 3);

$box = box('chaos.spec', new Box());

$drivers = PDO::getAvailableDrivers();

if (in_array('mysql', $drivers)) {
    $box->factory('source.database.mysql', function() {
        return new MySql([
            'database' => 'chaos_test',
            'username' => 'root',
            'password' => '',
            'encoding' => 'utf8'
        ]);
    });
}

if (in_array('pgsql', $drivers)) {
    $box->factory('source.database.postgresql', function() {
        return new PostgreSql([
            'database' => 'chaos_test',
            'username' => 'postgres',
            'password' => '',
            'encoding' => 'utf8'
        ]);
    });
}

if (in_array('sqlite', $drivers)) {
    $box->factory('source.database.sqlite', function() {
        return new Sqlite();
    });
}

?>
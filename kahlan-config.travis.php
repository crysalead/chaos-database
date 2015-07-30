<?php
use PDO;
use box\Box;
use chaos\database\adapter\MySql;
use chaos\database\adapter\PostgreSql;

$args = $this->args();
$args->argument('coverage', 'default', 3);

$box = box('chaos.spec', new Box());

$drivers = PDO::getAvailableDrivers();

if (in_array('mysql', $drivers)) {
    $box->service('source.database.mysql', function() {
        return new MySql([
            'database' => 'chaos_test',
            'username' => 'root',
            'password' => ''
        ]);
    });
}

if (in_array('pgsql', $drivers)) {
    $box->service('source.database.postgresql', function() {
        return new PostgreSql([
            'database' => 'chaos_test',
            'username' => 'postgres',
            'password' => ''
        ]);
    });
}

?>
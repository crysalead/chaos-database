<?php
use Lead\Box\Box;
use Chaos\Database\Adapter\MySql;
use Chaos\Database\Adapter\PostgreSql;
use Chaos\Database\Adapter\Sqlite;

date_default_timezone_set('UTC');

$box = box('chaos.spec', new Box());

$drivers = PDO::getAvailableDrivers();

if (in_array('mysql', $drivers)) {
    $box->factory('source.database.mysql', function() {
        return new MySql([
            'database' => 'chaos_test',
            'username' => 'root',
            'password' => 'root',
            'encoding' => 'utf8'
        ]);
    });
}

if (in_array('pgsql', $drivers)) {
    $box->factory('source.database.postgresql', function() {
        return new PostgreSql([
            'database' => 'chaos_test',
            'username' => 'postgres',
            'password' => 'postgres',
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

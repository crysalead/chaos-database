<?php
use box\Box;
use chaos\database\adapter\MySql;
use chaos\database\adapter\PostgreSql;

$box = box('chaos.spec', new Box());

/*
$box->service('source.database.mysql', function() {
    return new MySql([
        'database' => 'chaos_test',
        'username' => 'login',
        'password' => 'password'
    ]);
});

$box->service('source.database.postgresql', function() {
    return new PostgreSql([
        'database' => 'chaos_test',
        'username' => 'login',
        'password' => 'password'
    ]);
});
*/

?>
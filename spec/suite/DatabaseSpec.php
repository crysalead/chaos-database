<?php
namespace chaos\database\spec\suite;

use DateTime;
use chaos\database\DatabaseException;

use kahlan\plugin\Stub;
use kahlan\plugin\Monkey;

describe("Database", function() {

    beforeEach(function() {

        $this->client = Stub::create();
        Stub::on($this->client)->method('quote', function($string) {
            return "'{$string}'";
        });

        $this->database = Stub::create([
            'extends' => 'chaos\database\Database',
            'params'  => [[
                'client' => $this->client
            ]],
            'methods' => ['exception']
        ]);

    });

    describe("->config()", function() {

        it("returns the default config", function() {

            expect($this->database->config())->toBe([
                'classes'    => [
                    'cursor'  => 'chaos\database\Cursor',
                    'schema'  => 'chaos\database\Schema',
                    'dialect' => 'sql\Dialect'
                ],
                'connect'    => true,
                'meta'       => [
                    'key'    => 'id',
                    'locked' => true
                ],
                'persistent' => true,
                'host'       => 'localhost',
                'username'   => 'root',
                'password'   => '',
                'database'   => null,
                'encoding'   => null,
                'dsn'        => null,
                'options'    => [],
                'handlers'   => []
            ]);

        });

    });

    describe("->client()", function() {

        it("returns the PDO driver", function() {

            expect($this->database->client())->toBe($this->client);

        });

    });

    describe("->connect()", function() {

        it("throws an exception if no DSN is set", function() {

            $closure = function() {
                Stub::create(['extends' => 'chaos\database\Database']);
            };
            expect($closure)->toThrow(new DatabaseException('Error, no DSN setup has been configured for database connection.'));


        });

        it("throws an exception when PDO throws an exception on connect", function() {

            $closure = function() {
                Stub::create([
                    'extends' => 'chaos\database\Database',
                    'params'  => [[
                        'dsn' => 'mysql:host=localhost;port=3306;dbname=test'
                    ]]
                ]);
            };

            Monkey::patch('PDO', 'chaos\database\spec\mock\PDO');

            expect($closure)->toThrow(new DatabaseException("Error, PDO mock class used can't connect."));

        });

    });

    describe("->_exception()", function() {

        beforeEach(function() {
            Stub::on($this->database)->method('exception', function($e) {
                return $this->_exception($e);
            });
        });

        it("throws an exception when PDO can't connect to the host", function() {

            $closure = function() {
                $e = Stub::create();
                Stub::on($e)->method('getCode', function() {
                    return 'HY000';
                });
                $this->database->exception($e);
            };
            expect($closure)->toThrow(new DatabaseException("Unable to connect to host `localhost` [HY000]."));

        });

        it("throws an exception when PDO can't connect to the database", function() {

            $closure = function() {
                $e = Stub::create();
                Stub::on($e)->method('getCode', function() {
                    return '28000';
                });
                $this->database->exception($e);
            };
            expect($closure)->toThrow(new DatabaseException("Host connected, but could not access database ``.", 28000));

        });

    });

    describe("->disconnect()", function() {

        it("disconnects the connection", function() {

            expect($this->database->connected())->toBe(true);
            expect($this->database->disconnect())->toBe(true);
            expect($this->database->connected())->toBe(false);

        });

    });

    describe("->formatter()", function() {

        it("gets/sets a formatter", function() {

            $handler = function() {};
            expect($this->database->formatter('custom', 'mytype', $handler))->toBe($this->database);
            expect($this->database->formatter('custom', 'mytype'))->toBe($handler);


        });

        it("returns the `'_default_'` handler if no handler found", function() {

            $default = $this->database->formatter('cast', '_default_');
            expect($this->database->formatter('cast', 'mytype'))->toBe($default);

        });

    });

    describe("->formatter()", function() {

        it("gets/sets a formatter", function() {

            $handlers = [
                'cast' => [
                    'mytype' => function() {}
                ]
            ];

            $this->database->formatters($handlers);
            expect($this->database->formatters())->toBe($handlers);

        });

    });

    describe("->format()", function() {

        it("formats according default `'datasource'` handlers", function() {

            expect($this->database->format('datasource', 'id', 123))->toBe('123');
            expect($this->database->format('datasource', 'serial', 123))->toBe('123');
            expect($this->database->format('datasource', 'integer', 123))->toBe('123');
            expect($this->database->format('datasource', 'float', 12.3))->toBe('12.3');
            expect($this->database->format('datasource', 'decimal', 12.3))->toBe('12.3');
            $date = DateTime::createFromFormat('Y-m-d', '2014-11-21');
            expect($this->database->format('datasource', 'date', $date))->toBe("'2014-11-21'");
            expect($this->database->format('datasource', 'date', '2014-11-21'))->toBe("'2014-11-21'");
            $datetime = DateTime::createFromFormat('Y-m-d H:i:s', '2014-11-21 10:20:45');
            expect($this->database->format('datasource', 'datetime', $datetime))->toBe("'2014-11-21 10:20:45'");
            expect($this->database->format('datasource', 'datetime', '2014-11-21 10:20:45'))->toBe("'2014-11-21 10:20:45'");
            expect($this->database->format('datasource', 'boolean', true))->toBe('TRUE');
            expect($this->database->format('datasource', 'null', null))->toBe('NULL');
            expect($this->database->format('datasource', 'string', 'abc'))->toBe("'abc'");
            expect($this->database->format('datasource', '_default_', 'abc'))->toBe("'abc'");
            expect($this->database->format('datasource', '_undefined_', 'abc'))->toBe("'abc'");

        });

        it("formats according default `'cast'` handlers", function() {

            expect($this->database->format('cast', 'id', '123'))->toBe(123);
            expect($this->database->format('cast', 'serial', '123'))->toBe(123);
            expect($this->database->format('cast', 'integer', '123'))->toBe(123);
            expect($this->database->format('cast', 'float', '12.3'))->toBe(12.3);
            expect($this->database->format('cast', 'decimal', '12.3'))->toBe(12.3);
            $date = DateTime::createFromFormat('Y-m-d', '2014-11-21');
            expect($this->database->format('cast', 'date', $date)->format('Y-m-d'))->toBe('2014-11-21');
            expect($this->database->format('cast', 'date', '2014-11-21')->format('Y-m-d'))->toBe('2014-11-21');
            $datetime = DateTime::createFromFormat('Y-m-d H:i:s', '2014-11-21 10:20:45');
            expect($this->database->format('cast', 'datetime', $datetime)->format('Y-m-d H:i:s'))->toBe('2014-11-21 10:20:45');
            expect($this->database->format('cast', 'datetime', '2014-11-21 10:20:45')->format('Y-m-d H:i:s'))->toBe('2014-11-21 10:20:45');
            expect($this->database->format('cast', 'datetime', '1416565245')->format('Y-m-d H:i:s'))->toBe('2014-11-21 10:20:45');
            expect($this->database->format('cast', 'boolean', 'TRUE'))->toBe(true);
            expect($this->database->format('cast', 'null', 'NULL'))->toBe(null);
            expect($this->database->format('cast', 'string', 'abc'))->toBe('abc');
            expect($this->database->format('cast', '_default_', 'abc'))->toBe('abc');
            expect($this->database->format('cast', '_undefined_', 'abc'))->toBe('abc');

        });

    });

    describe("->errmsg()", function() {

        it("retuns the last error", function() {

            Stub::on($this->client)->method('errorInfo', function() {
                return ['0000', null, null];
            });

            expect($this->database->errmsg())->toBe('');

            Stub::on($this->client)->method('errorInfo', function() {
                return ['42S02', -204, "Error"];
            });

            expect($this->database->errmsg())->toBe('42S02 (-204):Error');

        });

    });

});

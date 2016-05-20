<?php
namespace Chaos\Database\Spec\Suite;

use DateTime;
use Chaos\Database\DatabaseException;

use Kahlan\Plugin\Stub;
use Kahlan\Plugin\Monkey;

describe("Database", function() {

    beforeEach(function() {

        $this->client = Stub::create();
        Stub::on($this->client)->method('quote', function($string) {
            return "'{$string}'";
        });

        $this->database = Stub::create([
            'extends' => 'Chaos\Database\Database',
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
                    'cursor'  => 'Chaos\Database\Cursor',
                    'schema'  => 'Chaos\Database\Schema',
                    'dialect' => 'Lead\Sql\Dialect'
                ],
                'meta'       => [
                    'key'    => 'id',
                    'locked' => true
                ],
                'dsn'        => null,
                'host'       => 'localhost',
                'username'   => 'root',
                'password'   => '',
                'database'   => null,
                'encoding'   => null,
                'connect'    => true,
                'persistent' => true,
                'options'    => []
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
                Stub::create(['extends' => 'Chaos\Database\Database']);
            };
            expect($closure)->toThrow(new DatabaseException('Error, no DSN setup has been configured for database connection.'));


        });

        it("throws an exception when PDO throws an exception on connect", function() {

            $closure = function() {
                Stub::create([
                    'extends' => 'Chaos\Database\Database',
                    'params'  => [[
                        'dsn' => 'mysql:host=localhost;port=3306;dbname=test'
                    ]]
                ]);
            };

            Monkey::patch('PDO', 'Chaos\Database\Spec\Mock\PDO');

            expect($closure)->toThrow(new DatabaseException("Error, PDO mock class used can't connect."));

        });

    });

    describe("->_exception()", function() {

        beforeEach(function() {
            Stub::on($this->database)->method('exception', function($e) {
                return $this->_exception($e);
            });
        });

        it("throws an exception when PDO can't connect to the database", function() {

            $closure = function() {
                $e = Stub::create();
                Stub::on($e)->method('getCode', function() {
                    return '28000';
                });
                Stub::on($e)->method('getMessage', function() {
                    return 'Host connected, but could not access database.';
                });
                $this->database->exception($e);
            };
            expect($closure)->toThrow(new DatabaseException("Host connected, but could not access database.", 28000));

        });

    });

    describe("->disconnect()", function() {

        it("disconnects the connection", function() {

            expect($this->database->connected())->toBe(true);
            expect($this->database->disconnect())->toBe(true);
            expect($this->database->connected())->toBe(false);

        });

    });

    describe("->format()", function() {

        it("formats `null` values", function() {

            expect($this->database->format('datasource', 'id', null))->toBe('NULL');
            expect($this->database->format('datasource', 'serial', null))->toBe('NULL');
            expect($this->database->format('datasource', 'integer', null))->toBe('NULL');
            expect($this->database->format('datasource', 'float', null))->toBe('NULL');
            expect($this->database->format('datasource', 'decimal', null))->toBe('NULL');
            expect($this->database->format('datasource', 'date', null))->toBe('NULL');
            expect($this->database->format('datasource', 'datetime', null))->toBe('NULL');
            expect($this->database->format('datasource', 'boolean', null))->toBe('NULL');
            expect($this->database->format('datasource', 'null', null))->toBe('NULL');
            expect($this->database->format('datasource', 'string', null))->toBe('NULL');
            expect($this->database->format('datasource', '_default_',null))->toBe('NULL');
            expect($this->database->format('datasource', '_undefined_', null))->toBe('NULL');

        });

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

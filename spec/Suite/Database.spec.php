<?php
namespace Chaos\Database\Spec\Suite;

use InvalidArgumentException;
use DateTime;
use Chaos\Database\DatabaseException;
use Chaos\ORM\Document;
use Chaos\Database\Schema;

use Kahlan\Plugin\Double;

describe("Database", function() {

    beforeEach(function() {

        $this->client = Double::instance();
        allow($this->client)->toReceive('quote')->andRun(function($string) {
            return "'{$string}'";
        });

        $this->database = Double::instance([
            'extends' => 'Chaos\Database\Database',
            'args'    => [[
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
                    'dialect' => 'Lead\Sql\Dialect\Dialect'
                ],
                'meta'       => [
                    'key'    => 'id',
                    'locked' => true
                ],
                'dsn'        => null,
                'host'       => 'localhost',
                'socket'     => null,
                'username'   => 'root',
                'password'   => '',
                'database'   => null,
                'encoding'   => null,
                'connect'    => true,
                'persistent' => false,
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
                Double::instance(['extends' => 'Chaos\Database\Database']);
            };
            expect($closure)->toThrow(new DatabaseException('Error, no database name has been configured.'));


        });

        it("throws an exception when PDO throws an exception on connect", function() {

            $closure = function() {
                Double::instance([
                    'extends' => 'Chaos\Database\Database',
                    'args'    => [[
                        'dsn' => 'mysql:host=localhost;port=3306;dbname=test'
                    ]]
                ]);
            };

            allow('PDO')->toBe('Chaos\Database\Spec\Mock\PDO');

            expect($closure)->toThrow(new DatabaseException("Error, PDO mock class used can't connect."));

        });

    });

    describe("->_exception()", function() {

        beforeEach(function() {
            allow($this->database)->toReceive('exception')->andRun(function($e) {
                return $this->_exception($e);
            });
        });

        it("throws an exception when PDO can't connect to the database", function() {

            $closure = function() {
                $e = Double::instance();
                allow($e)->toReceive('getCode')->andRun(function() {
                    return '28000';
                });
                allow($e)->toReceive('getMessage')->andRun(function() {
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

    describe("->convert()", function() {

        it("formats according default `'datasource'` handlers", function() {

            expect($this->database->convert('datasource', 'id', 123))->toBe('123');
            expect($this->database->convert('datasource', 'serial', 123))->toBe('123');
            expect($this->database->convert('datasource', 'integer', 123))->toBe('123');
            expect($this->database->convert('datasource', 'float', 12.3))->toBe('12.3');
            expect($this->database->convert('datasource', 'decimal', 12.3))->toBe('12.30');
            $date = DateTime::createFromFormat('Y-m-d', '2014-11-21');
            expect($this->database->convert('datasource', 'date', $date))->toBe("'2014-11-21'");
            expect($this->database->convert('datasource', 'date', '2014-11-21'))->toBe("'2014-11-21'");
            $datetime = DateTime::createFromFormat('Y-m-d H:i:s', '2014-11-21 10:20:45');
            expect($this->database->convert('datasource', 'datetime', $datetime))->toBe("'2014-11-21 10:20:45'");
            expect($this->database->convert('datasource', 'datetime', '2014-11-21 10:20:45'))->toBe("'2014-11-21 10:20:45'");
            expect($this->database->convert('datasource', 'boolean', true))->toBe('TRUE');
            expect($this->database->convert('datasource', 'null', null))->toBe('NULL');
            expect($this->database->convert('datasource', 'string', 'abc'))->toBe("'abc'");
            expect($this->database->convert('datasource', '_default_', 'abc'))->toBe("'abc'");
            expect($this->database->convert('datasource', '_undefined_', 'abc'))->toBe("'abc'");
            expect($this->database->convert('datasource', 'serial', [':plain' => 'default']))->toBe('default');
            expect($this->database->convert('datasource', 'json', [1,2]))->toBe("'[1,2]'");

        });

        it("formats `null` values on export", function() {

            expect($this->database->convert('datasource', 'id', null))->toBe('NULL');
            expect($this->database->convert('datasource', 'serial', null))->toBe('NULL');
            expect($this->database->convert('datasource', 'integer', null))->toBe('NULL');
            expect($this->database->convert('datasource', 'float', null))->toBe('NULL');
            expect($this->database->convert('datasource', 'decimal', null))->toBe('NULL');
            expect($this->database->convert('datasource', 'date', null))->toBe('NULL');
            expect($this->database->convert('datasource', 'datetime', null))->toBe('NULL');
            expect($this->database->convert('datasource', 'boolean', null))->toBe('NULL');
            expect($this->database->convert('datasource', 'null', null))->toBe('NULL');
            expect($this->database->convert('datasource', 'string', null))->toBe('NULL');
            expect($this->database->convert('datasource', '_default_',null))->toBe('NULL');
            expect($this->database->convert('datasource', '_undefined_', null))->toBe('NULL');

        });

        it("throws an exception when exporting an invalid date", function() {

            $closure = function() {
                $this->database->convert('datasource', 'date', '0000-00-00');
            };
            expect($closure)->toThrow(new InvalidArgumentException("Invalid date `0000-00-00`, can't be parsed."));

            $closure = function() {
                $this->database->convert('datasource', 'date', '2016-25-15');
            };
            expect($closure)->toThrow(new InvalidArgumentException("Invalid date `2016-25-15`, can't be parsed."));

            $closure = function() {
                $this->database->convert('datasource', 'datetime', '2016-12-15 80:90:00');
            };
            expect($closure)->toThrow(new InvalidArgumentException("Invalid date `2016-12-15 80:90:00`, can't be parsed."));

            $closure = function() {
                $this->database->convert('datasource', 'datetime', '0000-00-00 00:00:00');
            };
            expect($closure)->toThrow(new InvalidArgumentException("Invalid date `0000-00-00 00:00:00`, can't be parsed."));

        });

        it("formats according default `'cast'` handlers", function() {

            expect($this->database->convert('cast', 'id', '123'))->toBe(123);
            expect($this->database->convert('cast', 'serial', '123'))->toBe(123);
            expect($this->database->convert('cast', 'integer', '123'))->toBe(123);
            expect($this->database->convert('cast', 'float', '12.3'))->toBe(12.3);
            expect($this->database->convert('cast', 'decimal', '12.3'))->toBe('12.30');
            $date = DateTime::createFromFormat('Y-m-d H:i:s', '2014-11-21 00:00:00');
            expect($this->database->convert('cast', 'date', $date))->toEqual($date);
            expect($this->database->convert('cast', 'date', '2014-11-21'))->toEqual($date);
            $datetime = DateTime::createFromFormat('Y-m-d H:i:s', '2014-11-21 10:20:45');
            expect($this->database->convert('cast', 'datetime', $datetime))->toEqual($datetime);
            expect($this->database->convert('cast', 'datetime', 1416565245))->toEqual(new DateTime('2014-11-21 10:20:45'));
            expect($this->database->convert('cast', 'boolean', 1))->toBe(true);
            expect($this->database->convert('cast', 'boolean', 0))->toBe(false);
            expect($this->database->convert('cast', 'null', ''))->toBe(null);
            expect($this->database->convert('cast', 'string', 'abc'))->toBe('abc');
            expect($this->database->convert('cast', '_default_', 123))->toBe(123);
            expect($this->database->convert('cast', '_undefined_', 123))->toBe(123);
            expect($this->database->convert('cast', 'json', '[1,2]'))->toEqual([1,2]);
            expect($this->database->convert('cast', 'object', ['a' => 'b'])->data())->toEqual(['a' => 'b']);

            $schema = new Schema(['locked' => false]);
            $value = $this->database->convert('cast', 'object', ['a' => 'b'], [], ['basePath' => 'test', 'schema' => $schema]);
            expect($value->basePath())->toEqual('test');
            expect($value->schema())->toEqual($schema);
            expect($value->data())->toEqual(['a' => 'b']);

            $document = new Document();
            expect($this->database->convert('cast', 'object', $document))->toBe($document);

            $date = new DateTime();
            expect($this->database->convert('cast', 'object', $date))->toBe($date);

        });

    });

    describe("->errorMsg()", function() {

        it("retuns the last error", function() {

            allow($this->client)->toReceive('errorInfo')->andRun(function() {
                return ['0000', null, null];
            });

            expect($this->database->errorMsg())->toBe('');

            allow($this->client)->toReceive('errorInfo')->andRun(function() {
                return ['42S02', -204, "Error"];
            });

            expect($this->database->errorMsg())->toBe('42S02 (-204):Error');

        });

    });

});

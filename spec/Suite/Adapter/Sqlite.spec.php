<?php
namespace Chaos\Database\Spec\Suite\Adapter;

use DateTime;
use Chaos\Database\DatabaseException;
use Chaos\Database\Adapter\Sqlite;
use Chaos\Database\Schema;

describe("Sqlite", function() {

    beforeAll(function() {
        $box = box('chaos.spec');
        skipIf(!$box->has('source.database.sqlite'));
    });

    beforeEach(function() {
        $box = box('chaos.spec');
        $this->adapter = $box->get('source.database.sqlite');
    });

    describe("::enabled()", function() {

        it("returns `true` for enabled features, false otherwise.", function() {

            expect(Sqlite::enabled())->toEqual([
                'arrays'       => false,
                'transactions' => false,
                'booleans'     => true,
                'default'      => false
            ]);
            expect(Sqlite::enabled('arrays'))->toBe(false);
            expect(Sqlite::enabled('transactions'))->toBe(false);
            expect(Sqlite::enabled('booleans'))->toBe(true);
            expect(Sqlite::enabled('default'))->toBe(false);

        });

        it("throws an exception if the extension is not loaded.", function() {

            allow('extension_loaded')->toBeCalled()->andReturn(false);
            expect(function() { Sqlite::enabled(); })->toThrow(new DatabaseException("The PDO SQLite extension is not installed."));

        });

    });

    describe("->connect()", function() {

        it("throws an exception if no database name is set", function() {

            $closure = function() {
                new Sqlite(['database' => null]);
            };
            expect($closure)->toThrow(new DatabaseException('Error, no database name has been configured.'));

        });

    });

    describe("->sources()", function() {

        it("shows sources", function() {

            $schema = new Schema(['connection' => $this->adapter]);
            $schema->source('gallery');
            $schema->column('id', ['type' => 'serial']);
            $schema->create();

            $sources = $this->adapter->sources();

            expect($sources)->toBe([
                'gallery' => 'gallery'
            ]);

            $schema->drop();

        });

    });

    describe("->describe()", function() {

        beforeEach(function() {

            $this->schema = new Schema();
            $this->schema->source('gallery');
            $this->schema->column('id', ['type' => 'serial']);
            $this->schema->column('name', [
                'type'    => 'string',
                'length'  => 128,
                'default' => 'Johnny Boy'
            ]);
            $this->schema->column('active', [
                'type'    => 'boolean',
                'default' => true
            ]);
            $this->schema->column('inactive', [
                'type'    => 'boolean',
                'default' => false
            ]);
            $this->schema->column('money', [
                'type'      => 'decimal',
                'length'    => 10,
                'precision' => 2
            ]);
            $this->schema->column('created', [
                'type'    => 'datetime',
                'use'     => 'timestamp',
                'default' => [':plain' => 'CURRENT_TIMESTAMP']
            ]);

        });

        it("describe a source", function() {

            $this->schema->connection($this->adapter);
            $this->schema->create();

            $gallery = $this->adapter->describe('gallery');

            expect($gallery->column('id'))->toEqual([
                'use'     => 'integer',
                'type'    => 'integer',
                'null'    => false,
                'default' => null,
                'array'   => false
            ]);

            expect($gallery->column('name'))->toEqual([
                'use'     => 'varchar',
                'type'    => 'string',
                'length'  => 128,
                'null'    => false,
                'default' => 'Johnny Boy',
                'array'   => false
            ]);

            expect($gallery->column('active'))->toEqual([
                'use'     => 'boolean',
                'type'    => 'boolean',
                'null'    => false,
                'default' => true,
                'array'   => false
            ]);

            expect($gallery->column('inactive'))->toEqual([
                'use'     => 'boolean',
                'type'    => 'boolean',
                'null'    => false,
                'default' => false,
                'array'   => false
            ]);

            expect($gallery->column('money'))->toEqual([
                'use'       => 'decimal',
                'type'      => 'decimal',
                'length'    => 10,
                'precision' => 2,
                'null'      => false,
                'default'   => null,
                'array'     => false
            ]);

            expect($gallery->column('created'))->toEqual([
                'use'     => 'timestamp',
                'type'    => 'datetime',
                'null'    => false,
                'default' => null,
                'array'   => false
            ]);

            $this->schema->drop();

        });

        it("creates a schema instance without introspection", function() {

            $gallery = $this->adapter->describe('gallery', $this->schema->columns());

            expect($gallery->column('id'))->toEqual([
                'type'  => 'serial',
                'null'  => false,
                'array' => false
            ]);

            expect($gallery->column('name'))->toEqual([
                'type'    => 'string',
                'length'  => 128,
                'null'    => false,
                'default' => 'Johnny Boy',
                'array'   => false
            ]);

            expect($gallery->column('active'))->toEqual([
                'type'    => 'boolean',
                'null'    => false,
                'default' => true,
                'array'   => false
            ]);

            expect($gallery->column('inactive'))->toEqual([
                'type'    => 'boolean',
                'null'    => false,
                'default' => false,
                'array'   => false
            ]);

            expect($gallery->column('money'))->toEqual([
                'type'     => 'decimal',
                'length'   => 10,
                'precision'=> 2,
                'null'     => false,
                'array'    => false
            ]);

            expect($gallery->column('created'))->toEqual([
                'use'     => 'timestamp',
                'type'    => 'datetime',
                'null'    => false,
                'array'   => false,
                'default' => [':plain' => 'CURRENT_TIMESTAMP']
            ]);

        });

    });

    describe("->save()", function() {

        it("casts data on insert using datasource handlers", function() {

            $schema = new Schema(['source' => 'test']);
            $schema->connection($this->adapter);

            $schema->column('id',         ['type' => 'serial']);
            $schema->column('name',       ['type' => 'string']);
            $schema->column('null',       ['type' => 'string', 'null' => true]);
            $schema->column('value',      ['type' => 'integer']);
            $schema->column('double',     ['type' => 'float']);
            $schema->column('revenue',    [
              'type' => 'decimal',
              'length' => 20,
              'precision' => 2
            ]);
            $schema->column('active',     ['type' => 'boolean']);
            $schema->column('registered', ['type' => 'date']);
            $schema->column('created',    ['type' => 'datetime']);

            $schema->create();

            $schema->insert([
              'id' => 1,
              'name' => 'test',
              'null' => null,
              'value' => 1234,
              'double' => 1.5864,
              'revenue' => '152000.8589',
              'active' => true,
              'registered' => DateTime::createFromFormat('Y-m-d H:i:s', '2016-07-30 00:00:00'),
              'created' => DateTime::createFromFormat('Y-m-d H:i:s', '2016-07-30 04:38:55')
            ]);

            $cursor = $schema->connection()->query('SELECT * FROM test WHERE id = 1');
            $data = $cursor->next();

            expect($data)->toBe([
              'id' => '1',
              'name' => 'test',
              'null' => null,
              'value' => '1234',
              'double' => '1.5864',
              'revenue' => '152000.86',
              'active' => '1',
              'registered' => '2016-07-30',
              'created' => '2016-07-30 04:38:55'
            ]);

            $cursor->close();
            $schema->drop();
        });

    });

    describe("->lastInsertId()", function() {

        it("gets the encoding last insert ID", function() {

            $schema = new Schema(['connection' => $this->adapter]);
            $schema->source('gallery');
            $schema->column('id',   ['type' => 'serial']);
            $schema->column('name', ['type' => 'string', 'null' => true]);
            $schema->create();

            $schema->insert(['name' => 'new gallery']);
            expect($schema->lastInsertId())->toBe("1");

            $schema->drop();
        });

        it("gets the encoding last insert ID even with an empty record", function() {

            $schema = new Schema(['connection' => $this->adapter]);
            $schema->source('gallery');
            $schema->column('id',   ['type' => 'serial']);
            $schema->column('name', ['type' => 'string', 'null' => true]);
            $schema->create();

            $schema->insert([]);
            expect($schema->lastInsertId())->toBe("1");

            $schema->drop();

        });

    });

    describe("->query()", function() {

        it("throws an exception when an error occured", function() {

            $closure = function() {
                $this->adapter->query('SELECT');
            };

            expect($closure)->toThrow(/*'~error~'*/);

        });

    });

    describe("->encoding()", function() {

        it("gets/sets the encoding", function() {

            expect($this->adapter->encoding())->toBe('utf8');

        });

    });

});

?>
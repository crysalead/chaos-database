<?php
namespace chaos\database\spec\suite\adapter;

use chaos\database\DatabaseException;
use chaos\database\adapter\PostgreSql;
use chaos\database\Schema;

use kahlan\plugin\Stub;
use kahlan\plugin\Monkey;
use chaos\database\spec\fixture\Fixtures;

describe("PostgreSql", function() {

    before(function() {
        $box = box('chaos.spec');
        skipIf(!$box->has('source.database.postgresql'));
    });

    beforeEach(function() {
        $box = box('chaos.spec');
        $this->adapter = $box->get('source.database.postgresql');
    });

    describe("::enabled()", function() {

        it("returns `true` for enabled features, false otherwise.", function() {

            expect(PostgreSql::enabled())->toEqual([
                'arrays'       => true,
                'transactions' => true,
                'booleans'     => true
            ]);
            expect(PostgreSql::enabled('arrays'))->toBe(true);
            expect(PostgreSql::enabled('transactions'))->toBe(true);
            expect(PostgreSql::enabled('booleans'))->toBe(true);

        });

        it("throws an exception if the extension is not loaded.", function() {

            Monkey::patch('extension_loaded', function() { return false; });
            expect(function() { PostgreSql::enabled(); })->toThrow(new DatabaseException("The PDO PostgreSQL extension is not installed."));

        });

    });

    describe("->connect()", function() {

        it("throws an exception if no database name is set", function() {

            $closure = function() {
                new PostgreSql();
            };
            expect($closure)->toThrow(new DatabaseException('Error, no database name has been configured.'));


        });

    });

    describe("->sources()", function() {

        it("shows sources", function() {

            $schema = new Schema(['connection' => $this->adapter]);
            $schema->source('gallery');
            $schema->set('id', ['type' => 'serial']);
            $schema->create();

            $sources = $this->adapter->sources();

            expect($sources)->toBe([
                'gallery' => 'gallery'
            ]);

            $schema->drop();

        });

    });

    describe("->describe()", function() {

        it("describe a source", function() {

            $schema = new Schema(['connection' => $this->adapter]);
            $schema->source('gallery');
            $schema->set('id', ['type' => 'serial']);
            $schema->set('name', [
                'type'    => 'string',
                'length'  => 128,
                'default' => 'Johnny Boy'
            ]);
            $schema->set('active', [
                'type'    => 'boolean',
                'default' => true
            ]);
            $schema->set('inactive', [
                'type'    => 'boolean',
                'default' => false
            ]);
            $schema->set('money', [
                'type'      => 'decimal',
                'length'    => 10,
                'precision' => 2
            ]);
            $schema->set('created', [
                'type'    => 'datetime',
                'length'  => 2,
                'default' => [':plain' => 'CURRENT_TIMESTAMP']
            ]);
            $schema->create();

            $gallery = $this->adapter->describe('gallery');

            expect($gallery->field('id'))->toEqual([
                'use'     => 'integer',
                'type'    => 'integer',
                'null'    => false,
                'default' => null,
                'array'   => false
            ]);

            expect($gallery->field('name'))->toEqual([
                'use'     => 'character varying',
                'type'    => 'string',
                'length'  => 128,
                'null'    => true,
                'default' => 'Johnny Boy',
                'array'   => false
            ]);

            expect($gallery->field('active'))->toEqual([
                'use'     => 'boolean',
                'type'    => 'boolean',
                'null'    => true,
                'default' => true,
                'array'   => false
            ]);

            expect($gallery->field('inactive'))->toEqual([
                'use'     => 'boolean',
                'type'    => 'boolean',
                'null'    => true,
                'default' => false,
                'array'   => false
            ]);

            expect($gallery->field('money'))->toEqual([
                'use'       => 'numeric',
                'type'      => 'decimal',
                'length'    => 10,
                'precision' => 2,
                'null'      => true,
                'default'   => null,
                'array'     => false
            ]);

            expect($gallery->field('created'))->toEqual([
                'use'     => 'timestamp without time zone',
                'type'    => 'datetime',
                'length'  => 2,
                'null'    => true,
                'default' => null,
                'array'   => false
            ]);

            $schema->drop();

        });

    });

    describe("->format()", function() {

        it("formats according default `'datasource'` handlers", function() {

            expect($this->adapter->format('datasource', 'boolean', true))->toBe('true');
            expect($this->adapter->format('datasource', 'boolean', false))->toBe('false');
            expect($this->adapter->format('datasource', 'array', [1, 2, 3]))->toBe('{1,2,3}');

        });

        it("formats according default `'cast'` handlers", function() {

            expect($this->adapter->format('cast', 'boolean', 't'))->toBe(true);
            expect($this->adapter->format('cast', 'boolean', 'f'))->toBe(false);

        });

    });

    describe("->lastInsertId()", function() {

        it("gets the encoding last insert ID", function() {

            $schema = new Schema(['connection' => $this->adapter]);
            $schema->source('gallery');
            $schema->set('id',   ['type' => 'serial']);
            $schema->set('name', ['type' => 'string']);
            $schema->create();

            $schema->insert(['name' => 'new gallery']);
            expect($schema->lastInsertId())->toBe("1");

            $schema->drop();
        });

    });

    describe("->encoding()", function() {

        it("gets/sets the encoding", function() {

            expect($this->adapter->encoding())->toBe('utf8');
            expect($this->adapter->encoding('win1250'))->toBe(true);
            expect($this->adapter->encoding())->toBe('win1250');

        });

        it("return false for invalid encoding", function() {

            expect($this->adapter->encoding('cp1250'))->toBe(false);

        });

    });

    describe("->timezone()", function() {

        it("gets/sets the encoding", function() {

            expect($this->adapter->timezone('Europe/Rome'))->toBe(true);
            expect($this->adapter->timezone())->toBe('Europe/Rome');

        });

        it("returns `false` for invalid encoding", function() {

            expect($this->adapter->timezone('abdc'))->toBe(false);

        });

    });

    describe("->searchPath()", function() {

        it("gets/sets the search path", function() {

            expect($this->adapter->searchPath())->toBe(['public']);
            expect($this->adapter->searchPath('public'))->toBe(true);
            expect($this->adapter->searchPath())->toBe(['public']);

        });

        it("returns `false` for invalid search path", function() {

            expect($this->adapter->searchPath(null))->toBe(false);

        });

    });

});

?>
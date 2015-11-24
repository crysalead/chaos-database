<?php
namespace chaos\database\spec\suite\adapter;

use chaos\database\DatabaseException;
use chaos\database\adapter\Sqlite;
use chaos\database\Schema;

use kahlan\plugin\Stub;
use kahlan\plugin\Monkey;

describe("Sqlite", function() {

    before(function() {
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
                'booleans'     => true
            ]);
            expect(Sqlite::enabled('arrays'))->toBe(false);
            expect(Sqlite::enabled('transactions'))->toBe(false);
            expect(Sqlite::enabled('booleans'))->toBe(true);

        });

        it("throws an exception if the extension is not loaded.", function() {

            Monkey::patch('extension_loaded', function() { return false; });
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
                'use'     => 'timestamp',
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
                'use'     => 'varchar',
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
                'use'       => 'decimal',
                'type'      => 'decimal',
                'length'    => 10,
                'precision' => 2,
                'null'      => true,
                'default'   => null,
                'array'     => false
            ]);

            expect($gallery->field('created'))->toEqual([
                'use'     => 'timestamp',
                'type'    => 'datetime',
                'null'    => true,
                'default' => null,
                'array'   => false
            ]);

            $schema->drop();

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

    describe("->query", function() {

        it("returns an invalid cursor when an error occurs in silent mode", function() {

            $cursor = $this->adapter->query('SELECT', [], ['exception' => false]);
            expect($cursor->error())->toBe(true);

        });

    });

    describe("->encoding()", function() {

        it("gets/sets the encoding", function() {

            expect($this->adapter->encoding())->toBe('utf8');

        });

    });

});

?>
<?php
namespace chaos\database\spec\suite\adapter;

use chaos\database\adapter\PostgreSql;

use kahlan\plugin\Stub;
use chaos\database\spec\fixture\Fixtures;

describe("PostgreSql", function() {

    before(function() {
        $box = box('chaos.spec');
        skipIf(!$box->has('source.database.postgresql'));
        $this->adapter = $box->get('source.database.postgresql');
        $this->fixtures = new Fixtures([
            'connection' => $this->adapter,
            'fixtures'   => [
                'gallery' => 'chaos\database\spec\fixture\schema\Gallery'
            ]
        ]);
    });

    afterEach(function() {
        $this->fixtures->drop();
        $this->fixtures->reset();
    });

    describe("->sources()", function() {

        it("shows sources", function() {

            $this->fixtures->populate('gallery');
            $sources = $this->adapter->sources();

            expect($sources)->toBe([
                'gallery' => 'gallery'
            ]);

        });

    });

    describe("->describe()", function() {

        it("describe a source", function() {

            $this->fixtures->populate('gallery');

            $schema = $this->adapter->describe('gallery');

            expect($schema->field('id'))->toEqual([
                'type' => 'integer',
                'null' => false,
                'default' => null,
                'array' => false
            ]);

            expect($schema->field('name'))->toEqual([
                'type' => 'string',
                'length' => 255,
                'null' => true,
                'default' => null,
                'array' => false
            ]);

        });

    });

});

?>
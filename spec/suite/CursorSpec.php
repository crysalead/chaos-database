<?php
namespace chaos\database\spec\suite;

use PDOException;
use chaos\database\Cursor;

use kahlan\plugin\Stub;

describe("Cursor", function() {

    describe("->current()", function() {

        it("returns `false` when the `PDOStatement` returns `false`", function() {

            $resource = Stub::create();
            Stub::on($resource)->method('fetch', function() {
                return false;
            });

            $cursor = new Cursor(['resource' => $resource]);
            expect($cursor->current())->toBe(false);

        });

        it("returns `false` when the `PDOStatement` throws an exception", function() {

            $resource = Stub::create();
            Stub::on($resource)->method('fetch', function() {
                throw new PDOException();
            });

            $cursor = new Cursor(['resource' => $resource]);
            expect($cursor->current())->toBe(false);

        });

        it("sets the resource extracted data on success", function() {

            $resource = Stub::create();
            Stub::on($resource)->method('fetch', function() {
                return 'data';
            });

            $cursor = new Cursor(['resource' => $resource]);
            expect($cursor->current())->toBe('data');

        });

    });

});
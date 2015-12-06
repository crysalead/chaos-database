<?php
namespace Chaos\Database\Spec\Suite;

use PDOException;
use Chaos\Database\Cursor;

use Kahlan\Plugin\Stub;

describe("Cursor", function() {

    describe("->__construct()", function() {

        it("loads the data", function() {

            $cursor = new Cursor(['data' => ['foo']]);
            expect($cursor->current())->toBe('foo');

        });

    });

    describe("->key()", function() {

        it("returns current key", function() {

            $cursor = new Cursor(['data' => [1, 2, 3, 4, 5]]);
            expect($cursor->key())->toBe(0);
            $cursor->next();
            expect($cursor->key())->toBe(1);
            $cursor->next();
            expect($cursor->key())->toBe(2);
            $cursor->next();
            expect($cursor->key())->toBe(3);
            $cursor->next();
            expect($cursor->key())->toBe(4);
            $cursor->next();
            expect($cursor->key())->toBe(null);

        });

    });

    describe("->current()", function() {

        it("returns the current value", function() {

            $cursor = new Cursor(['data' => [1, 2, 3, 4, 5]]);
            $value = $cursor->current();
            expect($value)->toBe(1);

        });

    });

    describe("->next()", function() {

        it("returns the next value", function() {

            $cursor = new Cursor(['data' => [1, 2, 3, 4, 5]]);
            expect($cursor->next())->toBe(1);
            expect($cursor->current())->toBe(1);

            expect($cursor->next())->toBe(2);
            expect($cursor->current())->toBe(2);

            expect($cursor->next())->toBe(3);
            expect($cursor->current())->toBe(3);

            expect($cursor->next())->toBe(4);
            expect($cursor->current())->toBe(4);

            expect($cursor->next())->toBe(5);
            expect($cursor->current())->toBe(5);

            expect($cursor->next())->toBe(false); // returns `false` just to follow a weird PHP convention
            expect($cursor->valid())->toBe(false);
        });

    });

    describe("->rewind()", function() {

        it("returns respectively the first and the last item of the collection", function() {

            $cursor = new Cursor(['data' => [1, 2, 3, 4, 5]]);
            expect($cursor->next())->toBe(1);
            $cursor->rewind();
            expect($cursor->next())->toBe(1);
        });

    });

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
<?php
namespace Chaos\Database\Spec\Suite;

use Lead\Set\Set;
use Chaos\ChaosException;
use Chaos\Database\DatabaseException;
use Chaos\Model;
use Chaos\Finders;
use Chaos\Database\Query;

use Kahlan\Plugin\Stub;

use Chaos\Database\Spec\Fixture\Fixtures;

$box = box('chaos.spec');

$connections = [
    "MySQL" => $box->has('source.database.mysql') ? $box->get('source.database.mysql') : null,
    "PgSql" => $box->has('source.database.postgresql') ? $box->get('source.database.postgresql') : null
];

foreach ($connections as $db => $connection) {

    describe("Query[{$db}]", function() use ($connection) {

        before(function() use ($connection) {
            skipIf(!$connection);
        });

        beforeEach(function() use ($connection) {

            $this->connection = $connection;
            $this->fixtures = new Fixtures([
                'connection' => $connection,
                'fixtures'   => [
                    'gallery'        => 'Chaos\Database\Spec\Fixture\Schema\Gallery',
                    'gallery_detail' => 'Chaos\Database\Spec\Fixture\Schema\GalleryDetail',
                    'image'          => 'Chaos\Database\Spec\Fixture\Schema\Image',
                    'image_tag'      => 'Chaos\Database\Spec\Fixture\Schema\ImageTag',
                    'tag'            => 'Chaos\Database\Spec\Fixture\Schema\Tag'
                ]
            ]);

            $this->fixtures->populate('gallery', ['create']);
            $this->fixtures->populate('gallery_detail', ['create']);
            $this->fixtures->populate('image', ['create']);
            $this->fixtures->populate('image_tag', ['create']);
            $this->fixtures->populate('tag', ['create']);

            $this->gallery = $this->fixtures->get('gallery')->model();
            $this->galleryDetail = $this->fixtures->get('gallery_detail')->model();
            $this->image = $this->fixtures->get('image')->model();
            $this->image_tag = $this->fixtures->get('image_tag')->model();
            $this->tag = $this->fixtures->get('tag')->model();

            $this->query = new Query([
                'model'      => $this->gallery,
                'connection' => $this->connection
            ]);

        });

        afterEach(function() {
            $this->fixtures->drop();
            $this->fixtures->reset();
        });

        describe("->connection()", function() {

            it("returns the connection", function() {

                expect($this->query->connection())->toBe($this->connection);

            });

            it("throws an error if no connection is available", function() {

                $closure = function() {
                    $this->query = new Query(['model' => $this->gallery]);
                    $this->query->connection();
                };

                expect($closure)->toThrow(new DatabaseException("Error, missing connection for this query."));

            });

        });

        describe("->statement()", function() {

            it("returns the select statement", function() {

                $statement = $this->query->statement();
                $class = get_class($statement);
                $pos = strrpos($class, '\\');
                $basename = substr($class, $pos !== false ? $pos + 1 : 0);
                expect($basename)->toBe('Select');

            });

        });

        describe("->all()", function() {

            it("finds all records", function() {

                $this->fixtures->populate('gallery');

                $result = $this->query->order(['id'])->all()->data();
                expect($result)->toEqual([
                    ['id' => '1', 'name' => 'Foo Gallery'],
                    ['id' => '2', 'name' => 'Bar Gallery']
                ]);

            });

            it("filering out some fields", function() {

                $this->fixtures->populate('gallery');

                $result = $this->query->fields('name')->order(['id'])->all()->data();
                expect($result)->toEqual([
                    ['name' => 'Foo Gallery'],
                    ['name' => 'Bar Gallery']
                ]);

            });

        });

        describe("->get()", function() {

            it("finds all records", function() {

                $this->fixtures->populate('gallery');

                $result = $this->query->order(['id'])->get()->data();
                expect($result)->toEqual([
                    ['id' => '1', 'name' => 'Foo Gallery'],
                    ['id' => '2', 'name' => 'Bar Gallery']
                ]);

            });

            it("finds all records using array hydration", function() {

                $this->fixtures->populate('gallery');

                $result = $this->query->order(['id'])->get(['return' => 'array']);
                expect($result)->toEqual([
                    ['id' => '1', 'name' => 'Foo Gallery'],
                    ['id' => '2', 'name' => 'Bar Gallery']
                ]);

            });

            it("finds all records using object hydration", function() {

                $this->fixtures->populate('gallery');

                $result = $this->query->order(['id'])->get(['return' => 'object']);
                expect($result)->toEqual([
                    json_decode(json_encode(['id' => '1', 'name' => 'Foo Gallery']), false),
                    json_decode(json_encode(['id' => '2', 'name' => 'Bar Gallery']), false),
                ]);

            });

            it("throws an error if the return mode is not supported", function() {

                $this->fixtures->populate('gallery');

                $closure = function() {
                    $result = $this->query->get(['return' => 'unsupported']);
                };

                expect($closure)->toThrow(new DatabaseException("Invalid `'unsupported'` mode as `'return'` value"));

            });

        });

        describe("->first()", function() {

            it("finds the first record", function() {

                $this->fixtures->populate('gallery');

                $result = $this->query->order(['id'])->first()->data();
                expect($result)->toEqual(['id' => '1', 'name' => 'Foo Gallery']);

            });

        });

        describe("->getIterator()", function() {

            it("implements `IteratorAggregate`", function() {

                $this->fixtures->populate('gallery');

                $this->query->where(['name' => 'Foo Gallery'])->order(['id']);

                foreach ($this->query as $record) {
                    expect($record->data())->toEqual(['id' => '1', 'name' => 'Foo Gallery']);
                }

            });

        });

        describe("->__call()", function() {

            it("delegates the call to the finders", function() {

                $this->fixtures->populate('gallery');

                $finders = new Finders();
                $finders->set('fooGallery', function($query) {
                    $query->where(['name' => 'Foo Gallery']);
                });

                $query = new Query([
                    'model'      => $this->gallery,
                    'connection' => $this->connection,
                    'finders'    => $finders
                ]);

                $result = $query->fooGallery()->all()->data();
                expect($result)->toEqual([['id' => '1', 'name' => 'Foo Gallery']]);

            });

            it("throws an exception if no `Finders` has been defined", function() {

                $this->fixtures->populate('gallery');

                $closure = function() {
                    $this->query->fooGallery();
                };

                expect($closure)->toThrow(new DatabaseException("No finders instance has been defined."));

            });

            it("throws an exception if the finder method doesn't exist", function() {

                $this->fixtures->populate('gallery');

                $closure = function() {
                    $query = new Query([
                        'model'      => $this->gallery,
                        'connection' => $this->connection,
                        'finders'    => new Finders()
                    ]);
                    $query->fooGallery();
                };

                expect($closure)->toThrow(new ChaosException("Unexisting finder `'fooGallery'`."));

            });

        });

        describe("->fields()", function() {

            it("sets an aliased COUNT(*) field", function() {

                $this->fixtures->populate('gallery');

                $result = $this->query->fields([
                    [':as' => [[':plain' => 'COUNT(*)'], [':name' => 'count']]]
                ])->first();

                expect($result->data())->toEqual(['count' => 2]);

            });

        });

        describe("->where()", function() {

            it("filters out according conditions", function() {

                $this->fixtures->populate('gallery');

                $result = $this->query->where(['name' => 'Foo Gallery'])->get();
                expect(count($result))->toBe(1);

            });

        });

        describe("->conditions()", function() {

            it("filters out according conditions", function() {

                $this->fixtures->populate('gallery');

                $result = $this->query->conditions(['name' => 'Foo Gallery'])->get();
                expect(count($result))->toBe(1);

            });

        });

        describe("->group()", function() {

            it("groups by a field name", function() {

                $this->fixtures->populate('image');

                $query = new Query([
                    'model'      => $this->image,
                    'connection' => $this->connection
                ]);
                $result = $query->fields(['gallery_id'])
                                ->group('gallery_id')
                                ->get();
                expect(count($result))->toBe(2);

            });

        });

        describe("->having()", function() {

            it("filters out according conditions", function() {

                $this->fixtures->populate('gallery');

                $result = $this->query->fields(['name'])
                                ->group('name')
                                ->having(['name' => 'Foo Gallery'])
                                ->get();
                expect(count($result))->toBe(1);

            });

        });

        describe("->order()", function() {

            it("order by a field name ASC", function() {

                $this->fixtures->populate('gallery');

                $query = new Query([
                    'model'      => $this->gallery,
                    'connection' => $this->connection
                ]);
                $entity = $query->order(['name' => 'ASC'])->first();
                expect($entity->name)->toBe('Bar Gallery');

                $entity = $this->query->order('name')->first();
                expect($entity->name)->toBe('Bar Gallery');

            });

            it("order by a field name DESC", function() {

                $this->fixtures->populate('gallery');

                $entity = $this->query->order(['name' => 'DESC'])->first();
                expect($entity->name)->toBe('Foo Gallery');

            });

        });

        describe("->embed()", function() {

            it("gets/sets with relationship", function() {

                $query = new Query(['connection' => $this->connection]);
                $query->embed('relation1.relation2');
                $query->embed('relation3', [
                    'conditions' => ['title' => 'hello world']
                ]);
                expect($query->embed())->toBe([
                    'relation1.relation2' => [],
                    'relation3' => [
                        'conditions' => [
                            'title' => 'hello world'
                        ]
                    ]
                ]);

            });

            it("loads external relations embed a custom condition on tags", function() {

                $this->fixtures->populate('gallery');
                $this->fixtures->populate('image');
                $this->fixtures->populate('image_tag');
                $this->fixtures->populate('tag');

                $galleries = $this->query->embed([
                    'images' => function($query) {
                        $query->where(['title' => 'Las Vegas']);
                    }
                ])->order('id')->all();

                expect(count($galleries[0]->images))->toBe(1);
                expect(count($galleries[1]->images))->toBe(0);

            });

            it("loads external relations with a custom condition on tags using an array syntax", function() {

                $this->fixtures->populate('gallery');
                $this->fixtures->populate('image');
                $this->fixtures->populate('image_tag');
                $this->fixtures->populate('tag');

                $galleries = $this->query->embed([
                    'images' => ['conditions' => ['title' => 'Las Vegas']]
                ])->order('id')->all();

                expect(count($galleries[0]->images))->toBe(1);
                expect(count($galleries[1]->images))->toBe(0);

            });

        });

        describe("->has()", function() {

            it("sets a constraint on a nested relation", function() {

                $this->fixtures->populate('gallery');
                $this->fixtures->populate('image');
                $this->fixtures->populate('image_tag');
                $this->fixtures->populate('tag');

                $galleries = $this->query->has('images.tags', ['name' => 'Science'])->get();

                expect(count($galleries))->toBe(1);

            });

        });

        describe("->count()", function() {

            it("finds all records", function() {

                $this->fixtures->populate('gallery');

                $count = $this->query->count();
                expect($count)->toBe(2);

            });

        });

        describe("->alias()", function() {

            it("returns the alias value of table name by default", function() {

                expect($this->query->alias())->toBe('gallery');

            });

            it("gets/sets some alias values", function() {

                $image = $this->image;
                $schema = $image::schema();

                expect($this->query->alias('images', $schema))->toBe('image');
                expect($this->query->alias('images'))->toBe('image');

            });

            it("creates unique aliases when a same table is used multiple times", function() {

                $gallery = $this->gallery;
                $schema = $gallery::schema();

                expect($this->query->alias())->toBe('gallery');
                expect($this->query->alias('parent', $schema))->toBe('gallery__0');
                expect($this->query->alias('parent.parent', $schema))->toBe('gallery__1');
                expect($this->query->alias('parent.parent.parent', $schema))->toBe('gallery__2');

            });

            it("throws an exception if a relation has no alias defined", function() {

                $closure = function() {
                    $this->query->alias('images');
                };

                expect($closure)->toThrow(new DatabaseException("No alias has been defined for `'images'`."));

            });

        });

    });

}
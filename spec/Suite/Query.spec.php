<?php
namespace Chaos\Database\Spec\Suite;

use Lead\Set\Set;
use Chaos\ORM\ORMException;
use Chaos\Database\DatabaseException;
use Chaos\ORM\Model;
use Chaos\ORM\Finders;
use Chaos\Database\Query;
use Chaos\Database\Schema;

use Chaos\Database\Spec\Fixture\Fixtures;

$box = box('chaos.spec');

$connections = [
    "MySQL" => $box->has('source.database.mysql') ? $box->get('source.database.mysql') : null,
    "PgSql" => $box->has('source.database.postgresql') ? $box->get('source.database.postgresql') : null,
    "SQLite" => $box->has('source.database.sqlite') ? $box->get('source.database.sqlite') : null
];

foreach ($connections as $db => $connection) {

    describe("Query[{$db}]", function() use ($connection) {

        beforeAll(function() use ($connection) {
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
                'model' => $this->gallery
            ]);

        });

        afterEach(function() {
            $this->fixtures->drop();
            $this->fixtures->reset();
        });

        describe("->__construct()", function() {

            it("throws an error if no schema is available", function() {

                $closure = function() {
                    $this->query = new Query();
                };

                expect($closure)->toThrow(new DatabaseException("Error, missing schema for this query."));

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

                $result = $this->query->order(['id'])->all();
                expect($result->data())->toEqual([
                    ['id' => '1', 'name' => 'Foo Gallery'],
                    ['id' => '2', 'name' => 'Bar Gallery']
                ]);
                expect($result->get(0)->exists())->toBe(true);
                expect($result->get(1)->exists())->toBe(true);

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

        describe("->fetchOptions()", function() {

            it("gets/sets the fetching options", function() {

                expect($this->query->fetchOptions()['return'])->toBe('entity');
                $this->query->fetchOptions(['return' => 'array']);
                expect($this->query->fetchOptions()['return'])->toBe('array');

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

            context("using queries with no model", function() {

                beforeEach(function() {
                    $gallery = $this->gallery;
                    $this->query = new Query([
                        'schema' => $gallery::definition()
                    ]);
                });

                it("finds all records using object hydration", function() {

                    $this->fixtures->populate('gallery');

                    $result = $this->query->order(['id'])->get(['return' => 'array']);

                    expect($result)->toEqual([
                        ['id' => 1, 'name' => 'Foo Gallery'],
                        ['id' => 2, 'name' => 'Bar Gallery']
                    ]);

                });

                it("throws an error if the return mode has been set to `'entity'`", function() {

                    $closure = function() {
                        $this->fixtures->populate('gallery');
                        $this->query->get();
                    };

                    expect($closure)->toThrow(new DatabaseException("Missing model for this query, set `'return'` to `'array'` to get row data."));

                });

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
                        'finders'    => new Finders()
                    ]);
                    $query->fooGallery();
                };

                expect($closure)->toThrow(new ORMException("Unexisting finder `'fooGallery'`."));

            });

        });

        describe("->fields()", function() {

            it("sets an aliased COUNT(*) field", function() {

                $this->fixtures->populate('gallery');

                $result = $this->query->fields([
                    [':as' => [[':plain' => 'COUNT(*)'], [':name' => 'count']]]
                ])->first(['return' => 'array']);

                expect($result)->toEqual(['count' => 2]);

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

            it("sets conditions", function() {

                $this->fixtures->populate('gallery');

                $result = $this->query->conditions(['name' => 'Foo Gallery'])->get();
                expect(count($result))->toBe(1);

            });

            it("sets conditions on an relation", function() {

                $this->fixtures->populate('gallery');
                $this->fixtures->populate('image');

                $result = $this->query->conditions([
                    ':or()' => [
                        [':like' => [[':name' => 'name'], "%Bar%"]],
                        [':like' => [[':name' => 'images.title'], "%Vegas%"]]
                    ]
                ])->has('images')->get();

                expect(count($result))->toBe(2);

            });

        });

        describe("->group()", function() {

            it("groups by a field name", function() {

                $this->fixtures->populate('image');

                $query = new Query([
                    'model'      => $this->image
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
                    'model'      => $this->gallery
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

        describe("->page()", function() {

            it("returns records at a specific page", function() {

                $this->fixtures->populate('tag');

                $query = new Query([
                    'model'      => $this->tag
                ]);

                $result = $query->order(['id'])->page(1)->limit(3)->all()->data();
                expect($result)->toEqual([
                    ['id' => '1', 'name' => 'High Tech'],
                    ['id' => '2', 'name' => 'Sport'],
                    ['id' => '3', 'name' => 'Computer']
                ]);

                $result = $query->order(['id'])->page(2)->limit(3)->all()->data();
                expect($result)->toEqual([
                    ['id' => '4', 'name' => 'Art'],
                    ['id' => '5', 'name' => 'Science'],
                    ['id' => '6', 'name' => 'City']
                ]);

            });

            it("populates the meta count value", function() {

                $this->fixtures->populate('tag');

                $query = new Query([
                    'model'      => $this->tag
                ]);

                $result = $query->order(['id'])->page(1)->limit(3)->all();
                expect($result->meta())->toEqual([
                    'count' => 6
                ]);

            });

        });

        describe("->offset()", function() {

            it("returns records at a specific offset", function() {

                $this->fixtures->populate('tag');

                $query = new Query([
                    'model'      => $this->tag
                ]);

                $result = $query->order(['id'])->offset(0)->limit(3)->all()->data();
                expect($result)->toEqual([
                    ['id' => '1', 'name' => 'High Tech'],
                    ['id' => '2', 'name' => 'Sport'],
                    ['id' => '3', 'name' => 'Computer']
                ]);

                $result = $query->order(['id'])->offset(3)->limit(3)->all()->data();
                expect($result)->toEqual([
                    ['id' => '4', 'name' => 'Art'],
                    ['id' => '5', 'name' => 'Science'],
                    ['id' => '6', 'name' => 'City']
                ]);

            });

            it("populates the meta count value", function() {

                $this->fixtures->populate('tag');

                $query = new Query([
                    'model'      => $this->tag
                ]);

                $result = $query->order(['id'])->offset(3)->limit(3)->all();
                expect($result->meta())->toEqual([
                    'count' => 6
                ]);

            });

        });

        describe("->embed()", function() {

            it("gets/sets with relationship", function() {

                $query = new Query([
                    'schema' => new Schema([
                        'connection' => $this->connection
                    ])
                ]);
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

            it("finds all records with a conditions on an relation", function() {

                $this->fixtures->populate('gallery');
                $this->fixtures->populate('image');

                $result = $this->query->conditions([
                    ':or()' => [
                        [':like' => [[':name' => 'name'], "%Bar%"]],
                        [':like' => [[':name' => 'images.title'], "%Vegas%"]]
                    ]
                ])->has('images')->count();

                expect($result)->toBe(2);

            });

        });

        describe("->alias()", function() {

            it("returns the alias value of table name by default", function() {

                expect($this->query->alias())->toBe('gallery');

            });

            it("gets/sets some alias values", function() {

                $image = $this->image;
                $schema = $image::definition();

                expect($this->query->alias('images', $schema))->toBe('image');
                expect($this->query->alias('images'))->toBe('image');

            });

            it("creates unique aliases when a same table is used multiple times", function() {

                $gallery = $this->gallery;
                $schema = $gallery::definition();

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

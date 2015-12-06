<?php
namespace Chaos\Database\Spec\Suite;

use Lead\Set\Set;
use Chaos\ChaosException;
use Chaos\Database\DatabaseException;
use Chaos\Model;
use Chaos\Database\Query;
use Chaos\Database\Schema;

use Kahlan\Plugin\Stub;

use Chaos\Database\Spec\Fixture\Fixtures;

$box = box('chaos.spec');

$connections = [
    "MySQL" => $box->has('source.database.mysql') ? $box->get('source.database.mysql') : null,
    "PgSql" => $box->has('source.database.postgresql') ? $box->get('source.database.postgresql') : null
];

foreach ($connections as $db => $connection) {

    describe("Schema[{$db}]", function() use ($connection) {

        before(function() use ($connection) {
            skipIf(!$connection);
        });

        beforeEach(function() use ($connection) {

            skipIf(!$connection);

            $this->connection = $connection;
            $this->fixtures = new Fixtures([
                'connection' => $this->connection,
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

        });

        afterEach(function() {
            $this->fixtures->drop();
            $this->fixtures->reset();
        });

        describe("->query()", function() {

            it("throw an exception when no model is set", function() {

                $closure = function() {
                    $schema = new Schema(['connection' => $this->connection]);
                    $schema->query();
                };

                expect($closure)->toThrow(new DatabaseException("Missing model for this schema, can't create a query."));

            });

        });

        describe("->create()/->drop()", function() {

            it("creates/drop a table", function() {

                $this->fixtures->drop();

                $schema = new Schema([
                    'connection' => $this->connection,
                    'source'     => 'test_table'
                ]);
                $schema->set('id', ['type' => 'serial']);

                $schema->create();
                expect($this->connection->sources())->toBe(['test_table' => 'test_table']);
                $schema->drop();
                expect($this->connection->sources())->toBe([]);

            });

            it("throw an exception when source is not set", function() {

                $closure = function() {
                    $schema = new Schema(['connection' => $this->connection]);
                    $schema->create();
                };

                expect($closure)->toThrow(new DatabaseException("Missing table name for this schema."));

            });

            it("throw an exception when source is not set", function() {

                $closure = function() {
                    $schema = new Schema(['connection' => $this->connection]);
                    $schema->drop();
                };

                expect($closure)->toThrow(new DatabaseException("Missing table name for this schema."));

            });

        });

        context("with all data populated", function() {

            beforeEach(function() {

                $this->fixtures->populate('gallery', ['records']);
                $this->fixtures->populate('gallery_detail', ['records']);
                $this->fixtures->populate('image', ['records']);
                $this->fixtures->populate('image_tag', ['records']);
                $this->fixtures->populate('tag', ['records']);

            });

            describe("->embed()", function() {

                it("embeds a hasMany relationship", function() {

                    $model = $this->gallery;
                    $schema = $model::schema();
                    $galleries = $model::all();
                    $schema->embed($galleries, ['images']);

                    foreach ($galleries as $gallery) {
                        foreach ($gallery->images as $image) {
                            expect($gallery->id)->toBe($image->gallery_id);
                        }
                    }

                });

                it("embeds a belongsTo relationship", function() {

                    $model = $this->image;
                    $schema = $model::schema();
                    $images = $model::all();
                    $schema->embed($images, ['gallery']);

                    foreach ($images as $image) {
                        expect($image->gallery_id)->toBe($image->gallery->id);
                    }

                });

                it("embeds a hasOne relationship", function() {

                    $model = $this->gallery;
                    $schema = $model::schema();
                    $galleries = $model::all();
                    $schema->embed($galleries, ['detail', 'images']);

                    foreach ($galleries as $gallery) {
                        expect($gallery->id)->toBe($gallery->detail->gallery_id);
                    }

                });

                it("embeds a hasManyTrough relationship", function() {

                    $model = $this->image;
                    $schema = $model::schema();
                    $images = $model::all();
                    $schema->embed($images, ['tags']);

                    foreach ($images as $image) {
                        foreach ($image->images_tags as $index => $image_tag) {
                            expect($image_tag->tag)->toBe($image->tags[$index]);
                        }
                    }
                });

                it("embeds nested hasManyTrough relationship", function() {

                    $model = $this->image;
                    $schema = $model::schema();
                    $images = $model::all();
                    $schema->embed($images, ['tags.images']);

                    foreach ($images as $image) {
                        foreach ($image->images_tags as $index => $image_tag) {
                            expect($image_tag->tag)->toBe($image->tags[$index]);

                            foreach ($image_tag->tag->images_tags as $index2 => $image_tag2) {
                                expect($image_tag2->image)->toBe($image_tag->tag->images[$index2]);
                            }
                        }
                    }

                });

            });

            context("using the lazy strategy", function() {

                it("loads a hasMany relationship", function() {

                    $model = $this->gallery;
                    $schema = $model::schema();
                    $galleries = $model::all();

                    foreach ($galleries as $gallery) {
                        foreach ($gallery->images as $image) {
                            expect($gallery->id)->toBe($image->gallery_id);
                        }
                    }

                });

                it("loads a belongsTo relationship", function() {

                    $model = $this->image;
                    $schema = $model::schema();
                    $images = $model::all();

                    foreach ($images as $image) {
                        expect($image->gallery_id)->toBe($image->gallery->id);
                    }

                });

                it("loads a hasOne relationship", function() {

                    $model = $this->gallery;
                    $schema = $model::schema();
                    $galleries = $model::all();

                    foreach ($galleries as $gallery) {
                        expect($gallery->id)->toBe($gallery->detail->gallery_id);
                    }

                });

                it("loads a hasManyTrough relationship", function() {

                    $model = $this->image;
                    $schema = $model::schema();
                    $images = $model::all();

                    foreach ($images as $image) {
                        foreach ($image->images_tags as $index => $image_tag) {
                            expect($image_tag->tag)->toBe($image->tags[$index]);
                        }
                    }

                });

                it("loads nested hasManyTrough relationship", function() {

                    $model = $this->image;
                    $schema = $model::schema();
                    $images = $model::all();

                    foreach ($images as $image) {
                        foreach ($image->images_tags as $index => $image_tag) {
                            expect($image_tag->tag)->toBe($image->tags[$index]);

                            foreach ($image_tag->tag->images_tags as $index2 => $image_tag2) {
                                expect($image_tag2->image)->toBe($image_tag->tag->images[$index2]);
                            }
                        }
                    }

                });

            });

        });

        describe("->save()", function() {

            it("saves and updates an entity", function() {

                $data = [
                    'name' => 'amiga_1200.jpg',
                    'title' => 'Amiga 1200'
                ];

                $model = $this->image;
                $image = $model::create($data);
                expect($image->save())->toBe(true);
                expect($image->exists())->toBe(true);
                expect($image->primaryKey())->not->toBe(null);

                $reloaded = $model::id($image->primaryKey());
                expect($reloaded->data())->toEqual([
                    'id'         => $image->primaryKey(),
                    'gallery_id' => null,
                    'name'       => 'amiga_1200.jpg',
                    'title'      => 'Amiga 1200'
                ]);

                $reloaded->title = 'Amiga 1260';
                expect($reloaded->save())->toBe(true);
                expect($reloaded->exists())->toBe(true);
                expect($reloaded->primaryKey())->toBe($image->primaryKey());

                $persisted = $model::id($reloaded->primaryKey());
                expect($persisted->data())->toEqual([
                    'id'         => $reloaded->primaryKey(),
                    'gallery_id' => null,
                    'name'       => 'amiga_1200.jpg',
                    'title'      => 'Amiga 1260'
                ]);
            });

            it("saves a hasMany relationship", function() {

                $data = [
                    'name' => 'Foo Gallery',
                    'images' => [
                        ['name' => 'amiga_1200.jpg', 'title' => 'Amiga 1200'],
                        ['name' => 'srinivasa_ramanujan.jpg', 'title' => 'Srinivasa Ramanujan'],
                        ['name' => 'las_vegas.jpg', 'title' => 'Las Vegas'],
                    ]
                ];

                $model = $this->gallery;
                $gallery = $model::create($data);
                expect($gallery->save())->toBe(true);

                expect($gallery->primaryKey())->not->toBe(null);
                foreach ($gallery->images as $image) {
                    expect($image->gallery_id)->toBe($gallery->primaryKey());
                }

                $result = $model::id($gallery->primaryKey(),  ['embed' => ['images']]);
                expect($gallery->data())->toEqual($result->data());

            });

            it("saves a belongsTo relationship", function() {

                $data = [
                    'name' => 'amiga_1200.jpg',
                    'title' => 'Amiga 1200',
                    'gallery' => [
                        'name' => 'Foo Gallery'
                    ]
                ];

                $model = $this->image;
                $image = $model::create($data);
                expect($image->save())->toBe(true);

                expect($image->primaryKey())->not->toBe(null);
                expect($image->gallery_id)->toBe($image->gallery->primaryKey());

                $result = $model::id($image->primaryKey(),  ['embed' => ['gallery']]);
                expect($image->data())->toEqual($result->data());

            });

            it("saves a hasOne relationship", function() {

                $data = [
                    'name' => 'Foo Gallery',
                    'detail' => [
                        'description' => 'Foo Gallery Description'
                    ]
                ];

                $model = $this->gallery;
                $gallery = $model::create($data);

                expect($gallery->save())->toBe(true);

                expect($gallery->primaryKey())->not->toBe(null);
                expect($gallery->detail->gallery_id)->toBe($gallery->primaryKey());

                $result = $model::id($gallery->primaryKey(),  ['embed' => ['detail']]);
                expect($gallery->data())->toEqual($result->data());

            });

            context("with a hasManyTrough relationship", function() {

                beforeEach(function() {

                    $data = [
                        'name' => 'amiga_1200.jpg',
                        'title' => 'Amiga 1200',
                        'gallery' => [
                            'name' => 'Foo Gallery'
                        ],
                        'tags' => [
                            ['name' => 'tag1'],
                            ['name' => 'tag2'],
                            ['name' => 'tag3']
                        ]
                    ];

                    $model = $this->image;
                    $this->entity = $model::create($data);
                    $this->entity->save();

                });

                it("saves a hasManyTrough relationship", function() {

                    expect($this->entity->primaryKey())->not->toBe(null);
                    expect($this->entity->images_tags)->toHaveLength(3);
                    expect($this->entity->tags)->toHaveLength(3);

                    foreach ($this->entity->images_tags as $index => $image_tag) {
                        expect($image_tag->tag_id)->toBe($image_tag->tag->primaryKey());
                        expect($image_tag->image_id)->toBe($this->entity->primaryKey());
                        expect($image_tag->tag)->toBe($this->entity->tags[$index]);
                    }

                    $model = $this->image;
                    $result = $model::id($this->entity->primaryKey(),  ['embed' => ['gallery', 'tags']]);
                    expect($this->entity->data())->toEqual($result->data());

                });

                it("appends a hasManyTrough entity", function() {

                    $model = $this->image;
                    $reloaded = $model::id($this->entity->primaryKey());
                    $reloaded->tags[] = ['name' => 'tag4'];
                    expect(count($reloaded->tags))->toBe(4);

                    unset($reloaded->tags[0]);
                    expect($reloaded->save())->toBe(true);

                    $persisted = $model::find()->where(['id' => $reloaded->primaryKey()])->embed('tags')->first();

                    expect(count($persisted->tags))->toBe(3);

                    foreach ($persisted->images_tags as $index => $image_tag) {
                        expect($image_tag->tag_id)->toBe($image_tag->tag->primaryKey());
                        expect($image_tag->image_id)->toBe($persisted->primaryKey());
                        expect($image_tag->tag)->toBe($persisted->tags[$index]);
                    }

                });

            });

            it("saves a nested entities", function() {

                $data = [
                    'name' => 'Foo Gallery',
                    'images' => [
                        [
                            'name' => 'amiga_1200.jpg',
                            'title' => 'Amiga 1200',
                            'tags' => [
                                ['name' => 'tag1'],
                                ['name' => 'tag2'],
                                ['name' => 'tag3']
                            ]
                        ]
                    ]
                ];

                $model = $this->gallery;
                $gallery = $model::create($data);
                expect($gallery->save(['embed' => 'images.tags']))->toBe(true);

                expect($gallery->primaryKey())->not->toBe(null);
                expect($gallery->images)->toHaveLength(1);

                foreach ($gallery->images as $image) {
                    expect($image->gallery_id)->toBe($gallery->primaryKey());
                    expect($image->images_tags)->toHaveLength(3);
                    expect($image->tags)->toHaveLength(3);

                    foreach ($image->images_tags as $index => $image_tag) {
                        expect($image_tag->tag_id)->toBe($image_tag->tag->primaryKey());
                        expect($image_tag->image_id)->toBe($image->primaryKey());
                        expect($image_tag->tag)->toBe($image->tags[$index]);
                    }
                }

                $result = $model::id($gallery->primaryKey(),  ['embed' => ['images.tags']]);
                expect($gallery->data())->toEqual($result->data());

            });

            it("validates by default", function() {

                $model = $this->image;
                $image = $model::create([]);
                $model::validator()->rule('name', 'not:empty');

                expect($image->save())->toBe(false);
                expect($image->exists())->toBe(false);

            });

            it("validates direct relationships by default", function() {

                $gallery = $this->gallery;
                $gallery::validator()->rule('name', 'not:empty');


                $model = $this->image;
                $image = $model::create([
                    'name' => 'amiga_1200.jpg',
                    'title' => 'Amiga 1200',
                    'gallery' => []
                ]);
                expect($image->save())->toBe(false);
                expect($image->exists())->toBe(false);

            });

            it("throws an exception when trying to update an entity with no ID data", function() {

                $closure = function() {
                    $model = $this->gallery;
                    $gallery = $model::create([], ['exists' => true]);
                    $gallery->name = 'Foo Gallery';
                    $gallery->save();
                };

                expect($closure)->toThrow(new ChaosException("Can't update an entity missing ID data."));

            });

            it("throws an exception when trying to update an entity with no ID data and exists is `null`", function() {

                $closure = function() {
                    $model = $this->gallery;
                    $gallery = $model::create([], ['exists' => null, 'autoreload' => false]);
                    $gallery->name = 'Foo Gallery';
                    $gallery->save();
                };

                expect($closure)->toThrow(new ChaosException("Can't update an entity missing ID data."));

            });

        });

        describe("->persist()", function() {

            it("saves an entity", function() {

                $data = [
                    'name' => 'amiga_1200.jpg',
                    'title' => 'Amiga 1200'
                ];

                $model = $this->image;
                $image = $model::create($data);

                expect($image)->toReceive('save')->with([
                    'custom' => 'option',
                    'embed' => false
                ]);

                expect($image->persist(['custom' => 'option']))->toBe(true);
                expect($image->exists())->toBe(true);
                expect($image->primaryKey())->not->toBe(null);

            });

        });

        describe("->delete()", function() {

            it("deletes an entity", function() {

                $data = [
                    'name' => 'amiga_1200.jpg',
                    'title' => 'Amiga 1200'
                ];

                $model = $this->image;
                $image = $model::create($data);

                expect($image->save())->toBe(true);
                expect($image->exists())->toBe(true);

                expect($image->delete())->toBe(true);
                expect($image->exists())->toBe(false);

            });

        });

    });

};

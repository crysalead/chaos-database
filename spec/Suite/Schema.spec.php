<?php
namespace Chaos\Database\Spec\Suite;

use Exception;
use DateTime;
use Lead\Set\Set;
use Chaos\ORM\ORMException;
use Chaos\Database\DatabaseException;
use Chaos\ORM\Model;
use Chaos\Database\Query;
use Chaos\Database\Schema;

use Chaos\Database\Spec\Fixture\Fixtures;

use Kahlan\Plugin\Double;

$box = box('chaos.spec');

$connections = [
    "MySQL" => $box->has('source.database.mysql') ? $box->get('source.database.mysql') : null,
    "PostgreSQL" => $box->has('source.database.postgresql') ? $box->get('source.database.postgresql') : null,
    "SQLite" => $box->has('source.database.sqlite') ? $box->get('source.database.sqlite') : null
];

foreach ($connections as $db => $connection) {

    describe("Schema[{$db}]", function() use ($connection) {

        beforeAll(function() use ($connection) {
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

        describe("->__construct()", function() {

            it("correctly sets config options", function() {

                $connection = Double::instance();
                allow($connection)->toReceive('formatters')->andReturn([]);

                $schema = new Schema([
                    'connection'  => $connection
                ]);

                expect($schema->connection())->toBe($connection);
            });

        });

        describe("->connection()", function() {

            it("gets/sets the connection", function() {

                $connection = Double::instance();
                allow($connection)->toReceive('formatters')->andReturn([]);
                $schema = new Schema();

                expect($schema->connection($connection))->toBe($schema);
                expect($schema->connection())->toBe($connection);

            });

        });

        describe("->create()/->drop()", function() {

            it("creates/drop a table", function() {

                $this->fixtures->drop();

                $schema = new Schema([
                    'connection' => $this->connection,
                    'source'     => 'test_table'
                ]);
                $schema->column('id', ['type' => 'serial']);

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
                    $schema = $model::definition();
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
                    $schema = $model::definition();
                    $images = $model::all();
                    $schema->embed($images, ['gallery']);

                    foreach ($images as $image) {
                        expect($image->gallery_id)->toBe($image->gallery->id);
                    }

                });

                it("embeds a hasOne relationship", function() {

                    $model = $this->gallery;
                    $schema = $model::definition();
                    $galleries = $model::all();
                    $schema->embed($galleries, ['detail', 'images']);

                    foreach ($galleries as $gallery) {
                        expect($gallery->id)->toBe($gallery->detail->gallery_id);
                    }

                });

                it("embeds a hasManyTrough relationship", function() {

                    $model = $this->image;
                    $schema = $model::definition();
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
                    $schema = $model::definition();
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

                it("embeds nested hasManyTrough relationship using object hydration", function() {

                    $model = $this->image;
                    $schema = $model::definition();
                    $images = $model::all([], ['return' => 'object']);
                    $schema->embed($images, ['tags.images'], ['fetchOptions' => ['return' => 'object']]);

                    foreach ($images as $image) {
                        foreach ($image->images_tags as $index => $image_tag) {
                            expect($image_tag->tag)->toBe($image->tags[$index]);

                            foreach ($image_tag->tag->images_tags as $index2 => $image_tag2) {
                                expect($image_tag2->image)->toBe($image_tag->tag->images[$index2]);
                            }
                        }
                    }

                });

                it("embeds nested hasManyTrough relationship using array hydration", function() {

                    $model = $this->image;
                    $schema = $model::definition();
                    $images = $model::all([], ['return' => 'array']);
                    $schema->embed($images, ['tags.images'], ['fetchOptions' => ['return' => 'array']]);

                    foreach ($images as $image) {
                        foreach ($image['images_tags'] as $index => $image_tag) {
                            expect($image_tag['tag'])->toBe($image['tags'][$index]);

                            foreach ($image_tag['tag']['images_tags'] as $index2 => $image_tag2) {
                                expect($image_tag2['image'])->toBe($image_tag['tag']['images'][$index2]);
                            }
                        }
                    }

                });

            });

            context("using the lazy strategy", function() {

                it("loads a hasMany relationship", function() {

                    $model = $this->gallery;
                    $schema = $model::definition();
                    $galleries = $model::all();

                    foreach ($galleries as $gallery) {
                        foreach ($gallery->images as $image) {
                            expect($gallery->id)->toBe($image->gallery_id);
                        }
                    }

                });

                it("loads a belongsTo relationship", function() {

                    $model = $this->image;
                    $schema = $model::definition();
                    $images = $model::all();

                    foreach ($images as $image) {
                        expect($image->gallery_id)->toBe($image->gallery->id);
                    }

                });

                it("loads a hasOne relationship", function() {

                    $model = $this->gallery;
                    $schema = $model::definition();
                    $galleries = $model::all();

                    foreach ($galleries as $gallery) {
                        expect($gallery->id)->toBe($gallery->detail->gallery_id);
                    }

                });

                it("loads a hasManyTrough relationship", function() {

                    $model = $this->image;
                    $schema = $model::definition();
                    $images = $model::all();

                    foreach ($images as $image) {
                        foreach ($image->images_tags as $index => $image_tag) {
                            // Warning in lazy load `'images_tags'` is been fetched twice here
                            // Once through `$image->images_tags` and another time through `$image->tags`
                            // So `'image_tag'` is beeing overwrited and can't be used bellow in toBe().
                            expect($image->tags[$index])->toBe($image->images_tags[$index]->tag);
                        }
                    }

                });

                it("loads nested hasManyTrough relationship", function() {

                    $model = $this->image;
                    $schema = $model::definition();
                    $images = $model::all();

                    foreach ($images as $image) {
                        foreach ($image->images_tags as $index => $image_tag) {
                            // Warning in lazy load `'images_tags'` is been fetched twice here
                            // Once through `$image->images_tags` and another time through `$image->tags`
                            // So `'image_tag'` is beeing overwrited and can't be used bellow in toBe().
                            expect($image->tags[$index])->toBe($image->images_tags[$index]->tag);

                            foreach ($image_tag->tag->images_tags as $index2 => $image_tag2) {
                                // Warning in lazy load `'images_tags'` is been fetched twice here
                                // Once through `$image_tag->tag->images_tags` and another time through `$image_tag->tag->images`
                                // So `'image_tag2'` is beeing overwrited and can't be used bellow in toBe().
                                expect($image_tag->tag->images[$index2])->toBe($image_tag->tag->images_tags[$index2]->image);
                            }
                        }
                    }

                });

            });

        });

        describe("->save()", function() {

            it("saves empty entities", function() {

                $model = $this->image;
                $image = $model::create();
                expect($image->save())->toBe(true);
                expect($image->exists())->toBe(true);

            });

            it("uses whitelist with locked schema", function() {

                $model = $this->image;
                $image = $model::create();

                $image->set([
                  'name' => 'image',
                  'title' => 'Image',
                  'gallery_id' => 3
                ]);

                expect($image->save(['whitelist' => ['title']]))->toBe(true);
                expect($image->exists())->toBe(true);

                $reloaded = $model::load($image->id());
                expect($reloaded->data())->toEqual([
                  'id' => $image->id(),
                  'name' => null,
                  'title' => 'Image',
                  'gallery_id' => null
                ]);

            });

            it("saves and updates an entity", function() {

                $data = [
                    'name' => 'amiga_1200.jpg',
                    'title' => 'Amiga 1200'
                ];

                $model = $this->image;
                $image = $model::create($data);
                expect($image->save())->toBe(true);
                expect($image->exists())->toBe(true);
                expect($image->id())->not->toBe(null);
                expect($image->modified())->toBe(false);

                $reloaded = $model::load($image->id());
                expect($reloaded->data())->toEqual([
                    'id'         => $image->id(),
                    'gallery_id' => null,
                    'name'       => 'amiga_1200.jpg',
                    'title'      => 'Amiga 1200'
                ]);

                $reloaded->title = 'Amiga 1260';
                expect($reloaded->save())->toBe(true);
                expect($reloaded->exists())->toBe(true);
                expect($reloaded->id())->toBe($image->id());
                expect($reloaded->modified())->toBe(false);

                $persisted = $model::load($reloaded->id());
                expect($persisted->data())->toEqual([
                    'id'         => $reloaded->id(),
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
                expect($gallery->save(['embed' => 'images']))->toBe(true);

                expect($gallery->id())->not->toBe(null);
                foreach ($gallery->images as $image) {
                    expect($image->gallery_id)->toBe($gallery->id());
                }

                $result = $model::load($gallery->id(),  ['embed' => ['images']]);
                expect($result->data())->toEqual($gallery->data());

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
                expect($image->save(['embed' => 'gallery']))->toBe(true);

                expect($image->id())->not->toBe(null);
                expect($image->gallery_id)->toBe($image->gallery->id());

                $result = $model::load($image->id(), ['embed' => ['gallery']]);
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

                expect($gallery->save(['embed' => 'detail']))->toBe(true);

                expect($gallery->id())->not->toBe(null);
                expect($gallery->detail->gallery_id)->toBe($gallery->id());

                $result = $model::load($gallery->id(),  ['embed' => ['detail']]);
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
                    $this->entity->save(['embed' => ['gallery', 'tags']]);

                });

                it("saves a hasManyTrough relationship", function() {

                    expect($this->entity->id())->not->toBe(null);
                    expect($this->entity->images_tags)->toHaveLength(3);
                    expect($this->entity->tags)->toHaveLength(3);

                    foreach ($this->entity->images_tags as $index => $image_tag) {
                        expect($image_tag->tag_id)->toBe($image_tag->tag->id());
                        expect($image_tag->image_id)->toBe($this->entity->id());
                        expect($image_tag->tag)->toBe($this->entity->tags[$index]);
                    }

                    $model = $this->image;
                    $result = $model::load($this->entity->id(), ['embed' => ['gallery', 'tags']]);
                    expect($this->entity->data())->toEqual($result->data());

                });

                it("appends a hasManyTrough entity", function() {

                    $model = $this->image;
                    $reloaded = $model::load($this->entity->id());
                    $reloaded->tags[] = ['name' => 'tag4'];
                    expect(count($reloaded->tags))->toBe(4);

                    unset($reloaded->tags[0]);
                    expect($reloaded->save(['embed' => 'tags']))->toBe(true);

                    $persisted = $model::find()->where(['id' => $reloaded->id()])->embed('tags')->first();

                    expect(count($persisted->tags))->toBe(3);

                    foreach ($persisted->images_tags as $index => $image_tag) {
                        expect($image_tag->tag_id)->toBe($image_tag->tag->id());
                        expect($image_tag->image_id)->toBe($persisted->id());
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

                expect($gallery->id())->not->toBe(null);
                expect($gallery->images)->toHaveLength(1);

                foreach ($gallery->images as $image) {
                    expect($image->gallery_id)->toBe($gallery->id());
                    expect($image->images_tags)->toHaveLength(3);
                    expect($image->tags)->toHaveLength(3);

                    foreach ($image->images_tags as $index => $image_tag) {
                        expect($image_tag->tag_id)->toBe($image_tag->tag->id());
                        expect($image_tag->image_id)->toBe($image->id());
                        expect($image_tag->tag)->toBe($image->tags[$index]);
                    }
                }

                $result = $model::load($gallery->id(), ['embed' => ['images.tags']]);
                expect($gallery->data())->toEqual($result->data());

            });

            it("throws an exception when trying to update an entity with no ID data", function() {

                $closure = function() {
                    $model = $this->gallery;
                    $gallery = $model::create([], ['exists' => true]);
                    $gallery->name = 'Foo Gallery';
                    $gallery->save();
                };

                expect($closure)->toThrow(new ORMException("Existing entities must have a valid ID."));

            });

            context("with transactions", function() {

                it("commits on success", function() {

                    $id = null;

                    $this->connection->transaction(function() use (&$id) {
                        $model = $this->image;
                        $image = $model::create();
                        $image->save();
                        $id = $image->id();
                    });

                    $model = $this->image;
                    expect($model::load($id))->toBeAnInstanceOf($model);
                    expect($this->connection->transactionLevel())->toBe(0);

                });

                it("allows manual commit", function() {

                    $model = $this->image;
                    $image = $model::create();

                    $this->connection->beginTransaction();
                    $image->save();
                    $id = $image->id();
                    $this->connection->commit();

                    expect($model::load($id))->toBeAnInstanceOf($model);
                    expect($this->connection->transactionLevel())->toBe(0);

                });

                it("rollbacks on error", function() {

                    $id = null;

                    $closure = function() {
                        $this->connection->transaction(function() use (&$id) {
                            $model = $this->image;
                            $image = $model::create();
                            $image->save();
                            $id = $image->id();
                            throw new Exception('Error Processing.');
                        });
                    };

                    expect($closure)->toThrow(new Exception('Error Processing.'));

                    $model = $this->image;
                    expect($model::load($id))->toBe(null);
                    expect($this->connection->transactionLevel())->toBe(0);

                });

                it("allows manual rollback", function() {

                    $model = $this->image;
                    $image = $model::create();

                    $this->connection->beginTransaction();
                    $image->save();
                    $id = $image->id();
                    $this->connection->rollback();

                    expect($model::load($id))->toBe(null);
                    expect($this->connection->transactionLevel())->toBe(0);

                });

                it("supports save points", function() {
                    $model = $this->image;
                    $image = $model::create(['name' => 'Initial']);
                    $image->save();
                    $id = $image->id();

                    $this->connection->beginTransaction();
                    $image->set('name', 'Update1');
                    $image->save();

                    $this->connection->beginTransaction();
                    $image->set('name', 'Update2');
                    $image->save();

                    $this->connection->beginTransaction();
                    $image->set('name', 'Update3');
                    $image->save();

                    expect($model::load($id)->name)->toBe('Update3');

                    $this->connection->rollback(2);
                    expect($model::load($id)->name)->toBe('Update2');

                    $this->connection->rollback(1);
                    expect($model::load($id)->name)->toBe('Update1');

                    $this->connection->rollback();

                    expect($model::load($id)->name)->toBe('Initial');
                    expect($this->connection->transactionLevel())->toBe(0);

                });

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

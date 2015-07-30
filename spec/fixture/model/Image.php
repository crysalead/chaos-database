<?php
namespace chaos\database\spec\fixture\model;

class Image extends \chaos\Model
{
    protected static $_schema = 'chaos\database\Schema';

    protected static function _schema($schema)
    {
        $schema->set('id', ['type' => 'serial']);
        $schema->set('gallery_id', ['type' => 'integer']);
        $schema->set('name', ['type' => 'string']);
        $schema->set('title', ['type' => 'string', 'length' => 50]);

        $schema->bind('gallery', [
            'relation' => 'belongsTo',
            'to'       => 'chaos\database\spec\fixture\model\Gallery',
            'keys'     => ['gallery_id' => 'id']
        ]);

        $schema->bind('images_tags', [
            'relation' => 'hasMany',
            'to'       => 'chaos\database\spec\fixture\model\ImageTag',
            'keys'     => ['id' => 'image_id']
        ]);

        $schema->bind('tags', [
            'relation' => 'hasManyThrough',
            'through'  => 'images_tags',
            'using'    => 'tag'
        ]);
    }
}

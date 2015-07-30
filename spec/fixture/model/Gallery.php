<?php
namespace chaos\database\spec\fixture\model;

class Gallery extends \chaos\Model
{
    protected static $_schema = 'chaos\database\Schema';

    protected static function _schema($schema)
    {
        $schema->set('id', ['type' => 'serial']);
        $schema->set('name', ['type' => 'string']);

        $schema->bind('detail', [
            'relation' => 'hasOne',
            'to'       => 'chaos\database\spec\fixture\model\GalleryDetail',
            'keys'     => ['id' => 'gallery_id']
        ]);

        $schema->bind('images', [
            'relation' => 'hasMany',
            'to'       => 'chaos\database\spec\fixture\model\Image',
            'keys'     => ['id' => 'gallery_id']
        ]);
    }
}

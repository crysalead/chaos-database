<?php
namespace Chaos\Database\Spec\Fixture\Model;

class GalleryDetail extends BaseModel
{
    protected static function _define($schema)
    {
        $schema->set('id', ['type' => 'serial']);
        $schema->set('description', ['type' => 'string']);
        $schema->set('gallery_id', ['type' => 'integer']);

        $schema->belongsTo('gallery', Gallery::class, [
            'keys' => ['gallery_id' => 'id']
        ]);
    }
}

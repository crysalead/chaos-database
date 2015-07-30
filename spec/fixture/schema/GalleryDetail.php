<?php
namespace chaos\database\spec\fixture\schema;

class GalleryDetail extends \chaos\database\spec\fixture\Fixture
{
    public $_model = 'chaos\database\spec\fixture\model\GalleryDetail';

    public function all()
    {
        $this->create();
        $this->records();
    }

    public function records()
    {
        $this->populate([
            ['id' => 1, 'description' => 'Foo Gallery Description', 'gallery_id' => 1],
            ['id' => 2, 'description' => 'Bar Gallery Description', 'gallery_id' => 2]
        ]);
    }
}

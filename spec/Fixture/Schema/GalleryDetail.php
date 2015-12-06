<?php
namespace Chaos\Database\Spec\Fixture\Schema;

class GalleryDetail extends \Chaos\Database\Spec\Fixture\Fixture
{
    public $_model = 'Chaos\Database\Spec\Fixture\Model\GalleryDetail';

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

<?php
namespace chaos\database\spec\fixture\schema;

class Gallery extends \chaos\database\spec\fixture\Fixture
{
    public $_model = 'chaos\database\spec\fixture\model\Gallery';

    public function all()
    {
        $this->create();
        $this->records();
    }

    public function records()
    {
        $this->populate([
            ['id' => 1, 'name' => 'Foo Gallery'],
            ['id' => 2, 'name' => 'Bar Gallery']
        ]);
    }
}

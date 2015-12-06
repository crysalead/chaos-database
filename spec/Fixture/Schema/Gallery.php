<?php
namespace Chaos\Database\Spec\Fixture\Schema;

class Gallery extends \Chaos\Database\Spec\Fixture\Fixture
{
    public $_model = 'Chaos\Database\Spec\Fixture\Model\Gallery';

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

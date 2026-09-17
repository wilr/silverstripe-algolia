<?php

namespace Wilr\SilverStripe\Algolia\Tests;

use RuntimeException;
use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\FieldType\DBVarchar;

class ExplodingTestDBField extends DBVarchar implements TestOnly
{
    public function forTemplate(): string
    {
        throw new RuntimeException('Broken field export');
    }
}

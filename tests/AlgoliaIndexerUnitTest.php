<?php

namespace Wilr\SilverStripe\Algolia\Tests;

use PHPUnit\Framework\TestCase;
use Wilr\SilverStripe\Algolia\Service\AlgoliaIndexer;

class AlgoliaIndexerUnitTest extends TestCase
{
    public function testGenerateUniqueIdIncludesClassAndRecordId(): void
    {
        $record = new FakeIndexerRecord();
        $record->ID = 42;

        $id = (new AlgoliaIndexer())->generateUniqueID($record);

        $this->assertSame('wilr_silverstripe_algolia_tests_fakeindexerrecord_42', $id);
    }

    public function testDeleteItemReturnsFalseWhenUuidIsEmpty(): void
    {
        $indexer = new AlgoliaIndexer();

        $this->assertFalse($indexer->deleteItem('SomeClass', ''));
    }
}

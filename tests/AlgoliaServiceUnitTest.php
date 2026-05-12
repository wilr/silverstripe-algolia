<?php

namespace Wilr\SilverStripe\Algolia\Tests;

use PHPUnit\Framework\TestCase;
use Wilr\SilverStripe\Algolia\Service\AlgoliaService;

class AlgoliaServiceUnitTest extends TestCase
{
    public function testGetClientRequiresAdminApiKey(): void
    {
        $service = new AlgoliaService();
        $service->applicationId = 'app';

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('adminApiKey');
        $service->getClient();
    }

    public function testGetClientRequiresApplicationId(): void
    {
        $service = new AlgoliaService();
        $service->adminApiKey = 'secret';

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('applicationId');
        $service->getClient();
    }

    public function testGetSearchClientRequiresSearchApiKey(): void
    {
        $service = new AlgoliaService();
        $service->applicationId = 'app';

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('searchApiKey');
        $service->getSearchClient();
    }

    public function testGetIndexesExcludesReplicaNamesWhenFlagTrue(): void
    {
        $service = new AlgoliaService();
        $service->indexes = [
            'primary' => [
                'indexSettings' => ['replicas' => ['replica_a', 'replica_b']],
            ],
            'replica_a' => [],
            'replica_b' => [],
            'standalone' => [],
        ];

        $filtered = $service->getIndexes(true);
        $this->assertArrayHasKey('primary', $filtered);
        $this->assertArrayHasKey('standalone', $filtered);
        $this->assertArrayNotHasKey('replica_a', $filtered);
        $this->assertArrayNotHasKey('replica_b', $filtered);

        $all = $service->getIndexes(false);
        $this->assertArrayHasKey('replica_a', $all);
    }
}

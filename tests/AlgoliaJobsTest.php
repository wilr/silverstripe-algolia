<?php

namespace Wilr\SilverStripe\Algolia\Tests;

use ReflectionProperty;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\DataObjectSchema;
use stdClass;
use Symbiote\QueuedJobs\Services\AbstractQueuedJob;
use Wilr\SilverStripe\Algolia\Service\AlgoliaService;
use Wilr\SilverStripe\Algolia\Tasks\AlgoliaReindex;
use Wilr\SilverStripe\Algolia\Extensions\AlgoliaObjectExtension;
use Wilr\Silverstripe\Algolia\Jobs\AlgoliaDeleteItemJob;
use Wilr\Silverstripe\Algolia\Jobs\AlgoliaIndexItemJob;
use Wilr\Silverstripe\Algolia\Jobs\AlgoliaReindexAllJob;

class AlgoliaJobsTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected static $extra_dataobjects = [
        AlgoliaTestObject::class,
    ];

    protected static $required_extensions = [
        AlgoliaTestObject::class => [
            AlgoliaObjectExtension::class,
        ],
    ];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        Injector::inst()->get(DataObjectSchema::class)->reset();
        Injector::inst()->registerService(new TestAlgoliaService(), AlgoliaService::class);
    }

    public function testAlgoliaIndexItemJobGetTitle(): void
    {
        $job = new AlgoliaIndexItemJob(AlgoliaTestObject::class, [1, 2]);
        $this->assertStringContainsString(AlgoliaTestObject::class, $job->getTitle());
        $this->assertStringContainsString('1', $job->getTitle());
    }

    public function testAlgoliaIndexItemJobGetTitleWithCommaSeparatedConstructor(): void
    {
        $job = new AlgoliaIndexItemJob(AlgoliaTestObject::class, '5,6');
        $this->assertStringContainsString('5', $job->getTitle());
    }

    public function testAlgoliaIndexItemJobProcessIndexesRecord(): void
    {
        $obj = AlgoliaTestObject::create();
        $obj->Active = true;
        $obj->Title = 'Job';
        $obj->write();

        $job = new AlgoliaIndexItemJob(AlgoliaTestObject::class, [$obj->ID]);
        $job->setup();
        $job->process();

        $fresh = AlgoliaTestObject::get()->byID($obj->ID);
        $this->assertNotEmpty($fresh->AlgoliaUUID);
    }

    public function testAlgoliaDeleteItemJobProcessCompletes(): void
    {
        $job = new AlgoliaDeleteItemJob(AlgoliaTestObject::class, 'some-uuid-value');
        $job->process();

        $this->assertTrue($job->jobFinished());
    }

    public function testAlgoliaReindexAllJobSetupBuildsIndexData(): void
    {
        $obj = AlgoliaTestObject::create();
        $obj->Active = true;
        $obj->Title = 'Batch';
        $obj->write();

        $job = new AlgoliaReindexAllJob();

        $jd = new ReflectionProperty(AbstractQueuedJob::class, 'jobData');
        $jd->setValue($job, new stdClass());

        $job->setup();

        $totalStepsProp = new ReflectionProperty(AbstractQueuedJob::class, 'totalSteps');
        $this->assertGreaterThan(0, $totalStepsProp->getValue($job));

        $jobDataProp = new ReflectionProperty(AbstractQueuedJob::class, 'jobData');
        /** @var stdClass $jobData */
        $jobData = $jobDataProp->getValue($job);
        $this->assertNotEmpty($jobData->IndexData ?? null);
    }

    public function testAlgoliaReindexAllJobProcessRunsBatchStep(): void
    {
        Config::modify()->set(AlgoliaReindex::class, 'batch_size', 10);

        $obj = AlgoliaTestObject::create();
        $obj->Active = true;
        $obj->Title = 'ReindexJob';
        $obj->write();

        $job = new AlgoliaReindexAllJob();

        $jd = new ReflectionProperty(AbstractQueuedJob::class, 'jobData');
        $jd->setValue($job, new stdClass());

        $job->setup();
        $job->process();

        $refStep = new ReflectionProperty(AbstractQueuedJob::class, 'currentStep');
        $this->assertSame(1, $refStep->getValue($job));

        $job->process();
        $this->assertTrue($job->jobFinished());
    }
}

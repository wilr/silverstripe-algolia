<?php

namespace Wilr\SilverStripe\Algolia\Tests;

use InvalidArgumentException;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\DataObjectSchema;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Wilr\SilverStripe\Algolia\Extensions\AlgoliaObjectExtension;
use Wilr\SilverStripe\Algolia\Service\AlgoliaService;
use Wilr\SilverStripe\Algolia\Tasks\AlgoliaReindex;
use Wilr\SilverStripe\Algolia\Tasks\AlgoliaReindexItem;

class AlgoliaReindexTest extends SapphireTest
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

    public function testGetItemsThrowsForNonDataObjectClass(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AlgoliaReindex::create()->getItems('NotARealDataObjectClass');
    }

    public function testGetItemsReturnsListForAlgoliaTestObject(): void
    {
        $one = AlgoliaTestObject::create();
        $one->Active = true;
        $one->Title = 'A';
        $one->write();

        $list = AlgoliaReindex::create()->getItems(AlgoliaTestObject::class);

        $this->assertTrue($list->filter('ID', $one->ID)->exists());
    }

    public function testIndexItemReturnsFalseWhenBlocked(): void
    {
        $obj = AlgoliaTestObject::create();
        $obj->Active = false;
        $obj->write();

        $this->assertFalse(AlgoliaReindex::create()->indexItem($obj));
    }

    public function testIndexItemIndexesWhenAllowed(): void
    {
        $obj = AlgoliaTestObject::create();
        $obj->Active = true;
        $obj->Title = 'Live';
        $obj->write();

        $this->assertTrue(AlgoliaReindex::create()->indexItem($obj));
    }

    public function testClearErrorsAndGetErrors(): void
    {
        $task = AlgoliaReindex::create();
        $task->clearErrors();
        $this->assertSame([], $task->getErrors());
    }

    public function testIndexBatchDelegatesToAlgoliaIndex(): void
    {
        $task = AlgoliaReindex::create();
        $ok = $task->indexBatch('testIndex', [
            [
                'objectID' => 'uuid-1',
                'objectTitle' => 'Hi',
            ],
        ]);

        $this->assertTrue($ok);
    }

    public function testIndexItemsProcessesSmallList(): void
    {
        $a = AlgoliaTestObject::create();
        $a->Active = true;
        $a->Title = 'Idx1';
        $a->write();

        Config::modify()->set(AlgoliaReindex::class, 'batch_size', 5);

        $task = AlgoliaReindex::create();
        $list = AlgoliaTestObject::get()->filter('ID', $a->ID);
        $summary = $task->indexItems('testIndex', $list, null);

        $this->assertIsString($summary);
        $this->assertStringContainsString('testIndex', $summary);
    }

    public function testAlgoliaReindexItemTaskSuccessPath(): void
    {
        $obj = AlgoliaTestObject::create();
        $obj->Active = true;
        $obj->Title = 'Task';
        $obj->write();

        $task = new AlgoliaReindexItem();
        $definition = new InputDefinition($task->getOptions());
        $input = new ArrayInput([
            '--class' => AlgoliaTestObject::class,
            '--id' => (string) $obj->ID,
        ], $definition);

        $output = new PolyOutput(PolyOutput::FORMAT_ANSI, OutputInterface::VERBOSITY_QUIET);
        $ref = new \ReflectionMethod(AlgoliaReindexItem::class, 'execute');
        $code = $ref->invoke($task, $input, $output);

        $this->assertSame(Command::SUCCESS, $code);
    }

    public function testAlgoliaReindexItemTaskMissingArgs(): void
    {
        $task = new AlgoliaReindexItem();
        $definition = new InputDefinition($task->getOptions());
        $input = new ArrayInput([], $definition);
        $output = new PolyOutput(PolyOutput::FORMAT_ANSI, OutputInterface::VERBOSITY_QUIET);

        $ref = new \ReflectionMethod(AlgoliaReindexItem::class, 'execute');
        $code = $ref->invoke($task, $input, $output);

        $this->assertSame(Command::FAILURE, $code);
    }

    public function testExecuteWithClearAndForceClearsIndexes(): void
    {
        $task = AlgoliaReindex::create();
        $definition = new InputDefinition($task->getOptions());
        $input = new ArrayInput([
            '--clear' => true,
            '--force' => true,
        ], $definition);
        $output = new PolyOutput(PolyOutput::FORMAT_ANSI, OutputInterface::VERBOSITY_QUIET);

        $ref = new \ReflectionMethod(AlgoliaReindex::class, 'execute');
        $code = $ref->invoke($task, $input, $output);

        $this->assertSame(Command::SUCCESS, $code);
    }
}

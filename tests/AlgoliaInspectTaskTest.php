<?php

namespace Wilr\SilverStripe\Algolia\Tests;

use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\DataObjectSchema;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Output\OutputInterface;
use Wilr\SilverStripe\Algolia\Extensions\AlgoliaObjectExtension;
use Wilr\SilverStripe\Algolia\Service\AlgoliaService;
use Wilr\SilverStripe\Algolia\Tasks\AlgoliaInspect;

class AlgoliaInspectTaskTest extends SapphireTest
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

    public function testExecuteFailsWithoutClassOrId(): void
    {
        $task = new AlgoliaInspect();
        $definition = new InputDefinition((new AlgoliaInspect())->getOptions());
        $input = new ArrayInput([], $definition);
        $output = new PolyOutput(PolyOutput::FORMAT_ANSI, OutputInterface::VERBOSITY_QUIET);

        $ref = new \ReflectionMethod(AlgoliaInspect::class, 'execute');
        $code = $ref->invoke($task, $input, $output);

        $this->assertSame(Command::FAILURE, $code);
    }

    public function testExecuteOutputsPayloadsForViewableRecord(): void
    {
        $obj = AlgoliaTestObject::create();
        $obj->Active = true;
        $obj->Title = 'Inspect me';
        $obj->write();

        $task = new AlgoliaInspect();
        $definition = new InputDefinition((new AlgoliaInspect())->getOptions());
        $input = new ArrayInput([
            '--class' => AlgoliaTestObject::class,
            '--id' => (string) $obj->ID,
        ], $definition);
        $output = new PolyOutput(PolyOutput::FORMAT_ANSI, OutputInterface::VERBOSITY_QUIET);

        $ref = new \ReflectionMethod(AlgoliaInspect::class, 'execute');
        $code = $ref->invoke($task, $input, $output);

        $this->assertSame(Command::SUCCESS, $code);
    }

    public function testExecuteFailsWhenRecordDoesNotExist(): void
    {
        $task = new AlgoliaInspect();
        $definition = new InputDefinition((new AlgoliaInspect())->getOptions());
        $input = new ArrayInput([
            '--class' => AlgoliaTestObject::class,
            '--id' => '999999999',
        ], $definition);
        $output = new PolyOutput(PolyOutput::FORMAT_ANSI, OutputInterface::VERBOSITY_QUIET);

        $ref = new \ReflectionMethod(AlgoliaInspect::class, 'execute');
        $code = $ref->invoke($task, $input, $output);

        $this->assertSame(Command::FAILURE, $code);
    }
}

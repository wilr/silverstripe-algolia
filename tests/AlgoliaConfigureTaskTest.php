<?php

namespace Wilr\SilverStripe\Algolia\Tests;

use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\DataObjectSchema;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\OutputInterface;
use Wilr\SilverStripe\Algolia\Service\AlgoliaService;
use Wilr\SilverStripe\Algolia\Tasks\AlgoliaConfigure;

class AlgoliaConfigureTaskTest extends SapphireTest
{
    protected $usesDatabase = true;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        Injector::inst()->get(DataObjectSchema::class)->reset();
        Injector::inst()->registerService(new TestAlgoliaService(), AlgoliaService::class);
    }

    public function testExecuteWhenTaskDisabled(): void
    {
        Config::modify()->set(AlgoliaConfigure::class, 'is_enabled', false);

        $task = new AlgoliaConfigure();
        $output = new PolyOutput(PolyOutput::FORMAT_ANSI, OutputInterface::VERBOSITY_QUIET);
        $input = new ArrayInput([]);

        $ref = new \ReflectionMethod(AlgoliaConfigure::class, 'execute');
        $code = $ref->invoke($task, $input, $output);

        $this->assertSame(Command::FAILURE, $code);
    }

    public function testExecuteSyncSuccess(): void
    {
        Config::modify()->set(AlgoliaConfigure::class, 'is_enabled', true);

        $service = new TestAlgoliaService();
        $service->indexes = [
            'cfgIndex' => [
                'indexSettings' => [
                    'searchableAttributes' => ['title'],
                ],
            ],
        ];
        Injector::inst()->registerService($service, AlgoliaService::class);

        $task = new AlgoliaConfigure();
        $output = new PolyOutput(PolyOutput::FORMAT_ANSI, OutputInterface::VERBOSITY_QUIET);
        $input = new ArrayInput([]);

        $ref = new \ReflectionMethod(AlgoliaConfigure::class, 'execute');
        $code = $ref->invoke($task, $input, $output);

        $this->assertSame(Command::SUCCESS, $code);
    }
}

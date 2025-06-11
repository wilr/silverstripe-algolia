<?php

namespace Wilr\SilverStripe\Algolia\Tasks;

use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\BuildTask;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Wilr\SilverStripe\Algolia\Service\AlgoliaService;

/**
 * Syncs index settings to Algolia.
 *
 * Note this runs on dev/build automatically but is provided separately for
 * uses where dev/build is slow (e.g 100,000+ record tables)
 */
class AlgoliaConfigure extends BuildTask
{
    protected string $title = 'Algolia Configure';

    protected static string $description = 'Sync Algolia index configuration';

    protected static string $commandName = 'algolia:configure';

    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        $service = Injector::inst()->get(AlgoliaService::class);

        if (!$this->isEnabled()) {
            $output->writeln('This task is disabled.');
            return Command::FAILURE;
        }

        try {
            if ($service->syncSettings()) {
                $output->writeln('Algolia settings synced successfully.' . PHP_EOL);

                return Command::SUCCESS;
            }

            $output->writeln('An error occurred while syncing the settings. Please check your error logs.');
        } catch (\Exception $e) {
            $output->writeln('An error occurred while syncing the settings. Please check your error logs.');
            $output->writeln('Error: ' . $e->getMessage());
        }

        return Command::FAILURE;
    }
}

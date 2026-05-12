<?php

namespace Wilr\SilverStripe\Algolia\Tasks;

use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\BuildTask;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Wilr\SilverStripe\Algolia\Service\AlgoliaService;
use Wilr\SilverStripe\Algolia\Tasks\Concerns\UsesAlgoliaQuietOption;

/**
 * Syncs index settings to Algolia.
 *
 * Note this runs on dev/build automatically but is provided separately for
 * uses where dev/build is slow (e.g 100,000+ record tables)
 */
class AlgoliaConfigure extends BuildTask
{
    use UsesAlgoliaQuietOption;

    protected string $title = 'Algolia Configure';

    protected static string $description = 'Sync Algolia index configuration';

    protected static string $commandName = 'algolia-configure';

    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        $this->applyQuietFromInput($input, $output);

        $service = Injector::inst()->get(AlgoliaService::class);

        if (!$this->isEnabled()) {
            $this->writelnError($output, 'This task is disabled.');
            return Command::FAILURE;
        }

        try {
            if ($service->syncSettings()) {
                $output->writeln('Algolia settings synced successfully.' . PHP_EOL);

                return Command::SUCCESS;
            }

            $this->writelnError($output, 'An error occurred while syncing the settings. Please check your error logs.');
        } catch (\Exception $e) {
            $this->writelnError($output, 'An error occurred while syncing the settings. Please check your error logs.');
            $this->writelnError($output, 'Error: ' . $e->getMessage());
        }

        return Command::FAILURE;
    }

    public function getOptions(): array
    {
        return [
            $this->algoliaQuietInputOption(),
        ];
    }
}

<?php

namespace Wilr\SilverStripe\Algolia\Tasks;

use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\BuildTask;
use SilverStripe\ORM\DataObject;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Wilr\SilverStripe\Algolia\Service\AlgoliaIndexer;

class AlgoliaReindexItem extends BuildTask
{
    protected static string $commandName = 'algolia:index-item';

    protected string $title = 'Algolia Reindex Item';

    protected static string $description = 'Reindex a single item to Algolia';

    protected $errors = [];

    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        Environment::increaseMemoryLimitTo();
        Environment::increaseTimeLimitTo();

        if ($input->getOption('class')) {
            $targetClass = $input->getOption('class');
        } else {
            $output->writeln('<error>Missing class argument</error>');
            return Command::FAILURE;
        }

        if ($input->getOption('id')) {
            $id = $input->getOption('id');
        } else {
            $output->writeln('<error>Missing id argument</error>');
            return Command::FAILURE;
        }

        $obj = DataObject::get($targetClass)->byID($id);

        if (!$obj) {
            $output->writeln('<error>Object not found</error>');
            return Command::FAILURE;
        }

        // Set AlgoliaUUID, in case it wasn't previously set
        if (!$obj->AlgoliaUUID) {
            $output->writeln('No AlgoliaUUID set on object, generating one...');
            $obj->assignAlgoliaUUID(true);
        }

        $indexer = Injector::inst()->get(AlgoliaIndexer::class);
        $service = $indexer->getService();

        $output->write('Indexing to Algolia indexes (');
        $output->write(implode(', ', array_map(function ($indexName) use ($service) {
            return $service->environmentizeIndex($indexName);
        }, array_keys($service->initIndexes($obj)))));
        $output->writeln(')');

        $result = $obj->doImmediateIndexInAlgolia();

        $output->writeln(sprintf(
            'Indexed: %s%sUUID: %s%s%s',
            $result ? 'true ' . '(timestamp ' . $obj->AlgoliaIndexed . ')' : 'false',
            PHP_EOL,
            $obj->AlgoliaUUID ? $obj->AlgoliaUUID : 'No ID set',
            PHP_EOL,
            $obj->AlgoliaError ? 'Error from Algolia: ' . $obj->AlgoliaError : ''
        ));

        return $result ? Command::SUCCESS : Command::FAILURE;
    }

    public function getOptions(): array
    {
        return [
            new InputOption('class', null, InputOption::VALUE_REQUIRED, 'The class name of the object to reindex'),
            new InputOption('id', null, InputOption::VALUE_REQUIRED, 'The ID of the object to reindex'),
        ];
    }
}

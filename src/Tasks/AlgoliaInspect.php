<?php

namespace Wilr\SilverStripe\Algolia\Tasks;

use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\BuildTask;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Wilr\SilverStripe\Algolia\Service\AlgoliaIndexer;
use Wilr\SilverStripe\Algolia\Tasks\Concerns\UsesAlgoliaQuietOption;

class AlgoliaInspect extends BuildTask
{
    use UsesAlgoliaQuietOption;

    protected string $title = 'Algolia Inspect';

    protected static string $description = 'Inspect Algolia index configuration';

    protected static string $commandName = 'algolia-inspect';

    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        $this->applyQuietFromInput($input, $output);

        $itemClass = $input->getOption('class');
        $itemId = $input->getOption('id');

        if (!$itemClass || !$itemId) {
            $this->writelnError($output, 'Missing class or id parameters');

            return Command::FAILURE;
        }

        $item = $itemClass::get()->byId($itemId);

        if (!$item || !$item->canView()) {
            $this->writelnError($output, 'Missing or unviewable object ' . $itemClass . ' #' . $itemId);

            return Command::FAILURE;
        }

        $indexer = Injector::inst()->create(AlgoliaIndexer::class);
        $indexer->getService()->syncSettings();

        $output->writeln('### LOCAL FIELDS ###');
        $output->writeln('<pre>');
        $output->writeln(print_r($indexer->exportAttributesFromObject($item), true));

        $output->writeln('### REMOTE FIELDS ###');
        $output->writeln(print_r($indexer->getObject($item), true));

        $output->writeln('### INDEX SETTINGS ###');
        foreach ($item->getAlgoliaIndexes() as $index) {
            $output->writeln(print_r($index->getSettings(), true));
        }

        $output->writeln('### ALGOLIA STATUS ###');
        $output->writeln('Error: ' . $item->AlgoliaError);
        $output->writeln('LastIndexed: ' . $item->AlgoliaIndexed);
        $output->writeln('Algolia UUID: ' . $item->AlgoliaUUID);

        return Command::SUCCESS;
    }

    public function getOptions(): array
    {
        return [
            new InputOption('class', null, InputOption::VALUE_OPTIONAL, 'Fully qualified DataObject class name'),
            new InputOption('id', null, InputOption::VALUE_OPTIONAL, 'Record ID'),
            $this->algoliaQuietInputOption(),
        ];
    }
}

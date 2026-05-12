<?php

namespace Wilr\SilverStripe\Algolia\Tasks\Concerns;

use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Adds `-q` / `--quiet` handling and helpers so tasks stay script-friendly and test-friendly.
 */
trait UsesAlgoliaQuietOption
{
    /**
     * Merge this into {@see getOptions()} for CLI and web task runners.
     */
    protected function algoliaQuietInputOption(): InputOption
    {
        return new InputOption(
            'quiet',
            'q',
            InputOption::VALUE_NONE,
            'Only print errors and essential messages (suppress progress and data dumps).'
        );
    }

    /**
     * When `--quiet` / `-q` is passed, align {@link PolyOutput} with Symfony quiet semantics so
     * normal `writeln()` calls are skipped automatically.
     */
    protected function applyQuietFromInput(InputInterface $input, PolyOutput $output): void
    {
        if ($input->hasOption('quiet') && $input->getOption('quiet')) {
            $output->setVerbosity(OutputInterface::VERBOSITY_QUIET);
        }
    }

    /**
     * Message that must appear even in quiet mode (validation failures, etc.).
     */
    protected function writelnError(PolyOutput $output, string $message): void
    {
        $output->writeln($message, OutputInterface::OUTPUT_NORMAL | OutputInterface::VERBOSITY_QUIET);
    }
}

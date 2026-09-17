<?php

namespace Wilr\SilverStripe\Algolia\Tests;

use Psr\Log\AbstractLogger;

class TestLogger extends AbstractLogger
{
    /**
     * @var array<int, array{level: string, message: string|\Stringable, context: array<string, mixed>}>
     */
    public array $records = [];

    /**
     * @param string|\Stringable $message
     */
    public function log($level, $message, array $context = []): void
    {
        $this->records[] = [
            'level' => $level,
            'message' => $message,
            'context' => $context,
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Traits;

use Closure;
use Illuminate\Support\Facades\Process;
use Throwable;

trait RunsSteps
{
    /**
     * @var list<string>
     */
    private const array SLOW_TOOLS = ['composer', 'npm'];

    /**
     * @param  list<string>|Closure(): mixed  $step
     * @return array{bool, string}
     */
    private function performStep(array|Closure $step): array
    {
        if ($step instanceof Closure) {
            try {
                $step();

                return [true, ''];
            } catch (Throwable $throwable) {
                return [false, $throwable->getMessage()];
            }
        }

        $result = Process::path(base_path())->env(['PATH' => $this->searchPath()])->timeout(in_array($step[0], self::SLOW_TOOLS, true) ? 1200 : 600)->run($step);

        return [$result->successful(), mb_trim($result->output()."\n".$result->errorOutput())];
    }

    private function searchPath(): string
    {
        return implode(':', array_unique(array_filter([
            ...explode(':', (string) getenv('PATH')),
            dirname(PHP_BINARY),
            '/usr/local/sbin',
            '/usr/local/bin',
            '/usr/sbin',
            '/usr/bin',
            '/sbin',
            '/bin',
        ])));
    }
}

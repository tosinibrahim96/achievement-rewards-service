<?php

declare(strict_types=1);

namespace Tests\Support;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use RuntimeException;
use Throwable;

final class ConcurrentRunner
{
    /**
     * Run independent callbacks at the same time in forked PHP processes.
     *
     * Each child purges the inherited connection before touching PostgreSQL.
     * The sockets form a start barrier, so the operations genuinely overlap.
     *
     * @param  list<Closure(): void>  $callbacks
     */
    public function run(array $callbacks): void
    {
        if (! function_exists('pcntl_fork')) {
            throw new RuntimeException('The pcntl extension is required for concurrency tests.');
        }

        DB::disconnect();

        /** @var list<array{pid: int, socket: resource}> $children */
        $children = [];

        foreach ($callbacks as $callback) {
            $children[] = $this->startChild($callback);
        }

        foreach ($children as $child) {
            fwrite($child['socket'], '1');
        }

        $failures = [];

        foreach ($children as $child) {
            $failure = $this->awaitChild($child);

            if ($failure !== null) {
                $failures[] = $failure;
            }
        }

        DB::purge();

        if ($failures !== []) {
            throw new RuntimeException("Concurrent callback failure:\n".implode("\n", $failures));
        }
    }

    /** @return array{pid: int, socket: resource} */
    private function startChild(Closure $callback): array
    {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        if ($sockets === false) {
            throw new RuntimeException('Unable to create the concurrency start barrier.');
        }

        [$parentSocket, $childSocket] = $sockets;
        $pid = pcntl_fork();

        if ($pid === -1) {
            throw new RuntimeException('Unable to fork a concurrency test process.');
        }

        if ($pid === 0) {
            $this->runChild($callback, $parentSocket, $childSocket);
        }

        fclose($childSocket);

        return ['pid' => $pid, 'socket' => $parentSocket];
    }

    /** @param resource $parentSocket @param resource $childSocket */
    private function runChild(Closure $callback, mixed $parentSocket, mixed $childSocket): never
    {
        fclose($parentSocket);
        fread($childSocket, 1);
        DB::purge();
        Redis::purge('default');
        Redis::purge('cache');

        try {
            $callback();
            fwrite($childSocket, 'ok');
            fclose($childSocket);
            exit(0);
        } catch (Throwable $throwable) {
            fwrite($childSocket, $throwable::class.': '.$throwable->getMessage());
            fclose($childSocket);
            exit(1);
        }
    }

    /**
     * @param  array{pid: int, socket: resource}  $child
     */
    private function awaitChild(array $child): ?string
    {
        $message = stream_get_contents($child['socket']);
        fclose($child['socket']);
        $status = 0;
        pcntl_waitpid($child['pid'], $status);

        if (pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0) {
            return null;
        }

        return $message === false ? 'Unknown child-process failure.' : $message;
    }
}

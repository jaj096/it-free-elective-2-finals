<?php

namespace Tests\Support;

use RuntimeException;

/**
 * Starts the REAL generator-service (php -S) on a free port with its own temporary
 * .env and storage folder, so tests exercise the real sender and the real receiver.
 */
final class GeneratorProcess
{
    /** @var resource */
    private $process;

    public readonly string $url;

    public readonly string $storage;

    /**
     * @param  array<string, string>  $env  generator .env values
     */
    public function __construct(array $env)
    {
        $root = dirname(__DIR__, 3).'/generator-service';
        $this->storage = sys_get_temp_dir().'/certq-gen-'.bin2hex(random_bytes(4));
        mkdir($this->storage.'/pdf', 0777, true);

        $envFile = $this->storage.'/.env';
        $this->configure($env);

        $port = self::freePort();
        $this->url = "http://127.0.0.1:{$port}";

        $this->process = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', "{$root}/public", "{$root}/public/index.php"],
            [0 => ['pipe', 'r'], 1 => ['file', $this->storage.'/stdout.txt', 'a'], 2 => ['file', $this->storage.'/stderr.txt', 'a']],
            $pipes,
            null,
            ['GENERATOR_ENV_FILE' => $envFile, 'GENERATOR_STORAGE_DIR' => $this->storage, 'PATH' => getenv('PATH') ?: '/usr/bin:/bin'],
        );

        for ($i = 0; $i < 50; $i++) {
            if (@file_get_contents($this->url.'/health') !== false) {
                return;
            }
            usleep(100_000);
        }
        $this->stop();
        throw new RuntimeException('Generator service did not start.');
    }

    /** Rewrites the receiver's .env (it is re-read on every request, like the real service). */
    public function configure(array $env): void
    {
        file_put_contents($this->storage.'/.env', implode("\n", array_map(fn ($k, $v) => "{$k}={$v}", array_keys($env), $env))."\n");
    }

    /** Lines of the receiver's structured log (storage/generator.log). */
    public function logLines(): array
    {
        $file = $this->storage.'/generator.log';

        return is_file($file) ? array_map(fn ($l) => json_decode($l, true), file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) : [];
    }

    public function rawLog(): string
    {
        return (string) @file_get_contents($this->storage.'/generator.log').(string) @file_get_contents($this->storage.'/stderr.txt');
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $name = stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr(strrchr($name, ':'), 1);
    }
}

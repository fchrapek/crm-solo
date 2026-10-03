<?php

declare(strict_types=1);

namespace App\Services\Integrations\Trello;

/**
 * A file stream that accepts at most a fixed number of bytes. A write past
 * the cap writes nothing and returns 0, which makes curl abort the transfer
 * there and then (a write callback that takes fewer bytes than offered is a
 * write error), instead of the whole body arriving before anyone checks.
 * Opened through the `trello-capped://` wrapper with a stream context holding
 * the limit and the object that records the refusal.
 */
final class CappedFileSink
{
    public const string SCHEME = 'trello-capped';

    /** @var resource|null */
    public $context;

    /** @var resource|null */
    private $file;

    private int $limit = 0;

    private CappedFileSinkState $state;

    public static function register(): void
    {
        if (! in_array(self::SCHEME, stream_get_wrappers(), true)) {
            stream_wrapper_register(self::SCHEME, self::class);
        }
    }

    /**
     * @return resource
     */
    public static function open(string $path, int $limit, CappedFileSinkState $state)
    {
        self::register();
        $context = stream_context_create([self::SCHEME => ['limit' => $limit, 'state' => $state]]);
        $handle = fopen(self::SCHEME.'://'.$path, 'w+', false, $context);
        if ($handle === false) {
            throw new TrelloRequestFailed('The download file could not be opened.');
        }

        return $handle;
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $settings = stream_context_get_options($this->context)[self::SCHEME] ?? [];
        $this->limit = (int) ($settings['limit'] ?? 0);
        $this->state = ($settings['state'] ?? null) instanceof CappedFileSinkState ? $settings['state'] : new CappedFileSinkState;
        $file = fopen(mb_substr($path, mb_strlen(self::SCHEME.'://')), $mode);
        if ($file === false) {
            return false;
        }
        $this->file = $file;

        return true;
    }

    public function stream_write(string $data): int
    {
        $length = mb_strlen($data, '8bit');
        if ($this->state->written + $length > $this->limit) {
            $this->state->refuse();

            return 0;
        }
        $this->state->written += $length;

        return (int) fwrite($this->file, $data);
    }

    public function stream_read(int $count): string|false
    {
        return fread($this->file, $count);
    }

    public function stream_eof(): bool
    {
        return feof($this->file);
    }

    public function stream_tell(): int
    {
        return (int) ftell($this->file);
    }

    public function stream_seek(int $offset, int $whence = SEEK_SET): bool
    {
        return fseek($this->file, $offset, $whence) === 0;
    }

    public function stream_truncate(int $size): bool
    {
        return ftruncate($this->file, $size);
    }

    public function stream_set_option(int $option, int $arg1, ?int $arg2): bool
    {
        return false;
    }

    public function stream_flush(): bool
    {
        return fflush($this->file);
    }

    public function stream_close(): void
    {
        fclose($this->file);
    }

    /**
     * @return array<int|string, int>|false
     */
    public function stream_stat(): array|false
    {
        return fstat($this->file);
    }
}

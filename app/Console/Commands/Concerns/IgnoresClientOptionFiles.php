<?php

declare(strict_types=1);

namespace App\Console\Commands\Concerns;

use Illuminate\Support\Facades\Process;
use RuntimeException;

trait IgnoresClientOptionFiles
{
    /**
     * --no-defaults must come first. Oracle MySQL clients still read the
     * .mylogin.cnf login-path file under it, whose password beats MYSQL_PWD,
     * so --no-login-paths follows it (MySQL 8.2+; it must sit straight after
     * --no-defaults). MariaDB clients, including ones installed under the
     * mysql names, and older MySQL do not list it in --help and do not get it.
     *
     * The probe itself runs under --no-defaults, since a bad option file can
     * stop a plain --help before it prints. A probe that fails says nothing
     * about the flag, so it stops the run instead of guessing.
     *
     * @throws RuntimeException when the client cannot print its help
     */
    private function optionFileArgs(string $binary): string
    {
        if (! in_array($binary, ['mysqldump', 'mysql'], true)) {
            return '--no-defaults';
        }

        $probe = Process::run([$binary, '--no-defaults', '--help']);
        if ($probe->failed() || mb_trim($probe->output()) === '') {
            throw new RuntimeException(sprintf(
                '%s --no-defaults --help failed (exit %d%s), so it is unknown whether it can skip saved login paths.',
                $binary,
                (int) $probe->exitCode(),
                mb_trim($probe->errorOutput()) !== '' ? ': '.mb_trim($probe->errorOutput()) : '',
            ));
        }

        return str_contains($probe->output(), '--no-login-paths') ? '--no-defaults --no-login-paths' : '--no-defaults';
    }
}

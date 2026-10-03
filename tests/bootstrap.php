<?php

declare(strict_types=1);

use App\Services\Concerns\SpawnEnvironment;

require __DIR__.'/../vendor/autoload.php';

// Run from a git hook (the pre-push job), the suite inherits GIT_DIR and its
// kin, and every git command a test spawns would act on this repository
// instead of its temp directory. ParaTest loads this file in each worker too.
foreach (SpawnEnvironment::GIT_REPOSITORY_ENV as $name) {
    putenv($name);
    unset($_ENV[$name], $_SERVER[$name]);
}

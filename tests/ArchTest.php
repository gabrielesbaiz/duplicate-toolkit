<?php

declare(strict_types=1);
use Gabrielesbaiz\DuplicateToolkit\Exceptions\DuplicateToolkitException;
use Illuminate\Console\Command;

arch('no debugging leftovers')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'die'])
    ->not->toBeUsed();

arch('everything is strictly typed')
    ->expect('Gabrielesbaiz\DuplicateToolkit')
    ->toUseStrictTypes();

arch('enums live in the Enums namespace')
    ->expect('Gabrielesbaiz\DuplicateToolkit\Enums')
    ->toBeEnums();

arch('contracts are interfaces')
    ->expect('Gabrielesbaiz\DuplicateToolkit\Contracts')
    ->toBeInterfaces();

arch('exceptions extend the package exception')
    ->expect('Gabrielesbaiz\DuplicateToolkit\Exceptions')
    ->toExtend(DuplicateToolkitException::class)
    ->ignoring(DuplicateToolkitException::class);

arch('events are final')
    ->expect('Gabrielesbaiz\DuplicateToolkit\Events')
    ->toBeFinal();

arch('commands extend the Laravel command')
    ->expect('Gabrielesbaiz\DuplicateToolkit\Commands')
    ->toExtend(Command::class)
    ->ignoring('Gabrielesbaiz\DuplicateToolkit\Commands\Concerns');

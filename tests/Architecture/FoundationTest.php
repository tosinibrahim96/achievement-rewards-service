<?php

declare(strict_types=1);

arch('application classes use strict types')
    ->expect('App')
    ->toUseStrictTypes();

arch('application code does not contain debugging calls')
    ->expect('App')
    ->not->toUse(['dd', 'dump', 'ray']);

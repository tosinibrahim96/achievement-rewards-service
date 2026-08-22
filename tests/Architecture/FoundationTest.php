<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

arch('application classes use strict types')
    ->expect('App')
    ->toUseStrictTypes();

arch('application code does not contain debugging calls')
    ->expect('App')
    ->not->toUse(['dd', 'dump', 'ray']);

arch('controllers do not perform persistence provider calls or event orchestration')
    ->expect('App\Http\Controllers')
    ->not->toUse([DB::class, Http::class, Event::class, Bus::class]);

arch('Actions do not depend on the HTTP transport layer')
    ->expect('App\Actions')
    ->not->toUse('Illuminate\Http');

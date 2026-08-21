<?php

declare(strict_types=1);

it('reports that the application is running', function (): void {
    $this->get('/')
        ->assertOk()
        ->assertExactJson([
            'name' => 'Achievement Rewards Service',
            'status' => 'ok',
        ]);
});

it('exposes the framework health endpoint', function (): void {
    $this->get('/up')->assertOk();
});

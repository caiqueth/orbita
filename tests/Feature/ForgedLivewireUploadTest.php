<?php

declare(strict_types=1);

namespace Tests\Feature;

use Livewire\Livewire;
use Livewire\Mechanisms\HandleRequests\EndpointResolver;

use function Pest\Laravel\postJson;

it('forged _startUpload on a component without uploads is a 400, not a 500', function () {
    // Testable exposes the snapshot only through __get(), which PHPStan cannot see.
    /** @phpstan-ignore property.notFound */
    $snapshot = Livewire::test('reactions', [
        'type' => 'post',
        'id' => 1,
        'summary' => ['reactions' => [], 'score' => 0, 'count' => 0],
        'reported' => false,
    ])->snapshot;

    postJson(EndpointResolver::updatePath(), [
        'components' => [[
            'snapshot' => json_encode($snapshot),
            'updates' => [],
            'calls' => [[
                'method' => '_startUpload',
                'params' => ['x.phtml', [['name' => 'x.phtml', 'size' => 128, 'type' => 'image/png']], false],
                'path' => '',
            ]],
        ]],
    ], ['X-Livewire' => 'true'])->assertBadRequest();
});

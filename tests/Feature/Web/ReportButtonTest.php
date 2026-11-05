<?php

declare(strict_types=1);

/**
 * The report action inside the reactions dropdown and its triage rows.
 */

namespace Tests\Feature\Web;

use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use App\Services\ReactionService;
use Database\Seeders\ReactionTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;
use function Pest\Laravel\seed;

uses(RefreshDatabase::class);

/**
 * Monotonic across the file, so hashids never collide between tests.
 */
function reportButtonSeq(): int
{
    static $n = 0;

    return ++$n;
}

function reportablePost(): Post
{
    $author = User::factory()->createOne(['username' => 'autor_report', 'email_verified_at' => now()]);

    return Post::create([
        'user_id' => $author->id, 'hashid' => 'rep'.reportButtonSeq(), 'title' => 'Assunto',
        'slug' => 'assunto-'.uniqid(), 'content' => 'corpo', 'status' => 'published',
        'published_at' => now(),
    ]);
}

it('guest is sent to login when reporting', function () {
    $post = reportablePost();

    Livewire::test('reactions', ['type' => 'post', 'id' => (int) $post->id])
        ->call('report')
        ->assertRedirect(route('login', ['redirect_to' => url()->previous()]));

    expect(Report::count())->toBe(0);
});

it('an authenticated user reports a post with an optional reason', function () {
    seed(ReactionTypeSeeder::class);
    $user = User::factory()->createOne(['email_verified_at' => now()]);
    $post = reportablePost();

    Livewire::actingAs($user)
        ->test('reactions', ['type' => 'post', 'id' => (int) $post->id])
        ->assertSet('reported', false)
        ->set('reason', 'conteúdo ilegal')
        ->call('report')
        ->assertSet('reported', true)
        ->assertSet('reason', '');

    assertDatabaseHas('reports', [
        'user_id' => $user->id,
        'reportable_type' => 'post',
        'reportable_id' => $post->id,
        'reason' => 'conteúdo ilegal',
        'read_at' => null,
    ]);
});

it('reporting without a reason works', function () {
    seed(ReactionTypeSeeder::class);
    $user = User::factory()->createOne(['email_verified_at' => now()]);
    $post = reportablePost();

    Livewire::actingAs($user)
        ->test('reactions', ['type' => 'post', 'id' => (int) $post->id])
        ->call('report')
        ->assertSet('reported', true);

    expect(Report::sole()->reason)->toBeNull();
});

it('reporting twice creates a single row', function () {
    seed(ReactionTypeSeeder::class);
    $user = User::factory()->createOne(['email_verified_at' => now()]);
    $post = reportablePost();

    Livewire::actingAs($user)
        ->test('reactions', ['type' => 'post', 'id' => (int) $post->id])
        ->call('report')
        ->assertSet('reported', true)
        ->call('report')
        ->assertSet('reported', true);

    expect(Report::count())->toBe(1);
});

it('the reason is capped at 500 chars', function () {
    seed(ReactionTypeSeeder::class);
    $user = User::factory()->createOne(['email_verified_at' => now()]);
    $post = reportablePost();

    Livewire::actingAs($user)
        ->test('reactions', ['type' => 'post', 'id' => (int) $post->id])
        ->set('reason', str_repeat('a', 501))
        ->call('report')
        ->assertHasErrors('reason');

    expect(Report::count())->toBe(0);
});

it('an already reported target shows the reportado state', function () {
    seed(ReactionTypeSeeder::class);
    $user = User::factory()->createOne(['email_verified_at' => now()]);
    $post = reportablePost();

    Report::create([
        'user_id' => $user->id,
        'reportable_type' => 'post',
        'reportable_id' => $post->id,
    ]);

    // Self-lookup (post page) and batched (feed/comment-tree) both light the state.
    Livewire::actingAs($user)
        ->test('reactions', ['type' => 'post', 'id' => (int) $post->id])
        ->assertSet('reported', true)
        ->assertSee('Reportado');

    Livewire::actingAs($user)
        ->test('reactions', ['type' => 'post', 'id' => (int) $post->id, 'reported' => true])
        ->assertSee('Reportado');
});

it('the report reaction type is gone', function () {
    seed(ReactionTypeSeeder::class);

    assertDatabaseMissing('reaction_types', ['slug' => 'report']);

    foreach (['user', 'moderator', 'admin'] as $role) {
        $slugs = app(ReactionService::class)->typesForRole($role)->pluck('slug')->all();
        expect($slugs)->not->toContain('report');
    }
});

it('rejects client writes to server-owned reaction state', function (string $property, mixed $value) {
    $post = reportablePost();

    expect(fn () => Livewire::test('reactions', ['type' => 'post', 'id' => (int) $post->id])
        ->set($property, $value))
        ->toThrow(CannotUpdateLockedPropertyException::class);
})->with([
    'nested reactions count' => ['reactions.like', ['x']],
    'whole reactions map' => ['reactions', ['like' => ['x']]],
    'count' => ['count', 99],
    'userReaction' => ['userReaction', 'like'],
    'reported' => ['reported', true],
    'announcement' => ['announcement', 'spoof'],
]);

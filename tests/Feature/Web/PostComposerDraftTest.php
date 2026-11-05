<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\Media;
use App\Models\Post;
use App\Models\PostDraft;
use App\Models\User;
use App\Support\HashId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;
use function Pest\Laravel\assertDatabaseCount;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;

uses(RefreshDatabase::class);

function draftMedia(User $owner): Media
{
    return Media::create([
        'file_hash' => bin2hex(random_bytes(16)),
        'file_name' => 'foto.jpg',
        'mime_type' => 'image/jpeg',
        'file_extension' => 'jpg',
        'file_type' => 'image',
        'media_type' => Media::TYPE_POST,
        'path' => 'posts/'.bin2hex(random_bytes(8)).'.jpg',
        'file_size' => 1024,
        'uploaded_by' => $owner->id,
    ]);
}

function draftUser(): User
{
    $user = User::factory()->createOne(['email_verified_at' => now()]);
    actingAs($user);

    return $user;
}

it('saveDraft keeps a single draft per user and updates it', function () {
    $user = draftUser();

    $component = Livewire::test('⚡post-composer')
        ->set('title', 'Primeira versão')
        ->set('content', 'Texto inicial')
        ->call('saveDraft');

    expect($component->get('draftSavedAt'))->not->toBeNull();

    $component->set('content', 'Texto revisado')->call('saveDraft');

    assertDatabaseCount('post_drafts', 1);
    assertDatabaseHas('post_drafts', [
        'user_id' => $user->id,
        'title' => 'Primeira versão',
        'content' => 'Texto revisado',
    ]);
});

it('an empty composer deletes the draft instead of saving it', function () {
    $user = draftUser();
    PostDraft::create(['user_id' => $user->id, 'title' => 'Velho', 'content' => 'x']);

    Livewire::test('⚡post-composer')
        ->set('title', '')
        ->set('content', '')
        ->call('saveDraft')
        ->assertSet('draftSavedAt', null);

    assertDatabaseCount('post_drafts', 0);
});

it('a draft does not create posts nor count for antispam', function () {
    draftUser();

    Livewire::test('⚡post-composer')
        ->set('title', 'Um post escrito com calma')
        ->set('content', 'Conteúdo do post.')
        ->call('saveDraft')
        ->call('saveDraft')
        ->call('save')
        ->assertHasNoErrors();

    expect(Post::query()->where('status', '!=', 'revision')->count())->toBe(1);
});

it('opening the composer restores the saved draft', function () {
    $user = draftUser();
    PostDraft::create(['user_id' => $user->id, 'title' => 'Rascunho salvo', 'content' => 'Meio do texto']);

    Livewire::test('⚡post-composer')
        ->assertSet('title', 'Rascunho salvo')
        ->assertSet('content', 'Meio do texto')
        ->assertSet('isEdit', false)
        ->assertNotSet('draftSavedAt', null);
});

it('restoring a draft ignores media uploaded by someone else', function () {
    $user = draftUser();
    $other = User::factory()->createOne();

    $mine = draftMedia($user);
    $theirs = draftMedia($other);

    PostDraft::create([
        'user_id' => $user->id,
        'title' => 'Com imagens',
        'content' => 'x',
        'media_ids' => [$mine->id, $theirs->id, 999999],
    ]);

    Livewire::test('⚡post-composer')->assertSet('pendingMediaIds', [$mine->id]);
});

it('an uploaded image is kept in the draft and attached when the post is published', function () {
    Storage::fake('local');
    $user = draftUser();

    Livewire::test('⚡post-composer')
        ->set('title', 'Post com imagem')
        ->set('content', 'Conteúdo')
        ->set('pendingImage', UploadedFile::fake()->image('foto.jpg', 800, 600))
        ->call('insertImage')
        ->assertHasNoErrors();

    $media = Media::query()->firstOrFail();
    expect(PostDraft::query()->where('user_id', $user->id)->value('media_ids'))->toBe([$media->id]);

    // Simulates a reload: a fresh component picks the media back up from the draft.
    Livewire::test('⚡post-composer')
        ->assertSet('pendingMediaIds', [$media->id])
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('post-created');

    $post = Post::query()->latest('id')->firstOrFail();
    assertDatabaseHas('media_relationship', ['post_id' => $post->id, 'media_id' => $media->id]);
    assertDatabaseCount('post_drafts', 0);
});

it('pendingMediaIds cannot be set from the client', function () {
    draftUser();

    Livewire::test('⚡post-composer')->set('pendingMediaIds', [1]);
})->throws(CannotUpdateLockedPropertyException::class);

it('discardDraft removes the draft and clears the composer', function () {
    $user = draftUser();
    PostDraft::create(['user_id' => $user->id, 'title' => 'Descartável', 'content' => 'x']);

    Livewire::test('⚡post-composer')
        ->call('discardDraft')
        ->assertSet('title', '')
        ->assertSet('content', '')
        ->assertSet('draftSavedAt', null)
        ->assertDispatched('draft-discarded');

    assertDatabaseMissing('post_drafts', ['user_id' => $user->id]);
});

it('editing an existing post neither loads nor saves drafts', function () {
    $user = draftUser();
    PostDraft::create(['user_id' => $user->id, 'title' => 'Rascunho de post novo', 'content' => 'x']);

    $post = Post::create([
        'user_id' => $user->id,
        'hashid' => HashId::encode(1),
        'title' => 'Post existente',
        'slug' => 'post-existente',
        'content' => 'Conteúdo original.',
        'status' => 'published',
        'published_at' => now(),
    ]);

    Livewire::test('⚡post-composer', ['post' => $post])
        ->assertSet('title', 'Post existente')
        ->set('title', 'Editado')
        ->call('saveDraft');

    assertDatabaseHas('post_drafts', ['user_id' => $user->id, 'title' => 'Rascunho de post novo']);
});

it('an unverified account cannot save drafts', function () {
    $user = User::factory()->unverified()->createOne();
    actingAs($user);

    Livewire::test('⚡post-composer')
        ->set('title', 'Tentativa')
        ->set('content', 'x')
        ->call('saveDraft');

    assertDatabaseCount('post_drafts', 0);
});

it('the prune command deletes only stale drafts', function () {
    $old = User::factory()->createOne();
    $recent = User::factory()->createOne();

    $stale = PostDraft::create(['user_id' => $old->id, 'title' => 'Antigo']);
    $stale->forceFill(['updated_at' => now()->subDays(31)])->saveQuietly();
    PostDraft::create(['user_id' => $recent->id, 'title' => 'Recente']);

    artisan('orbita:post-drafts-prune')->assertSuccessful();

    assertDatabaseMissing('post_drafts', ['user_id' => $old->id]);
    assertDatabaseHas('post_drafts', ['user_id' => $recent->id]);
});

<?php

use App\Livewire\Attributes\RequiresActivatedAccount;
use App\Models\Media;
use App\Models\Post;
use App\Models\PostDraft;
use App\Services\OembedService;
use App\Services\PostService;
use App\Support\Slug;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Create/edit a post: title, external URL and Markdown content with inline image
 * uploads (button / drag-drop / paste in the editor), plus a live oEmbed preview.
 *
 * All business rules are delegated: antispam + slug/hashid + revision snapshots
 * to PostService, image store to ImageService, embed resolution to OembedService.
 * Inline image count is capped by config('orbita.limit_images_post').
 */
new class extends Component
{
    use WithFileUploads;

    #[Locked]
    public ?int $postId = null;

    #[Locked]
    public ?string $postHashid = null;

    #[Locked]
    public ?string $postSlug = null;

    public string $title = '';

    public string $url = '';

    public string $content = '';

    /** Inline-editor upload: the file currently being processed (Livewire temp). */
    public $pendingImage = null;

    /**
     * media_ids inserted inline into a NEW post, attached to the pivot on save.
     * Locked: only the server appends to it, so a client cannot claim someone else's media.
     */
    #[Locked]
    public array $pendingMediaIds = [];

    /** ISO timestamp of the last server-side draft save (new posts only). */
    #[Locked]
    public ?string $draftSavedAt = null;

    public ?string $oembedHtml = null;

    /** oEmbed `type` (video|rich|photo|link); only `video` gets the forced 16:9 box. */
    public ?string $oembedType = null;

    /** URL the current preview belongs to, so typing does not refetch what we already have. */
    #[Locked]
    public ?string $oembedFetchedUrl = null;

    public bool $isEdit = false;

    public function mount(?Post $post = null): void
    {
        if ($post !== null && $post->exists) {
            $this->postId = (int) $post->id;
            $this->postHashid = (string) $post->hashid;
            $this->postSlug = $post->slug;
            $this->title = (string) $post->title;
            $this->url = (string) ($post->url ?? '');
            $this->content = (string) $post->content;
            $this->isEdit = true;
            $this->refreshOembed();

            return;
        }

        $this->restoreDraft();
    }

    private function restoreDraft(): void
    {
        $draft = PostDraft::query()->where('user_id', (int) auth()->id())->first();
        if ($draft === null) {
            return;
        }

        $this->title = (string) ($draft->title ?? '');
        $this->url = (string) ($draft->url ?? '');
        $this->content = (string) ($draft->content ?? '');
        $this->pendingMediaIds = $this->ownedMediaIds((array) ($draft->media_ids ?? []));
        $this->draftSavedAt = $draft->updated_at?->toIso8601String();
        $this->refreshOembed();
    }

    /**
     * Keeps only media this user uploaded that still exist and are not attached to a post.
     *
     * @param  array<int, mixed>  $ids
     * @return array<int, int>
     */
    private function ownedMediaIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($ids === []) {
            return [];
        }

        $valid = Media::query()
            ->whereIn('id', $ids)
            ->where('uploaded_by', (int) auth()->id())
            ->whereNotExists(fn ($q) => $q->selectRaw('1')
                ->from('media_relationship')
                ->whereColumn('media_relationship.media_id', 'media.id'))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return array_values(array_filter($ids, fn (int $id) => in_array($id, $valid, true)));
    }

    /**
     * Auto-save of a NEW post being written. Called periodically by the client
     * (title/content travel with the request, since they are deferred wire:models).
     * Returns the ISO save time, or null when nothing was saved.
     */
    #[RequiresActivatedAccount(silent: true)]
    public function saveDraft(): ?string
    {
        if ($this->isEdit) {
            return null;
        }

        $userId = (int) auth()->id();
        $key = 'post-draft:'.$userId;
        if (RateLimiter::tooManyAttempts($key, 30)) {
            return null;
        }
        RateLimiter::hit($key, 60);

        $title = mb_substr($this->title, 0, (int) config('orbita.posts.max_title', 250));
        $url = mb_substr(trim($this->url), 0, (int) config('orbita.posts.max_url_length', 2048));
        $content = mb_substr($this->content, 0, (int) config('orbita.posts.max_content', 50000));

        if (trim($title) === '' && $url === '' && trim($content) === '' && $this->pendingMediaIds === []) {
            PostDraft::query()->where('user_id', $userId)->delete();
            $this->draftSavedAt = null;

            return null;
        }

        $draft = PostDraft::query()->updateOrCreate(['user_id' => $userId], [
            'title' => $title,
            'url' => $url !== '' ? $url : null,
            'content' => $content,
            'media_ids' => array_values($this->pendingMediaIds),
        ]);
        $draft->touch();

        return $this->draftSavedAt = $draft->updated_at->toIso8601String();
    }

    /** Throws away the auto-saved draft and the images uploaded only for it. */
    public function discardDraft(): void
    {
        if ($this->isEdit) {
            return;
        }

        $userId = (int) auth()->id();
        PostDraft::query()->where('user_id', $userId)->delete();

        $orphans = Media::query()->whereIn('id', $this->ownedMediaIds($this->pendingMediaIds))->get();
        foreach ($orphans as $media) {
            try {
                app(\App\Services\ImageService::class)->deleteImage((string) $media->path, (int) $media->id, $userId);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        $this->reset('title', 'url', 'content', 'pendingMediaIds', 'draftSavedAt', 'oembedHtml', 'oembedType', 'oembedFetchedUrl');
        $this->resetErrorBag();
        $this->dispatch('editor-reset')->el('.markdown-editor');
        $this->dispatch('draft-discarded');
    }

    public function updatedUrl(): void
    {
        $this->refreshOembed();
    }

    #[RequiresActivatedAccount(silent: true)]
    public function insertImage(): string
    {
        if (! $this->canUploadImage()) {
            return '';
        }

        $maxKb = (int) (((int) config('orbita.posts.image_max_size', 10485760)) / 1024);

        try {
            $this->validate([
                'pendingImage' => ['required', 'image', 'mimes:'.\App\Support\ImageFormats::mimesRule(), 'max:'.$maxKb],
            ]);
        } catch (\Illuminate\Validation\ValidationException) {
            $this->pendingImage = null;

            return '';
        }

        $userId = (int) auth()->id();

        try {
            $result = app(\App\Services\ImageService::class)->uploadImage([
                'name' => $this->pendingImage->getClientOriginalName(),
                'tmp_name' => $this->pendingImage->getRealPath(),
                'size' => $this->pendingImage->getSize(),
                'type' => $this->pendingImage->getMimeType(),
            ], 'posts', $userId);

            $mediaId = (int) ($result['media_id'] ?? 0);
            $this->pendingImage = null;

            if (! ($result['success'] ?? false) || $mediaId === 0) {
                return '';
            }

            if ($this->isEdit && $this->postId !== null) {
                $post = Post::query()->find($this->postId);
                if ($post !== null) {
                    $order = (int) $post->media()->count();
                    $post->media()->syncWithoutDetaching([$mediaId => ['display_order' => $order]]);
                }
            } else {
                $this->pendingMediaIds[] = $mediaId;
                $this->saveDraft();
            }

            $media = \App\Models\Media::query()->find($mediaId);

            return $media !== null ? \App\Support\ImageUrl::original((string) $media->path) : '';
        } catch (\Throwable $e) {
            report($e);
            $this->pendingImage = null;
            $this->addError('content', 'Não foi possível enviar a imagem. Tente novamente.');

            return '';
        }
    }

    private function canUploadImage(): bool
    {
        $limit = (int) config('orbita.limit_images_post', 5);
        $existing = $this->isEdit && $this->postId !== null
            ? (int) Post::query()->find($this->postId)?->media()->count()
            : 0;

        return (count($this->pendingMediaIds) + $existing) < $limit;
    }

    private function refreshOembed(): void
    {
        $url = trim($this->url);

        if ($this->oembedFetchedUrl === $url && $this->oembedHtml !== null) {
            return;
        }

        $this->oembedHtml = null;
        $this->oembedType = null;
        $this->oembedFetchedUrl = null;

        if ($url === '' || ! Str::isUrl($url, ['http', 'https'])) {
            return;
        }

        try {
            $service = app(OembedService::class);
            $provider = $service->checkUrl($url);
            if ($provider === null) {
                return;
            }

            $data = $service->fetchOembed($url, $provider['provider']);
            if (! empty($data['html'])) {
                $this->oembedHtml = $data['html'];
                $this->oembedType = $data['type'] ?? null;
                $this->oembedFetchedUrl = $url;
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    #[RequiresActivatedAccount]
    public function save(PostService $postService): void
    {
        $this->validate([
            'title' => ['required', 'string', 'max:250'],
            'url' => ['nullable', 'url', 'max:2048'],
            'content' => ['nullable', 'string', 'max:50000'],
        ]);

        $url = trim($this->url);
        $content = $this->content;

        if ($url === '' && trim($content) === '') {
            $this->addError('content', 'Informe uma URL ou um conteúdo.');

            return;
        }

        $userId = (int) auth()->id();

        try {
            if ($this->isEdit) {
                $post = Post::query()->findOrFail($this->postId);
                try {
                    $postService->assertWithinEditWindow($post);
                } catch (\Illuminate\Validation\ValidationException $e) {
                    $this->addError('title', $e->validator->errors()->first());

                    return;
                }

                try {
                    $postService->updatePost($post, [
                        'title' => $this->title,
                        'url' => $url !== '' ? $url : null,
                        'content' => $content,
                    ]);
                } catch (\RuntimeException $e) {
                    $this->addError('content', $e->getMessage());

                    return;
                }
            } else {
                $antispam = $postService->checkAntispam($userId, $this->title, $url !== '' ? $url : null, $content);
                if (! $antispam['allowed']) {
                    $this->addError('title', $antispam['reason'] ?? 'Muitos posts.');

                    return;
                }

                try {
                    $post = $postService->createPost([
                        'user_id' => $userId,
                        'title' => $this->title,
                        'url' => $url !== '' ? $url : null,
                        'content' => $content,
                        'status' => 'published',
                    ]);
                } catch (\RuntimeException $e) {
                    $this->addError('content', $e->getMessage());

                    return;
                }
            }

            if (! $this->isEdit && $this->pendingMediaIds !== [] && isset($post)) {
                $order = (int) $post->media()->count();
                $attach = [];
                foreach ($this->pendingMediaIds as $mediaId) {
                    $attach[(int) $mediaId] = ['display_order' => $order++];
                }
                $post->media()->syncWithoutDetaching($attach);
            }

            if (! $this->isEdit) {
                PostDraft::query()->where('user_id', $userId)->delete();
                $this->dispatch('post-created');
            }
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            //
            throw $e;
        } catch (\Throwable $e) {
            report($e);
            $this->addError('title', 'Não foi possível salvar agora. Tente novamente.');

            return;
        }

        $this->redirectRoute('posts.show', ['hashid' => $post->hashid, 'slug' => $post->slug]);
    }
}; ?>

<div @if (! $isEdit)
         x-data="postAutosave(@js([
             'storageKey' => 'orbita:post-draft:'.auth()->id(),
             'syncSeconds' => (int) config('orbita.posts.draft_sync_seconds', 15),
         ]))"
         x-on:input="markDirty()"
         x-on:post-created.window="clearLocal()"
         x-on:draft-discarded.window="clearLocal()"
     @endif>
    <form wire:submit="save" class="space-y-5">
        <div>
            <label for="pc-title" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">
                Título <span class="text-red-500">*</span>
            </label>
            <input type="text" id="pc-title" wire:model="title" maxlength="250"
                   placeholder="Digite um título..."
                   class="w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 placeholder-gray-400 focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100">
            @error('title') <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="pc-url" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">
                URL externa
            </label>
            <input type="url" id="pc-url" wire:model.live.debounce.700ms="url"
                   placeholder="https://exemplo.com/artigo"
                   class="w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 placeholder-gray-400 focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100">
            @error('url') <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror

            <div aria-live="polite">
                <div wire:loading.delay wire:target="url" class="mt-2 text-sm text-gray-500 dark:text-gray-400">Buscando pré-visualização...</div>

                @if ($oembedHtml)
                    <div wire:loading.remove wire:target="url" class="mt-3">
                        <p class="mb-1 text-sm text-gray-500 dark:text-gray-400">Pré-visualização</p>
                        <div class="oembed-frame overflow-hidden rounded-md border border-gray-200 dark:border-gray-800 {{ $oembedType === 'video' ? 'aspect-video' : '' }}">
                            {!! $oembedHtml !!}
                        </div>
                    </div>
                @endif
            </div>
        </div>

        <div>
            <label for="pc-content" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">
                Conteúdo (Markdown) <span class="text-red-500">*</span>
            </label>
            <x-markdown-editor id="pc-content" wire:model="content" rows="10"
                               placeholder="Escreva seu conteúdo aqui..."
                               allow-image
                               textarea-class="min-h-[320px]"
                               preview-class="min-h-[320px] max-w-none md-content--post" />
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                {{ \App\Support\ImageFormats::label() }}. Máx {{ (int) (((int) config('orbita.posts.image_max_size', 10485760)) / 1048576) }} MB por imagem. Limite: {{ config('orbita.limit_images_post', 5) }} imagens.
            </p>
            @error('content') <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
        </div>

        <div class="flex flex-wrap items-center justify-end gap-2">
            @unless ($isEdit)
                <p class="mr-auto text-sm text-gray-500 dark:text-gray-400" role="status" aria-live="polite" x-text="statusText" x-cloak></p>
                <template x-if="hasDraft">
                    <button type="button" @click="discard()"
                            class="rounded-md px-3 py-2 text-sm font-medium text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800"
                            x-text="confirmingDiscard ? 'Clique de novo para descartar' : 'Descartar rascunho'"></button>
                </template>
            @endunless
            @if ($isEdit && $postHashid)
                <a href="{{ route('posts.show', ['hashid' => $postHashid, 'slug' => $postSlug]) }}" wire:navigate
                   class="rounded-md px-4 py-2 text-sm font-medium text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800">Cancelar</a>
            @endif
            <button type="submit" wire:loading.attr="disabled" wire:target="save"
                    class="rounded-md bg-primary-600 px-5 py-2 text-sm font-semibold text-white hover:bg-primary-700 disabled:opacity-50">
                <span wire:loading.remove wire:target="save">{{ $isEdit ? 'Atualizar post' : 'Criar post' }}</span>
                <span wire:loading wire:target="save">Salvando...</span>
            </button>
        </div>
    </form>
</div>

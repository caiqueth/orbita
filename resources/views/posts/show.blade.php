@php
    $author = $post->user?->authorName() ?? 'Anônimo';
    $domain = \App\Support\Url::domain($post->url);
    $faviconUrl = \App\Support\Favicon::url($domain);
    $paywallUrl = \App\Support\PaywallBypass::wrap($post->url);
    $translateUrl = \App\Support\AutoTranslate::wrap($post->url, $post->title);
    $canonicalUrl = route('posts.show', ['hashid' => $post->hashid, 'slug' => $post->slug]);
    $postUrl = $post->url;

    $commentPage = max(1, (int) request()->query(\App\Support\Url::COMMENT_PAGE, 1));
    $canonicalWithPage = $commentPage > 1
        ? $canonicalUrl.'?'.\App\Support\Url::COMMENT_PAGE.'='.$commentPage
        : $canonicalUrl;
@endphp

<x-layouts.app :title="$post->title" :description="$meta['description'] ?? null" :canonical="$canonicalWithPage">
    @push('head')
        <x-meta-og :meta="$meta" :url="$canonicalUrl" />
        <x-pagination-head :total="$commentRootCount" :per-page="(int) config('orbita.comments.per_page', 20)"
                           :page-name="\App\Support\Url::COMMENT_PAGE" />
    @endpush

    <div class="space-y-6">
        <article>
            <h1 class="font-serif font-bold leading-tight text-balance break-words text-gray-900 dark:text-gray-100"
                style="font-size: clamp(1.5rem, 1.2rem + 1.6vw, 2rem);">{{ $post->title }}</h1>

            @php $postTags = \App\Support\FeedRowMeta::tagsFor([(int) $post->id])[$post->id] ?? []; @endphp
            @if ($postTags !== [])
                <p class="mt-2 flex flex-wrap gap-x-3 font-mono text-sm">
                    @foreach ($postTags as $tag)
                        <a href="{{ route('tags.show', ['slug' => $tag->slug]) }}" wire:navigate
                           class="text-primary-700 hover:underline dark:text-primary-300">#{{ $tag->name }}</a>
                    @endforeach
                </p>
            @endif

            @php
                $publishedAt = $post->published_at ?? $post->created_at;
                $reactionScore = (int) ($reactionSummary['score'] ?? 0);
                $commentCount = (int) ($post->comment_count ?? 0);
                $showScore = (bool) config('orbita.posts.show_score', true);
            @endphp

            <div class="mt-4 flex items-center gap-3 border-b border-gray-100 pb-4 dark:border-gray-800">
                <div class="shrink-0">
                    @if ($post->user)
                        @if ($post->user->isLinkable())
                            <a href="{{ route('users.profile', ['username' => $post->user->username]) }}" wire:navigate tabindex="-1" aria-hidden="true">
                                <x-avatar :user="$post->user" class="h-[30px] w-[30px] sm:h-[42px] sm:w-[42px]" />
                            </a>
                        @else
                            <x-avatar :user="$post->user" class="h-[30px] w-[30px] sm:h-[42px] sm:w-[42px]" />
                        @endif
                    @else
                        <span class="block h-[30px] w-[30px] rounded-full bg-gray-200 sm:h-[42px] sm:w-[42px] dark:bg-gray-800" aria-hidden="true"></span>
                    @endif
                </div>

                <div class="flex min-w-0 flex-col gap-0.5">
                    <div class="flex flex-wrap items-center gap-x-1.5">
                        @if ($post->user?->isLinkable())
                            <a href="{{ route('users.profile', ['username' => $post->user->username]) }}" wire:navigate
                               class="text-sm font-semibold text-gray-900 hover:text-primary-600 dark:text-gray-100 dark:hover:text-primary-400">{{ $author }}</a>
                            <span aria-hidden="true" class="font-mono text-sm text-gray-500 dark:text-gray-400">&middot;</span>
                            <span class="font-mono text-sm text-gray-500 dark:text-gray-400">&#64;{{ $post->user->username }}</span>
                        @else
                            <span class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $author }}</span>
                        @endif
                    </div>

                    <div class="flex flex-wrap items-center gap-x-2.5 gap-y-1 font-mono text-sm text-gray-500 dark:text-gray-400">
                        <x-date-time :value="$publishedAt" />

                        @if ($showScore)
                        <span aria-hidden="true">&middot;</span>
                        <span x-data="{ score: {{ $reactionScore }} }"
                              x-on:reaction-score-updated.window="if ($event.detail.type === 'post' && $event.detail.id === {{ $post->id }}) score = $event.detail.score"
                              x-text="score > 0 ? '+' + score : score"
                              :aria-label="`${score} ${Math.abs(score) === 1 ? 'ponto' : 'pontos'}`"
                              aria-label="{{ $reactionScore }} {{ abs($reactionScore) === 1 ? 'ponto' : 'pontos' }}"
                              @class([
                                  'font-semibold tabular-nums',
                                  'text-positive' => $reactionScore > 0,
                                  'text-gray-500 dark:text-gray-400' => $reactionScore === 0,
                                  'text-red-600 dark:text-red-400' => $reactionScore < 0,
                              ])
                              :class="{
                                  'text-positive': score > 0,
                                  'text-gray-500 dark:text-gray-400': score === 0,
                                  'text-red-600 dark:text-red-400': score < 0,
                              }">{{ $reactionScore > 0 ? '+'.$reactionScore : $reactionScore }}</span>
                        @endif

                        @if ($post->edited_at)
                            <span aria-hidden="true">&middot;</span>
                            @if ($canEdit || $canDelete)
                                <a href="{{ route('posts.revisions', ['hashid' => $post->hashid]) }}" wire:navigate
                                   class="rounded bg-gray-100 px-1.5 py-0.5 hover:bg-gray-200 dark:bg-gray-800 dark:hover:bg-gray-700"
                                   title="Editado - ver revisões">editado</a>
                            @else
                                <span class="rounded bg-gray-100 px-1.5 py-0.5 dark:bg-gray-800">editado</span>
                            @endif
                        @endif

                        @if ($canEdit || $canDelete || $canAdmin)
                            <div class="relative -my-2" x-data="{ menu: false }" @keydown.escape="menu = false; $refs.trigger.focus()">
                                <button type="button" x-ref="trigger" @click="menu = !menu"
                                        aria-label="Mais ações" aria-haspopup="true" :aria-expanded="menu ? 'true' : 'false'"
                                        class="inline-flex items-center justify-center rounded-md p-3.5 text-gray-500 hover:bg-gray-100 hover:text-gray-900 dark:text-gray-400 dark:hover:bg-gray-800 dark:hover:text-gray-100">
                                    <x-heroicon-o-ellipsis-horizontal class="h-4 w-4" aria-hidden="true" />
                                </button>

                                <div x-show="menu" x-transition @click.outside="menu = false" x-cloak
                                     class="absolute left-0 z-20 mt-1 w-40 overflow-hidden rounded-md border border-gray-200 bg-white font-sans shadow-lg dark:border-gray-700 dark:bg-gray-800">
                                    @if ($canEdit)
                                        <a href="{{ route('posts.edit', ['hashid' => $post->hashid]) }}" wire:navigate
                                            class="flex items-center gap-2 px-3 py-3 text-sm text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-700">
                                            <x-heroicon-o-pencil-square class="h-4 w-4" aria-hidden="true" />
                                            Editar
                                        </a>
                                    @endif
                                    @if ($canAdmin)
                                        <a href="{{ \App\Filament\Resources\Posts\PostResource::getUrl('edit', ['record' => $post]) }}"
                                            class="flex items-center gap-2 px-3 py-3 text-sm text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-700">
                                            <x-heroicon-o-wrench-screwdriver class="h-4 w-4" aria-hidden="true" />
                                            Editar no admin
                                        </a>
                                    @endif
                                    @if ($canDelete)
                                        <form method="POST" action="{{ route('posts.destroy', ['hashid' => $post->hashid]) }}"
                                              x-data x-on:submit.prevent="
                                                  if (confirm('Deletar este post?')) { $el.submit() }
                                              ">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit"
                                                    class="flex w-full items-center gap-2 px-3 py-3 text-left text-sm text-red-600 hover:bg-gray-100 dark:text-red-400 dark:hover:bg-gray-700">
                                                <x-heroicon-o-trash class="h-4 w-4" aria-hidden="true" />
                                                Deletar
                                            </button>
                                        </form>
                                    @endif
                                </div>
                            </div>
                        @endif
                    </div>
                </div>
            </div>

            @if (! empty($post->url))
                @if ($oembedHtml)
                    <div class="oembed-frame mt-4 overflow-hidden rounded-lg border border-gray-200 dark:border-gray-800 {{ $oembedType === 'video' ? 'aspect-video' : '' }}">
                        {!! $oembedHtml !!}
                    </div>
                @endif

                <div class="mt-4 flex flex-wrap items-center gap-2">
                    <a href="{{ $post->url }}" target="_blank" rel="noopener noreferrer" title="{{ $postUrl }}"
                       class="inline-flex min-w-0 max-w-full items-center gap-1.5 rounded-md border border-primary-300 px-3 py-3 text-sm font-medium text-primary-700 hover:bg-primary-50 dark:border-primary-700 dark:text-primary-300 dark:hover:bg-primary-950">
                        @if ($faviconUrl)
                            <img src="{{ $faviconUrl }}" alt="" width="16" height="16" loading="lazy"
                                 class="h-4 w-4 shrink-0 rounded-sm"
                                 onerror="this.nextElementSibling.hidden = false; this.remove()">
                            <x-heroicon-o-link class="h-4 w-4 shrink-0" aria-hidden="true" hidden />
                        @else
                            <x-heroicon-o-link class="h-4 w-4 shrink-0" aria-hidden="true" />
                        @endif
                        <span class="truncate">{{ $postUrl }}</span>
                    </a>
                    @if ($paywallUrl && $paywallUrl !== $post->url)
                        <a href="{{ $paywallUrl }}" target="_blank" rel="noopener noreferrer"
                           class="inline-flex items-center gap-1.5 rounded-md border border-gray-300 px-3 py-3 text-sm font-medium text-gray-600 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800">
                            <x-heroicon-o-lock-open class="h-4 w-4" aria-hidden="true" />
                            Arquivo
                        </a>
                    @endif
                    @if ($translateUrl && $translateUrl !== $post->url)
                        <a href="{{ $translateUrl }}" target="_blank" rel="noopener noreferrer"
                           class="inline-flex items-center gap-1.5 rounded-md border border-gray-300 px-3 py-3 text-sm font-medium text-gray-600 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800">
                            <x-heroicon-o-language class="h-4 w-4" aria-hidden="true" />
                            Traduzir
                        </a>
                    @endif
                </div>
            @endif

            @if (trim($contentHtml) !== '')
                <div class="md-content md-content--post mt-4 max-w-[64ch] text-gray-800 dark:text-gray-200">
                    {!! \App\Support\InlineImages::decorate($contentHtml, $post->media, auth()->user(), \App\Support\InlineImages::POST_MAX) !!}
                </div>
            @endif

            <div class="mt-6 flex flex-wrap items-center gap-x-4 gap-y-1 border-t border-gray-100 pt-4 font-mono dark:border-gray-800">
                <livewire:reactions type="post" :id="$post->id" :compact="false"
                                    :summary="$reactionSummary" :key="'react-post-'.$post->id" />

                <a href="#comentarios"
                   aria-label="{{ $commentCount === 0 && $post->allow_comments ? 'Comentar' : $commentCount.' '.($commentCount === 1 ? 'comentário' : 'comentários') }}"
                   class="inline-flex items-center gap-1.5 py-3 text-sm text-gray-500 transition-colors hover:text-primary-600 dark:text-gray-400 dark:hover:text-primary-400">
                    <x-heroicon-o-chat-bubble-left class="h-4 w-4" aria-hidden="true" />
                    @if ($commentCount === 0 && $post->allow_comments)
                        <span aria-hidden="true">Comentar</span>
                    @else
                        <span class="font-mono tabular-nums" aria-hidden="true">{{ $commentCount }}</span>
                        <span aria-hidden="true">{{ $commentCount === 1 ? 'comentário' : 'comentários' }}</span>
                    @endif
                </a>

                <x-bookmark-button :hashid="$post->hashid" :bookmarked="$isBookmarked" :compact="false" />

                <x-share-button :url="route('posts.show', ['hashid' => $post->hashid, 'slug' => $post->slug])"
                                :title="$post->title" :compact="false" />
            </div>
        </article>

        <section id="comentarios" class="scroll-mt-20 space-y-4">
            @php
                $closeDays = (int) config('orbita.posts.close_inactive_days');
                $closedNotice = match (true) {
                    (bool) $post->allow_comments => null,
                    $post->status === 'closed' && $closeDays > 0 => "Os comentários fecharam depois de {$closeDays} dias sem atividade. A conversa fica aqui para leitura.",
                    default => 'Os comentários estão fechados para este post.',
                };
            @endphp

            @if ($post->allow_comments)
                <livewire:comment-form :post-id="$post->id" />
            @endif

            <livewire:comment-tree :post-id="$post->id" :allow-comments="$post->allow_comments" :closed-notice="$closedNotice" />
        </section>
    </div>
</x-layouts.app>

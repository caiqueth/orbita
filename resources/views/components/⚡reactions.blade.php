<?php

use App\Livewire\Attributes\RequiresActivatedAccount;
use App\Services\ReactionService;
use App\Services\ReportService;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component
{
    #[Locked]
    public string $type = 'post';

    #[Locked]
    public int $id = 0;

    /** @var array<string, int> slug => count */
    #[Locked]
    public array $reactions = [];

    #[Locked]
    public int $score = 0;

    /** Transient: filled by a reaction, empty otherwise. See announceScore(). */
    #[Locked]
    public string $announcement = '';

    #[Locked]
    public int $count = 0;

    #[Locked]
    public ?string $userReaction = null;

    #[Locked]
    public bool $compact = false;

    #[Locked]
    public bool $reported = false;

    public string $reason = '';

    #[Locked]
    public bool $showSummary = true;

    public function mount(string $type, int $id, bool $compact = false, ?array $summary = null, bool $showSummary = true, ?bool $reported = null): void
    {
        $this->type = $type;
        $this->id = $id;
        $this->compact = $compact;
        $this->showSummary = $showSummary;
        $this->reported = $reported ?? (
            auth()->check()
                ? app(ReportService::class)->hasReported((int) auth()->id(), $type, $id)
                : false
        );

        if ($summary !== null) {
            $this->reactions = $summary['reactions'] ?? [];
            $this->score = (int) ($summary['score'] ?? 0);
            $this->count = (int) ($summary['count'] ?? 0);
            $this->userReaction = $summary['user_reaction'] ?? null;

            return;
        }

        $this->refreshSummary();
    }

    /** @return array<int, array{slug: string, name: string, emoji: string}> */
    public function getAvailableProperty(): array
    {
        $role = auth()->user()?->role?->value ?? 'user';

        return app(ReactionService::class)->typesForRole($role)
            ->map(fn ($t) => ['slug' => $t->slug, 'name' => $t->name, 'emoji' => $t->emoji])
            ->all();
    }

    /** @return array<string, string> slug => emoji, for rendering summary badges */
    public function getEmojiMapProperty(): array
    {
        return collect($this->available)->pluck('emoji', 'slug')->all();
    }

    #[RequiresActivatedAccount]
    public function react(string $slug): void
    {
        $service = app(ReactionService::class);
        $user = auth()->user();
        $type = $service->usableType($slug, $user->role?->value ?? 'user');
        if ($type === null) {
            return;
        }

        $target = $service->findTarget($this->type, $this->id);
        if ($target === null) {
            return;
        }

        $result = $service->toggle((int) $user->id, $this->type, $this->id, $type, $target);
        $this->applySummary($result);
        $this->announceScore();
    }

    #[RequiresActivatedAccount(silent: true)]
    public function removeReaction(): void
    {
        app(ReactionService::class)->remove((int) auth()->id(), $this->type, $this->id);
        $this->refreshSummary();
        $this->announceScore();
    }

    #[RequiresActivatedAccount]
    public function report(): void
    {
        $this->validate(['reason' => ['nullable', 'string', 'max:500']]);

        $report = app(ReportService::class)->report(
            (int) auth()->id(),
            $this->type,
            $this->id,
            $this->reason,
        );

        if ($report !== null) {
            $this->reported = true;
            $this->reason = '';
        }
    }

    /**
     * Reacting rewrites these buttons in place, so the count and the "you reacted" state used to
     * change with no announcement whatsoever.
     *
     * The message is transient — set by the action, empty on an ordinary render — because this
     * component is mounted once per post in the feed. A permanent live region here would mean
     * 25 of them on a page, each a candidate for spurious announcements; this way only the one
     * the user just operated has anything to say.
     */
    private function announceScore(): void
    {
        $name = $this->userReaction
            ? (collect($this->available)->firstWhere('slug', $this->userReaction)['name'] ?? $this->userReaction)
            : null;

        $this->announcement = trim(
            ($name !== null ? "Você reagiu com {$name}. " : 'Reação removida. ')
            .$this->count.' '.($this->count === 1 ? 'reação' : 'reações').' no total.'
        );

        $this->dispatch('reaction-score-updated', type: $this->type, id: $this->id, score: $this->score);
    }

    public function refreshSummary(): void
    {
        $this->applySummary(
            app(ReactionService::class)->summaryForUser($this->type, $this->id, auth()->id() ? (int) auth()->id() : null)
        );
    }

    /** @param array<string, mixed> $summary */
    private function applySummary(array $summary): void
    {
        $this->reactions = $summary['reactions'] ?? [];
        $this->score = (int) ($summary['score'] ?? 0);
        $this->count = (int) ($summary['count'] ?? 0);
        $this->userReaction = $summary['user_reaction'] ?? null;
    }
}; ?>

@php
    $reactor = auth()->user();
    $canReact = $reactor !== null && ! $reactor->isPendingActivation();
    $gateUrl = $reactor !== null
        ? route('verification.notice')
        : route('login', ['redirect_to' => url()->current()]);
    $gateVerb = $reactor !== null ? 'Ative sua conta para reagir' : 'Entrar para reagir';
@endphp

<div class="contents">
    <div class="flex flex-wrap items-center gap-2 text-sm">
    @if ($announcement !== '')
        <p role="status" aria-live="polite" class="sr-only">{{ $announcement }}</p>
    @endif

    @if ($showSummary && ! empty($reactions))
        @foreach ($reactions as $slug => $n)
            @php
                $name = collect($this->available)->firstWhere('slug', $slug)['name'] ?? $slug;
                $mine = $userReaction === $slug;
            @endphp

            @if ($canReact)
                <button type="button" wire:click="react('{{ $slug }}')"
                        aria-pressed="{{ $mine ? 'true' : 'false' }}"
                        aria-label="{{ $name }} ({{ $n }})"
                        @class([
                            'inline-flex min-h-8 items-center gap-1 rounded-full border px-2 font-mono text-sm tabular-nums transition data-loading:opacity-50 data-loading:pointer-events-none',
                            'border-primary-300 bg-primary-50 text-primary-700 dark:border-primary-700 dark:bg-primary-950 dark:text-primary-300' => $mine,
                            'border-transparent bg-gray-100 text-gray-600 hover:border-gray-300 dark:bg-gray-800 dark:text-gray-300 dark:hover:border-gray-600' => ! $mine,
                        ])>
                    <span aria-hidden="true" class="font-sans">{{ $this->emojiMap[$slug] ?? '•' }}</span>{{ $n }}
                </button>
            @else
                <a href="{{ $gateUrl }}" rel="nofollow" aria-label="{{ $gateVerb }} - {{ $name }} ({{ $n }})"
                   class="inline-flex min-h-8 items-center gap-1 rounded-full border border-transparent bg-gray-100 px-2 font-mono text-sm tabular-nums text-gray-600 hover:border-gray-300 dark:bg-gray-800 dark:text-gray-300 dark:hover:border-gray-600">
                    <span aria-hidden="true" class="font-sans">{{ $this->emojiMap[$slug] ?? '•' }}</span>{{ $n }}
                </a>
            @endif
        @endforeach
    @endif

    @if ($canReact)
        <div class="relative" x-data="{ open: false }" @keydown.escape="open = false; $refs.trigger.focus()">
            <button type="button" x-ref="trigger" @click="open = !open"
                    @class([
                        'inline-flex items-center gap-1.5 rounded-md text-sm font-medium text-gray-500 transition data-loading:pointer-events-none data-loading:opacity-50 dark:text-gray-400',
                        'justify-center bg-gray-100 p-3.5 hover:bg-gray-100 hover:text-gray-900 dark:bg-gray-800 dark:hover:bg-gray-800 dark:hover:text-gray-100' => $compact,
                        'min-w-6 justify-center px-1 py-3 hover:text-primary-600 dark:hover:text-primary-400' => ! $compact,
                    ])
                    aria-haspopup="true" :aria-expanded="open ? 'true' : 'false'"
                    aria-label="{{ $userReaction ? 'Alterar reação' : 'Reagir' }}">
                <x-heroicon-o-face-smile class="h-4 w-4" aria-hidden="true" />
            </button>

            <div x-show="open" x-transition @click.outside="open = false" x-cloak
                 class="absolute left-0 z-20 w-56 overflow-hidden rounded-md border border-gray-200 bg-white shadow-lg dark:border-gray-700 dark:bg-gray-800">
                @foreach ($this->available as $reaction)
                    <button type="button" wire:click="react('{{ $reaction['slug'] }}')" @click="open = false"
                            @class([
                                'flex w-full items-center gap-2 px-3 py-3 text-left text-sm hover:bg-gray-100 dark:hover:bg-gray-700 data-loading:opacity-50 data-loading:pointer-events-none',
                                'text-primary-700 dark:text-primary-300' => $userReaction === $reaction['slug'],
                                'text-gray-700 dark:text-gray-200' => $userReaction !== $reaction['slug'],
                            ])>
                        <span aria-hidden="true">{{ $reaction['emoji'] }}</span>
                        {{ $reaction['name'] }}
                    </button>
                @endforeach
                @if ($userReaction)
                    <div class="my-1 border-t border-gray-100 dark:border-gray-700"></div>
                    <button type="button" wire:click="removeReaction" @click="open = false"
                            class="flex w-full items-center px-3 py-3 text-left text-sm text-red-600 hover:bg-gray-100 dark:text-red-400 dark:hover:bg-gray-700 data-loading:opacity-50 data-loading:pointer-events-none">
                        Remover reação
                    </button>
                @endif
            </div>
        </div>
    @else
        <a href="{{ $gateUrl }}" rel="nofollow"
           aria-label="{{ $gateVerb }}"
           @class([
               'inline-flex items-center gap-1.5 rounded-md text-sm text-gray-500 transition hover:text-primary-600 dark:text-gray-400 dark:hover:text-primary-400',
                'justify-center bg-gray-100 p-3.5 hover:bg-gray-100 dark:bg-gray-800 dark:hover:bg-gray-800' => $compact,
                'min-w-6 justify-center px-1 py-3' => ! $compact,
           ])>
            <x-heroicon-o-face-smile class="h-4 w-4" aria-hidden="true" />
        </a>
    @endif
    </div>

    @if ($canReact)
        <div class="relative order-last" x-data="{ open: false, reportOpen: false }"
             @keydown.escape="open = false; $refs.more.focus()">
            <button type="button" x-ref="more" @click="open = !open"
                    aria-haspopup="true" :aria-expanded="open ? 'true' : 'false'" aria-label="Mais ações"
                    @class([
                        'inline-flex items-center rounded-md text-sm text-gray-500 transition hover:text-primary-600 dark:text-gray-400 dark:hover:text-primary-400',
                        'justify-center bg-gray-100 p-3.5 dark:bg-gray-800' => $compact,
                        'px-1 py-3' => ! $compact,
                    ])>
                <x-heroicon-o-ellipsis-horizontal class="h-4 w-4" aria-hidden="true" />
            </button>

            <div x-show="open" x-transition @click.outside="open = false; reportOpen = false" x-cloak
                 class="absolute right-0 z-20 w-64 overflow-hidden rounded-md border border-gray-200 bg-white font-sans shadow-lg dark:border-gray-700 dark:bg-gray-800">
                @if ($reported)
                    <span class="flex w-full items-center gap-2 px-3 py-3 text-sm font-medium text-amber-700 dark:text-amber-400">
                        <x-heroicon-s-flag class="h-4 w-4" aria-hidden="true" />
                        Reportado. A moderação vai olhar.
                    </span>
                @else
                    <button type="button" @click="reportOpen = !reportOpen"
                            :aria-expanded="reportOpen ? 'true' : 'false'"
                            class="flex w-full items-center gap-2 px-3 py-3 text-left text-sm font-medium text-gray-700 transition hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-700">
                        <x-heroicon-o-flag class="h-4 w-4" aria-hidden="true" />
                        Reportar à moderação
                    </button>

                    <form x-show="reportOpen" x-cloak wire:submit="report" class="px-3 pb-3">
                        <label for="report-reason-{{ $this->getId() }}" class="mb-1 block text-sm text-gray-600 dark:text-gray-300">Motivo (opcional)</label>
                        <textarea id="report-reason-{{ $this->getId() }}" wire:model="reason" rows="2" maxlength="500"
                                  class="block w-full rounded-md border border-gray-300 bg-white px-2 py-1.5 text-sm text-gray-700 focus:border-primary-500 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200"></textarea>
                        @error('reason')
                            <p class="mt-1 text-sm text-red-700 dark:text-red-300">{{ $message }}</p>
                        @enderror
                        <div class="mt-2 flex items-center justify-end gap-1">
                            <button type="button" @click="reportOpen = false"
                                    class="rounded px-3 py-2 text-sm font-medium text-gray-600 hover:text-gray-900 dark:text-gray-300 dark:hover:text-gray-100">
                                Cancelar
                            </button>
                            <button type="submit" wire:loading.attr="disabled"
                                    class="rounded bg-amber-600 px-3 py-2 text-sm font-semibold text-white hover:bg-amber-700 disabled:opacity-50">
                                <span wire:loading.remove wire:target="report">Enviar</span>
                                <span wire:loading wire:target="report">Enviando…</span>
                            </button>
                        </div>
                    </form>
                @endif
            </div>
        </div>
    @endif
</div>

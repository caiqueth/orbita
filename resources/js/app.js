import PhotoSwipeLightbox from 'photoswipe/lightbox';
import 'photoswipe/style.css';

let markdownItPromise = null;

function loadMarkdownIt() {
    markdownItPromise ??= import('markdown-it').then(({ default: MarkdownIt }) => {
        const instance = new MarkdownIt({
            html: false,
            linkify: true,
            typographer: true,
            breaks: false,
        });
        instance.disable(['heading', 'lheading']);

        return instance;
    });

    return markdownItPromise;
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('markdownEditor', (config = {}) => ({
        mode: 'markdown',
        preview: '',
        previewTimer: null,
        previewGeneration: 0,
        uploading: false,
        allowImage: config.allowImage ?? false,

        mentionOpen: false,
        mentionResults: [],
        mentionIndex: 0,
        mentionQuery: '',
        mentionTokenStart: 0,
        mentionStyle: '',
        mentionTimer: null,
        mentionAbort: null,
        mentionGeneration: 0,
        mentionId: 'mention-' + Math.random().toString(36).slice(2, 9),

        get textarea() {
            return this.$refs.textarea;
        },

        init() {
            this.renderPreview();
        },

        schedulePreview() {
            clearTimeout(this.previewTimer);
            this.previewTimer = setTimeout(() => this.renderPreview(), 300);
        },

        onInput() {
            this.schedulePreview();
            this.detectMention();
        },

        detectMention() {
            const el = this.textarea;
            const beforeCursor = el.value.substring(0, el.selectionStart);
            const match = beforeCursor.match(/(?:^|[^A-Za-z0-9_])@([A-Za-z0-9_]{1,50})$/);

            if (!match) {
                this.closeMention();
                return;
            }

            this.mentionTokenStart = beforeCursor.length - match[1].length - 1;
            this.mentionQuery = match[1];

            const generation = ++this.mentionGeneration;
            clearTimeout(this.mentionTimer);
            this.mentionTimer = setTimeout(() => this.fetchMentions(generation), 250);
        },

        async fetchMentions(generation) {
            this.mentionAbort?.abort();
            const controller = new AbortController();
            this.mentionAbort = controller;

            let users = [];
            try {
                const response = await fetch('/mention-search?q=' + encodeURIComponent(this.mentionQuery), {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    signal: controller.signal,
                });
                if (!response.ok) {
                    this.closeMention();
                    return;
                }
                users = await response.json();
            } catch (error) {
                if (error.name !== 'AbortError') {
                    this.closeMention();
                }
                return;
            }

            if (generation !== this.mentionGeneration) {
                return;
            }

            if (users.length === 0) {
                this.closeMention();
                return;
            }

            this.mentionResults = users;
            this.mentionIndex = 0;
            this.mentionOpen = true;
            this.positionMention();
        },

        applyMention(user) {
            if (!user) {
                return;
            }

            this.insert(this.mentionTokenStart, this.textarea.selectionStart, '@' + user.username + ' ');
            this.closeMention();
        },

        closeMention() {
            this.mentionOpen = false;
            this.mentionResults = [];
            this.mentionIndex = 0;
            this.mentionGeneration++;
            clearTimeout(this.mentionTimer);
            this.mentionAbort?.abort();
            this.mentionAbort = null;
        },

        handleMentionKey(event) {
            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();
                const delta = event.key === 'ArrowDown' ? 1 : -1;
                const count = this.mentionResults.length;
                this.mentionIndex = (this.mentionIndex + delta + count) % count;
                this.$nextTick(() => {
                    this.$refs.mentionList?.children[this.mentionIndex]?.scrollIntoView({ block: 'nearest' });
                });
                return true;
            }
            if (event.key === 'Enter' || event.key === 'Tab') {
                event.preventDefault();
                this.applyMention(this.mentionResults[this.mentionIndex]);
                return true;
            }
            if (event.key === 'Escape') {
                event.preventDefault();
                this.closeMention();
                return true;
            }
            return false;
        },

        positionMention() {
            const el = this.textarea;
            const caret = this.caretCoordinates(el, this.mentionTokenStart);
            const listWidth = this.$refs.mentionList?.offsetWidth || 288;
            const maxLeft = Math.max(el.clientWidth - listWidth, 0);
            const left = Math.min(Math.max(caret.left - el.scrollLeft, 0), maxLeft);
            this.mentionStyle = `top: ${caret.top - el.scrollTop + caret.height + 4}px; left: ${left}px;`;
        },

        caretCoordinates(el, position) {
            const computed = getComputedStyle(el);
            const mirror = document.createElement('div');
            const properties = [
                'boxSizing', 'width', 'paddingTop', 'paddingRight', 'paddingBottom', 'paddingLeft',
                'borderTopWidth', 'borderRightWidth', 'borderBottomWidth', 'borderLeftWidth',
                'fontFamily', 'fontSize', 'fontWeight', 'lineHeight', 'letterSpacing', 'textIndent',
            ];
            mirror.style.position = 'absolute';
            mirror.style.top = '-9999px';
            mirror.style.left = '-9999px';
            mirror.style.visibility = 'hidden';
            mirror.style.whiteSpace = 'pre-wrap';
            mirror.style.overflowWrap = 'break-word';
            properties.forEach((property) => {
                mirror.style[property] = computed[property];
            });
            mirror.textContent = el.value.substring(0, position);

            const marker = document.createElement('span');
            marker.textContent = '\u200b';
            mirror.appendChild(marker);
            document.body.appendChild(mirror);

            const coordinates = {
                top: marker.offsetTop,
                left: marker.offsetLeft,
                height: marker.offsetHeight || parseInt(computed.lineHeight, 10) || 20,
            };
            mirror.remove();

            return coordinates;
        },

        async renderPreview() {
            const value = this.textarea ? this.textarea.value : '';

            if (!value.trim()) {
                this.preview = '';

                return;
            }

            const generation = ++this.previewGeneration;
            const rendered = await this.renderMarkdown(value);

            if (generation === this.previewGeneration) {
                this.preview = rendered;
            }
        },

        async renderMarkdown(value) {
            const md = await loadMarkdownIt();

            return md
                .render(value)
                .replace(/<li>(<p>)?\[ \] /g, '<li>$1<input type="checkbox" disabled> ')
                .replace(/<li>(<p>)?\[[xX]\] /g, '<li>$1<input type="checkbox" disabled checked> ');
        },

        toggleMode() {
            this.closeMention();
            this.mode = this.mode === 'markdown' ? 'preview' : 'markdown';
            if (this.mode === 'preview') {
                this.renderPreview();
            } else {
                this.$nextTick(() => this.textarea.focus());
            }
        },

        resetEditor() {
            this.closeMention();
            clearTimeout(this.previewTimer);
            this.previewGeneration++;
            this.preview = '';
            this.mode = 'markdown';
        },

        destroy() {
            clearTimeout(this.previewTimer);
            clearTimeout(this.mentionTimer);
            this.mentionAbort?.abort();
        },

        apply(action) {
            if (this.mode !== 'markdown') {
                this.mode = 'markdown';
            }

            const el = this.textarea;
            const start = el.selectionStart;
            const end = el.selectionEnd;
            const selected = el.value.substring(start, end);

            let text = '';
            switch (action) {
                case 'bold':
                    text = selected ? `**${selected}**` : '**texto em negrito**';
                    break;
                case 'italic':
                    text = selected ? `*${selected}*` : '*texto em itálico*';
                    break;
                case 'strikethrough':
                    text = selected ? `~~${selected}~~` : '~~texto tachado~~';
                    break;
                case 'link':
                    text = selected ? `[${selected}](url)` : '[texto do link](url)';
                    break;
                case 'ul':
                    text = selected
                        ? selected.split('\n').map((line) => `- ${line}`).join('\n')
                        : '- item da lista';
                    break;
                case 'ol':
                    text = selected
                        ? selected.split('\n').map((line, i) => `${i + 1}. ${line}`).join('\n')
                        : '1. item da lista';
                    break;
                case 'checklist':
                    text = selected
                        ? selected.split('\n').map((line) => `- [ ] ${line}`).join('\n')
                        : '- [ ] item da checklist';
                    break;
                case 'quote':
                    text = selected
                        ? selected.split('\n').map((line) => `> ${line}`).join('\n')
                        : '> citação';
                    break;
                case 'code':
                    text = selected && selected.includes('\n')
                        ? `\n\`\`\`\n${selected}\n\`\`\`\n`
                        : (selected ? `\`${selected}\`` : '`código`');
                    break;
                case 'hr':
                    text = '\n\n---\n\n';
                    break;
                default:
                    return;
            }

            this.insert(start, end, text);
        },

        insert(start, end, text) {
            const el = this.textarea;
            el.value = el.value.substring(0, start) + text + el.value.substring(end);

            if (start === end) {
                el.selectionStart = start;
                el.selectionEnd = start + text.length;
            } else {
                el.selectionStart = el.selectionEnd = start + text.length;
            }

            el.focus();
            el.dispatchEvent(new Event('input', { bubbles: true }));
            this.schedulePreview();
        },

        onKeydown(event) {
            if (this.mentionOpen && this.handleMentionKey(event)) {
                return;
            }
            if (!(event.ctrlKey || event.metaKey)) {
                return;
            }
            const key = event.key.toLowerCase();
            const map = { b: 'bold', i: 'italic', k: 'link' };
            if (map[key]) {
                event.preventDefault();
                this.apply(map[key]);
            }
        },

        pickImage() {
            if (this.allowImage) {
                this.$refs.fileInput?.click();
            }
        },

        onFilePicked(event) {
            const file = event.target.files?.[0];
            if (file) {
                this.uploadFile(file);
            }
            event.target.value = '';
        },

        onDragOver(event) {
            if (this.allowImage) {
                event.preventDefault();
            }
        },

        onDrop(event) {
            if (!this.allowImage) {
                return;
            }

            const file = event.dataTransfer?.files?.[0];
            if (file?.type.startsWith('image/')) {
                event.preventDefault();
                this.uploadFile(file);
            }
        },

        onPaste(event) {
            if (!this.allowImage) {
                return;
            }

            const file = event.clipboardData?.files?.[0];
            if (file?.type.startsWith('image/')) {
                event.preventDefault();
                this.uploadFile(file);
            }
        },

        uploadFile(file) {
            if (!this.allowImage || this.uploading) {
                return;
            }
            this.uploading = true;

            this.$wire
                .upload('pendingImage', file, () => {
                    this.$wire
                        .insertImage()
                        .then((response) => {
                            const url = typeof response === 'string' ? response : (response?.url ?? '');
                            if (url) {
                                this.applyImageMarkdown(url);
                            }
                        })
                        .catch(() => {})
                        .finally(() => {
                            this.uploading = false;
                        });
                }, () => {
                    this.uploading = false;
                });
        },

        applyImageMarkdown(url) {
            if (this.mode !== 'markdown') {
                this.mode = 'markdown';
            }
            const el = this.textarea;
            const markdown = `\n![](${url})\n`;
            const start = el.selectionStart;
            const end = el.selectionEnd;

            this.insert(start, end, markdown);

            const altPosition = start + '\n!['.length;
            el.selectionStart = el.selectionEnd = altPosition;
            el.focus();
        },
    }));
});

document.addEventListener('alpine:init', () => {
    window.Alpine.data('bookmarkButton', (config = {}) => ({
        url: config.url,
        bookmarked: config.bookmarked ?? false,
        busy: false,

        init() {
            this.$watch('bookmarked', () => this.syncLabel());
        },

        syncLabel() {
            this.$el.setAttribute('aria-pressed', this.bookmarked ? 'true' : 'false');
            if (this.$el.hasAttribute('aria-label')) {
                this.$el.setAttribute('aria-label', this.bookmarked ? 'Deixar de acompanhar' : 'Acompanhar post');
            }
        },

        async toggle() {
            if (this.busy) {
                return;
            }

            this.busy = true;
            const previous = this.bookmarked;
            this.bookmarked = !this.bookmarked;

            try {
                const response = await fetch(this.url, {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                    },
                });

                if (!response.ok) {
                    const body = await response.json().catch(() => ({}));

                    this.bookmarked = previous;

                    if (body.url) {
                        window.location.href = body.url;

                        return;
                    }

                    window.orbitaToast?.(body.message || 'Não foi possível atualizar. Tente novamente.');

                    return;
                }

                const body = await response.json();
                this.bookmarked = body.bookmarked ?? this.bookmarked;
            } catch {
                this.bookmarked = previous;
                window.orbitaToast?.('Não foi possível salvar. Verifique sua conexão.');
            } finally {
                this.busy = false;
            }
        },
    }));

    window.Alpine.data('themeSwitcher', () => ({
        theme: localStorage.getItem('theme') || 'auto',
        media: null,
        onSchemeChange: null,

        init() {
            this.apply();

            this.media = window.matchMedia('(prefers-color-scheme: dark)');
            this.onSchemeChange = () => {
                if (this.theme === 'auto') {
                    this.apply();
                }
            };

            this.media.addEventListener('change', this.onSchemeChange);
        },

        destroy() {
            if (this.media && this.onSchemeChange) {
                this.media.removeEventListener('change', this.onSchemeChange);
            }

            this.media = null;
            this.onSchemeChange = null;
        },

        set(value) {
            this.theme = value;
            localStorage.setItem('theme', value);
            this.apply();
        },

        apply() {
            const prefersDark = (this.media ?? window.matchMedia('(prefers-color-scheme: dark)')).matches;
            const isDark = this.theme === 'dark' || (this.theme === 'auto' && prefersDark);
            document.documentElement.classList.toggle('dark', isDark);
        },
    }));
});

document.addEventListener('alpine:init', () => {
    window.Alpine.data('composeFab', () => ({
        visible: true,
        composing: false,
        reading: false,
        lift: 0,
        observer: null,
        onResize: null,
        onScroll: null,
        onReplyToggled: null,
        onCommentCreated: null,
        onFocusIn: null,
        onFocusOut: null,
        focusTimer: null,
        syncTimer: null,

        get shown() {
            return this.visible && ! this.composing && ! this.reading;
        },

        init() {
            const openReplies = new Set();
            let composerFocus = false;

            const sync = () => {
                for (const el of openReplies) {
                    if (! el.isConnected) {
                        openReplies.delete(el);
                    }
                }

                this.composing = openReplies.size > 0 || composerFocus;
            };

            this.onReplyToggled = (event) => {
                const el = event.target;
                if (! (el instanceof Element)) {
                    return;
                }

                if (event.detail?.open) {
                    openReplies.add(el);
                } else {
                    openReplies.delete(el);
                }

                sync();
            };

            this.onCommentCreated = () => {
                composerFocus = false;
                sync();

                clearTimeout(this.syncTimer);
                this.syncTimer = setTimeout(sync, 0);
            };

            this.onFocusIn = (event) => {
                composerFocus = !! event.target?.closest?.('[data-composer]');
                sync();
            };

            this.onFocusOut = () => {
                clearTimeout(this.focusTimer);
                this.focusTimer = setTimeout(() => {
                    composerFocus = !! document.activeElement?.closest?.('[data-composer]');
                    sync();
                }, 0);
            };

            let lastY = window.scrollY;
            this.onScroll = () => {
                const y = window.scrollY;
                if (Math.abs(y - lastY) < 8) {
                    return;
                }
                this.reading = y > lastY && y > 120;
                lastY = y;
            };

            window.addEventListener('reply-toggled', this.onReplyToggled);
            window.addEventListener('comment-created', this.onCommentCreated);
            window.addEventListener('scroll', this.onScroll, { passive: true });
            document.addEventListener('focusin', this.onFocusIn);
            document.addEventListener('focusout', this.onFocusOut);

            const footer = document.querySelector('footer');
            if (! footer) {
                return;
            }

            const scrollable = () =>
                document.documentElement.scrollHeight > window.innerHeight + 8;

            const measure = () => {
                this.lift = scrollable()
                    ? 0
                    : Math.max(0, window.innerHeight - footer.getBoundingClientRect().top);
            };

            this.observer = new IntersectionObserver(([entry]) => {
                this.visible = ! (entry.isIntersecting && scrollable());
                measure();
            });
            this.observer.observe(footer);

            this.onResize = measure;
            window.addEventListener('resize', this.onResize);
            measure();
        },

        destroy() {
            this.observer?.disconnect();
            clearTimeout(this.focusTimer);
            clearTimeout(this.syncTimer);
            window.removeEventListener('resize', this.onResize);
            window.removeEventListener('scroll', this.onScroll);
            window.removeEventListener('reply-toggled', this.onReplyToggled);
            window.removeEventListener('comment-created', this.onCommentCreated);
            document.removeEventListener('focusin', this.onFocusIn);
            document.removeEventListener('focusout', this.onFocusOut);
        },
    }));
});

document.addEventListener('alpine:init', () => {
    window.Alpine.data('shareButton', (config = {}) => ({
        url: config.url ?? window.location.href,
        title: config.title ?? document.title,
        status: '',
        timer: null,

        async share() {
            if (navigator.share) {
                try {
                    await navigator.share({ title: this.title, url: this.url });
                    return;
                } catch (error) {
                    if (error?.name === 'AbortError') return;
                }
            }

            await this.copy();
        },

        async copy() {
            try {
                await navigator.clipboard.writeText(this.url);
                this.announce('Link copiado');
            } catch {
                this.announce('Não foi possível copiar. Copie da barra de endereço.');
            }
        },

        announce(message) {
            this.status = message;
            clearTimeout(this.timer);
            this.timer = setTimeout(() => (this.status = ''), 2500);
        },

        destroy() {
            clearTimeout(this.timer);
        },
    }));
});

const LOGO_SPIN_MS = 900;
let logoSpinStartedAt = 0;

function spinLogo(offset = 0) {
    const moon = document.getElementById('orbita-moon');
    if (!moon || moon.getAnimations().some((animation) => animation.playState === 'running')) {
        return;
    }

    const spin = moon.animate(
        { transform: ['rotate(0deg)', 'rotate(360deg)'] },
        { duration: LOGO_SPIN_MS, easing: 'cubic-bezier(0.65, 0, 0.35, 1)' },
    );
    spin.currentTime = offset;
}

document.addEventListener('livewire:navigating', () => {
    document.documentElement.setAttribute('data-navigating', '');
    logoSpinStartedAt = performance.now();
    spinLogo();
});
document.addEventListener('livewire:navigated', () => {
    document.documentElement.removeAttribute('data-navigating');
    document.documentElement.classList.add('js');

    const elapsed = performance.now() - logoSpinStartedAt;
    if (elapsed < LOGO_SPIN_MS) {
        spinLogo(elapsed);
    }
});

const TOAST_TIMEOUT = 6000;
let toastTimer = null;

function hideToast() {
    clearTimeout(toastTimer);
    toastTimer = null;

    const box = document.getElementById('app-toast-box');
    if (box) {
        box.classList.add('hidden');
        box.classList.remove('flex');
    }
}

function showToast(message) {
    const box = document.getElementById('app-toast-box');
    const text = document.getElementById('app-toast-message');
    if (!box || !text) {
        return;
    }

    text.textContent = message;
    box.classList.remove('hidden');
    box.classList.add('flex');

    clearTimeout(toastTimer);
    toastTimer = setTimeout(hideToast, TOAST_TIMEOUT);
}

window.orbitaToast = showToast;

document.addEventListener('click', (event) => {
    if (event.target?.closest?.('#app-toast-close')) {
        hideToast();
    }
});

document.addEventListener('livewire:navigating', hideToast);

const LIVEWIRE_ERROR_MESSAGES = {
    403: 'Você não tem permissão para isso.',
    404: 'Conteúdo não encontrado.',
    429: 'Muitas requisições. Aguarde um instante.',
};
const LIVEWIRE_ERROR_FALLBACK = 'Algo deu errado. Tente novamente em instantes.';

document.addEventListener('livewire:init', () => {
    window.Livewire.interceptRequest(({ onError, onFailure }) => {
        spinLogo();

        onError(({ response, body, preventDefault }) => {
            if (response.status === 419 || response.aborted) {
                return;
            }

            if (import.meta.env.DEV) {
                return;
            }

            preventDefault();
            console.error(`Livewire ${response.status}`, body);
            showToast(LIVEWIRE_ERROR_MESSAGES[response.status] ?? LIVEWIRE_ERROR_FALLBACK);
        });

        onFailure(({ error }) => {
            console.error('Livewire request failure', error);
            showToast('Não foi possível conectar. Verifique sua internet.');
        });
    });
});

document.addEventListener('alpine:init', () => {
    window.Alpine.data('scrollHint', () => ({
        atStart: true,
        atEnd: true,
        scroller: null,
        observer: null,
        onScroll: null,

        init() {
            this.$nextTick(() => {
                this.scroller = this.$refs.scroller;
                if (! this.scroller) {
                    return;
                }

                this.onScroll = () => this.update();
                this.scroller.addEventListener('scroll', this.onScroll, { passive: true });
                this.observer = new ResizeObserver(this.onScroll);
                this.observer.observe(this.scroller);
                this.update();
            });
        },

        destroy() {
            this.observer?.disconnect();
            if (this.onScroll) {
                this.scroller?.removeEventListener('scroll', this.onScroll);
            }
        },

        update() {
            const max = this.scroller.scrollWidth - this.scroller.clientWidth;
            this.atStart = this.scroller.scrollLeft <= 1;
            this.atEnd = max <= 1 || this.scroller.scrollLeft >= max - 1;
        },

        scrollBy(direction) {
            this.scroller?.scrollBy({
                left: direction * this.scroller.clientWidth * 0.6,
                behavior: 'smooth',
            });
        },
    }));

    window.Alpine.data('infiniteMore', () => ({
        loading: false,
        observer: null,
        watchdog: null,

        init() {
            this.observer = new IntersectionObserver((entries) => {
                if (entries[0].isIntersecting) {
                    this.trigger();
                }
            }, { rootMargin: '600px 0px' });
            this.observer.observe(this.$el);
        },

        trigger() {
            if (this.loading) {
                return;
            }

            const budget = (window.__infiniteBudget ??= { count: 0, at: 0 });
            const now = performance.now();
            if (now - budget.at > 1500) {
                budget.count = 0;
            }
            budget.count += 1;
            budget.at = now;
            if (budget.count > 4) {
                return;
            }

            this.loading = true;

            clearTimeout(this.watchdog);
            this.watchdog = setTimeout(() => {
                this.loading = false;
            }, 10000);

            this.$refs.more.click();
        },

        destroy() {
            this.observer?.disconnect();
            clearTimeout(this.watchdog);
        },
    }));

    window.Alpine.data('infiniteUrl', () => ({
        observer: null,
        mutations: null,
        onScroll: null,
        current: null,
        frame: null,
        dedupeFrame: null,

        init() {
            this.observer = new IntersectionObserver(() => this.schedule(), {
                rootMargin: '-12% 0px -85% 0px',
            });
            this.observeMarkers();

            this.onScroll = () => this.schedule();
            window.addEventListener('scroll', this.onScroll, { passive: true });

            this.mutations = new MutationObserver(() => {
                this.scheduleDedupe();
                this.schedule();
            });
            this.mutations.observe(this.$el, { childList: true, subtree: true });

            this.schedule();
            this.maybeJumpToHash();
        },

        markers() {
            return Array.from(this.$el.querySelectorAll('[data-page-marker]'));
        },

        observeMarkers() {
            this.markers().forEach((marker) => {
                if (!marker.dataset.observed) {
                    marker.dataset.observed = '1';
                    this.observer.observe(marker);
                }
            });
        },

        schedule() {
            cancelAnimationFrame(this.frame);
            this.frame = requestAnimationFrame(() => this.sync());
        },

        scheduleDedupe() {
            cancelAnimationFrame(this.dedupeFrame);
            this.dedupeFrame = requestAnimationFrame(() => {
                this.dedupe();
                this.observeMarkers();
            });
        },

        sync() {
            if (document.documentElement.hasAttribute('data-navigating')) {
                return;
            }

            const markers = this.markers();
            const limit = window.innerHeight * 0.15;
            const passed = markers.filter((m) => m.getBoundingClientRect().top <= limit);
            const active = passed.length ? passed[passed.length - 1] : markers[0];

            if (!active || active === this.current) {
                return;
            }
            this.current = active;

            const url = new URL(window.location.href);
            const param = active.dataset.pageParam;
            const page = active.dataset.pageMarker;

            if (page === '1') {
                url.searchParams.delete(param);
            } else {
                url.searchParams.set(param, page);
            }

            history.replaceState(history.state, '', url.pathname + url.search + url.hash);

            this.syncHead(url, active);
        },

        syncHead(url, marker) {
            const set = (rel, href) => {
                let link = document.head.querySelector(`link[rel="${rel}"]`);
                if (!href) {
                    link?.remove();
                    return;
                }
                if (!link) {
                    link = document.createElement('link');
                    link.rel = rel;
                    document.head.appendChild(link);
                }
                link.href = href;
            };

            set('canonical', url.origin + url.pathname + url.search);
            set('prev', marker.dataset.prevUrl || '');
            set('next', marker.dataset.nextUrl || '');
        },

        dedupe() {
            const seen = new Set();
            this.$el.querySelectorAll('[wire\\:key]').forEach((el) => {
                const key = el.getAttribute('wire:key');
                if (seen.has(key)) {
                    el.remove();
                } else {
                    seen.add(key);
                }
            });
        },

        async maybeJumpToHash() {
            const hash = window.location.hash;
            if (hash.length < 2) {
                return;
            }

            let target = null;
            try {
                target = document.querySelector(hash);
            } catch {
                return;
            }

            for (let i = 0; i < 20 && !target; i++) {
                const more = this.$el.querySelector('[x-ref="more"]');
                if (!more) {
                    return;
                }
                more.click();
                await new Promise((resolve) => setTimeout(resolve, 300));
                target = document.querySelector(hash);
            }

            target?.scrollIntoView({ block: 'start' });
        },

        destroy() {
            window.removeEventListener('scroll', this.onScroll);
            this.observer?.disconnect();
            this.mutations?.disconnect();
            cancelAnimationFrame(this.frame);
            cancelAnimationFrame(this.dedupeFrame);
        },
    }));
});

const PSWP_ITEM = 'a.pswp-item';

const lightbox = new PhotoSwipeLightbox({
    pswpModule: () => import('photoswipe'),
    bgOpacity: 0.9,
    wheelToZoom: true,
    history: false,
});

lightbox.on('uiRegister', () => {
    lightbox.pswp.ui.registerElement({
        name: 'inline-caption',
        appendTo: 'root',
        onInit: (el, pswp) => {
            el.classList.add('pswp__custom-caption');
            const render = () => {
                el.textContent = pswp.currSlide?.data?.alt || '';
            };
            pswp.on('change', render);
            render();
        },
    });
});

lightbox.init();

function pswpSlide(link) {
    const img = link.querySelector('img');

    return {
        src: link.href,
        width: Number(link.dataset.pswpWidth) || img?.naturalWidth || img?.offsetWidth || 0,
        height: Number(link.dataset.pswpHeight) || img?.naturalHeight || img?.offsetHeight || 0,
        alt: img?.alt || '',
        msrc: img?.currentSrc || img?.src,
        element: link,
    };
}

document.addEventListener('click', (event) => {
    if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
        return;
    }

    const link = event.target instanceof Element ? event.target.closest(PSWP_ITEM) : null;
    if (!link) {
        return;
    }

    event.preventDefault();

    const scope = link.closest('.md-content') || document;
    const items = [...scope.querySelectorAll(PSWP_ITEM)];

    lightbox.loadAndOpen(Math.max(items.indexOf(link), 0), items.map(pswpSlide));
});

document.addEventListener('livewire:navigating', () => {
    lightbox.pswp?.close();
});

document.addEventListener('alpine:init', () => {
    window.Alpine.data('postAutosave', (config = {}) => ({
        storageKey: config.storageKey,
        savedAt: null,
        syncMs: Math.max(5, config.syncSeconds ?? 15) * 1000,
        dirty: false,
        syncing: false,
        failed: false,
        hasLocal: false,
        restoredLocal: false,
        localOk: true,
        submitting: false,
        confirmingDiscard: false,
        writeTimer: null,
        syncTimer: null,
        confirmTimer: null,

        init() {
            this.savedAt = this.$wire.draftSavedAt ?? null;
            this.restoreLocal();

            this.syncTimer = setInterval(() => this.sync(), this.syncMs);
            this.onVisibility = () => {
                if (document.visibilityState === 'hidden') {
                    this.writeLocal();
                    this.sync();
                }
            };
            this.onBeforeUnload = (event) => {
                if (this.dirty && !this.localOk && !this.submitting) {
                    event.preventDefault();
                    event.returnValue = '';
                }
            };
            document.addEventListener('visibilitychange', this.onVisibility);
            window.addEventListener('beforeunload', this.onBeforeUnload);
            this.$el.querySelector('form')?.addEventListener('submit', () => {
                this.submitting = true;
            });
        },

        destroy() {
            clearInterval(this.syncTimer);
            clearTimeout(this.writeTimer);
            clearTimeout(this.confirmTimer);
            document.removeEventListener('visibilitychange', this.onVisibility);
            window.removeEventListener('beforeunload', this.onBeforeUnload);
        },

        get hasDraft() {
            return Boolean(this.savedAt || this.hasLocal || this.dirty);
        },

        get statusText() {
            if (this.syncing) {
                return 'Salvando rascunho…';
            }
            if (this.failed && this.hasLocal) {
                return 'Rascunho salvo neste navegador (sem conexão com o servidor)';
            }
            if (this.restoredLocal) {
                return 'Rascunho restaurado deste navegador';
            }
            if (this.savedAt) {
                const locale = document.documentElement.lang || undefined;
                const time = new Date(this.savedAt).toLocaleTimeString(locale, { hour: '2-digit', minute: '2-digit' });
                return 'Rascunho salvo às ' + time;
            }
            return '';
        },

        values() {
            return {
                title: this.$wire.title ?? '',
                url: this.$wire.url ?? '',
                content: this.$wire.content ?? '',
            };
        },

        readLocal() {
            try {
                const raw = window.localStorage.getItem(this.storageKey);
                return raw ? JSON.parse(raw) : null;
            } catch {
                return null;
            }
        },

        restoreLocal() {
            const local = this.readLocal();
            if (!local || typeof local !== 'object') {
                return;
            }

            const current = this.values();
            const same = ['title', 'url', 'content'].every((k) => (local[k] ?? '') === current[k]);
            const localIsNewer = !this.savedAt || Date.parse(local.updatedAt ?? 0) > Date.parse(this.savedAt);

            if (same || !localIsNewer) {
                this.hasLocal = true;
                return;
            }

            this.$wire.set('title', local.title ?? '', false);
            this.$wire.set('content', local.content ?? '', false);
            // url is a live model: setting it also refreshes the oEmbed preview.
            this.$wire.set('url', local.url ?? '');
            this.hasLocal = true;
            this.restoredLocal = true;
            this.dirty = true;
        },

        markDirty() {
            this.dirty = true;
            this.submitting = false;
            this.confirmingDiscard = false;
            clearTimeout(this.writeTimer);
            this.writeTimer = setTimeout(() => this.writeLocal(), 500);
        },

        writeLocal() {
            clearTimeout(this.writeTimer);
            if (!this.dirty) {
                return;
            }
            const data = this.values();
            try {
                if (!data.title.trim() && !data.url.trim() && !data.content.trim()) {
                    window.localStorage.removeItem(this.storageKey);
                    this.hasLocal = false;
                } else {
                    window.localStorage.setItem(this.storageKey, JSON.stringify({ ...data, updatedAt: new Date().toISOString() }));
                    this.hasLocal = true;
                }
                this.localOk = true;
            } catch {
                this.localOk = false;
            }
        },

        sync() {
            if (!this.dirty || this.syncing || this.submitting) {
                return;
            }
            this.writeLocal();
            this.syncing = true;
            this.dirty = false;

            // A request that dies offline may never settle; don't let it pin "Salvando…".
            const timeout = new Promise((_, reject) => setTimeout(() => reject(new Error('timeout')), 20000));

            Promise.race([this.$wire.saveDraft(), timeout])
                .then((savedAt) => {
                    this.savedAt = savedAt || null;
                    this.failed = false;
                    this.restoredLocal = false;
                })
                .catch(() => {
                    this.dirty = true;
                    this.failed = true;
                })
                .finally(() => {
                    this.syncing = false;
                });
        },

        discard() {
            if (!this.confirmingDiscard) {
                this.confirmingDiscard = true;
                clearTimeout(this.confirmTimer);
                this.confirmTimer = setTimeout(() => (this.confirmingDiscard = false), 4000);
                return;
            }
            this.confirmingDiscard = false;
            this.$wire.discardDraft();
        },

        clearLocal() {
            clearTimeout(this.writeTimer);
            try {
                window.localStorage.removeItem(this.storageKey);
            } catch {
                // storage unavailable: nothing to clear
            }
            this.dirty = false;
            this.failed = false;
            this.hasLocal = false;
            this.restoredLocal = false;
            this.savedAt = null;
        },
    }));
});

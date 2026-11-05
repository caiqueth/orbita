<?php

return [
    'name' => env('ORBITA_NAME', 'Órbita'),
    'url' => env('ORBITA_URL', env('APP_URL', 'http://localhost')),
    'register' => env('ORBITA_REGISTER', true),
    'avatar_style' => 'thumbs',
    'avatar_gravatar_enabled' => env('ORBITA_AVATAR_GRAVATAR_ENABLED', true),
    'limit_images_post' => 5,
    'http_proxy' => env('ORBITA_HTTP_PROXY'),

    'quotes' => implode("\n", [
        '"Está é a ultima vez que atualizo esse laiaute!" | Rodrigo Ghedin',
        '"Beep bee beepbeeep beeep" | R2D2',
    ]),

    'home' => [
        'default_tab' => 'popular',
    ],

    'posts' => [
        'per_page' => 25,
        'image_max_width' => 1980,
        'image_max_size' => 10485760,
        'score_decay_hours' => 3,
        'enable_paywall_bypass' => true,
        'paywall_bypass_rules' => '',
        'enable_auto_translate' => true,
        'translate_base_url' => env('POSTS_TRANSLATE_BASE_URL', 'https://translate.google.com/translate?sl=auto&tl='.str_replace('_', '-', (string) env('APP_LOCALE', 'en')).'&u={url}'),
        'edit_time_limit' => 900,
        'vote_allow_negative' => false,
        'show_score' => true,
        'close_inactive_days' => 30,
        'limit_tags' => 10,
        'max_title' => 250,
        'max_content' => 50000,
        'max_url_length' => 2048,
        'draft_sync_seconds' => 15,
        'draft_ttl_days' => 30,
    ],

    'comments' => [
        'max_nesting_level' => 5,
        'edit_time_limit' => 900,
        'require_login' => true,
        'allow_images' => true,
        'max_images' => 4,
        'default_sort' => 'newest',
        'per_page' => 20,
    ],

    'moderation' => [
        'auto_hide_threshold' => 3,
        'strip_referral_params' => true,
        'hide_referral_for_review' => false,
        'forbidden_url_params' => implode("\n", [
            'ref',
            'referral',
            'referral-code',
            'referralcode',
            'referrer',
            'aff',
            'affiliate',
            'affiliate_id',
            'aff_id',
            'partner',
            'promo',
            'invite',
            'invite_code',
            'convite',
            'indicacao',
            'tag',
            'fbclid',
            'gclid',
            'msclkid',
            'mc_cid',
            'mc_eid',
        ]),
        'link_guard_enabled' => false,
        'link_domain_blocklist' => '',
        'link_domain_allowlist' => '',
        'link_trust_account_age_hours' => 72,
        'link_trust_min_comments' => 3,
        'email_domain_blocklist' => '',
    ],

    'antispam' => [
        'post_cooldown' => 300,
        'comment_cooldown' => 30,
        'max_posts_per_hour' => 5,
        'max_comments_per_hour' => 20,
        'duplicate_detection' => true,
    ],

    'auth' => [
        'max_login_attempts' => 5,
        'login_decay_seconds' => 1800,
        'username_change_cooldown_days' => 30,
        'remember_minutes' => 259200,
        'remember_default_minutes' => 43200,
    ],

    'password' => [
        'min_length' => 10,
    ],

    'turnstile' => [
        'enabled' => false,
        'site_key' => env('TURNSTILE_SITE_KEY'),
        'secret_key' => env('TURNSTILE_SECRET_KEY'),
    ],

    'social' => [
        'google' => ['enabled' => false, 'require_email_confirmation' => false],
        'linkedin' => ['enabled' => false, 'require_email_confirmation' => false],
        'github' => ['enabled' => false, 'require_email_confirmation' => true],
        'instagram' => ['enabled' => false, 'require_email_confirmation' => true],
    ],

    'logs' => [
        'access' => [
            'enabled' => env('LOG_ACCESS_ENABLED', true),

            'exclude' => [
                'up',
                'healthcheck',
                'livewire/update',
                'livewire/livewire.js*',
                'build/*',
                'storage/*',
                'favicon.ico',
                'robots.txt',
            ],

            'redact' => [
                'signature',
                'token',
                '_token',
                'password',
                'api_key',
            ],

            'max_uri_length' => 512,
        ],
    ],

    'security' => [
        'permissions_policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',

        'csp' => [
            'enforce' => env('ORBITA_CSP_ENFORCE', false),

            'report_uri' => env('ORBITA_CSP_REPORT_URI'),

            'directives' => [
                'default-src' => ["'self'"],
                'base-uri' => ["'self'"],
                'object-src' => ["'none'"],
                'frame-ancestors' => ["'self'"],
                'form-action' => ["'self'"],
                'script-src' => ["'self'", "'unsafe-inline'", "'unsafe-eval'", 'https:'],
                'style-src' => ["'self'", "'unsafe-inline'", 'https:'],
                'img-src' => ["'self'", 'data:', 'blob:', 'https:'],
                'font-src' => ["'self'", 'data:', 'https:'],
                'media-src' => ["'self'", 'https:'],
                'connect-src' => ["'self'", 'https:'],
                'frame-src' => ["'self'", 'https:'],
            ],
        ],
    ],

    'cache' => [
        'ranking_ttl' => [
            'getPopularPosts' => 300,
            'getAllPosts' => 900,
            'getPostsByRecentComments' => 900,
            'getPostsByReactionsOnly' => 300,
            'getPostsByCommentCount' => 900,
            'getPostsWithoutComments' => 900,
            'getAllComments' => 900,
            'trending' => 120,
            'hot' => 300,
            'default' => 300,
        ],

        'browser_max_age' => env('ORBITA_CACHE_BROWSER_MAX_AGE', 30),
        'edge_max_age' => env('ORBITA_CACHE_EDGE_MAX_AGE', 60),
        'stale_while_revalidate' => env('ORBITA_CACHE_SWR', 600),
        'stale_if_error' => env('ORBITA_CACHE_SIE', 86400),
    ],

    'pagination' => [
        'profile_per_page' => 10,
        'notifications_per_page' => 20,
        'bookmarks_per_page' => 20,
        'feed_limit' => 50,
        'search_limit' => 50,
        'search_max_limit' => 100,
    ],

    'notifications' => [
        'read_retention_days' => 30,
        'unread_retention_days' => 90,
    ],

    'ogimage' => [
        'width' => 1200,
        'height' => 630,
        'description_length' => 160,
    ],

    'meta' => [
        'max_description_length' => 240,
        'default_description' => 'Uma comunidade para compartilhar links e conversar sobre eles.',
        'third_party_code' => env('ORBITA_THIRD_PARTY_CODE', ''),
        'og_locale' => env('ORBITA_OG_LOCALE', str_replace('-', '_', (string) env('APP_LOCALE', 'pt_BR'))),
        'twitter_site' => env('ORBITA_TWITTER_SITE'),
    ],

    'sitemap' => [
        'posts_per_file' => 200,

        'cache_dir' => storage_path('app/sitemap'),
    ],

    'robots' => [
        'content' => implode("\n", [
            'User-agent: *',
            'Disallow: /admin',
            'Disallow: /notifications',
            'Disallow: /bookmarks',
            'Disallow: /posts/create',
            'Disallow: /*/edit',
            'Disallow: /*/revisions',
        ]),
    ],

    'images' => [
        'max_width' => 1980,

        'max_megapixels' => 30,

        'quality' => 82,
        'avatar_max_size' => 5120,
        'avatar_size' => 300,
        'avatar_quality' => 90,
        'cache_disk' => env('IMAGE_CACHE_DISK', 'local'),
        'cache_prefix' => 'img-cache',
    ],

    'oembed' => [
        'providers' => [
            '#https?://(www\.)?codepoints\.net/.*#i' => ['https://codepoints.net/api/v1/oembed', true],
            '#https?://(www|geo)\.dailymotion\.com/.*#i' => ['https://www.dailymotion.com/services/oembed', true],
            '#https?://(.*\.)?docdroid\.(net|com)/.*#i' => ['https://www.docdroid.net/api/oembed', true],
            '#https?://docdro\.id/.*#i' => ['https://www.docdroid.net/api/oembed', true],
            '#https?://tools\.pinpoll\.com/embed/.*#i' => ['https://tools.pinpoll.com/oembed', true],
            '#https?://(www\.)?pinterest\.com/.*#i' => ['https://www.pinterest.com/oembed.json', true],
            '#https?://(www\.)?reddit\.com/r/.*/comments/.*/.*#i' => ['https://www.reddit.com/oembed', false],
            '#https?://(www\.)?scribd\.com/doc/.*#i' => ['https://www.scribd.com/services/oembed/', false],
            '#https?://(.*\.)?slideshare\.net/.*/.*#i' => ['https://www.slideshare.net/api/oembed/2', true],
            '#https?://bsky\.app/profile/.*/post/.*#i' => ['https://embed.bsky.app/oembed', true],
            '#https?://mastodon\.social/@.*/.*#i' => ['https://mastodon.social/api/oembed', true],
            '#https?://mastodon\.social/web/@.*/.*#i' => ['https://mastodon.social/api/oembed', true],
            '#https?://players\.brightcove\.net/.*#i' => ['https://oembed.brightcove.net/', false],
            '#https?://bcove\.video/.*#i' => ['https://oembed.brightcove.net/', false],
            '#https?://(www\.)?canva\.com/design/.*/view.*#i' => ['https://www.canva.com/_oembed', true],
            '#https?://codepen\.io/.*#i' => ['https://codepen.io/api/oembed', false],
            '#https?://codesandbox\.io/(s|embed)/.*#i' => ['https://codesandbox.io/oembed', false],
            '#https?://(.*\.)?deviantart\.com/.*#i' => ['https://backend.deviantart.com/oembed', false],
            '#https?://fav\.me/.*#i' => ['https://backend.deviantart.com/oembed', false],
            '#https?://sta\.sh/.*#i' => ['https://backend.deviantart.com/oembed', false],
            '#https?://(www\.)?figma\.com/(file|design|board|slides|buzz|site|make)/.*#i' => ['https://www.figma.com/api/oembed', true],
            '#https?://(.*\.)?flickr\.com/.*#i' => ['https://www.flickr.com/services/oembed/', true],
            '#https?://flic\.kr/.*#i' => ['https://www.flickr.com/services/oembed/', true],
            '#https?://public\.flourish\.studio/(visualisation|story)/.*#i' => ['https://app.flourish.studio/api/v1/oembed', true],
            '#https?://gty\.im/.*#i' => ['https://embed.gettyimages.com/oembed', false],
            '#https?://giphy\.com/(gifs|clips)/.*#i' => ['https://giphy.com/services/oembed', true],
            '#https?://gph\.is/.*#i' => ['https://giphy.com/services/oembed', true],
            '#https?://media\.giphy\.com/media/.*/giphy\.gif#i' => ['https://giphy.com/services/oembed', true],
            '#https?://(www\.)?ifixit\.com/Guide/View/.*#i' => ['https://www.ifixit.com/Embed', false],
            '#https?://infogram\.com/.*#i' => ['https://infogram.com/oembed', false],
            '#https?://(www\.)?inoreader\.com/oembed/.*#i' => ['https://www.inoreader.com/oembed/api/', true],
            '#https?://(www\.)?kickstarter\.com/projects/.*#i' => ['https://www.kickstarter.com/services/oembed', false],
            '#https?://livestream\.com/.*#i' => ['https://livestream.com/oembed', true],
            '#https?://(www\.)?loom\.(com|i)/.*#i' => ['https://www.loom.com/v1/oembed', true],
            '#https?://miro\.com/(app/board|video-player)/.*#i' => ['https://miro.com/api/v1/oembed', true],
            '#https?://(www\.)?mixcloud\.com/.*/.*/#i' => ['https://www.mixcloud.com/oembed/', false],
            '#https?://(.*\.)?podbean\.com/e/.*#i' => ['https://api.podbean.com/v1/oembed', false],
            '#https?://(.*\.)?smugmug\.com/.*#i' => ['https://api.smugmug.com/services/oembed/', true],
            '#https?://(www\.)?soundcloud\.com/.*#i' => ['https://soundcloud.com/oembed', false],
            '#https?://on\.soundcloud\.com/.*#i' => ['https://soundcloud.com/oembed', false],
            '#https?://soundcloud\.app\.goog\.gl/.*#i' => ['https://soundcloud.com/oembed', false],
            '#https?://open\.spotify\.com/.*#i' => ['https://open.spotify.com/oembed', true],
            '#https?://spotify\.link/.*#i' => ['https://open.spotify.com/oembed', true],
            '#https?://(www\.)?streamable\.com/.*#i' => ['https://api.streamable.com/oembed.json', true],
            '#https?://s3m\.io/.*#i' => ['https://streamio.com/api/v1/oembed', true],
            '#https?://23m\.io/.*#i' => ['https://streamio.com/api/v1/oembed', true],
            '#https?://(www\.)?ted\.com/talks/.*#i' => ['https://www.ted.com/services/v1/oembed.{format}', true],
            '#https?://(www\.)?tiktok\.com/.*#i' => ['https://www.tiktok.com/oembed', false],
            '#https?://(.*\.)?tumblr\.com/post/.*#i' => ['https://www.tumblr.com/oembed/1.0', false],
            '#https?://(www\.)?twitter\.com/.*#i' => ['https://publish.twitter.com/oembed', false],
            '#https?://(.*\.)?twitter\.com/.*/status/.*#i' => ['https://publish.twitter.com/oembed', false],
            '#https?://(www\.)?vimeo\.com/.*#i' => ['https://vimeo.com/api/oembed.{format}', true],
            '#https?://player\.vimeo\.com/video/.*#i' => ['https://vimeo.com/api/oembed.{format}', true],
            '#https?://(www\.)?x\.com/.*#i' => ['https://publish.x.com/oembed', false],
            '#https?://(.*\.)?x\.com/.*/status/.*#i' => ['https://publish.x.com/oembed', false],
            '#https?://((m|www)\.)?youtube\.com/watch.*#i' => ['https://www.youtube.com/oembed', true],
            '#https?://((m|www)\.)?youtube\.com/playlist.*#i' => ['https://www.youtube.com/oembed', true],
            '#https?://((m|www)\.)?youtube\.com/shortsvideo/.*#i' => ['https://www.youtube.com/oembed', true],
            '#https?://((m|www)\.)?youtube\.com/shorts/.*#i' => ['https://www.youtube.com/oembed', true],
            '#https?://youtu\.be/.*#i' => ['https://www.youtube.com/oembed', true],
            '#https?://(www\.)?tiktok\.com/@.*#i' => ['https://www.tiktok.com/oembed', true],
            '#https?://([a-z]{2}|www)\.pinterest\.com(\.(au|mx))?/.*#i' => ['https://www.pinterest.com/oembed.json', true],
            '#https?://(www\.)?wolframcloud\.com/obj/.+#i' => ['https://www.wolframcloud.com/oembed', true],
            '#https?://pca\.st/.+#i' => ['https://pca.st/oembed.json', true],
            '#https?://((play|www)\.)?anghami\.com/.*#i' => ['https://api.anghami.com/rest/v1/oembed.view', true],
        ],

        'cache_ttl_days' => 120,

        'failure_ttl_minutes' => 60,
    ],
];

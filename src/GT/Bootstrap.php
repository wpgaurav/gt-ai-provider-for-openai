<?php
/**
 * GT customizations for the OpenAI provider.
 *
 * Improvements bundled here:
 *   - Attachment title & filename sanitizer: replaces generic "AI generated image" / openai-default
 *     filenames with a 4-word meaningful title derived from the current post or prompt, plus a
 *     short random alphanumeric suffix to keep filenames unique and non-guessable.
 *   - Model-list caching via transients so the /v1/models call isn't made on every admin page load.
 *   - Default-model preferences (GPT_OPENAI_DEFAULT_TEXT_MODEL, GT_OPENAI_DEFAULT_IMAGE_MODEL)
 *     surfaced via filters other plugins / the AI Client UI can read.
 *   - Content-site system instruction default that nudges OpenAI toward no-slop, entity-rich output.
 *
 * @package WordPress\OpenAiAiProvider\GT
 */

declare(strict_types=1);

namespace WordPress\OpenAiAiProvider\GT;

if (!defined('ABSPATH')) {
    return;
}

class Bootstrap
{
    /**
     * Pattern matching filenames/titles produced by default AI-image workflows.
     * Every capture here gets renamed.
     */
    private const GENERIC_PATTERNS = [
        '/^ai[-_ ]generated[-_ ]image/i',
        '/^ai[-_ ]image/i',
        '/^openai[-_ ]image/i',
        '/^dalle[-_ ]/i',
        '/^gpt[-_ ]image/i',
        '/^img[-_ ]\d{10,}/i', // "img-1745678901" epoch-style names
    ];

    public static function boot(): void
    {
        Abilities::boot();
        // Rename attachment titles + slugs at insert time.
        add_filter('wp_insert_attachment_data', [self::class, 'renameOnInsert'], 20, 2);
        add_filter('attachment_fields_to_save', [self::class, 'renameOnSave'], 20, 2);
        add_action('add_attachment', [self::class, 'renameAfterInsert'], 20, 1);

        // Rename the underlying file when uploaded via the media library.
        add_filter('wp_handle_upload_prefilter', [self::class, 'renameUploadFile'], 20, 1);
        add_filter('wp_handle_sideload_prefilter', [self::class, 'renameUploadFile'], 20, 1);

        // Default-model preferences surfaced via filters.
        add_filter('gt_openai_default_text_model', [self::class, 'defaultTextModel']);
        add_filter('gt_openai_default_image_model', [self::class, 'defaultImageModel']);
        add_filter('gt_openai_default_meta_model', [self::class, 'defaultMetaModel']);

        // Cache layer for model discovery — handled via a transient-backed HTTP filter.
        add_filter('pre_http_request', [self::class, 'cacheOpenAiModelsResponse'], 10, 3);
        add_action('http_api_curl', [self::class, 'rememberModelsRequest'], 10, 3);

        // Admin notice when the API key is missing.
        add_action('admin_notices', [self::class, 'maybeWarnMissingKey']);

        // Content-site default system instruction — short, opinionated, voice-aware.
        add_filter('ai_client_default_system_instruction', [self::class, 'contentSiteSystemInstruction']);
    }

    /* ---------------------------------------------------------------------
     * ATTACHMENT RENAMING
     * ------------------------------------------------------------------ */

    public static function renameOnInsert(array $data, array $postarr): array
    {
        if (($data['post_type'] ?? '') !== 'attachment') {
            return $data;
        }

        $title = (string) ($data['post_title'] ?? '');
        if (!self::isGeneric($title)) {
            return $data;
        }

        $newTitle = self::buildHumanTitle($postarr);
        $data['post_title'] = $newTitle;
        $data['post_name']  = sanitize_title($newTitle);

        return $data;
    }

    public static function renameOnSave(array $post, array $attachment): array
    {
        $title = (string) ($post['post_title'] ?? '');
        if (self::isGeneric($title)) {
            $post['post_title'] = self::buildHumanTitle($post);
        }
        return $post;
    }

    
    public static function renameAfterInsert(int $attachmentId): void
    {
        $post = get_post($attachmentId);
        if (!$post || $post->post_type !== 'attachment') {
            return;
        }
        if (!self::isGeneric((string) $post->post_title) && !self::isGeneric((string) $post->post_name)) {
            return;
        }

        $context = [
            'post_parent' => $post->post_parent,
            '_wp_attachment_image_alt' => get_post_meta($attachmentId, '_wp_attachment_image_alt', true),
        ];
        $newTitle = self::buildHumanTitle($context);
        if ($newTitle === '') {
            return;
        }

        // Remove the hook temporarily to avoid recursion via wp_update_post filters.
        remove_action('add_attachment', [self::class, 'renameAfterInsert'], 20);
        wp_update_post([
            'ID'         => $attachmentId,
            'post_title' => $newTitle,
            'post_name'  => sanitize_title($newTitle),
        ]);
        add_action('add_attachment', [self::class, 'renameAfterInsert'], 20, 1);
    }

    public static function renameUploadFile(array $file): array
    {
        if (empty($file['name']) || !self::isGeneric((string) $file['name'])) {
            return $file;
        }
        $ext  = pathinfo($file['name'], PATHINFO_EXTENSION) ?: 'png';
        $base = self::slugify(self::buildHumanTitle([])) . '-' . self::randomSuffix();
        $file['name'] = $base . '.' . strtolower($ext);
        return $file;
    }

    /**
     * True if the given string matches a generic AI-default filename/title.
     */
    private static function isGeneric(string $value): bool
    {
        $value = trim($value);
        if ($value === '') {
            return true;
        }
        foreach (self::GENERIC_PATTERNS as $pattern) {
            if (preg_match($pattern, $value)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Build a 4-word human title + short random alphanumeric suffix.
     * Pulls the topic from: current post title, referring post context, or the provided alt text.
     */
    private static function buildHumanTitle(array $context): string
    {
        $source = '';

        // 1. Try the alt text / description / caption on $context.
        foreach (['_wp_attachment_image_alt', 'post_excerpt', 'post_content'] as $key) {
            if (!empty($context[$key])) {
                $source = (string) $context[$key];
                break;
            }
        }

        // 2a. Try post_parent from the attachment context (set by media_handle_sideload()).
        if ($source === '' && !empty($context['post_parent'])) {
            $parent = get_post((int) $context['post_parent']);
            if ($parent) {
                $source = $parent->post_title;
            }
        }

        // 2b. Try the currently edited post via REST/admin globals.
        if ($source === '') {
            $postId = 0;
            // REST: JSON body with post_id
            if (defined('REST_REQUEST') && REST_REQUEST) {
                $body = file_get_contents('php://input');
                if ($body) {
                    $json = json_decode($body, true);
                    if (is_array($json) && isset($json['input']['post_id'])) {
                        $postId = absint($json['input']['post_id']);
                    }
                }
            }
            if (!$postId) {
                $postId = isset($_REQUEST['post_id']) ? absint($_REQUEST['post_id']) : 0;
            }
            if (!$postId && isset($_REQUEST['post'])) {
                $postId = absint($_REQUEST['post']);
            }
            if ($postId) {
                $post = get_post($postId);
                if ($post) {
                    $source = $post->post_title;
                }
            }
        }

        // 3. Site name fallback.
        if ($source === '') {
            $source = (string) get_bloginfo('name');
        }

        $words = self::topWords($source, 4);
        if ($words === '') {
            $words = 'Featured Image';
        }

        return $words . ' ' . self::randomSuffix();
    }

    /**
     * Extract up to N meaningful words (lowercased, stopwords removed, title-cased for display).
     */
    private static function topWords(string $text, int $max): string
    {
        $text  = strip_tags($text);
        $text  = preg_replace('/[^\p{L}\p{N}\s-]+/u', ' ', $text) ?: '';
        $parts = preg_split('/\s+/u', trim($text)) ?: [];
        $stop  = [
            'the','a','an','and','or','but','for','of','in','on','at','by','to','with',
            'is','are','was','were','be','been','being','have','has','had','do','does',
            'did','will','would','could','should','may','might','must','shall','can',
            'this','that','these','those','it','its','as','from','how','what','why','when',
            'where','who','which','i','we','you','they','he','she','my','our','your',
        ];
        $picked = [];
        foreach ($parts as $word) {
            $clean = strtolower(trim($word));
            if ($clean === '' || in_array($clean, $stop, true)) {
                continue;
            }
            if (mb_strlen($clean) < 2) {
                continue;
            }
            $picked[] = ucfirst($clean);
            if (count($picked) >= $max) {
                break;
            }
        }
        return implode(' ', $picked);
    }

    private static function slugify(string $value): string
    {
        $slug = sanitize_title($value);
        return $slug !== '' ? $slug : 'featured-image';
    }

    /**
     * 6-char alphanumeric suffix like "a7k2df".
     */
    private static function randomSuffix(): string
    {
        try {
            $bytes = random_bytes(4);
        } catch (\Throwable $e) {
            $bytes = pack('N', mt_rand());
        }
        return substr(bin2hex($bytes), 0, 6);
    }

    /* ---------------------------------------------------------------------
     * DEFAULTS
     * ------------------------------------------------------------------ */

    public static function defaultTextModel(): string
    {
        $opt = (string) get_option('gt_openai_default_text_model', '');
        if ($opt !== '') {
            return $opt;
        }
        return defined('GT_OPENAI_DEFAULT_TEXT_MODEL') ? GT_OPENAI_DEFAULT_TEXT_MODEL : 'gpt-5.4';
    }

    public static function defaultImageModel(): string
    {
        $opt = (string) get_option('gt_openai_default_image_model', '');
        if ($opt !== '') {
            return $opt;
        }
        return defined('GT_OPENAI_DEFAULT_IMAGE_MODEL') ? GT_OPENAI_DEFAULT_IMAGE_MODEL : 'gpt-image-2-2026-04-21';
    }

    public static function defaultMetaModel(): string
    {
        $opt = (string) get_option('gt_openai_default_meta_model', '');
        if ($opt !== '') {
            return $opt;
        }
        return defined('GT_OPENAI_DEFAULT_META_MODEL') ? GT_OPENAI_DEFAULT_META_MODEL : 'gpt-4.1-mini';
    }

    public static function contentSiteSystemInstruction(string $existing = ''): string
    {
        if ($existing !== '') {
            return $existing;
        }
        return 'You are helping a content publisher. Prefer specific numbers over adjectives, '
            . 'name entities (tools, brands, versions, prices, dates), avoid AI slop phrases '
            . '("in today\'s fast-paced world", "whether you are", "dive into", "leverage", '
            . '"utilize"), use contractions, and keep paragraphs 1-4 sentences. American English. '
            . 'Answer-first: open every section with the direct answer, then support with evidence.';
    }

    /* ---------------------------------------------------------------------
     * MODEL LIST CACHE
     * ------------------------------------------------------------------ */

    private static ?string $pendingModelsCacheKey = null;

    public static function rememberModelsRequest($handle, $parsed_args, $url): void
    {
        if (is_string($url) && str_contains($url, 'api.openai.com/v1/models')) {
            self::$pendingModelsCacheKey = 'gt_openai_models_cache_' . md5($url);
        }
    }

    /**
     * Serves the /v1/models response from a 24h transient when available.
     * Falls through to the normal HTTP request otherwise, and stores the response.
     */
    public static function cacheOpenAiModelsResponse($preempt, $parsed_args, $url)
    {
        if (!is_string($url) || !str_contains($url, 'api.openai.com/v1/models')) {
            return $preempt;
        }

        $key = 'gt_openai_models_cache_' . md5($url);
        $cached = get_transient($key);
        if (is_array($cached)) {
            return $cached;
        }

        // Not cached — let the real request happen, then capture via http_response.
        add_filter('http_response', function ($response, $args, $rurl) use ($key, $url) {
            if ($rurl === $url && is_array($response) && ($response['response']['code'] ?? 0) === 200) {
                set_transient($key, $response, GT_OPENAI_MODEL_CACHE_TTL);
            }
            return $response;
        }, 10, 3);

        return $preempt;
    }

    /* ---------------------------------------------------------------------
     * ADMIN UX
     * ------------------------------------------------------------------ */

    public static function maybeWarnMissingKey(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        // Same resolution order the AI Client uses: env, constant, then Connectors options.
        if (getenv('OPENAI_API_KEY')) {
            return;
        }
        if (defined('OPENAI_API_KEY') && \OPENAI_API_KEY) {
            return;
        }
        $openaiKey = get_option('connectors_ai_openai_api_key', '');
        if (is_string($openaiKey) && $openaiKey !== '') {
            return;
        }
        $gtCustomKey = get_option('connectors_ai_gt-custom-openai_api_key', '');
        if (is_string($gtCustomKey) && $gtCustomKey !== '') {
            return;
        }
        $url = esc_url(admin_url('options-connectors.php'));
        echo '<div class="notice notice-warning"><p><strong>GT OpenAI Provider:</strong> '
            . sprintf(
                esc_html__('No OpenAI API key configured. Set it on the %s, or define OPENAI_API_KEY in wp-config.php / the environment.', 'gt-ai-provider-for-openai'),
                '<a href="' . $url . '">' . esc_html__('Connectors page', 'gt-ai-provider-for-openai') . '</a>'
            )
            . '</p></div>';
    }
}

<?php
/**
 * GT Abilities registrar.
 *
 * Registers the following abilities on top of the WordPress Abilities API:
 *   - gt/stop-slop-scan             Scan content for AI-slop phrases
 *   - gt/rewrite-for-voice          Rewrite content with a custom voice instruction
 *   - gt/suggest-internal-links     Suggest 3-5 internal links for a post
 *   - gt/generate-faqs-accordion    Generate FAQs as a single ACF accordion block
 *   - gt/set-featured-image         Orchestrate image prompt → generate (16:9) → set as featured
 *   - gt/tts-readaloud              Synthesize an MP3 and attach it to the media library
 *   - gt/rankmath-meta-sync         Write rank_math_title/description/focus_keyword for a post
 *
 * Everything is filterable so anyone can install this plugin and customize
 * defaults without editing code.
 *
 * @package WordPress\OpenAiAiProvider\GT
 */

declare(strict_types=1);

namespace WordPress\OpenAiAiProvider\GT;

use WP_Error;
use WordPress\AiClient\AiClient;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
use WordPress\AiClient\Files\Enums\FileTypeEnum;

if (!defined('ABSPATH')) {
    return;
}

class Abilities
{
    public static function boot(): void
    {
        add_action('wp_abilities_api_categories_init', [self::class, 'registerCategory']);
        add_action('wp_abilities_api_init', [self::class, 'register']);
    }

    public static function registerCategory(): void
    {
        if (function_exists('wp_register_ability_category')) {
            wp_register_ability_category('gt', [
                'label'       => __('GT AI Tools', 'gt-ai-provider-for-openai'),
                'description' => __('Site-specific AI abilities bundled with the GT AI Provider for OpenAI plugin.', 'gt-ai-provider-for-openai'),
            ]);
        }
    }

    public static function register(): void
    {
        if (!function_exists('wp_register_ability')) {
            return;
        }

        wp_register_ability('gt/stop-slop-scan', [
            'label'              => __('Stop AI-Slop Scan', 'gt-ai-provider-for-openai'),
            'category'   => 'gt',
            'meta'       => ['show_in_rest' => true],
            'description'        => __('Scan HTML or plain text for AI-slop phrases and overused AI tells. Returns a list of matches with line numbers and surrounding context.', 'gt-ai-provider-for-openai'),
            'input_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'content' => [
                        'type'              => 'string',
                        'sanitize_callback' => 'wp_kses_post',
                        'description'       => __('HTML or plain text to scan.', 'gt-ai-provider-for-openai'),
                    ],
                    'post_id' => [
                        'type'              => 'integer',
                        'sanitize_callback' => 'absint',
                        'description'       => __('Optional post ID. If provided and content is empty, the post body will be scanned.', 'gt-ai-provider-for-openai'),
                    ],
                ],
            ],
            'output_schema'      => [
                'type'        => 'array',
                'description' => __('Array of detected slop phrases with location info.', 'gt-ai-provider-for-openai'),
                'items'       => [
                    'type'       => 'object',
                    'properties' => [
                        'phrase'  => ['type' => 'string'],
                        'line'    => ['type' => 'integer'],
                        'context' => ['type' => 'string'],
                    ],
                ],
            ],
            'permission_callback' => static fn() => current_user_can('edit_posts'),
            'execute_callback'    => [self::class, 'executeStopSlop'],
        ]);

        wp_register_ability('gt/rewrite-for-voice', [
            'label'              => __('Rewrite for Voice', 'gt-ai-provider-for-openai'),
            'category'   => 'gt',
            'meta'       => ['show_in_rest' => true],
            'description'        => __('Rewrite content using a voice instruction. Enforces contractions, no em-dashes, short paragraphs, and removes AI-slop by default. Override via filter gt_rewrite_default_voice.', 'gt-ai-provider-for-openai'),
            'input_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'content'           => ['type' => 'string', 'description' => __('Content to rewrite.', 'gt-ai-provider-for-openai')],
                    'voice_instruction' => ['type' => 'string', 'description' => __('Optional custom voice instruction (system prompt). Falls back to the filtered default.', 'gt-ai-provider-for-openai')],
                    'model'             => ['type' => 'string', 'description' => __('Optional model override. Defaults to GT_OPENAI_DEFAULT_TEXT_MODEL.', 'gt-ai-provider-for-openai')],
                ],
                'required'   => ['content'],
            ],
            'output_schema'      => ['type' => 'string', 'description' => __('Rewritten content.', 'gt-ai-provider-for-openai')],
            'permission_callback' => static fn() => current_user_can('edit_posts'),
            'execute_callback'    => [self::class, 'executeRewriteForVoice'],
        ]);

        wp_register_ability('gt/suggest-internal-links', [
            'label'              => __('Suggest Internal Links', 'gt-ai-provider-for-openai'),
            'category'   => 'gt',
            'meta'       => ['show_in_rest' => true],
            'description'        => __('Return up to 5 published posts/pages semantically related to a given post, with suggested anchor text. Uses lightweight term overlap by default; plug in your own ranker via the filter gt_internal_link_ranker.', 'gt-ai-provider-for-openai'),
            'input_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'post_id' => ['type' => 'integer', 'description' => __('Post ID to find link targets for.', 'gt-ai-provider-for-openai')],
                    'limit'   => ['type' => 'integer', 'description' => __('Max suggestions (1-10). Default 5.', 'gt-ai-provider-for-openai')],
                ],
                'required'   => ['post_id'],
            ],
            'output_schema'      => [
                'type'        => 'array',
                'description' => __('List of link suggestions.', 'gt-ai-provider-for-openai'),
                'items'       => [
                    'type'       => 'object',
                    'properties' => [
                        'post_id' => ['type' => 'integer'],
                        'title'   => ['type' => 'string'],
                        'url'     => ['type' => 'string'],
                        'anchor'  => ['type' => 'string'],
                        'score'   => ['type' => 'number'],
                    ],
                ],
            ],
            'permission_callback' => static fn() => current_user_can('edit_posts'),
            'execute_callback'    => [self::class, 'executeSuggestInternalLinks'],
        ]);

        wp_register_ability('gt/generate-faqs-accordion', [
            'label'              => __('Generate FAQs (ACF Accordion Block)', 'gt-ai-provider-for-openai'),
            'category'   => 'gt',
            'meta'       => ['show_in_rest' => true],
            'description'        => __('Generate 6-10 FAQs from a post and return them as a single-line ACF accordion block in the flat indexed format required by the acf/accordion block.', 'gt-ai-provider-for-openai'),
            'input_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'post_id' => ['type' => 'integer'],
                    'content' => ['type' => 'string'],
                    'count'   => ['type' => 'integer', 'description' => __('How many FAQs to generate (6-10). Default 8.', 'gt-ai-provider-for-openai')],
                ],
            ],
            'output_schema'      => [
                'type'        => 'object',
                'properties'  => [
                    'block' => ['type' => 'string', 'description' => __('ACF accordion block markup, single line.', 'gt-ai-provider-for-openai')],
                    'faqs'  => ['type' => 'array', 'description' => __('Raw array of {question, answer} pairs.', 'gt-ai-provider-for-openai')],
                ],
            ],
            'permission_callback' => static fn() => current_user_can('edit_posts'),
            'execute_callback'    => [self::class, 'executeGenerateFaqs'],
        ]);

        wp_register_ability('gt/set-featured-image', [
            'label'              => __('Generate and Set Featured Image', 'gt-ai-provider-for-openai'),
            'category'   => 'gt',
            'meta'       => ['show_in_rest' => true],
            'description'        => __('Generate a 16:9 image from the post content using the configured image model, import it into the media library, and set it as the post\'s featured image.', 'gt-ai-provider-for-openai'),
            'input_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'post_id'       => ['type' => 'integer', 'description' => __('Post to attach the generated image to.', 'gt-ai-provider-for-openai')],
                    'prompt'        => ['type' => 'string',  'description' => __('Optional override prompt. If empty, one is auto-generated from the post.', 'gt-ai-provider-for-openai')],
                    'style_preset'  => ['type' => 'string',  'description' => __('Optional style preset appended to the prompt. Defaults to "photorealistic, editorial magazine quality".', 'gt-ai-provider-for-openai')],
                ],
                'required'   => ['post_id'],
            ],
            'output_schema'      => [
                'type'       => 'object',
                'properties' => [
                    'attachment_id' => ['type' => 'integer'],
                    'url'           => ['type' => 'string'],
                    'prompt_used'   => ['type' => 'string'],
                ],
            ],
            'permission_callback' => static fn($input) => current_user_can('edit_post', (int) ($input['post_id'] ?? 0)) && current_user_can('upload_files'),
            'execute_callback'    => [self::class, 'executeSetFeaturedImage'],
        ]);

        wp_register_ability('gt/tts-readaloud', [
            'label'              => __('TTS Read Aloud', 'gt-ai-provider-for-openai'),
            'category'   => 'gt',
            'meta'       => ['show_in_rest' => true],
            'description'        => __('Synthesize speech from a post or raw text using the configured TTS model and attach the MP3 to the media library.', 'gt-ai-provider-for-openai'),
            'input_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'post_id' => ['type' => 'integer'],
                    'text'    => ['type' => 'string'],
                    'voice'   => ['type' => 'string', 'description' => __('OpenAI voice (alloy, echo, fable, onyx, nova, shimmer). Default: alloy.', 'gt-ai-provider-for-openai')],
                    'model'   => ['type' => 'string', 'description' => __('TTS model. Default: gpt-4o-mini-tts.', 'gt-ai-provider-for-openai')],
                ],
            ],
            'output_schema'      => [
                'type'       => 'object',
                'properties' => [
                    'attachment_id' => ['type' => 'integer'],
                    'url'           => ['type' => 'string'],
                    'duration'      => ['type' => 'number'],
                ],
            ],
            'permission_callback' => static fn() => current_user_can('upload_files'),
            'execute_callback'    => [self::class, 'executeTts'],
        ]);

        wp_register_ability('gt/rankmath-meta-sync', [
            'label'              => __('Rank Math Meta Sync', 'gt-ai-provider-for-openai'),
            'category'   => 'gt',
            'meta'       => ['show_in_rest' => true],
            'description'        => __('Write rank_math_title, rank_math_description, and rank_math_focus_keyword to a post. Silent no-op if Rank Math is not active.', 'gt-ai-provider-for-openai'),
            'input_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'post_id'              => ['type' => 'integer'],
                    'rank_math_title'      => ['type' => 'string'],
                    'rank_math_description'=> ['type' => 'string'],
                    'rank_math_focus_keyword' => ['type' => 'string'],
                ],
                'required'   => ['post_id'],
            ],
            'output_schema'      => [
                'type'       => 'object',
                'properties' => [
                    'updated'            => ['type' => 'array'],
                    'rank_math_active'   => ['type' => 'boolean'],
                ],
            ],
            'permission_callback' => static fn($input) => current_user_can('edit_post', (int) ($input['post_id'] ?? 0)),
            'execute_callback'    => [self::class, 'executeRankMathSync'],
        ]);
    }

    /* =====================================================================
     * gt/stop-slop-scan
     * ================================================================== */

    public static function executeStopSlop($input)
    {
        $content = (string) ($input['content'] ?? '');
        if ($content === '' && !empty($input['post_id'])) {
            $post = get_post((int) $input['post_id']);
            if (!$post) {
                return new WP_Error('post_not_found', __('Post not found.', 'gt-ai-provider-for-openai'));
            }
            $content = $post->post_content;
        }
        if ($content === '') {
            return new WP_Error('no_content', __('Provide content or a post_id.', 'gt-ai-provider-for-openai'));
        }

        $text = wp_strip_all_tags($content);
        $lines = preg_split("/\r\n|\n|\r/", $text) ?: [];

        /**
         * Default AI-slop phrase list. Each entry is a plain substring match
         * (case-insensitive). Override with your own library (e.g. from an
         * editorial style guide) via this filter.
         *
         * @param string[] $phrases
         */
        $phrases = apply_filters('gt_stop_slop_banned_phrases', Defaults::slopPhrases());

        $hits = [];
        foreach ($lines as $i => $line) {
            foreach ($phrases as $phrase) {
                if ($phrase === '') continue;
                if (stripos($line, $phrase) !== false) {
                    $hits[] = [
                        'phrase'  => $phrase,
                        'line'    => $i + 1,
                        'context' => trim(mb_substr($line, 0, 160)),
                    ];
                }
            }
        }
        return $hits;
    }

    /* =====================================================================
     * gt/rewrite-for-voice
     * ================================================================== */

    public static function executeRewriteForVoice($input)
    {
        $content = (string) ($input['content'] ?? '');
        if ($content === '') {
            return new WP_Error('no_content', __('Content is required.', 'gt-ai-provider-for-openai'));
        }
        $voice = (string) ($input['voice_instruction'] ?? '');
        if ($voice === '') {
            /**
             * Default voice instruction. Customize per-site.
             *
             * @param string $instruction
             */
            $voice = (string) apply_filters('gt_rewrite_default_voice', Defaults::voiceInstruction());
        }
        $model = (string) ($input['model'] ?? apply_filters('gt_openai_default_text_model', defined('GT_OPENAI_DEFAULT_TEXT_MODEL') ? GT_OPENAI_DEFAULT_TEXT_MODEL : 'gpt-5.4'));

        if (!class_exists(AiClient::class)) {
            return new WP_Error('ai_client_missing', __('WordPress AI Client is not available on this site.', 'gt-ai-provider-for-openai'));
        }

        try {
            $gtRO = new RequestOptions();
            $gtRO->setTimeout(120.0);
            $result = AiClient::prompt($content)
                ->usingProvider('openai')
                ->usingRequestOptions($gtRO)
                ->usingModelPreference($model)
                ->usingSystemInstruction($voice)
                ->generateTextResult();
            return (string) $result->toText();
        } catch (\Throwable $e) {
            return new WP_Error('ai_request_failed', $e->getMessage());
        }
    }

    /* =====================================================================
     * gt/suggest-internal-links
     * ================================================================== */

    public static function executeSuggestInternalLinks($input)
    {
        $postId = (int) ($input['post_id'] ?? 0);
        $limit  = max(1, min(10, (int) ($input['limit'] ?? 5)));

        $post = $postId ? get_post($postId) : null;
        if (!$post) {
            return new WP_Error('post_not_found', __('Post not found.', 'gt-ai-provider-for-openai'));
        }

        // Extract keyword candidates from title + first 500 chars of content.
        $haystack = $post->post_title . ' ' . wp_strip_all_tags($post->post_content);
        $terms    = self::extractTerms($haystack, 15);

        if (empty($terms)) {
            return [];
        }

        $post_types = apply_filters('gt_internal_link_post_types', ['post', 'page']);
        $query = new \WP_Query([
            'post_type'      => $post_types,
            'post_status'    => 'publish',
            'posts_per_page' => 50,
            's'              => implode(' ', array_slice($terms, 0, 5)),
            'post__not_in'   => [$postId],
            'no_found_rows'  => true,
            'orderby'        => 'relevance',
        ]);

        $candidates = [];
        foreach ($query->posts as $candidate) {
            $candText = $candidate->post_title . ' ' . wp_strip_all_tags($candidate->post_content);
            $candTerms = self::extractTerms($candText, 20);
            $overlap   = count(array_intersect($terms, $candTerms));
            if ($overlap === 0) continue;
            $score = $overlap / max(count($terms), 1);
            $candidates[] = [
                'post_id' => $candidate->ID,
                'title'   => $candidate->post_title,
                'url'     => get_permalink($candidate),
                'anchor'  => self::suggestAnchor($candidate->post_title, $terms),
                'score'   => round($score, 3),
            ];
        }

        /**
         * Pluggable ranker: receives (suggestions, source post). Default ranks
         * by overlap score descending.
         */
        $ranked = apply_filters('gt_internal_link_ranker', $candidates, $post);
        usort($ranked, static fn($a, $b) => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));
        return array_slice($ranked, 0, $limit);
    }

    /* =====================================================================
     * gt/generate-faqs-accordion
     * ================================================================== */

    public static function executeGenerateFaqs($input)
    {
        $content = (string) ($input['content'] ?? '');
        $count   = max(6, min(10, (int) ($input['count'] ?? 8)));

        if ($content === '' && !empty($input['post_id'])) {
            $post = get_post((int) $input['post_id']);
            if (!$post) {
                return new WP_Error('post_not_found', __('Post not found.', 'gt-ai-provider-for-openai'));
            }
            $content = $post->post_title . "\n\n" . $post->post_content;
        }
        if ($content === '') {
            return new WP_Error('no_content', __('Provide content or a post_id.', 'gt-ai-provider-for-openai'));
        }
        if (!class_exists(AiClient::class)) {
            return new WP_Error('ai_client_missing', __('WordPress AI Client is not available.', 'gt-ai-provider-for-openai'));
        }

        $prompt = sprintf(
            "Read the article below and generate exactly %d FAQs that are NOT already answered verbatim by an H2 in the article. "
            . "Return JSON ONLY in this exact shape: {\"faqs\":[{\"q\":\"...\",\"a\":\"<p>...</p>\"}]}. "
            . "Every answer must be wrapped in <p> tags, 1-3 sentences, direct answer first, American English, no AI-slop phrases. "
            . "\n\n=== ARTICLE ===\n%s\n=== END ===",
            $count,
            wp_strip_all_tags(wp_trim_words($content, 3000, '...'))
        );

        $model = apply_filters('gt_openai_default_meta_model', defined('GT_OPENAI_DEFAULT_META_MODEL') ? GT_OPENAI_DEFAULT_META_MODEL : 'gpt-4.1-mini');

        try {
            $gtRO = new RequestOptions();
            $gtRO->setTimeout(120.0);
            $raw = AiClient::prompt($prompt)
                ->usingProvider('openai')
                ->usingRequestOptions($gtRO)
                ->usingModelPreference($model)
                ->generateTextResult()
                ->toText();
        } catch (\Throwable $e) {
            return new WP_Error('ai_request_failed', $e->getMessage());
        }

        // Parse JSON (tolerate code fences).
        $json = preg_replace('/^```(?:json)?|```$/m', '', trim($raw));
        $decoded = json_decode((string) $json, true);
        if (!is_array($decoded) || empty($decoded['faqs'])) {
            return new WP_Error('bad_ai_response', __('AI returned non-JSON FAQ output.', 'gt-ai-provider-for-openai'));
        }

        $faqs = [];
        foreach ($decoded['faqs'] as $row) {
            $q = trim((string) ($row['q'] ?? ''));
            $a = trim((string) ($row['a'] ?? ''));
            if ($q === '' || $a === '') continue;
            if (strpos($a, '<p>') === false) {
                $a = '<p>' . esc_html($a) . '</p>';
            }
            $faqs[] = ['question' => $q, 'answer' => $a];
        }

        if (!$faqs) {
            return new WP_Error('empty_faqs', __('AI returned zero valid FAQs.', 'gt-ai-provider-for-openai'));
        }

        // Build the flat-index ACF accordion block.
        $data = [
            'acf_accord_enable_faq_schema'  => '1',
            '_acf_accord_enable_faq_schema' => 'field_acf_accord_enable_faq_schema',
            'acf_accord_groups'             => (string) count($faqs),
            '_acf_accord_groups'            => 'field_acf_accord_groups',
        ];
        foreach ($faqs as $i => $f) {
            $data["acf_accord_groups_{$i}_acf_accord_group_title"]    = $f['question'];
            $data["_acf_accord_groups_{$i}_acf_accord_group_title"]   = 'field_acf_accord_group_title';
            $data["acf_accord_groups_{$i}_acf_accord_group_content"]  = $f['answer'];
            $data["_acf_accord_groups_{$i}_acf_accord_group_content"] = 'field_acf_accord_group_content';
        }
        $data['acf_accordion_class']   = '';
        $data['_acf_accordion_class']  = 'field_acf_accordion_class';
        $data['acf_accordion_inline'] = '';
        $data['_acf_accordion_inline']= 'field_acf_accordion_inline';

        $attrs = [
            'name' => 'acf/accordion',
            'data' => $data,
            'mode' => 'preview',
        ];
        $block = '<!-- wp:acf/accordion ' . wp_json_encode($attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ' /-->';

        return ['block' => $block, 'faqs' => $faqs];
    }

    /* =====================================================================
     * gt/set-featured-image
     * ================================================================== */

    public static function executeSetFeaturedImage($input)
    {
        $postId = (int) ($input['post_id'] ?? 0);
        $post   = $postId ? get_post($postId) : null;
        if (!$post) {
            return new WP_Error('post_not_found', __('Post not found.', 'gt-ai-provider-for-openai'));
        }
        if (!class_exists(AiClient::class)) {
            return new WP_Error('ai_client_missing', __('WordPress AI Client is not available.', 'gt-ai-provider-for-openai'));
        }

        // Build prompt from inputs / post, with a consistent style preset.
        $prompt = trim((string) ($input['prompt'] ?? ''));
        if ($prompt === '') {
            $prompt = $post->post_title;
            if ($post->post_excerpt) {
                $prompt .= '. ' . $post->post_excerpt;
            }
        }
        $stylePreset = (string) ($input['style_preset'] ?? '');
        if ($stylePreset === '') {
            $stylePreset = (string) apply_filters(
                'gt_featured_image_style_preset',
                'Style: photorealistic, cinematic lighting, shallow depth of field, editorial magazine composition, rich color grading, crisp focal subject. No text overlays.'
            );
        }
        $finalPrompt = $prompt . "\n\n" . $stylePreset;

        // 16:9 landscape featured image.
        $imageModel = apply_filters('gt_openai_default_image_model', defined('GT_OPENAI_DEFAULT_IMAGE_MODEL') ? GT_OPENAI_DEFAULT_IMAGE_MODEL : 'gpt-image-2-2026-04-21');

        try {
            $requestOptions = new RequestOptions();
            $requestOptions->setTimeout(120.0);
            $result = AiClient::prompt($finalPrompt)
                ->usingRequestOptions($requestOptions)
                ->asOutputFileType(FileTypeEnum::inline())
                ->usingModelPreference($imageModel, 'gpt-image-2', 'gpt-image-1.5', 'gpt-image-1')
                ->asOutputMediaAspectRatio('3:2')
                ->generateImageResult();
        } catch (\Throwable $e) {
            return new WP_Error('image_generation_failed', $e->getMessage());
        }

        // Extract base64 from the first image file.
        $b64 = null;
        foreach ($result->toFiles() as $file) {
            // File interface exposes getBase64Data() in the AI Client.
            if (method_exists($file, 'getBase64Data')) {
                $b64 = $file->getBase64Data();
                break;
            }
        }
        if (!$b64) {
            return new WP_Error('no_image_returned', __('No base64 image returned from the model.', 'gt-ai-provider-for-openai'));
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $bytes = base64_decode($b64);
        if ($bytes === false) {
            return new WP_Error('bad_image_data', __('Failed to decode image data.', 'gt-ai-provider-for-openai'));
        }

        $filename = self::filenameFromPost($post, 'png');
        $upload   = wp_upload_bits($filename, null, $bytes);
        if (!empty($upload['error'])) {
            return new WP_Error('upload_failed', $upload['error']);
        }

        $filetype = wp_check_filetype($upload['file'], null);
        $attachmentId = wp_insert_attachment([
            'post_mime_type' => $filetype['type'] ?? 'image/png',
            'post_title'     => self::readableTitleFromPost($post),
            'post_content'   => '',
            'post_status'    => 'inherit',
        ], $upload['file'], $postId);

        if (is_wp_error($attachmentId)) {
            return $attachmentId;
        }

        wp_update_attachment_metadata(
            $attachmentId,
            wp_generate_attachment_metadata($attachmentId, $upload['file'])
        );
        update_post_meta($attachmentId, '_wp_attachment_image_alt', self::altFromPost($post));
        set_post_thumbnail($postId, $attachmentId);

        return [
            'attachment_id' => (int) $attachmentId,
            'url'           => wp_get_attachment_url($attachmentId),
            'prompt_used'   => $finalPrompt,
        ];
    }

    /* =====================================================================
     * gt/tts-readaloud
     * ================================================================== */

    public static function executeTts($input)
    {
        $text  = (string) ($input['text'] ?? '');
        $post  = null;
        if ($text === '' && !empty($input['post_id'])) {
            $post = get_post((int) $input['post_id']);
            if (!$post) {
                return new WP_Error('post_not_found', __('Post not found.', 'gt-ai-provider-for-openai'));
            }
            $text = wp_strip_all_tags($post->post_title . '. ' . $post->post_content);
        }
        if ($text === '') {
            return new WP_Error('no_text', __('Provide text or a post_id.', 'gt-ai-provider-for-openai'));
        }

        $voice = (string) ($input['voice'] ?? 'alloy');
        $model = (string) ($input['model'] ?? 'gpt-4o-mini-tts');

        $apiKey = self::apiKey();
        if (!$apiKey) {
            return new WP_Error('no_api_key', __('OPENAI_API_KEY is not configured.', 'gt-ai-provider-for-openai'));
        }

        $response = wp_remote_post('https://api.openai.com/v1/audio/speech', [
            'timeout' => 120,
            'headers' => [
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode([
                'model'  => $model,
                'input'  => mb_substr($text, 0, 4000),
                'voice'  => $voice,
                'format' => 'mp3',
            ]),
        ]);
        if (is_wp_error($response)) {
            return $response;
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return new WP_Error('tts_failed', wp_remote_retrieve_body($response));
        }
        $audio = wp_remote_retrieve_body($response);

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $base = $post ? sanitize_title($post->post_name ?: $post->post_title) : 'tts-' . time();
        $filename = $base . '-readaloud.mp3';
        $upload = wp_upload_bits($filename, null, $audio);
        if (!empty($upload['error'])) {
            return new WP_Error('upload_failed', $upload['error']);
        }
        $attId = wp_insert_attachment([
            'post_mime_type' => 'audio/mpeg',
            'post_title'     => $post ? ($post->post_title . ' — Read Aloud') : __('Read Aloud', 'gt-ai-provider-for-openai'),
            'post_status'    => 'inherit',
        ], $upload['file'], $post ? $post->ID : 0);
        if (is_wp_error($attId)) {
            return $attId;
        }
        wp_update_attachment_metadata($attId, wp_generate_attachment_metadata($attId, $upload['file']));

        $meta = wp_get_attachment_metadata($attId) ?: [];
        return [
            'attachment_id' => (int) $attId,
            'url'           => wp_get_attachment_url($attId),
            'duration'      => isset($meta['length']) ? (float) $meta['length'] : 0,
        ];
    }

    /* =====================================================================
     * gt/rankmath-meta-sync
     * ================================================================== */

    public static function executeRankMathSync($input)
    {
        $postId = (int) ($input['post_id'] ?? 0);
        if (!$postId || !get_post($postId)) {
            return new WP_Error('post_not_found', __('Post not found.', 'gt-ai-provider-for-openai'));
        }
        $rankMathActive = defined('RANK_MATH_VERSION') || class_exists('\\RankMath\\Helper');
        $updated = [];

        $map = [
            'rank_math_title'         => 'sanitize_text_field',
            'rank_math_description'   => 'sanitize_textarea_field',
            'rank_math_focus_keyword' => 'sanitize_text_field',
        ];
        foreach ($map as $key => $sanitizer) {
            if (!array_key_exists($key, $input)) continue;
            $value = call_user_func($sanitizer, (string) $input[$key]);
            update_post_meta($postId, $key, $value);
            $updated[] = $key;
        }

        return ['updated' => $updated, 'rank_math_active' => $rankMathActive];
    }

    /* =====================================================================
     * HELPERS
     * ================================================================== */

    /**
     * Extract up to N distinct lowercase terms, removing stopwords and short words.
     *
     * @return string[]
     */
    private static function extractTerms(string $text, int $max = 15): array
    {
        $text = strip_tags($text);
        $text = preg_replace('/[^\p{L}\p{N}\s-]+/u', ' ', $text) ?: '';
        $parts = preg_split('/\s+/u', strtolower(trim($text))) ?: [];
        $stop = Defaults::stopwords();
        $terms = [];
        foreach ($parts as $word) {
            if (mb_strlen($word) < 4) continue;
            if (in_array($word, $stop, true)) continue;
            if (!isset($terms[$word])) {
                $terms[$word] = true;
            }
            if (count($terms) >= $max) break;
        }
        return array_keys($terms);
    }

    private static function suggestAnchor(string $title, array $sourceTerms): string
    {
        // Try to find a 2-4 word overlap between the candidate title and source terms.
        $words = preg_split('/\s+/', strtolower($title)) ?: [];
        $match = array_values(array_intersect($words, $sourceTerms));
        if (count($match) >= 2) {
            return trim(implode(' ', array_slice($match, 0, 4)));
        }
        return $title;
    }

    private static function filenameFromPost(\WP_Post $post, string $ext): string
    {
        $base = sanitize_title($post->post_name ?: $post->post_title) ?: 'featured-image';
        try {
            $rand = substr(bin2hex(random_bytes(3)), 0, 6);
        } catch (\Throwable $e) {
            $rand = substr(md5((string) mt_rand()), 0, 6);
        }
        return $base . '-' . $rand . '.' . $ext;
    }

    private static function readableTitleFromPost(\WP_Post $post): string
    {
        $title = trim(wp_strip_all_tags($post->post_title));
        return $title !== '' ? $title : __('Featured Image', 'gt-ai-provider-for-openai');
    }

    private static function altFromPost(\WP_Post $post): string
    {
        if ($post->post_excerpt) {
            return wp_strip_all_tags($post->post_excerpt);
        }
        return wp_strip_all_tags($post->post_title);
    }

    private static function apiKey(): ?string
    {
        if (defined('OPENAI_API_KEY') && \OPENAI_API_KEY) {
            return (string) \OPENAI_API_KEY;
        }
        $env = getenv('OPENAI_API_KEY');
        return $env ?: null;
    }
}

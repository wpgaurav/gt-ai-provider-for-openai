<?php
/**
 * Model-list fetcher + 7-day transient cache.
 *
 * Queries OpenAI's /v1/models endpoint with the configured API key, groups
 * results by capability (text, image, meta), and caches for 7 days. Used to
 * populate the default-model dropdowns on the GT AI settings page.
 *
 * @package WordPress\OpenAiAiProvider\GT
 */

declare(strict_types=1);

namespace WordPress\OpenAiAiProvider\GT;

if (!defined('ABSPATH')) {
    return;
}

class ModelList
{
    private const TRANSIENT_KEY = 'gt_openai_model_list_v1';
    private const BUCKETS = ['text', 'image', 'meta'];

    /**
     * Get the cached model list, refreshing on demand if missing or forced.
     *
     * @return array{text: list<string>, image: list<string>, meta: list<string>}
     */
    public static function get(bool $forceRefresh = false): array
    {
        if (!$forceRefresh) {
            $cached = get_transient(self::TRANSIENT_KEY);
            if (is_array($cached) && isset($cached['text'], $cached['image'], $cached['meta'])) {
                return $cached;
            }
        }
        $fresh = self::fetchAndCategorize();
        if ($fresh === null) {
            // Return a sensible fallback list so dropdowns aren't empty when the API is down.
            return self::fallback();
        }
        $ttl = defined('GT_OPENAI_MODEL_CACHE_TTL') ? (int) \GT_OPENAI_MODEL_CACHE_TTL : (7 * DAY_IN_SECONDS);
        set_transient(self::TRANSIENT_KEY, $fresh, $ttl);
        return $fresh;
    }

    public static function forget(): void
    {
        delete_transient(self::TRANSIENT_KEY);
    }

    /**
     * Fetch /v1/models directly via wp_remote_get (bypasses the AI Client so
     * this works even when the Connectors page is blank).
     *
     * @return array{text: list<string>, image: list<string>, meta: list<string>}|null
     */
    private static function fetchAndCategorize(): ?array
    {
        $apiKey = self::apiKey();
        if (!$apiKey) {
            return null;
        }

        $baseUrl = self::baseUrl();
        $response = wp_remote_get($baseUrl . '/models', [
            'timeout' => 15,
            'headers' => ['Authorization' => 'Bearer ' . $apiKey],
        ]);
        if (is_wp_error($response)) {
            return null;
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return null;
        }
        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($body) || empty($body['data'])) {
            return null;
        }

        $text = [];
        $image = [];

        foreach ($body['data'] as $entry) {
            $id = (string) ($entry['id'] ?? '');
            if ($id === '') continue;

            // Image models
            if (str_starts_with($id, 'gpt-image-') || str_starts_with($id, 'dall-e-')) {
                $image[] = $id;
                continue;
            }

            // Skip realtime, audio, tts, embedding, moderation — not suitable for text gen.
            if (str_contains($id, '-realtime')
                || str_contains($id, '-audio')
                || str_starts_with($id, 'tts-')
                || str_contains($id, '-tts')
                || str_starts_with($id, 'whisper-')
                || str_starts_with($id, 'text-embedding-')
                || str_starts_with($id, 'omni-moderation-')
                || str_contains($id, '-transcribe')) {
                continue;
            }

            // Text generation family: gpt-*, o1-*, o3-*, o4-*
            if (str_starts_with($id, 'gpt-')
                || preg_match('/^o\d-/', $id)) {
                $text[] = $id;
            }
        }

        // Dedupe + sort with latest-first preference.
        $text  = self::sortPreferred(array_values(array_unique($text)), 'text');
        $image = self::sortPreferred(array_values(array_unique($image)), 'image');

        // "Meta" models are a subset of text models that are fast + cheap (for
        // meta descriptions, FAQ generation, etc). Prefer -mini, -nano suffixes.
        $meta = $text;
        usort($meta, static function (string $a, string $b): int {
            $aMini = (int) (str_contains($a, '-mini') || str_contains($a, '-nano'));
            $bMini = (int) (str_contains($b, '-mini') || str_contains($b, '-nano'));
            if ($aMini !== $bMini) {
                return $bMini <=> $aMini;
            }
            return strcmp($a, $b);
        });

        return ['text' => $text, 'image' => $image, 'meta' => $meta];
    }

    /**
     * @param list<string> $ids
     * @param 'text'|'image' $family
     * @return list<string>
     */
    private static function sortPreferred(array $ids, string $family): array
    {
        usort($ids, static function (string $a, string $b) use ($family): int {
            if ($family === 'image') {
                // gpt-image-2 > gpt-image-1.5 > gpt-image-1 > dall-e-*
                $aNum = preg_match('/^gpt-image-(\d+(?:\.\d+)?)/', $a, $m) ? (float) $m[1] : -1;
                $bNum = preg_match('/^gpt-image-(\d+(?:\.\d+)?)/', $b, $m) ? (float) $m[1] : -1;
                if ($aNum !== $bNum) return $bNum <=> $aNum;
                // Later dated snapshot wins.
                if (preg_match('/(\d{4}-\d{2}-\d{2})$/', $a, $ma)
                    && preg_match('/(\d{4}-\d{2}-\d{2})$/', $b, $mb)) {
                    return strcmp($mb[1], $ma[1]);
                }
                return strcmp($a, $b);
            }
            // text family
            $aMatch = preg_match('/^gpt-(\d+(?:\.\d+)?)(?:-|$)/', $a, $am);
            $bMatch = preg_match('/^gpt-(\d+(?:\.\d+)?)(?:-|$)/', $b, $bm);
            if ($aMatch && $bMatch) {
                $av = (float) $am[1]; $bv = (float) $bm[1];
                if ($av !== $bv) return $bv <=> $av;
            } elseif ($aMatch) {
                return -1;
            } elseif ($bMatch) {
                return 1;
            }
            return strcmp($a, $b);
        });
        return $ids;
    }

    /**
     * @return array{text: list<string>, image: list<string>, meta: list<string>}
     */
    private static function fallback(): array
    {
        $text = ['gpt-5.4', 'gpt-5.1', 'gpt-5', 'gpt-4.1', 'gpt-4.1-mini', 'gpt-4o', 'gpt-4o-mini'];
        $image = ['gpt-image-2-2026-04-21', 'gpt-image-2', 'gpt-image-1.5', 'gpt-image-1', 'dall-e-3'];
        return ['text' => $text, 'image' => $image, 'meta' => ['gpt-4.1-mini', 'gpt-4o-mini', 'gpt-5.4']];
    }

    private static function apiKey(): ?string
    {
        if (defined('OPENAI_API_KEY') && \OPENAI_API_KEY) {
            return (string) \OPENAI_API_KEY;
        }
        $env = getenv('OPENAI_API_KEY');
        if ($env !== false && $env !== '') {
            return $env;
        }
        $opt = get_option('connectors_ai_openai_api_key', '');
        return is_string($opt) && $opt !== '' ? $opt : null;
    }

    private static function baseUrl(): string
    {
        // Match the OpenAI provider's base URL.
        return 'https://api.openai.com/v1';
    }
}

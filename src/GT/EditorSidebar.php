<?php
/**
 * Enqueues the GT AI sidebar panel in the block editor.
 *
 * Adds a "GT AI Tools" panel to the post sidebar with two one-click actions:
 *   - Generate Featured Image (16:9)  → invokes gt/set-featured-image
 *   - Generate FAQs (8)               → invokes gt/generate-faqs-accordion
 *
 * Both buttons call the WP Abilities REST API and apply the result to the
 * current post without reloading.
 *
 * @package WordPress\OpenAiAiProvider\GT
 */

declare(strict_types=1);

namespace WordPress\OpenAiAiProvider\GT;

if (!defined('ABSPATH')) {
    return;
}

class EditorSidebar
{
    public static function boot(): void
    {
        add_action('enqueue_block_editor_assets', [self::class, 'enqueue']);
    }

    public static function enqueue(): void
    {
        $handle     = 'gt-ai-sidebar';
        $plugin_url = plugins_url('', GT_OPENAI_PLUGIN_FILE);
        $asset_path = dirname(GT_OPENAI_PLUGIN_FILE) . '/assets/editor-sidebar.js';
        $asset_url  = $plugin_url . '/assets/editor-sidebar.js';

        if (!file_exists($asset_path)) {
            return;
        }

        wp_enqueue_script(
            $handle,
            $asset_url,
            [
                'wp-plugins',
                'wp-edit-post',
                'wp-editor',
                'wp-element',
                'wp-components',
                'wp-data',
                'wp-api-fetch',
                'wp-blocks',
                'wp-i18n',
            ],
            (string) filemtime($asset_path),
            true
        );

        if (function_exists('wp_set_script_translations')) {
            wp_set_script_translations($handle, 'gt-ai-provider-for-openai');
        }
    }
}

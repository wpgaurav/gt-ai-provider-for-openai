<?php

/**
 * Plugin Name: GT AI Provider for OpenAI
 * Plugin URI: https://github.com/WordPress/ai-provider-for-openai
 * Description: OpenAI provider for the WordPress AI Client, customized by Gaurav Tiwari: latest model preferences (GPT 5.4, GPT Image 2), larger aspect-ratio set, cached model discovery, human-friendly titles for AI-generated media.
 * Requires at least: 6.9
 * Requires PHP: 7.4
 * Version: 1.0.2-gt1
 * Author: Gaurav Tiwari (fork of WordPress AI Team)
 * Author URI: https://gauravtiwari.org/
 * License: GPL-2.0-or-later
 * License URI: https://spdx.org/licenses/GPL-2.0-or-later.html
 * Text Domain: gt-ai-provider-for-openai
 *
 * @package WordPress\OpenAiAiProvider
 */

declare(strict_types=1);

namespace WordPress\OpenAiAiProvider;

// Full path to this plugin's main file; used for plugin_basename() in admin UI.
define('GT_OPENAI_PLUGIN_FILE', __FILE__);

use WordPress\AiClient\AiClient;
use WordPress\OpenAiAiProvider\Provider\OpenAiProvider;

if (!defined('ABSPATH')) {
    return;
}

// Config constants — override in wp-config.php if you want different behavior.
if (!defined('GT_OPENAI_DEFAULT_TEXT_MODEL')) {
    define('GT_OPENAI_DEFAULT_TEXT_MODEL', 'gpt-5.4');
}
if (!defined('GT_OPENAI_DEFAULT_IMAGE_MODEL')) {
    define('GT_OPENAI_DEFAULT_IMAGE_MODEL', 'gpt-image-2-2026-04-21');
}
if (!defined('GT_OPENAI_DEFAULT_META_MODEL')) {
    define('GT_OPENAI_DEFAULT_META_MODEL', 'gpt-4.1-mini');
}
if (!defined('GT_OPENAI_MODEL_CACHE_TTL')) {
    define('GT_OPENAI_MODEL_CACHE_TTL', 7 * DAY_IN_SECONDS);
}

require_once __DIR__ . '/src/autoload.php';
require_once __DIR__ . '/src/GT/Bootstrap.php';

/**
 * Registers the OpenAI provider with the WordPress AI Client.
 *
 * @since 1.0.0
 */
function register_provider(): void
{
    if (!class_exists(AiClient::class)) {
        return;
    }

    $registry = AiClient::defaultRegistry();

    if ($registry->hasProvider(OpenAiProvider::class)) {
        return;
    }

    $registry->registerProvider(OpenAiProvider::class);
}

add_action('init', __NAMESPACE__ . '\\register_provider', 5);

// Boot GT customizations (image title filter, model cache, default preferences).
add_action('plugins_loaded', [\WordPress\OpenAiAiProvider\GT\Bootstrap::class, 'boot']);
add_action('plugins_loaded', [\WordPress\OpenAiAiProvider\GT\CustomInstance::class, 'boot']);
add_action('plugins_loaded', [\WordPress\OpenAiAiProvider\GT\EditorSidebar::class, 'boot']);

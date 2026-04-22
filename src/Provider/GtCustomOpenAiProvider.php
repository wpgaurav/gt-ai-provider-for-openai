<?php
/**
 * GT Custom OpenAI-compatible provider.
 *
 * Registers a SECOND AI Client provider alongside the vanilla OpenAI one.
 * Base URL is read from the option `connectors_ai_gt_custom_base_url` (falls
 * back to the constant `GT_CUSTOM_OPENAI_BASE_URL`, then to OpenAI's default).
 * API key is read from `OPENAI_API_KEY`-style env/constant using the provider
 * id `gt-custom-openai`, i.e. constant/env `GT_CUSTOM_OPENAI_API_KEY`, or from
 * the option `connectors_ai_gt_custom_openai_api_key` (set via the Connectors
 * page).
 *
 * Because this provider registers itself with `AiClient::defaultRegistry()`,
 * WordPress core's Connectors registry auto-discovers it on
 * `_wp_connectors_init()` and surfaces it on the wp-admin → Connectors page
 * with an API-key input.
 *
 * @package WordPress\OpenAiAiProvider\Provider
 */

declare(strict_types=1);

namespace WordPress\OpenAiAiProvider\Provider;

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Http\Enums\RequestAuthenticationMethod;

if (!defined('ABSPATH')) {
    return;
}

/**
 * Custom OpenAI-compatible provider. Extends the base GT OpenAI provider but
 * overrides the provider ID, display name, and base URL so it shows up as a
 * distinct connector on wp-admin/options-connectors.php.
 */
class GtCustomOpenAiProvider extends OpenAiProvider
{
    public const PROVIDER_ID = 'gt-custom-openai';

    public const BASE_URL_OPTION = 'connectors_ai_gt_custom_openai_base_url';

    public const DEFAULT_BASE_URL = 'https://api.openai.com/v1';

    /**
     * Base URL, resolved at request time so changes via the admin UI take
     * effect without a code deploy.
     */
    protected static function baseUrl(): string
    {
        $value = '';

        // 1. wp-config constant (highest priority).
        if (defined('GT_CUSTOM_OPENAI_BASE_URL') && is_string(\GT_CUSTOM_OPENAI_BASE_URL)) {
            $value = (string) \GT_CUSTOM_OPENAI_BASE_URL;
        }

        // 2. Environment variable.
        if ($value === '') {
            $env = getenv('GT_CUSTOM_OPENAI_BASE_URL');
            if ($env !== false && $env !== '') {
                $value = $env;
            }
        }

        // 3. Option set via the admin settings field.
        if ($value === '' && function_exists('get_option')) {
            $opt = get_option(self::BASE_URL_OPTION, '');
            if (is_string($opt) && $opt !== '') {
                $value = $opt;
            }
        }

        if ($value === '') {
            $value = self::DEFAULT_BASE_URL;
        }

        $value = rtrim($value, '/');
        // Accept either "…/v1" (OpenAI-style) or bare-host; if no /v1 suffix, leave as-is.
        return $value;
    }

    /**
     * Provider metadata — distinct ID, name, description, and credentials URL.
     * The AI Client uses this to populate the Connector entry.
     */
    protected static function createProviderMetadata(): ProviderMetadata
    {
        $args = [
            self::PROVIDER_ID,
            'GT Custom OpenAI Instance',
            ProviderTypeEnum::cloud(),
            admin_url('options-general.php?page=gt-ai-custom-instance'),
            RequestAuthenticationMethod::apiKey(),
        ];

        if (version_compare(AiClient::VERSION, '1.2.0', '>=')) {
            $args[] = function_exists('__')
                ? __('Connect a custom OpenAI-compatible endpoint (Azure OpenAI, OpenRouter, LiteLLM proxy, local LLM server, etc.). Configure the base URL under Settings → GT Custom OpenAI Instance.', 'gt-ai-provider-for-openai')
                : 'Custom OpenAI-compatible endpoint.';
        }

        return new ProviderMetadata(...$args);
    }
}

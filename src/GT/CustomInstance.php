<?php
/**
 * Admin UI + AI Client registration for the GT Custom OpenAI provider.
 *
 * Registers the provider with the WP AI Client default registry (which makes
 * it auto-appear on wp-admin/options-connectors.php), and adds a small
 * Settings → "GT Custom OpenAI Instance" page where the user can enter the
 * base URL (the API key is managed by the Connectors page itself via the
 * option `connectors_ai_gt-custom-openai_api_key`, following core convention).
 *
 * @package WordPress\OpenAiAiProvider\GT
 */

declare(strict_types=1);

namespace WordPress\OpenAiAiProvider\GT;

use WordPress\AiClient\AiClient;
use WordPress\OpenAiAiProvider\Provider\GtCustomOpenAiProvider;

if (!defined('ABSPATH')) {
    return;
}

class CustomInstance
{
    public static function boot(): void
    {
        add_action('init', [self::class, 'registerProvider'], 6);
        add_action('admin_init', [self::class, 'registerSettings']);
        add_action('admin_menu', [self::class, 'addSettingsPage']);
        add_filter('plugin_action_links_' . plugin_basename(GT_OPENAI_PLUGIN_FILE), [self::class, 'addActionLink']);
    }

    public static function registerProvider(): void
    {
        if (!class_exists(AiClient::class)) {
            return;
        }
        $registry = AiClient::defaultRegistry();
        if ($registry->hasProvider(GtCustomOpenAiProvider::class)) {
            return;
        }
        $registry->registerProvider(GtCustomOpenAiProvider::class);
    }

    public static function registerSettings(): void
    {
        register_setting(
            'gt_ai_custom_instance',
            GtCustomOpenAiProvider::BASE_URL_OPTION,
            [
                'type'              => 'string',
                'sanitize_callback' => [self::class, 'sanitizeUrl'],
                'default'           => '',
                'show_in_rest'      => false,
            ]
        );
        foreach ([
            'gt_openai_default_text_model',
            'gt_openai_default_image_model',
            'gt_openai_default_meta_model',
        ] as $opt) {
            register_setting('gt_ai_custom_instance', $opt, [
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'default'           => '',
                'show_in_rest'      => false,
            ]);
        }
    }

    public static function sanitizeUrl($value): string
    {
        $value = is_string($value) ? trim($value) : '';
        if ($value === '') {
            return '';
        }
        $clean = esc_url_raw($value, ['http', 'https']);
        return rtrim($clean, '/');
    }

    public static function addSettingsPage(): void
    {
        add_options_page(
            __('GT Custom OpenAI Instance', 'gt-ai-provider-for-openai'),
            __('GT Custom OpenAI Instance', 'gt-ai-provider-for-openai'),
            'manage_options',
            'gt-ai-custom-instance',
            [self::class, 'renderSettingsPage']
        );
    }

    public static function addActionLink(array $links): array
    {
        $links[] = '<a href="' . esc_url(admin_url('options-general.php?page=gt-ai-custom-instance')) . '">'
            . esc_html__('Custom Instance', 'gt-ai-provider-for-openai') . '</a>';
        $links[] = '<a href="' . esc_url(admin_url('options-connectors.php')) . '">'
            . esc_html__('Connectors', 'gt-ai-provider-for-openai') . '</a>';
        return $links;
    }

    public static function renderSettingsPage(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Handle an explicit cache-refresh click.
        if (isset($_GET['gt_refresh_models'])
            && check_admin_referer('gt_refresh_models')) {
            ModelList::forget();
            ModelList::get(true);
            wp_safe_redirect(add_query_arg(['page' => 'gt-ai-custom-instance', 'gt_refreshed' => 1], admin_url('options-general.php')));
            exit;
        }

        $models = ModelList::get();
        $textModels  = $models['text'];
        $imageModels = $models['image'];
        $metaModels  = $models['meta'];

        $currentText  = (string) get_option('gt_openai_default_text_model', '');
        $currentImage = (string) get_option('gt_openai_default_image_model', '');
        $currentMeta  = (string) get_option('gt_openai_default_meta_model', '');

        $defaultText  = defined('GT_OPENAI_DEFAULT_TEXT_MODEL')  ? \GT_OPENAI_DEFAULT_TEXT_MODEL  : 'gpt-5.4';
        $defaultImage = defined('GT_OPENAI_DEFAULT_IMAGE_MODEL') ? \GT_OPENAI_DEFAULT_IMAGE_MODEL : 'gpt-image-2-2026-04-21';
        $defaultMeta  = defined('GT_OPENAI_DEFAULT_META_MODEL')  ? \GT_OPENAI_DEFAULT_META_MODEL  : 'gpt-4.1-mini';

        $refreshUrl = wp_nonce_url(
            add_query_arg(['page' => 'gt-ai-custom-instance', 'gt_refresh_models' => 1], admin_url('options-general.php')),
            'gt_refresh_models'
        );
        $current = get_option(GtCustomOpenAiProvider::BASE_URL_OPTION, '');
        $constant = defined('GT_CUSTOM_OPENAI_BASE_URL') ? \GT_CUSTOM_OPENAI_BASE_URL : '';
        $env      = getenv('GT_CUSTOM_OPENAI_BASE_URL');
        $effective = $constant ?: ($env ?: ($current ?: GtCustomOpenAiProvider::DEFAULT_BASE_URL));
        $connectorsUrl = admin_url('options-connectors.php');
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('GT Custom OpenAI Instance', 'gt-ai-provider-for-openai'); ?></h1>
            <p><?php esc_html_e('Configure a custom OpenAI-compatible endpoint (Azure OpenAI, OpenRouter, a LiteLLM proxy, a self-hosted model server, or any other OpenAI-protocol API). The API key lives on the Connectors page — this screen only controls the base URL.', 'gt-ai-provider-for-openai'); ?></p>

            <form method="post" action="options.php">
                <?php settings_fields('gt_ai_custom_instance'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="<?php echo esc_attr(GtCustomOpenAiProvider::BASE_URL_OPTION); ?>">
                                <?php esc_html_e('Base URL', 'gt-ai-provider-for-openai'); ?>
                            </label>
                        </th>
                        <td>
                            <input
                                type="url"
                                id="<?php echo esc_attr(GtCustomOpenAiProvider::BASE_URL_OPTION); ?>"
                                name="<?php echo esc_attr(GtCustomOpenAiProvider::BASE_URL_OPTION); ?>"
                                value="<?php echo esc_attr($current); ?>"
                                class="regular-text code"
                                placeholder="https://api.openai.com/v1"
                                <?php echo $constant !== '' ? 'readonly' : ''; ?>
                            />
                            <p class="description">
                                <?php esc_html_e('Must end at the OpenAI-compatible root (e.g. /v1). Examples:', 'gt-ai-provider-for-openai'); ?>
                                <br><code>https://openrouter.ai/api/v1</code>
                                <br><code>https://&lt;resource&gt;.openai.azure.com/openai/v1</code>
                                <br><code>http://localhost:4000/v1</code> <?php esc_html_e('(local LiteLLM/Ollama)', 'gt-ai-provider-for-openai'); ?>
                            </p>
                            <?php if ($constant !== '') : ?>
                                <p class="description">
                                    <strong><?php esc_html_e('Locked:', 'gt-ai-provider-for-openai'); ?></strong>
                                    <?php
                                    /* translators: %s: constant name */
                                    printf(esc_html__('Currently set via the %s constant in wp-config.php. Remove that line to edit here.', 'gt-ai-provider-for-openai'), '<code>GT_CUSTOM_OPENAI_BASE_URL</code>');
                                    ?>
                                </p>
                            <?php elseif ($env !== false && $env !== '') : ?>
                                <p class="description">
                                    <strong><?php esc_html_e('Overridden:', 'gt-ai-provider-for-openai'); ?></strong>
                                    <?php esc_html_e('An environment variable GT_CUSTOM_OPENAI_BASE_URL is currently active and takes precedence.', 'gt-ai-provider-for-openai'); ?>
                                </p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Effective base URL', 'gt-ai-provider-for-openai'); ?></th>
                        <td><code><?php echo esc_html($effective); ?></code></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('API key', 'gt-ai-provider-for-openai'); ?></th>
                        <td>
                            <?php
                            /* translators: %s: URL to the Connectors page */
                            printf(
                                wp_kses(
                                    __('Managed on the <a href="%s">Connectors</a> page as <em>GT Custom OpenAI Instance</em>. You can also define a <code>GT_CUSTOM_OPENAI_API_KEY</code> constant or environment variable — those take precedence over the database value.', 'gt-ai-provider-for-openai'),
                                    ['a' => ['href' => []], 'em' => [], 'code' => []]
                                ),
                                esc_url($connectorsUrl)
                            );
                            ?>
                        </td>
                    </tr>
                </table>
                <h2 style="margin-top:2em"><?php esc_html_e('Default Models', 'gt-ai-provider-for-openai'); ?></h2>
                <p>
                    <?php esc_html_e('These defaults feed the GT abilities (rewrite-for-voice, generate-faqs-accordion, set-featured-image) and any code that reads the gt_openai_default_* filters. Model list is pulled from the OpenAI /v1/models API and cached for 7 days.', 'gt-ai-provider-for-openai'); ?>
                    <?php if (!empty($_GET['gt_refreshed'])) : ?>
                        <br><span style="color:#007017;font-weight:600">✓ <?php esc_html_e('Model list refreshed.', 'gt-ai-provider-for-openai'); ?></span>
                    <?php endif; ?>
                </p>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="gt_openai_default_text_model"><?php esc_html_e('Text generation model', 'gt-ai-provider-for-openai'); ?></label>
                        </th>
                        <td>
                            <select id="gt_openai_default_text_model" name="gt_openai_default_text_model" class="regular-text">
                                <option value=""><?php printf(esc_html__('Default (%s)', 'gt-ai-provider-for-openai'), esc_html($defaultText)); ?></option>
                                <?php foreach ($textModels as $id) : ?>
                                    <option value="<?php echo esc_attr($id); ?>" <?php selected($currentText, $id); ?>><?php echo esc_html($id); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php esc_html_e('Used for rewrite-for-voice and any long-form generation.', 'gt-ai-provider-for-openai'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="gt_openai_default_image_model"><?php esc_html_e('Image generation model', 'gt-ai-provider-for-openai'); ?></label>
                        </th>
                        <td>
                            <select id="gt_openai_default_image_model" name="gt_openai_default_image_model" class="regular-text">
                                <option value=""><?php printf(esc_html__('Default (%s)', 'gt-ai-provider-for-openai'), esc_html($defaultImage)); ?></option>
                                <?php foreach ($imageModels as $id) : ?>
                                    <option value="<?php echo esc_attr($id); ?>" <?php selected($currentImage, $id); ?>><?php echo esc_html($id); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php esc_html_e('Used for set-featured-image (16:9 landscape).', 'gt-ai-provider-for-openai'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="gt_openai_default_meta_model"><?php esc_html_e('Meta / summarization model', 'gt-ai-provider-for-openai'); ?></label>
                        </th>
                        <td>
                            <select id="gt_openai_default_meta_model" name="gt_openai_default_meta_model" class="regular-text">
                                <option value=""><?php printf(esc_html__('Default (%s)', 'gt-ai-provider-for-openai'), esc_html($defaultMeta)); ?></option>
                                <?php foreach ($metaModels as $id) : ?>
                                    <option value="<?php echo esc_attr($id); ?>" <?php selected($currentMeta, $id); ?>><?php echo esc_html($id); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php esc_html_e('Used for generate-faqs-accordion, meta descriptions, summarization. Prefer -mini / -nano variants here for cost.', 'gt-ai-provider-for-openai'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Model list cache', 'gt-ai-provider-for-openai'); ?></th>
                        <td>
                            <a class="button" href="<?php echo esc_url($refreshUrl); ?>"><?php esc_html_e('Refresh now (clear 7-day cache)', 'gt-ai-provider-for-openai'); ?></a>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
}

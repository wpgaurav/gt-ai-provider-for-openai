=== GT AI Provider for OpenAI ===
Contributors: gauravtiwari
Tags: ai, openai, gpt, dalle, abilities
Requires at least: 7.0
Tested up to: 7.1
Stable tag: 1.0.2-gt1
Requires PHP: 7.4
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

OpenAI provider for the WordPress AI Client, with latest-model defaults, a custom OpenAI-compatible connector, and seven content-site Abilities.

== Description ==

GT AI Provider for OpenAI is a content-site-focused fork of the upstream wordpress/ai-provider-for-openai plugin. It keeps the upstream provider intact and adds:

* Latest-first model preferences (GPT 5.4, GPT Image 2) and a dropdown UI populated from the live OpenAI model catalog, cached 7 days.
* A second OpenAI-compatible connector entry on the Connectors page for Azure OpenAI, OpenRouter, LiteLLM, Ollama, or any other OpenAI-protocol endpoint. Base URL managed from a dedicated settings page.
* Seven new WordPress Abilities (gt/stop-slop-scan, gt/rewrite-for-voice, gt/suggest-internal-links, gt/generate-faqs-accordion, gt/set-featured-image, gt/tts-readaloud, gt/rankmath-meta-sync) callable via REST at /wp-json/wp-abilities/v1/abilities/{name}/run.
* A block editor sidebar panel ("GT AI Tools") with one-click buttons for generating a 16:9 featured image and generating an ACF-accordion FAQ block.
* Automatic rename of AI-generated image filenames so they inherit a human-readable title from the parent post instead of ai-generated-image-<timestamp>.png.

Requires WordPress 7.0 or newer, which ships the AI Client and Abilities API in core.

== Installation ==

1. Upload the plugin files to /wp-content/plugins/gt-ai-provider-for-openai/ or install via Plugins > Add New > Upload Plugin.
2. Activate the plugin through the Plugins screen in WordPress.
3. Open Settings > Connectors and paste your OpenAI API key into the OpenAI connector.
4. (Optional) Open Settings > GT Custom OpenAI Instance to pick default models or wire a custom endpoint.

== Frequently Asked Questions ==

= Does this replace the official OpenAI provider? =

No. It registers alongside it. The upstream provider continues to handle everything it did before. The GT additions sit on top.

= Do I need to change anything on existing AI features? =

No. All upstream features (Excerpt Generation, Alt Text Generation, Image Generation, etc. from the WordPress AI plugin) keep working. GT adds extra abilities and UI; it doesn't deactivate any.

= Can I use Azure OpenAI or OpenRouter? =

Yes. Activate the "GT Custom OpenAI Instance" connector on the Connectors page, then set the base URL on Settings > GT Custom OpenAI Instance. Supported values include https://openrouter.ai/api/v1, https://<resource>.openai.azure.com/openai/v1, http://localhost:4000/v1, etc.

= Where do generated images land? =

They're saved to the standard WordPress media library using wp_insert_attachment. Filenames and titles are derived from the parent post title, not the generic ai-generated-image-<timestamp> default.

== Changelog ==

= 1.0.2-gt1 =

* Fork of upstream wordpress/ai-provider-for-openai 1.0.2.
* Add GT Custom OpenAI Instance connector for OpenAI-compatible endpoints.
* Add Settings > GT Custom OpenAI Instance page with base-URL field.
* Add default-model dropdowns (text, image, meta) populated from /v1/models, cached 7 days.
* Add seven new WordPress Abilities under the gt/ category.
* Add block editor sidebar panel with Generate Featured Image (16:9) and Generate FAQs (8) buttons.
* Rename AI-generated images from ai-generated-image-<timestamp> to a post-derived slug.
* Expand gpt-image-* aspect ratio metadata to 9 ratios (1:1, 3:2, 2:3, 7:4, 4:7, 16:9, 9:16, 2:1, 1:2).
* Update model-sort preference so gpt-image-2 and dated snapshots rank above older variants.
* Admin notice accepts the OpenAI key from env, constant, Connectors option, or the GT Custom connector option.

= 1.0.2 =

(Upstream) Add provider description, fix modality combinations, update readme tags.

= 1.0.1 =

(Upstream) Initial plugin release. GPT text generation, DALL-E image generation, function calling, web search.

= 1.0.0 =

(Upstream) Initial Composer package release.

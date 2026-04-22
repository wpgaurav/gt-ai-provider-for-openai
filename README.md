# GT AI Provider for OpenAI

A WordPress AI Client OpenAI provider with content-site enhancements. Fork of [`wordpress/ai-provider-for-openai`](https://github.com/WordPress/ai-provider-for-openai), extended with:

- Latest-model-first defaults (GPT 5.4, GPT Image 2) with a UI to pick any other model from a live, cached dropdown
- A second OpenAI-compatible connector entry (Azure OpenAI, OpenRouter, LiteLLM, local LLM server) registered as a first-class entry on `wp-admin/options-connectors.php`
- 7 new [WordPress Abilities](https://developer.wordpress.org/reference/functions/wp_register_ability/) content sites actually need — slop scanner, voice rewriter, internal-link suggester, FAQ-to-ACF-accordion generator, 16:9 featured-image generator, TTS, Rank Math meta sync
- Two one-click editor sidebar panels in the block editor
- Automatic rename of AI-generated image filenames so you never end up with `ai-generated-image-1776823794.png` again
- Cached 7-day model discovery, shared-transient HTTP layer

Requires WordPress 7.0+ (which ships the AI Client and Abilities API in core).

---

## Installation

### Manual

1. Download the latest release zip or clone this repo into `wp-content/plugins/gt-ai-provider-for-openai/`.
2. In `wp-admin → Plugins`, activate **GT AI Provider for OpenAI**.
3. Open `wp-admin → Settings → Connectors` and paste your OpenAI API key into the **OpenAI** connector.
4. (Optional) Open `Settings → GT Custom OpenAI Instance` to pick default models or wire a custom OpenAI-compatible endpoint.

### Composer

```bash
composer require gauravtiwari/gt-ai-provider-for-openai:dev-main
```

---

## What's inside

### 1. Smarter OpenAI provider defaults

- Prefers the latest GPT 5.x text model and the most recent `gpt-image-2` snapshot when models are auto-discovered.
- Recognizes and sorts dated snapshots (`gpt-image-2-2026-04-21`) above their undated family (`gpt-image-2`).
- Expanded aspect-ratio metadata for `gpt-image-*` so the AI Client advertises `1:1, 3:2, 2:3, 7:4, 4:7, 16:9, 9:16, 2:1, 1:2` instead of three ratios.
- `/v1/models` response cached for 7 days via a single WordPress transient — cuts admin-page load time from 2-3s to near-zero.

### 2. Second OpenAI-compatible connector

Adds a second connector row on `options-connectors.php` called **GT Custom OpenAI Instance**. Use it to plug in any OpenAI-compatible endpoint:

| Scenario | Base URL |
|---|---|
| OpenRouter | `https://openrouter.ai/api/v1` |
| Azure OpenAI | `https://<resource>.openai.azure.com/openai/v1` |
| LiteLLM proxy | `http://localhost:4000/v1` |
| Ollama (OpenAI-compatible shim) | `http://localhost:11434/v1` |

The API key lives on the Connectors page, the base URL lives on `Settings → GT Custom OpenAI Instance`. Both can be overridden via `wp-config.php` constants or environment variables for infra-as-code setups.

### 3. Default-model picker

`Settings → GT Custom OpenAI Instance` adds three dropdowns populated from the live OpenAI model catalog (cached 7 days):

- **Text generation model** — default `gpt-5.4`. Used by `gt/rewrite-for-voice` and any code that reads `apply_filters('gt_openai_default_text_model', '...')`.
- **Image generation model** — default `gpt-image-2-2026-04-21`. Used by `gt/set-featured-image`.
- **Meta / summarization model** — default `gpt-4.1-mini`. Used by `gt/generate-faqs-accordion` and any other short-form generation where cost matters.

A "Refresh now (clear 7-day cache)" button purges the transient and re-fetches.

### 4. New WordPress Abilities

All registered under the `gt` category. Invoke via `/wp-json/wp-abilities/v1/abilities/{name}/run` or programmatically via `wp_get_ability('gt/...')->invoke($input)`.

| Ability | Purpose | Required cap |
|---|---|---|
| `gt/stop-slop-scan` | Scans HTML/text for ~70 AI-slop phrases ("in today's fast-paced world", "dive into", "leverage", "cutting-edge", etc.) and returns `{phrase, line, context}[]`. Phrase list filterable via `gt_stop_slop_banned_phrases`. | `edit_posts` |
| `gt/rewrite-for-voice` | Calls the configured text model with a system instruction that enforces contractions, no em dashes, answer-first paragraphs, and no AI-slop openers. Override the voice via `gt_rewrite_default_voice`. | `edit_posts` |
| `gt/suggest-internal-links` | Returns up to 5 related published posts/pages via term-overlap matching. Post-type list filterable via `gt_internal_link_post_types`, ranker swappable via `gt_internal_link_ranker`. | `edit_posts` |
| `gt/generate-faqs-accordion` | Generates 6–10 FAQs from a post's content. Returns both the raw `{question, answer}[]` and a ready-to-paste single-line `acf/accordion` block markup in the flat indexed format. | `edit_posts` |
| `gt/set-featured-image` | Auto-generates a prompt from post title/excerpt, renders a **16:9** landscape image via `gpt-image-2`, imports to the media library with a human-friendly filename + alt text, sets as featured image. Style preset filterable via `gt_featured_image_style_preset`. | `edit_post` + `upload_files` |
| `gt/tts-readaloud` | POSTs to `/v1/audio/speech` with `gpt-4o-mini-tts`, saves the MP3 to the media library attached to the post. | `upload_files` |
| `gt/rankmath-meta-sync` | Writes `rank_math_title`, `rank_math_description`, `rank_math_focus_keyword` post meta. Silent no-op if Rank Math isn't active. | `edit_post` |

### 5. Block editor sidebar panel

Under the **GT AI Tools** panel in the post sidebar:

- **Generate Featured Image (16:9)** — one-click invocation of `gt/set-featured-image`, sets the result as the featured image without reloading.
- **Generate FAQs (8)** — one-click invocation of `gt/generate-faqs-accordion`, inserts the accordion block at the end of the post.

Both buttons disable while in-flight, show spinners, and guard against running on unsaved posts.

### 6. Human-friendly AI-generated image filenames

The upstream `ai/image-generation` ability defaults its filename to `ai-generated-image-<timestamp>`. GT patches multiple points in the upload pipeline so any matching filename is rewritten to a title derived from (in order): attachment alt/excerpt → parent post's title → REST input.post_id → site name. Then a 6-char hex suffix keeps filenames unique.

Result: `how-to-run-windows-apps-on-mac-a7k2df.png` with `post_title = "How To Run Windows Apps On Mac a7k2df"` instead of `ai-generated-image-1776823794.png`.

Detection patterns (case-insensitive): `ai-generated-image`, `ai-image`, `openai-image`, `dalle-*`, `gpt-image-*`, `img-<epoch>`. Extend via standard WP filters if needed.

### 7. Content-site system instruction

A site-wide filter `ai_client_default_system_instruction` nudges any OpenAI call toward specific numbers over adjectives, named entities, no AI-slop phrases, contractions, 1-4 sentence paragraphs, American English.

---

## Configuration

### Constants (wp-config.php)

| Constant | Default | Purpose |
|---|---|---|
| `OPENAI_API_KEY` | *(reads Connectors option)* | OpenAI API key for the main provider |
| `GT_OPENAI_DEFAULT_TEXT_MODEL` | `gpt-5.4` | Fallback when no option is set |
| `GT_OPENAI_DEFAULT_IMAGE_MODEL` | `gpt-image-2-2026-04-21` | Fallback when no option is set |
| `GT_OPENAI_DEFAULT_META_MODEL` | `gpt-4.1-mini` | Fallback when no option is set |
| `GT_OPENAI_MODEL_CACHE_TTL` | `7 * DAY_IN_SECONDS` | Transient TTL for the `/v1/models` cache |
| `GT_CUSTOM_OPENAI_BASE_URL` | *(reads option)* | Custom endpoint base URL for the second connector |
| `GT_CUSTOM_OPENAI_API_KEY` | *(reads option)* | API key for the custom endpoint |

### Filters

| Filter | Default | Purpose |
|---|---|---|
| `gt_openai_default_text_model` | option or constant | Used by `gt/rewrite-for-voice` |
| `gt_openai_default_image_model` | option or constant | Used by `gt/set-featured-image` |
| `gt_openai_default_meta_model` | option or constant | Used by `gt/generate-faqs-accordion` |
| `gt_stop_slop_banned_phrases` | 70 phrases in `Defaults::slopPhrases()` | Override the slop-scan dictionary |
| `gt_rewrite_default_voice` | `Defaults::voiceInstruction()` | Override the default rewrite voice |
| `gt_internal_link_post_types` | `['post','page']` | Post types considered for internal-link suggestions |
| `gt_internal_link_ranker` | overlap-score sort | Replace the candidate-ranking logic entirely |
| `gt_featured_image_style_preset` | photorealistic editorial | Style line appended to generated-image prompts |
| `ai_client_default_system_instruction` | content-site defaults | Default system prompt for AI Client calls |

---

## REST examples

### Scan a post for AI-slop

```bash
curl -u "admin:app-password" \
  -X POST "https://example.com/wp-json/wp-abilities/v1/abilities/gt/stop-slop-scan/run" \
  -H "Content-Type: application/json" \
  -d '{"input":{"post_id":123}}'
```

### Generate and set a featured image

```bash
curl -u "admin:app-password" \
  -X POST "https://example.com/wp-json/wp-abilities/v1/abilities/gt/set-featured-image/run" \
  -H "Content-Type: application/json" \
  -d '{"input":{"post_id":123}}'
```

### Programmatic call from another plugin

```php
$ability = wp_get_ability( 'gt/generate-faqs-accordion' );
$result  = $ability->invoke( array(
    'post_id' => 123,
    'count'   => 8,
) );

// $result['block'] contains the single-line acf/accordion block, ready to prepend/append.
// $result['faqs']  contains the raw { question, answer }[] array.
```

---

## Architecture

```
gt-ai-provider-for-openai/
├── gt-ai-provider-for-openai.php       # Plugin manifest, constant defaults
├── src/
│   ├── autoload.php                    # PSR-4 for WordPress\OpenAiAiProvider\*
│   ├── Provider/
│   │   ├── OpenAiProvider.php          # Upstream base provider (unchanged signature)
│   │   └── GtCustomOpenAiProvider.php  # Extends base, overrides baseUrl() for custom instances
│   ├── Metadata/
│   │   └── OpenAiModelMetadataDirectory.php  # Patched: expanded aspect ratios, better sort
│   ├── Models/
│   │   ├── OpenAiTextGenerationModel.php
│   │   └── OpenAiImageGenerationModel.php
│   └── GT/
│       ├── Bootstrap.php               # Attachment rename, model cache, admin notice, default-model resolution
│       ├── Abilities.php               # 7 abilities + shared helpers
│       ├── Defaults.php                # Slop phrase list, stopwords, voice instruction
│       ├── CustomInstance.php          # Settings page + Custom connector registration
│       ├── ModelList.php               # 7-day transient cache for /v1/models
│       └── EditorSidebar.php           # Enqueues the block editor panel
└── assets/
    └── editor-sidebar.js               # Vanilla JS, no build step needed
```

---

## Uninstall

Deactivating the plugin leaves the upstream OpenAI provider registered via WordPress core. To remove GT additions entirely, deactivate and delete the plugin in `wp-admin → Plugins`. The following options and transients are left in place for safety (delete manually if desired):

- `connectors_ai_gt-custom-openai_api_key`
- `connectors_ai_gt_custom_openai_base_url`
- `gt_openai_default_text_model`
- `gt_openai_default_image_model`
- `gt_openai_default_meta_model`
- `gt_agent_readiness_version`
- Transient `gt_openai_model_list_v1`

---

## License

GPL-2.0-or-later. Fork of `wordpress/ai-provider-for-openai` (same license). See [LICENSE](LICENSE).

---

## Contributing

Issues and PRs welcome. See [CHANGELOG.md](CHANGELOG.md) for version history.

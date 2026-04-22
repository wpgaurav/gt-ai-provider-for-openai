# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html) with a `-gt<n>` suffix tracking GT-specific iterations on top of the upstream version.

## [1.0.2-gt1] — 2026-04-22

Initial public release of the GT fork, rebased on upstream `wordpress/ai-provider-for-openai` 1.0.2.

### Added
- **GT Custom OpenAI Instance** connector — a second entry on `wp-admin/options-connectors.php` for any OpenAI-compatible endpoint (Azure OpenAI, OpenRouter, LiteLLM, Ollama, self-hosted).
- `Settings → GT Custom OpenAI Instance` admin page with:
  - Base URL field (override via `GT_CUSTOM_OPENAI_BASE_URL` constant or env var).
  - Three live-fetched model dropdowns (text, image, meta) with latest-first sort, cached 7 days.
  - "Refresh now" button to purge the model cache.
- Seven new WordPress Abilities under the `gt/` category:
  - `gt/stop-slop-scan`
  - `gt/rewrite-for-voice`
  - `gt/suggest-internal-links`
  - `gt/generate-faqs-accordion`
  - `gt/set-featured-image` (16:9 landscape)
  - `gt/tts-readaloud`
  - `gt/rankmath-meta-sync`
- Block editor sidebar panel "GT AI Tools" with one-click **Generate Featured Image** and **Generate FAQs** actions.
- Human-friendly AI-generated image filenames (replaces `ai-generated-image-<timestamp>.png` with a post-derived slug plus 6-char suffix). Works across `wp_handle_upload_prefilter`, `wp_handle_sideload_prefilter`, `wp_insert_attachment_data`, and `add_attachment` hooks.
- 7-day transient cache for `/v1/models` (shared between the metadata directory and the dropdown UI).
- Filter `ai_client_default_system_instruction` preseeding a content-site prompt: specific numbers over adjectives, no AI-slop openers, contractions on, American English.
- Filters: `gt_openai_default_text_model`, `gt_openai_default_image_model`, `gt_openai_default_meta_model`, `gt_stop_slop_banned_phrases`, `gt_rewrite_default_voice`, `gt_internal_link_post_types`, `gt_internal_link_ranker`, `gt_featured_image_style_preset`.

### Changed
- Default text model now `gpt-5.4` (was auto-selected from the /v1/models list).
- Default image model now `gpt-image-2-2026-04-21` dated snapshot.
- Model sort preference now ranks `gpt-image-2` and newer above `gpt-image-1.5`/`gpt-image-1`/`dall-e-*`, and ranks newer dated snapshots above older ones within the same family.
- Aspect-ratio metadata for `gpt-image-*` expanded from 3 ratios to 9 (`1:1, 3:2, 2:3, 7:4, 4:7, 16:9, 9:16, 2:1, 1:2`).
- Admin notice about missing API key now accepts the key from env, constant, `connectors_ai_openai_api_key` option, or the GT Custom connector option (was env/constant only).
- All AI Client calls from GT abilities now set a 120 s `RequestOptions::setTimeout()` to avoid hitting the WordPress HTTP default 5 s timeout on image generation.

### Fixed
- Settings page no longer locks the base URL unless a `wp-config.php` constant is active.
- Abilities correctly serialize into the flat indexed `acf/accordion` block format required by ACF blocks.

## Upstream — 1.0.2

- Add provider description.
- Fix missing input/output modality combinations.
- Update `readme.txt` tags.

## Upstream — 1.0.1

- Initial plugin release. GPT text generation, DALL-E image generation, function calling, web search.

## Upstream — 1.0.0

- Initial Composer package release.

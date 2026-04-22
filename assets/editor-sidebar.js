/**
 * GT AI Sidebar — Block Editor panel that exposes GT abilities as one-click
 * actions. No build step: uses UMD globals (wp.element, wp.plugins, etc.).
 */
(function (wp) {
    if (!wp || !wp.plugins || !wp.editor || !wp.element) { return; }

    var el = wp.element.createElement;
    var Fragment = wp.element.Fragment;
    var useState = wp.element.useState;
    var PluginDocumentSettingPanel = (wp.editor && wp.editor.PluginDocumentSettingPanel)
        || (wp.editPost && wp.editPost.PluginDocumentSettingPanel);
    var Button = wp.components.Button;
    var Notice = wp.components.Notice;
    var Spinner = wp.components.Spinner;
    var PanelRow = wp.components.PanelRow;
    var useSelect = wp.data.useSelect;
    var useDispatch = wp.data.useDispatch;
    var apiFetch = wp.apiFetch;
    var __ = wp.i18n.__;

    if (!PluginDocumentSettingPanel) { return; }

    function runAbility(name, input) {
        return apiFetch({
            path: '/wp-abilities/v1/abilities/' + encodeURIComponent(name).replace(/%2F/gi, '/') + '/run',
            method: 'POST',
            data: { input: input },
        });
    }

    function Panel() {
        var postId = useSelect(function (select) {
            return select('core/editor').getCurrentPostId();
        }, []);
        var isSaved = useSelect(function (select) {
            return !select('core/editor').isEditedPostDirty()
                && !select('core/editor').isEditedPostNew();
        }, []);

        var editorDispatch = useDispatch('core/editor');
        var blockEditorDispatch = useDispatch('core/block-editor');

        var imgState = useState({ status: 'idle', message: '' });
        var imgStatus = imgState[0];
        var setImgStatus = imgState[1];

        var faqState = useState({ status: 'idle', message: '' });
        var faqStatus = faqState[0];
        var setFaqStatus = faqState[1];

        function handleGenerateImage() {
            if (!postId) { return; }
            if (!isSaved) {
                setImgStatus({ status: 'warning', message: __('Save the post first so the AI has content to work from.', 'gt-ai-provider-for-openai') });
                return;
            }
            setImgStatus({ status: 'loading', message: '' });
            runAbility('gt/set-featured-image', { post_id: postId })
                .then(function (res) {
                    if (res && res.attachment_id) {
                        editorDispatch.editPost({ featured_media: res.attachment_id });
                        editorDispatch.savePost();
                        setImgStatus({
                            status: 'success',
                            message: __('Featured image generated and set (16:9).', 'gt-ai-provider-for-openai'),
                        });
                    } else {
                        setImgStatus({ status: 'error', message: __('No image returned.', 'gt-ai-provider-for-openai') });
                    }
                })
                .catch(function (err) {
                    setImgStatus({ status: 'error', message: (err && err.message) || __('Generation failed.', 'gt-ai-provider-for-openai') });
                });
        }

        function handleGenerateFaqs() {
            if (!postId) { return; }
            if (!isSaved) {
                setFaqStatus({ status: 'warning', message: __('Save the post first so the AI has content to work from.', 'gt-ai-provider-for-openai') });
                return;
            }
            setFaqStatus({ status: 'loading', message: '' });
            runAbility('gt/generate-faqs-accordion', { post_id: postId, count: 8 })
                .then(function (res) {
                    if (!res || !res.block) {
                        setFaqStatus({ status: 'error', message: __('No FAQ block returned.', 'gt-ai-provider-for-openai') });
                        return;
                    }
                    var blocks = wp.blocks.parse(res.block);
                    if (!blocks || !blocks.length) {
                        setFaqStatus({ status: 'error', message: __('Failed to parse generated block.', 'gt-ai-provider-for-openai') });
                        return;
                    }
                    var allBlocks = wp.data.select('core/block-editor').getBlocks();
                    blockEditorDispatch.insertBlocks(blocks, allBlocks.length);
                    setFaqStatus({
                        status: 'success',
                        message: (res.faqs ? res.faqs.length + ' ' : '') + __('FAQs inserted at the end of the post.', 'gt-ai-provider-for-openai'),
                    });
                })
                .catch(function (err) {
                    setFaqStatus({ status: 'error', message: (err && err.message) || __('Generation failed.', 'gt-ai-provider-for-openai') });
                });
        }

        function renderStatus(state) {
            if (state.status === 'loading') {
                return el('div', { style: { display: 'flex', alignItems: 'center', gap: '8px', marginTop: '6px' } },
                    el(Spinner, null),
                    el('span', null, __('Working…', 'gt-ai-provider-for-openai'))
                );
            }
            if (state.status === 'success') {
                return el(Notice, { status: 'success', isDismissible: false, style: { margin: '8px 0 0' } }, state.message);
            }
            if (state.status === 'warning') {
                return el(Notice, { status: 'warning', isDismissible: false, style: { margin: '8px 0 0' } }, state.message);
            }
            if (state.status === 'error') {
                return el(Notice, { status: 'error', isDismissible: false, style: { margin: '8px 0 0' } }, state.message);
            }
            return null;
        }

        return el(PluginDocumentSettingPanel, {
                name: 'gt-ai-sidebar-panel',
                title: __('GT AI Tools', 'gt-ai-provider-for-openai'),
                initialOpen: true,
                className: 'gt-ai-sidebar-panel',
            },
            el(PanelRow, { className: 'gt-ai-sidebar-row' },
                el('div', { style: { width: '100%' } },
                    el(Button, {
                            variant: 'secondary',
                            onClick: handleGenerateImage,
                            disabled: imgStatus.status === 'loading' || !postId,
                            style: { width: '100%', justifyContent: 'center' },
                        },
                        __('Generate Featured Image (16:9)', 'gt-ai-provider-for-openai')
                    ),
                    renderStatus(imgStatus)
                )
            ),
            el(PanelRow, { className: 'gt-ai-sidebar-row' },
                el('div', { style: { width: '100%', marginTop: '4px' } },
                    el(Button, {
                            variant: 'secondary',
                            onClick: handleGenerateFaqs,
                            disabled: faqStatus.status === 'loading' || !postId,
                            style: { width: '100%', justifyContent: 'center' },
                        },
                        __('Generate FAQs (8)', 'gt-ai-provider-for-openai')
                    ),
                    renderStatus(faqStatus)
                )
            )
        );
    }

    wp.plugins.registerPlugin('gt-ai-sidebar', {
        render: function () {
            return el(Fragment, null, el(Panel, null));
        },
    });
})(window.wp);

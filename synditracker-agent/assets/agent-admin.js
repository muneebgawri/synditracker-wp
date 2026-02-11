jQuery(document).ready(function ($) {
    const $form = $('#st-agent-form');
    const $btn = $form.find('input[type="submit"]');
    const $msgContainer = $('#st-agent-message');
    const $statusIndicator = $('#st-connection-status');

    $form.on('submit', function (e) {
        e.preventDefault();

        // Basic Validation
        const hubUrl = $('#st_agent_hub_url').val();
        const siteKey = $('#st_agent_site_key').val();

        if (!hubUrl || !siteKey) {
            showMessage('Hub URL and Site Key are required.', 'error');
            return;
        }

        $btn.prop('disabled', true).val('Connecting...');
        $msgContainer.empty();

        const formData = new FormData(this);
        formData.append('action', 'st_agent_save_settings');
        formData.append('_ajax_nonce', st_agent.nonce);

        $.ajax({
            url: st_agent.ajax_url,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function (response) {
                if (response.success) {
                    showMessage(response.data.message, 'success');
                    updateStatus(response.data.connected);
                } else {
                    showMessage(response.data.message, 'error');
                    updateStatus(false);
                }
            },
            error: function () {
                showMessage('System error. Please try again.', 'error');
                updateStatus(false);
            },
            complete: function () {
                $btn.prop('disabled', false).val('Save & Test Connection');
            }
        });
    });

    function showMessage(msg, type) {
        // Use WP native classes
        const cls = type === 'success' ? 'updated' : 'error';
        $msgContainer.html('<div class="notice ' + cls + ' is-dismissible"><p>' + msg + '</p></div>');
    }

    function updateStatus(isConnected) {
        if (isConnected) {
            $statusIndicator
                .removeClass('st-status-disconnected')
                .addClass('st-status-connected')
                .html('<strong>Status:</strong> Connected to Hub');
        } else {
            $statusIndicator
                .removeClass('st-status-connected')
                .addClass('st-status-disconnected')
                .html('<strong>Status:</strong> Not Connected');
        }
    }
});

jQuery(document).ready(function($) {
    /**
     * Click to Copy Logic
     */
    $(document).on('click', '.st-copy-btn', function() {
        const text = $(this).data('copy');
        const $btn = $(this);
        const originalHtml = $btn.html();

        if (navigator.clipboard) {
            navigator.clipboard.writeText(text).then(() => {
                showSuccess($btn);
            });
        } else {
            // Fallback
            const textArea = document.createElement("textarea");
            textArea.value = text;
            document.body.appendChild(textArea);
            textArea.select();
            document.execCommand("copy");
            document.body.removeChild(textArea);
            showSuccess($btn);
        }

        function showSuccess($el) {
            $el.html('<span class="dashicons dashicons-yes st-copied"></span>');
            setTimeout(() => {
                $el.html(originalHtml);
            }, 2000);
        }
    });

    /**
     * AJAX Form Submissions
     */
    $('.synditracker-dashboard form').on('submit', function(e) {
        // Only target specific forms for AJAX
        const $form = $(this);
        const $submit = $form.find('input[type="submit"]');
        const action = $submit.attr('name');

        // If it's the keys form or alerts form, handle via AJAX
        if (action === 'st_generate_key' || action === 'st_save_alerts') {
            e.preventDefault();
            
            // Basic Frontend Validation
            if (action === 'st_save_alerts') {
                const discordUrl = $form.find('#st_discord_webhook').val();
                if (discordUrl && !discordUrl.includes('discord.com/api/webhooks/')) {
                    alert('Invalid Discord Webhook URL.');
                    return;
                }
            }

            $submit.prop('disabled', true).addClass('st-loading');
            
            const formData = new FormData(this);
            formData.append('action', 'st_ajax_handle_' + action);
            formData.append('_ajax_nonce', st_admin.nonce);

            $.ajax({
                url: st_admin.ajax_url,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function(response) {
                    if (response.success) {
                        // Show success message
                        const $msg = $('<div class="updated notice is-dismissible st-ajax-msg"><p>' + response.data.message + '</p></div>');
                        $('.st-header').after($msg);
                        
                        // If it was key generation, reload the page after a short delay or refresh the table
                        if (action === 'st_generate_key') {
                            setTimeout(() => { location.reload(); }, 1000);
                        }
                    } else {
                        const $msg = $('<div class="error notice is-dismissible st-ajax-msg"><p>' + response.data.message + '</p></div>');
                        $('.st-header').after($msg);
                    }
                },
                error: function() {
                    alert('A system error occurred. Please try again.');
                },
                complete: function() {
                    $submit.prop('disabled', false).removeClass('st-loading');
                }
            });
        }
    });
});

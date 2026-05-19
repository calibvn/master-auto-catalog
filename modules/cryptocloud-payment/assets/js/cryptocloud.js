jQuery(document).ready(function ($) {
    // Export functions externally
    function showLoading(message = 'Processing...') {
        $('body').append(`
            <div class="cryptocloud-loading">
                <div class="cryptocloud-loading-spinner"></div>
                <div>${message}</div>
            </div>
        `);
    }
    
    function hideLoading() {
        $('.cryptocloud-loading').remove();
    }
    
    // Handling payment button click
    $('.cryptocloud-pay-button').on('click', function () {
        var button = $(this);
        var amount = button.data('amount') || 40;

        // Show modal window for entering data
        showPaymentModal(amount);
    });

    // Show payment modal
    function showPaymentModal(amount) {
        var modalHTML = `
        <div class="cryptocloud-modal-overlay">
            <div class="cryptocloud-modal">
                <div class="cryptocloud-modal-header">
                    <h3>Payment via CryptoCloud</h3>
                    <span class="cryptocloud-modal-close">&times;</span>
                </div>
                <div class="cryptocloud-modal-body">
                    <form id="cryptocloud-payment-form">
                        <div class="cryptocloud-form-group">
                            <label>Email *</label>
                            <input type="email" name="email" required placeholder="your@email.com">
                        </div>
                        <div class="cryptocloud-form-group">
                            <label>Telegram (optional)</label>
                            <input type="text" name="telegram" placeholder="@username">
                        </div>
                        <div class="cryptocloud-form-group">
                            <label>Promocode (optional)</label>
                            <div class="cryptocloud-promo-wrapper">
                                <input type="text" name="promocode" id="cryptocloud-promocode" placeholder="Enter promocode">
                                <button type="button" id="cryptocloud-check-promo" class="button">Apply</button>
                            </div>
                            <div id="cryptocloud-promo-result"></div>
                        </div>
                        <div class="cryptocloud-price-info">
                            <strong>Amount to pay:</strong>
                            <span id="cryptocloud-final-amount">$${amount}</span>
                        </div>
                        <input type="hidden" name="amount" id="cryptocloud-amount" value="${amount}">
                        <input type="hidden" name="product_url" id="cryptocloud-product-url" value="">
                        <div class="cryptocloud-modal-footer">
                            <button type="button" class="cryptocloud-btn-cancel">Cancel</button>
                            <button type="submit" class="cryptocloud-btn-pay" id="cryptocloud-submit-btn">
                                Proceed to payment
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>`;

        $('body').append(modalHTML);

        // Get clean URL
        var currentUrl = window.location.href;
        var cleanUrl = currentUrl.replace(/\/undefined$/i, '')
                                .replace(/\/null$/i, '')
                                .replace(/\/$/i, '');
        
        // Set clean URL
        $('#cryptocloud-product-url').val(cleanUrl);
        
        console.log('Original URL:', currentUrl);
        console.log('Clean URL:', cleanUrl);

        // Initialize reCAPTCHA if available
        if (typeof grecaptcha !== 'undefined' && cryptocloud_ajax.recaptcha_site_key) {
            grecaptcha.ready(function () {
                grecaptcha.execute(cryptocloud_ajax.recaptcha_site_key, { action: 'payment' });
            });
        }

        // Close modal window
        $('.cryptocloud-modal-close, .cryptocloud-btn-cancel').on('click', function () {
            $('.cryptocloud-modal-overlay').remove();
        });

        // Promocode checking
        $('#cryptocloud-check-promo').on('click', function () {
            var promocode = $('#cryptocloud-promocode').val().trim();
            if (!promocode) {
                $('#cryptocloud-promo-result').html('<div class="cryptocloud-error">Enter promocode</div>');
                return;
            }

            $('#cryptocloud-check-promo').prop('disabled', true).text('Checking...');

            $.ajax({
                url: cryptocloud_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'check_cryptocloud_promocode',
                    nonce: cryptocloud_ajax.nonce,
                    promocode: promocode,
                    base_price: amount
                },
                success: function (response) {
                    if (response.success) {
                        var messageHtml = '<div class="cryptocloud-success">' + response.data.message + '</div>';
                        if (response.data.is_free) {
                            messageHtml += '<div class="cryptocloud-success" style="margin-top: 5px; font-weight: bold;">🎉 The car will be hidden for free!</div>';
                        }
                        if (response.data.usage_info) {
                            messageHtml += '<div class="cryptocloud-info" style="margin-top: 5px; font-size: 11px;">' + response.data.usage_info + '</div>';
                        }

                        $('#cryptocloud-promo-result').html(messageHtml);
                        $('#cryptocloud-amount').val(response.data.new_price);
                        $('#cryptocloud-final-amount').text('$' + response.data.new_price);

                        // If free promocode, change button text
                        if (response.data.is_free) {
                            $('#cryptocloud-submit-btn').text('Get for free');
                        }
                    } else {
                        $('#cryptocloud-promo-result').html('<div class="cryptocloud-error">' + response.data + '</div>');
                        $('#cryptocloud-amount').val(amount);
                        $('#cryptocloud-final-amount').text('$' + amount);
                        $('#cryptocloud-submit-btn').text('Proceed to payment');
                    }
                },
                error: function () {
                    $('#cryptocloud-promo-result').html('<div class="cryptocloud-error">Error checking promocode</div>');
                },
                complete: function () {
                    $('#cryptocloud-check-promo').prop('disabled', false).text('Apply');
                }
            });
        });

        $('#cryptocloud-promocode').on('keypress', function (e) {
            if (e.which === 13) {
                e.preventDefault();
                $('#cryptocloud-check-promo').click();
            }
        });
        $('#cryptocloud-promocode').on('input', function () {
            $('#cryptocloud-promo-result').empty();
        });

        // Form submission handling
        $('#cryptocloud-payment-form').on('submit', function (e) {
            e.preventDefault();

            var form = $(this);
            var submitBtn = $('#cryptocloud-submit-btn');

            // Check required fields
            var email = form.find('input[name="email"]').val().trim();
            if (!email) {
                alert('Please enter your email');
                return;
            }

            submitBtn.prop('disabled', true).text('Creating payment...');

            // Prepare data
            var formData = {
                action: 'create_cryptocloud_payment',
                nonce: cryptocloud_ajax.nonce,
                amount: $('#cryptocloud-amount').val(),
                product_url: $('#cryptocloud-product-url').val(),
                email: email,
                telegram: form.find('input[name="telegram"]').val().trim(),
                promocode: $('#cryptocloud-promocode').val().trim()
            };

            // Add reCAPTCHA token if available
            if (typeof grecaptcha !== 'undefined' && cryptocloud_ajax.recaptcha_site_key) {
                grecaptcha.ready(function () {
                    grecaptcha.execute(cryptocloud_ajax.recaptcha_site_key, { action: 'payment' })
                        .then(function (token) {
                            formData.recaptcha_token = token;
                            sendPaymentRequest(formData, submitBtn);
                        });
                });
            } else {
                sendPaymentRequest(formData, submitBtn);
            }
        });
    }

    function sendPaymentRequest(formData, submitBtn) {
        showLoading('Creating payment...');
        
        console.log('Sending payment data:', formData);

        $.ajax({
            url: cryptocloud_ajax.ajax_url,
            type: 'POST',
            data: formData,
            success: function (response) {
                console.log('Server response:', response);
                hideLoading();
                
                if (response.success) {
                    if (response.data.is_free) {
                        // Free promocode - redirect to success page
                        showLoading('Free promocode applied! Redirecting...');
                        console.log('Redirecting to:', response.data.redirect_url);
                        setTimeout(function () {
                            window.location.href = response.data.redirect_url;
                        }, 1500);
                    } else {
                        // Regular payment - redirect to payment page
                        console.log('Redirecting to payment page:', response.data.payment_url);
                        window.location.href = response.data.payment_url;
                    }
                } else {
                    console.error('Server error:', response.data);
                    alert('Error: ' + response.data);
                    submitBtn.prop('disabled', false).text('Proceed to payment');
                }
            },
            error: function (xhr, status, error) {
                console.error('AJAX error:', status, error);
                console.error('XHR object:', xhr);
                console.error('Response text:', xhr.responseText);
                
                hideLoading();
                alert('Connection error. Please try again later.\n' + error);
                submitBtn.prop('disabled', false).text('Proceed to payment');
            },
            complete: function() {
                console.log('AJAX request completed');
            }
        });
    }

    // Close modal by clicking on background
    $(document).on('click', '.cryptocloud-modal-overlay', function (e) {
        if ($(e.target).hasClass('cryptocloud-modal-overlay')) {
            $(this).remove();
        }
    });
});
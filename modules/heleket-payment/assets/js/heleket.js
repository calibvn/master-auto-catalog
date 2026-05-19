jQuery(document).ready(function ($) {
    // Выносим функции наружу
    function showLoading(message = 'Processing...') {
        $('body').append(`
            <div class="heleket-loading">
                <div class="heleket-loading-spinner"></div>
                <div class="heleket-loading-text">${message}</div>
            </div>
        `);
    }
    
    function hideLoading() {
        $('.heleket-loading').remove();
    }
    
    // Обработка клика на кнопку оплаты
    $(document).on('click', '.heleket-pay-button', function (e) {
        e.preventDefault();
        
        var button = $(this);
        var amount = button.data('amount') || 40;
        
        // Показываем модальное окно для ввода данных
        showPaymentModal(amount);
    });
    
    // Показ модального окна
    function showPaymentModal(amount) {
        var modalHTML = `
        <div class="heleket-modal-overlay">
            <div class="heleket-modal">
                <div class="heleket-modal-header">
                    <div class="heleket-modal-icon">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M20 7H4C2.9 7 2 7.9 2 9V19C2 20.1 2.9 21 4 21H20C21.1 21 22 20.1 22 19V9C22 7.9 21.1 7 20 7Z" stroke="#3B82F6" stroke-width="2"/>
                            <path d="M16 21V5C16 3.9 15.1 3 14 3H10C8.9 3 8 3.9 8 5V21" stroke="#3B82F6" stroke-width="2"/>
                        </svg>
                    </div>
                    <h3>Secure Payment</h3>
                    <span class="heleket-modal-close">&times;</span>
                </div>
                <div class="heleket-modal-body">
                    <form id="heleket-payment-form">
                        <div class="heleket-form-group">
                            <label>Email Address *</label>
                            <div class="heleket-input-wrapper">
                                <svg class="heleket-input-icon" width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                                    <path d="M2 4L7.89 8.26C7.96 8.31 8.04 8.31 8.11 8.26L14 4" stroke="#6B7280" stroke-width="1.5" stroke-linecap="round"/>
                                    <rect x="1" y="3" width="14" height="10" rx="2" stroke="#6B7280" stroke-width="1.5"/>
                                </svg>
                                <input type="email" name="email" required placeholder="your.email@example.com">
                            </div>
                        </div>
                        <div class="heleket-form-group">
                            <label>Telegram Username (optional)</label>
                            <div class="heleket-input-wrapper">
                                <svg class="heleket-input-icon" width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                                    <path d="M14 8C14 11.3137 11.3137 14 8 14C4.68629 14 2 11.3137 2 8C2 4.68629 4.68629 2 8 2C11.3137 2 14 4.68629 14 8Z" stroke="#6B7280" stroke-width="1.5"/>
                                    <path d="M10.5 5.5L5.5 10.5" stroke="#6B7280" stroke-width="1.5" stroke-linecap="round"/>
                                    <path d="M5.5 5.5L10.5 10.5" stroke="#6B7280" stroke-width="1.5" stroke-linecap="round"/>
                                </svg>
                                <input type="text" name="telegram" placeholder="@username">
                            </div>
                        </div>
                        <div class="heleket-form-group">
                            <label>Promo Code (optional)</label>
                            <div class="heleket-promo-wrapper">
                                <div class="heleket-input-wrapper">
                                    <svg class="heleket-input-icon" width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                                        <path d="M12 6L6 12" stroke="#6B7280" stroke-width="1.5" stroke-linecap="round"/>
                                        <path d="M8.5 2.5V4.5M11.5 5.5L13.5 3.5M14 8.5H12M5.5 11.5L3.5 13.5M2.5 8.5H4.5M7.5 8.5C7.5 9.05228 7.05228 9.5 6.5 9.5C5.94772 9.5 5.5 9.05228 5.5 8.5C5.5 7.94772 5.94772 7.5 6.5 7.5C7.05228 7.5 7.5 7.94772 7.5 8.5Z" stroke="#6B7280" stroke-width="1.5" stroke-linecap="round"/>
                                    </svg>
                                    <input type="text" name="promocode" id="heleket-promocode" placeholder="Enter promo code">
                                </div>
                                <button type="button" id="heleket-check-promo" class="heleket-promo-btn">Apply</button>
                            </div>
                            <div id="heleket-promo-result"></div>
                        </div>
                        <div class="heleket-price-info">
                            <div class="heleket-price-label">Total Amount:</div>
                            <div class="heleket-price-amount" id="heleket-final-amount">$${amount}</div>
                        </div>
                        <input type="hidden" name="amount" id="heleket-amount" value="${amount}">
                        <input type="hidden" name="product_url" id="heleket-product-url" value="">
                        <div class="heleket-modal-footer">
                            <button type="button" class="heleket-btn heleket-btn-secondary heleket-btn-cancel">
                                <svg width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                                    <path d="M12 4L4 12" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                                    <path d="M4 4L12 12" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                                </svg>
                                Cancel
                            </button>
                            <button type="submit" class="heleket-btn heleket-btn-primary" id="heleket-submit-btn">
                                <svg width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                                    <path d="M14 8C14 11.3137 11.3137 14 8 14C4.68629 14 2 11.3137 2 8C2 4.68629 4.68629 2 8 2C11.3137 2 14 4.68629 14 8Z" stroke="currentColor" stroke-width="1.5"/>
                                    <path d="M6 8L8 10L10 6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                                Pay $${amount}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>`;

        $('body').append(modalHTML);

        // Получаем чистый URL
        var currentUrl = window.location.href;
        var cleanUrl = currentUrl.replace(/\/undefined$/i, '')
                                .replace(/\/null$/i, '')
                                .replace(/\/$/i, '');
        
        // Устанавливаем чистый URL
        $('#heleket-product-url').val(cleanUrl);
        
        console.log('Heleket - Original URL:', currentUrl);
        console.log('Heleket - Clean URL:', cleanUrl);

        // Закрытие модального окна
        $('.heleket-modal-close, .heleket-btn-cancel').on('click', function () {
            $('.heleket-modal-overlay').remove();
        });

        // Обработка проверки промокода
        $('#heleket-check-promo').on('click', function () {
            var promocode = $('#heleket-promocode').val().trim();
            if (!promocode) {
                $('#heleket-promo-result').html('<div class="heleket-error">Enter promo code</div>');
                return;
            }

            $('#heleket-check-promo').prop('disabled', true).text('Checking...');

            $.ajax({
                url: heleket_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'check_promocode',
                    nonce: heleket_ajax.nonce,
                    promocode: promocode,
                    base_price: amount
                },
                success: function (response) {
                    if (response.success) {
                        var messageHtml = '<div class="heleket-success">' + response.data.message + '</div>';
                        if (response.data.is_free) {
                            messageHtml += '<div class="heleket-success" style="margin-top: 5px; font-weight: bold;">🎉 The car will be hidden for free!</div>';
                        }
                        if (response.data.usage_info) {
                            messageHtml += '<div class="heleket-info" style="margin-top: 5px; font-size: 11px;">' + response.data.usage_info + '</div>';
                        }

                        $('#heleket-promo-result').html(messageHtml);
                        $('#heleket-amount').val(response.data.new_price);
                        $('#heleket-final-amount').text('$' + response.data.new_price);
                        $('#heleket-submit-btn').html(`
                            <svg width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M14 8C14 11.3137 11.3137 14 8 14C4.68629 14 2 11.3137 2 8C2 4.68629 4.68629 2 8 2C11.3137 2 14 4.68629 14 8Z" stroke="currentColor" stroke-width="1.5"/>
                                <path d="M6 8L8 10L10 6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                            Pay $` + response.data.new_price
                        );

                        // Если бесплатный промокод, меняем текст кнопки
                        if (response.data.is_free) {
                            $('#heleket-submit-btn').html(`
                                <svg width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                                    <path d="M12.5 8.5H3.5C2.94772 8.5 2.5 8.05228 2.5 7.5V4C2.5 3.44772 2.94772 3 3.5 3H12.5C13.0523 3 13.5 3.44772 13.5 4V7.5C13.5 8.05228 13.0523 8.5 12.5 8.5Z" stroke="currentColor" stroke-width="1.5"/>
                                    <path d="M4.5 3L8 6.5L11.5 3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                                    <path d="M6.5 13L8 11.5L9.5 13" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                                    <path d="M8 11.5V8.5" stroke="currentColor" stroke-width="1.5"/>
                                </svg>
                                Get for free
                            `);
                        }
                    } else {
                        $('#heleket-promo-result').html('<div class="heleket-error">' + response.data + '</div>');
                        $('#heleket-amount').val(amount);
                        $('#heleket-final-amount').text('$' + amount);
                        $('#heleket-submit-btn').html(`
                            <svg width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M14 8C14 11.3137 11.3137 14 8 14C4.68629 14 2 11.3137 2 8C2 4.68629 4.68629 2 8 2C11.3137 2 14 4.68629 14 8Z" stroke="currentColor" stroke-width="1.5"/>
                                <path d="M6 8L8 10L10 6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                            Pay $${amount}
                        `);
                    }
                },
                error: function () {
                    $('#heleket-promo-result').html('<div class="heleket-error">Error checking promo code</div>');
                },
                complete: function () {
                    $('#heleket-check-promo').prop('disabled', false).text('Apply');
                }
            });
        });

        $('#heleket-promocode').on('keypress', function (e) {
            if (e.which === 13) {
                e.preventDefault();
                $('#heleket-check-promo').click();
            }
        });
        $('#heleket-promocode').on('input', function () {
            $('#heleket-promo-result').empty();
        });

        // Обработка отправки формы
        $('#heleket-payment-form').on('submit', function (e) {
            e.preventDefault();

            var form = $(this);
            var submitBtn = $('#heleket-submit-btn');

            // Проверяем обязательные поля
            var email = form.find('input[name="email"]').val().trim();
            if (!email) {
                alert('Please enter your email');
                return;
            }

            submitBtn.prop('disabled', true);
            
            // Подготавливаем данные
            var formData = {
                action: 'create_heleket_payment',
                nonce: heleket_ajax.nonce,
                amount: $('#heleket-amount').val(),
                product_url: $('#heleket-product-url').val(),
                email: email,
                telegram: form.find('input[name="telegram"]').val().trim(),
                promocode: $('#heleket-promocode').val().trim()
            };

            sendPaymentRequest(formData, submitBtn);
        });
    }

    function sendPaymentRequest(formData, submitBtn) {
        showLoading('Creating payment...');
        
        console.log('Heleket - Sending payment data:', formData);

        $.ajax({
            url: heleket_ajax.ajax_url,
            type: 'POST',
            data: formData,
            success: function (response) {
                console.log('Heleket - Server response:', response);
                hideLoading();
                
                if (response.success) {
                    if (response.data.is_free) {
                        // Бесплатный промокод - сразу редирект на страницу успеха
                        showLoading('Free promo code applied! Redirecting...');
                        console.log('Heleket - Redirecting to:', response.data.redirect_url);
                        setTimeout(function () {
                            window.location.href = response.data.redirect_url;
                        }, 1500);
                    } else {
                        // Обычный платеж - редирект на страницу оплаты
                        console.log('Heleket - Redirecting to payment page:', response.data.payment_url);
                        window.location.href = response.data.payment_url;
                    }
                } else {
                    console.error('Heleket - Server error:', response.data);
                    alert('Error: ' + response.data);
                    submitBtn.prop('disabled', false);
                }
            },
            error: function (xhr, status, error) {
                console.error('Heleket - AJAX error:', status, error);
                console.error('Heleket - XHR object:', xhr);
                console.error('Heleket - Response text:', xhr.responseText);
                
                hideLoading();
                alert('Connection error. Please try again later.\n' + error);
                submitBtn.prop('disabled', false);
            },
            complete: function() {
                console.log('Heleket - AJAX request completed');
            }
        });
    }

    // Закрытие модального окна по клику на фон
    $(document).on('click', '.heleket-modal-overlay', function (e) {
        if ($(e.target).hasClass('heleket-modal-overlay')) {
            $(this).remove();
        }
    });
});
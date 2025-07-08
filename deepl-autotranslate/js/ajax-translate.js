jQuery(document).ready(function($) {
    
    // Initialize translation functionality
    function initTranslate() {
        // Add translate buttons to content areas
        addTranslateButtons();
        
        // Handle shortcode forms
        handleShortcodeForms();
        
        // Auto-translate on language change
        handleLanguageChange();
    }
    
    // Add translate buttons to content areas
    function addTranslateButtons() {
        // Add translate button to post content
        $('.entry-content, .post-content, .content').each(function() {
            var $content = $(this);
            if ($content.find('.dat-translate-btn').length === 0) {
                var $btn = $('<div class="dat-translate-controls">' +
                    '<button type="button" class="dat-translate-btn" data-target="content">' +
                    'Translate Content' +
                    '</button>' +
                    '<select class="dat-target-lang">' +
                    '<option value="hu">Hungarian</option>' +
                    '<option value="en">English</option>' +
                    '<option value="de">German</option>' +
                    '<option value="fr">French</option>' +
                    '<option value="es">Spanish</option>' +
                    '<option value="it">Italian</option>' +
                    '<option value="th">Thai</option>' +
                    '<option value="ru">Russian</option>' +
                    '<option value="pl">Polish</option>' +
                    '<option value="pt">Portuguese</option>' +
                    '</select>' +
                    '</div>');
                $content.prepend($btn);
            }
        });
    }
    
    // Handle shortcode forms
    function handleShortcodeForms() {
        // Create inline translation form
        $('body').append('<div id="dat-translate-modal" style="display:none;">' +
            '<div class="dat-modal-content">' +
            '<span class="dat-close">&times;</span>' +
            '<h3>Quick Translate</h3>' +
            '<form id="dat-translate-form">' +
            '<div class="dat-form-group">' +
            '<label>Text to translate:</label>' +
            '<textarea id="dat-source-text" rows="4" cols="50"></textarea>' +
            '</div>' +
            '<div class="dat-form-group">' +
            '<label>From:</label>' +
            '<select id="dat-from-lang">' +
            '<option value="en">English</option>' +
            '<option value="hu">Hungarian</option>' +
            '<option value="de">German</option>' +
            '<option value="fr">French</option>' +
            '<option value="es">Spanish</option>' +
            '<option value="it">Italian</option>' +
            '<option value="th">Thai</option>' +
            '<option value="ru">Russian</option>' +
            '<option value="pl">Polish</option>' +
            '<option value="pt">Portuguese</option>' +
            '</select>' +
            '</div>' +
            '<div class="dat-form-group">' +
            '<label>To:</label>' +
            '<select id="dat-to-lang">' +
            '<option value="hu">Hungarian</option>' +
            '<option value="en">English</option>' +
            '<option value="de">German</option>' +
            '<option value="fr">French</option>' +
            '<option value="es">Spanish</option>' +
            '<option value="it">Italian</option>' +
            '<option value="th">Thai</option>' +
            '<option value="ru">Russian</option>' +
            '<option value="pl">Polish</option>' +
            '<option value="pt">Portuguese</option>' +
            '</select>' +
            '</div>' +
            '<div class="dat-form-group">' +
            '<button type="submit" class="dat-btn-primary">Translate</button>' +
            '<button type="button" class="dat-btn-secondary" id="dat-copy-result">Copy Result</button>' +
            '</div>' +
            '<div class="dat-result-container">' +
            '<label>Translation result:</label>' +
            '<textarea id="dat-result-text" rows="4" cols="50" readonly></textarea>' +
            '</div>' +
            '</form>' +
            '</div>' +
            '</div>');
    }
    
    // Handle language change events
    function handleLanguageChange() {
        // Listen for WPML language switcher
        $(document).on('change', '.wpml-ls-statics-shortcode_actions select', function() {
            var newLang = $(this).val();
            if (newLang && newLang !== dat_ajax.current_lang) {
                // Auto-translate current page content
                autoTranslatePageContent(newLang);
            }
        });
        
        // Listen for Polylang language switcher
        $(document).on('click', '.lang-item a', function(e) {
            var href = $(this).attr('href');
            var langMatch = href.match(/\/([a-z]{2})\//);
            if (langMatch) {
                var newLang = langMatch[1];
                if (newLang !== dat_ajax.current_lang) {
                    // Auto-translate current page content
                    autoTranslatePageContent(newLang);
                }
            }
        });
    }
    
    // Auto-translate page content
    function autoTranslatePageContent(targetLang) {
        $('.entry-content, .post-content, .content').each(function() {
            var $content = $(this);
            var originalText = $content.text().trim();
            
            if (originalText.length > 0) {
                translateText(originalText, 'en', targetLang, function(translatedText) {
                    if (translatedText && translatedText !== originalText) {
                        $content.addClass('dat-translated');
                        $content.attr('data-original-text', originalText);
                        // Note: In a real implementation, you'd want to be more careful about replacing content
                        // This is a simplified version for demonstration
                    }
                });
            }
        });
    }
    
    // Main translation function
    function translateText(text, fromLang, toLang, callback) {
        if (!text || text.trim() === '') {
            if (callback) callback('');
            return;
        }
        
        // Show loading indicator
        showLoadingIndicator();
        
        $.ajax({
            url: dat_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'dat_translate',
                text: text,
                from_lang: fromLang,
                to_lang: toLang,
                nonce: dat_ajax.nonce
            },
            success: function(response) {
                hideLoadingIndicator();
                
                if (response.success && response.data.translated) {
                    if (callback) callback(response.data.translated);
                } else {
                    showError('Translation failed. Please try again.');
                    if (callback) callback(text); // Return original text on failure
                }
            },
            error: function(xhr, status, error) {
                hideLoadingIndicator();
                showError('Translation service is temporarily unavailable.');
                if (callback) callback(text); // Return original text on error
            }
        });
    }
    
    // Event handlers
    $(document).on('click', '.dat-translate-btn', function() {
        var $btn = $(this);
        var $content = $btn.closest('.entry-content, .post-content, .content');
        var targetLang = $btn.siblings('.dat-target-lang').val();
        var sourceText = $content.find('p').first().text().trim();
        
        if (sourceText.length === 0) {
            showError('No content found to translate.');
            return;
        }
        
        $btn.prop('disabled', true).text('Translating...');
        
        translateText(sourceText, 'en', targetLang, function(translatedText) {
            $btn.prop('disabled', false).text('Translate Content');
            
            if (translatedText && translatedText !== sourceText) {
                // Create translation overlay
                var $overlay = $('<div class="dat-translation-overlay">' +
                    '<div class="dat-translation-content">' +
                    '<h4>Translation (' + targetLang.toUpperCase() + ')</h4>' +
                    '<div class="dat-translated-text">' + translatedText + '</div>' +
                    '<button class="dat-close-overlay">Close</button>' +
                    '</div>' +
                    '</div>');
                
                $content.append($overlay);
                $overlay.fadeIn();
            }
        });
    });
    
    // Modal events
    $(document).on('click', '#dat-translate-modal .dat-close', function() {
        $('#dat-translate-modal').hide();
    });
    
    $(document).on('click', '.dat-close-overlay', function() {
        $(this).closest('.dat-translation-overlay').fadeOut(function() {
            $(this).remove();
        });
    });
    
    // Form submission
    $(document).on('submit', '#dat-translate-form', function(e) {
        e.preventDefault();
        
        var sourceText = $('#dat-source-text').val().trim();
        var fromLang = $('#dat-from-lang').val();
        var toLang = $('#dat-to-lang').val();
        
        if (!sourceText) {
            showError('Please enter text to translate.');
            return;
        }
        
        if (fromLang === toLang) {
            showError('Source and target languages cannot be the same.');
            return;
        }
        
        translateText(sourceText, fromLang, toLang, function(translatedText) {
            $('#dat-result-text').val(translatedText);
        });
    });
    
    // Copy result button
    $(document).on('click', '#dat-copy-result', function() {
        var resultText = $('#dat-result-text').val();
        if (resultText) {
            navigator.clipboard.writeText(resultText).then(function() {
                showSuccess('Translation copied to clipboard!');
            }).catch(function() {
                // Fallback for older browsers
                $('#dat-result-text').select();
                document.execCommand('copy');
                showSuccess('Translation copied to clipboard!');
            });
        }
    });
    
    // Quick translate shortcut (Ctrl+T)
    $(document).on('keydown', function(e) {
        if (e.ctrlKey && e.key === 't') {
            e.preventDefault();
            $('#dat-translate-modal').show();
            $('#dat-source-text').focus();
        }
    });
    
    // Utility functions
    function showLoadingIndicator() {
        if ($('#dat-loading').length === 0) {
            $('body').append('<div id="dat-loading" style="position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);background:rgba(0,0,0,0.8);color:white;padding:20px;border-radius:5px;z-index:9999;">Translating...</div>');
        }
    }
    
    function hideLoadingIndicator() {
        $('#dat-loading').remove();
    }
    
    function showError(message) {
        showNotification(message, 'error');
    }
    
    function showSuccess(message) {
        showNotification(message, 'success');
    }
    
    function showNotification(message, type) {
        var className = type === 'error' ? 'dat-notification-error' : 'dat-notification-success';
        var $notification = $('<div class="dat-notification ' + className + '">' + message + '</div>');
        
        $('body').append($notification);
        
        setTimeout(function() {
            $notification.fadeOut(function() {
                $(this).remove();
            });
        }, 3000);
    }
    
    // Initialize when document is ready
    initTranslate();
    
    // Add custom styles
    $('head').append('<style>' +
        '.dat-translate-controls { margin: 10px 0; padding: 10px; border: 1px solid #ddd; background: #f9f9f9; }' +
        '.dat-translate-btn { margin-right: 10px; padding: 5px 10px; background: #0073aa; color: white; border: none; cursor: pointer; }' +
        '.dat-translate-btn:hover { background: #005a87; }' +
        '.dat-translate-btn:disabled { background: #ccc; cursor: not-allowed; }' +
        '.dat-target-lang { padding: 5px; }' +
        '.dat-translation-overlay { position: absolute; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.9); color: white; z-index: 1000; }' +
        '.dat-translation-content { padding: 20px; max-width: 600px; margin: 50px auto; background: #333; border-radius: 5px; }' +
        '.dat-translated-text { margin: 15px 0; padding: 15px; background: #444; border-radius: 3px; }' +
        '.dat-close-overlay { padding: 5px 10px; background: #666; color: white; border: none; cursor: pointer; }' +
        '#dat-translate-modal { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); z-index: 10000; }' +
        '.dat-modal-content { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; padding: 20px; border-radius: 5px; max-width: 500px; width: 90%; }' +
        '.dat-close { position: absolute; top: 10px; right: 15px; font-size: 24px; cursor: pointer; }' +
        '.dat-form-group { margin-bottom: 15px; }' +
        '.dat-form-group label { display: block; margin-bottom: 5px; font-weight: bold; }' +
        '.dat-form-group input, .dat-form-group select, .dat-form-group textarea { width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 3px; }' +
        '.dat-btn-primary { background: #0073aa; color: white; padding: 10px 20px; border: none; border-radius: 3px; cursor: pointer; margin-right: 10px; }' +
        '.dat-btn-secondary { background: #666; color: white; padding: 10px 20px; border: none; border-radius: 3px; cursor: pointer; }' +
        '.dat-result-container { margin-top: 20px; }' +
        '.dat-notification { position: fixed; top: 20px; right: 20px; padding: 15px; border-radius: 5px; z-index: 10001; }' +
        '.dat-notification-error { background: #d63384; color: white; }' +
        '.dat-notification-success { background: #198754; color: white; }' +
        '.dat-translated { border-left: 4px solid #0073aa; }' +
        '</style>');
});
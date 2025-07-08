jQuery(document).ready(function($) {
    
    // Bulk Translation functionality
    var bulkTranslation = {
        isRunning: false,
        currentIndex: 0,
        totalPosts: 0,
        results: [],
        
        init: function() {
            $('#start-bulk-translate').on('click', this.start.bind(this));
            $('#stop-bulk-translate').on('click', this.stop.bind(this));
        },
        
        start: function() {
            var fromLang = $('#bulk-from-lang').val();
            var toLang = $('#bulk-to-lang').val();
            var contentType = $('#bulk-content-type').val();
            var postType = $('#bulk-post-type').val();
            var limit = parseInt($('#bulk-limit').val());
            
            if (fromLang === toLang) {
                alert('Source and target languages cannot be the same.');
                return;
            }
            
            this.isRunning = true;
            this.currentIndex = 0;
            this.results = [];
            
            // Show progress section
            $('#bulk-progress').show();
            $('#bulk-results').hide();
            
            // Update UI
            $('#start-bulk-translate').hide();
            $('#stop-bulk-translate').show();
            $('#bulk-translate-form :input').prop('disabled', true);
            
            // Get posts to translate
            this.getPosts(postType, limit, function(posts) {
                bulkTranslation.totalPosts = posts.length;
                bulkTranslation.updateProgress();
                bulkTranslation.translatePosts(posts, fromLang, toLang, contentType);
            });
        },
        
        stop: function() {
            this.isRunning = false;
            this.updateUI();
            this.showResults();
        },
        
        getPosts: function(postType, limit, callback) {
            $.ajax({
                url: dat_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'dat_get_posts',
                    post_type: postType,
                    limit: limit,
                    nonce: dat_admin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        callback(response.data.posts);
                    } else {
                        alert('Failed to get posts: ' + (response.data.message || 'Unknown error'));
                        bulkTranslation.stop();
                    }
                },
                error: function() {
                    alert('Failed to get posts. Please try again.');
                    bulkTranslation.stop();
                }
            });
        },
        
        translatePosts: function(posts, fromLang, toLang, contentType) {
            if (!this.isRunning || this.currentIndex >= posts.length) {
                this.stop();
                return;
            }
            
            var post = posts[this.currentIndex];
            var postIds = [post.ID];
            
            $.ajax({
                url: dat_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'dat_bulk_translate',
                    post_ids: postIds,
                    from_lang: fromLang,
                    to_lang: toLang,
                    content_type: contentType,
                    nonce: dat_admin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        bulkTranslation.results.push({
                            post: post,
                            result: response.data[0],
                            success: true
                        });
                    } else {
                        bulkTranslation.results.push({
                            post: post,
                            error: response.data.message || 'Translation failed',
                            success: false
                        });
                    }
                    
                    bulkTranslation.currentIndex++;
                    bulkTranslation.updateProgress();
                    
                    // Continue with next post
                    setTimeout(function() {
                        bulkTranslation.translatePosts(posts, fromLang, toLang, contentType);
                    }, 500); // Small delay to avoid overwhelming the server
                },
                error: function() {
                    bulkTranslation.results.push({
                        post: post,
                        error: 'Network error',
                        success: false
                    });
                    
                    bulkTranslation.currentIndex++;
                    bulkTranslation.updateProgress();
                    
                    // Continue with next post
                    setTimeout(function() {
                        bulkTranslation.translatePosts(posts, fromLang, toLang, contentType);
                    }, 500);
                }
            });
        },
        
        updateProgress: function() {
            var percentage = this.totalPosts > 0 ? (this.currentIndex / this.totalPosts) * 100 : 0;
            $('#progress-fill').css('width', percentage + '%');
            $('#progress-text').text(this.currentIndex + ' / ' + this.totalPosts + ' posts translated');
        },
        
        updateUI: function() {
            $('#start-bulk-translate').show();
            $('#stop-bulk-translate').hide();
            $('#bulk-translate-form :input').prop('disabled', false);
        },
        
        showResults: function() {
            $('#bulk-results').show();
            var $resultsList = $('#results-list');
            $resultsList.empty();
            
            var successCount = 0;
            var errorCount = 0;
            
            this.results.forEach(function(result) {
                var status = result.success ? 'success' : 'error';
                var statusText = result.success ? 'Success' : 'Error: ' + result.error;
                
                if (result.success) successCount++;
                else errorCount++;
                
                var $item = $('<div class="result-item result-' + status + '">' +
                    '<h4>' + result.post.post_title + ' (ID: ' + result.post.ID + ')</h4>' +
                    '<p><strong>Status:</strong> ' + statusText + '</p>' +
                    '</div>');
                
                $resultsList.append($item);
            });
            
            // Add summary
            var $summary = $('<div class="results-summary">' +
                '<h3>Summary</h3>' +
                '<p><strong>Total:</strong> ' + this.results.length + '</p>' +
                '<p><strong>Successful:</strong> ' + successCount + '</p>' +
                '<p><strong>Failed:</strong> ' + errorCount + '</p>' +
                '</div>');
            
            $resultsList.prepend($summary);
        }
    };
    
    // History functionality
    var historyManager = {
        currentPage: 1,
        perPage: 20,
        
        init: function() {
            $('#apply-filters').on('click', this.loadHistory.bind(this));
            $('#clear-history-btn').on('click', this.showClearModal.bind(this));
            $('#confirm-clear-history').on('click', this.clearHistory.bind(this));
            $('#cancel-clear-history').on('click', this.hideClearModal.bind(this));
            
            // Load initial history
            this.loadHistory();
        },
        
        loadHistory: function(page) {
            this.currentPage = page || 1;
            
            $.ajax({
                url: dat_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'dat_get_history',
                    page: this.currentPage,
                    per_page: this.perPage,
                    filter_lang: $('#filter-language').val(),
                    filter_provider: $('#filter-provider').val(),
                    nonce: dat_admin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        historyManager.displayHistory(response.data);
                    }
                }
            });
        },
        
        displayHistory: function(data) {
            var $tbody = $('#history-tbody');
            $tbody.empty();
            
            data.history.forEach(function(item) {
                var $row = $('<tr>' +
                    '<td>' + item.created_at + '</td>' +
                    '<td>' + item.from_lang + ' → ' + item.to_lang + '</td>' +
                    '<td>' + item.provider + '</td>' +
                    '<td>' + item.char_count + '</td>' +
                    '<td class="original-text">' + this.truncateText(item.original_text, 100) + '</td>' +
                    '<td class="translated-text">' + this.truncateText(item.translated_text, 100) + '</td>' +
                    '</tr>');
                $tbody.append($row);
            }.bind(this));
            
            this.updatePagination(data);
        },
        
        truncateText: function(text, maxLength) {
            if (text.length <= maxLength) return text;
            return text.substring(0, maxLength) + '...';
        },
        
        updatePagination: function(data) {
            var totalPages = Math.ceil(data.total / data.per_page);
            var $pagination = $('#history-pagination');
            $pagination.empty();
            
            if (totalPages > 1) {
                for (var i = 1; i <= totalPages; i++) {
                    var $link = $('<a href="#" class="page-link">' + i + '</a>');
                    if (i === this.currentPage) {
                        $link.addClass('current');
                    }
                    $link.data('page', i);
                    $pagination.append($link);
                }
            }
        },
        
        showClearModal: function() {
            $('#clear-history-modal').show();
        },
        
        hideClearModal: function() {
            $('#clear-history-modal').hide();
        },
        
        clearHistory: function() {
            var days = $('#clear-history-days').val();
            
            $.ajax({
                url: dat_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'dat_clear_history',
                    days: days,
                    nonce: dat_admin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        alert(response.data.message);
                        historyManager.loadHistory();
                        historyManager.hideClearModal();
                    }
                }
            });
        }
    };
    
    // Cache management
    var cacheManager = {
        init: function() {
            $('#clear-all-cache').on('click', function() {
                cacheManager.clearCache('all');
            });
            
            $('#clear-expired-cache').on('click', function() {
                cacheManager.clearCache('expired');
            });
            
            $('#clear-language-cache').on('click', function() {
                var language = $('#cache-language').val();
                cacheManager.clearCache('language', language);
            });
        },
        
        clearCache: function(type, language) {
            $.ajax({
                url: dat_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'dat_clear_cache',
                    cache_type: type,
                    language: language || '',
                    nonce: dat_admin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        $('#cache-results').show();
                        $('#cache-message').text(response.data.message);
                        
                        // Reload page to update cache stats
                        setTimeout(function() {
                            location.reload();
                        }, 2000);
                    }
                }
            });
        }
    };
    
    // Debug functionality
    var debugManager = {
        init: function() {
            $('#test-translate').on('click', this.testTranslation.bind(this));
            this.loadStats();
        },
        
        testTranslation: function() {
            var text = $('#test-text').val();
            var fromLang = $('#test-from-lang').val();
            var toLang = $('#test-to-lang').val();
            
            if (!text.trim()) {
                alert('Please enter text to translate.');
                return;
            }
            
            if (fromLang === toLang) {
                alert('Source and target languages cannot be the same.');
                return;
            }
            
            $('#test-translate').prop('disabled', true).text('Testing...');
            
            $.ajax({
                url: dat_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'dat_translate',
                    text: text,
                    from_lang: fromLang,
                    to_lang: toLang,
                    nonce: dat_admin.nonce
                },
                success: function(response) {
                    $('#test-translate').prop('disabled', false).text('Test Translation');
                    
                    if (response.success) {
                        $('#test-results').show();
                        $('#test-translation-result').html(
                            '<strong>Original:</strong> ' + text + '<br>' +
                            '<strong>Translated:</strong> ' + response.data.translated
                        );
                    } else {
                        alert('Translation failed: ' + (response.data.message || 'Unknown error'));
                    }
                },
                error: function() {
                    $('#test-translate').prop('disabled', false).text('Test Translation');
                    alert('Translation service is unavailable.');
                }
            });
        },
        
        loadStats: function() {
            $.ajax({
                url: dat_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'dat_get_stats',
                    nonce: dat_admin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        debugManager.displayStats(response.data);
                    }
                }
            });
        },
        
        displayStats: function(data) {
            var $stats = $('#debug-stats');
            var html = '<h4>Current Month Usage (' + data.provider + ')</h4>';
            html += '<p><strong>Total Characters:</strong> ' + data.total_chars.toLocaleString() + ' / ' + data.limit.toLocaleString() + '</p>';
            html += '<p><strong>Remaining:</strong> ' + data.remaining.toLocaleString() + '</p>';
            
            if (data.monthly_stats.length > 0) {
                html += '<h5>By Language:</h5><ul>';
                data.monthly_stats.forEach(function(stat) {
                    html += '<li>' + stat.language + ': ' + stat.chars.toLocaleString() + ' chars, ' + stat.translations + ' translations</li>';
                });
                html += '</ul>';
            }
            
            $stats.html(html);
        }
    };
    
    // Pagination handler
    $(document).on('click', '.page-link', function(e) {
        e.preventDefault();
        var page = $(this).data('page');
        historyManager.loadHistory(page);
    });
    
    // Initialize based on current page
    var currentPage = window.location.search.match(/page=deepl-([^&]+)/);
    if (currentPage) {
        switch (currentPage[1]) {
            case 'bulk-translate':
                bulkTranslation.init();
                break;
            case 'history':
                historyManager.init();
                break;
            case 'cache-manager':
                cacheManager.init();
                break;
            case 'usage-limits':
                // Usage page doesn't need special initialization
                break;
            case 'debug-info':
                debugManager.init();
                break;
        }
    }
});
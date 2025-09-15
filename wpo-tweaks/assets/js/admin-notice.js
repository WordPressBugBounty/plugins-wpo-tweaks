/**
 * WPO Tweaks Admin Notice JavaScript
 * @package WPO_Tweaks
 * @since 2.1.0
 */

(function($) {
    'use strict';
    
    /**
     * Initialize admin notice functionality
     */
    function initAdminNotice() {
        // Handle notice dismissal
        $('.ayudawp-wpotweaks-notice').on('click', '.notice-dismiss', function(e) {
            e.preventDefault();
            
            var $notice = $(this).closest('.ayudawp-wpotweaks-notice');
            
            // Add dismissing class for animation
            $notice.addClass('ayudawp-dismissing');
            
            // Send AJAX request to dismiss notice
            $.ajax({
                url: ayudawpWpoTweaks.ajaxurl,
                type: 'POST',
                data: {
                    action: 'ayudawp_wpotweaks_dismiss_notice',
                    nonce: ayudawpWpoTweaks.nonce
                },
                beforeSend: function() {
                    // Disable dismiss button to prevent multiple clicks
                    $notice.find('.notice-dismiss').prop('disabled', true);
                },
                success: function(response) {
                    // Fade out notice smoothly
                    $notice.fadeOut(300, function() {
                        $(this).remove();
                    });
                },
                error: function(xhr, status, error) {
                    // Re-enable dismiss button if error occurs
                    $notice.find('.notice-dismiss').prop('disabled', false);
                    $notice.removeClass('ayudawp-dismissing');
                    
                    // Log error for debugging
                    console.error('WPO Tweaks: Failed to dismiss notice', error);
                }
            });
        });
        
        // Add keyboard accessibility
        $('.ayudawp-wpotweaks-notice .notice-dismiss').on('keydown', function(e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                $(this).click();
            }
        });
        
        // Analytics tracking for link clicks (optional)
        $('.ayudawp-notice-footer a').on('click', function() {
            var linkText = $(this).text();
            var linkHref = $(this).attr('href');
            
            // Log link click for future analytics if needed
            console.log('WPO Tweaks: Link clicked', linkText, linkHref);
        });
    }
    
    /**
     * Add smooth animations
     */
    function addAnimations() {
        var $notice = $('.ayudawp-wpotweaks-notice');
        
        if ($notice.length) {
            // Ensure notice is visible before animating
            $notice.css('opacity', 0).animate({
                opacity: 1
            }, 300);
        }
    }
    
    /**
     * Accessibility enhancements
     */
    function enhanceAccessibility() {
        var $notice = $('.ayudawp-wpotweaks-notice');
        
        if ($notice.length) {
            // Add ARIA label to dismiss button
            $notice.find('.notice-dismiss').attr('aria-label', 'Dismiss WPO Tweaks activation notice');
            
            // Add role and aria-live for screen readers
            $notice.attr({
                'role': 'alert',
                'aria-live': 'polite'
            });
        }
    }
    
    /**
     * Handle responsive behavior
     */
    function handleResponsive() {
        function checkScreenSize() {
            var $grid = $('.ayudawp-optimizations-grid');
            
            if ($(window).width() < 768) {
                $grid.addClass('ayudawp-mobile-view');
            } else {
                $grid.removeClass('ayudawp-mobile-view');
            }
        }
        
        // Check on load and resize
        checkScreenSize();
        $(window).on('resize', debounce(checkScreenSize, 250));
    }
    
    /**
     * Debounce function for performance
     */
    function debounce(func, wait) {
        var timeout;
        return function executedFunction() {
            var context = this;
            var args = arguments;
            
            var later = function() {
                timeout = null;
                func.apply(context, args);
            };
            
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    }
    
    /**
     * Initialize everything when DOM is ready
     */
    $(document).ready(function() {
        // Check if notice exists before initializing
        if ($('.ayudawp-wpotweaks-notice').length) {
            initAdminNotice();
            addAnimations();
            enhanceAccessibility();
            handleResponsive();
            
            // Log initialization for debugging
            console.log('WPO Tweaks: Admin notice initialized');
        }
    });
    
})(jQuery);
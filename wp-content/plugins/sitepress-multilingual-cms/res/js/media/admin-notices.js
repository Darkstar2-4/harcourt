/*global jQuery, ajaxurl, addLoadEvent */

(function () {
  'use strict';

  /**
   * Helper to safely call PostHog captureEvent
   */
  function captureEvent(eventName, eventProperties) {
    if (typeof window.wpmlPostHog?.captureEvent === 'function') {
      return window.wpmlPostHog.captureEvent(eventName, eventProperties);
    }
    // Return resolved Promise if PostHog not available
    return Promise.resolve();
  }

  /**
   * Detect WPML page name from URL
   */
  function detectWpmlPageName() {
    let page = new URLSearchParams(window.location.search).get('page')

    if (page && page.includes('tm/menu/main')) return 'dashboard'
    if (page && page.includes('tm/menu/settings')) return 'settings'
    if (page && page.includes('tm/menu/translations-queue')) return 'translations_page'
    if (page && page.includes('wpml-media')) return 'mt_list'
    return null
  }

  addLoadEvent(
    function () {
      var banner = jQuery('#admin_banner_about_automatic_media_detection');
      var enableNowButton = jQuery('#wpml-media-settings-button');
      var enableNowLink = jQuery('#wpml-media-settings-link');
      var notice = jQuery('#admin_banner_about_automatic_media_detection_after_30_days');
      var elementorNotice = jQuery('#admin_banner_for_elementor_on_mt_homepage');
      var bannerDismissButton = jQuery('#admin_banner_about_automatic_media_detection .dismiss-button');

      if (enableNowButton.length && enableNowLink.length) {
        const href = enableNowLink.attr('href')

        //Capture PostHog event
        captureEvent('wpml_mt_feature_announce_shown',{
          audience: 'existing',
          location: detectWpmlPageName(),
        })

        enableNowButton.on('click', function (e) {
          e.preventDefault()

          // Capture PostHog event
          captureEvent('wpml_mt_feature_announce_clicked', {
            cta: 'go_to_settings'
          }).then(()=>{
            window.location.href = href
          }).catch(()=>{
            window.location.href = href
          })
        });
      }

      if ( bannerDismissButton.length ) {
        bannerDismissButton.on('click', function() {
          wpml_media_dismiss_should_handle_media_auto_banner();
        });
      }
      var noticeDismissButton = jQuery('#admin_banner_about_automatic_media_detection_after_30_days .dismiss-button');
      if ( noticeDismissButton.length ) {
        noticeDismissButton.on('click', function() {
          wpml_media_dismiss_should_handle_media_auto_notice();
        });
      }
      var elementorDismissButton = jQuery('#admin_banner_for_elementor_on_mt_homepage .dismiss-button');
      if ( elementorDismissButton.length ) {
        elementorDismissButton.on('click', function() {
          wpml_media_dismiss_elementor_notice();
        });
      }

      function wpml_media_dismiss_should_handle_media_auto_banner() {
        jQuery.ajax(
          {
            url:      ajaxurl,
            type:     'POST',
            data:     {
              action: 'wpml_media_dismiss_should_handle_media_auto_banner',
              nonce: wpml_media_admin_notices_data.nonce_wpml_media_dismiss_should_handle_media_auto_banner,
            },
            dataType: 'json',
            success:  function (ret) {
              banner.remove();
            },
          }
        );
      }

      function wpml_media_dismiss_should_handle_media_auto_notice() {
        jQuery.ajax(
          {
            url:      ajaxurl,
            type:     'POST',
            data:     {
              action: 'wpml_media_dismiss_should_handle_media_auto_notice',
              nonce: wpml_media_admin_notices_data.nonce_wpml_media_dismiss_should_handle_media_auto_notice,
            },
            dataType: 'json',
            success:  function (ret) {
              notice.remove();
            },
          }
        );
      }

      function wpml_media_dismiss_elementor_notice() {
        jQuery.ajax(
          {
            url:      ajaxurl,
            type:     'POST',
            data:     {
              action: 'wpml_media_dismiss_admin_notice_for_elementor_on_mt_homepage_notice',
              nonce: wpml_media_admin_notices_data.nonce_wpml_media_dismiss_admin_notice_for_elementor_on_mt_homepage_notice,
            },
            dataType: 'json',
            success:  function (ret) {
              elementorNotice.remove();
            },
          }
        );
      }
    }
  );
}());

jQuery(document).ready(function($) {
  'use strict';

  // Toggle functionality for log sections.
  $(document).on('click', '.tm-log-toggle', function(e) {
    e.preventDefault();

    var $button = $(this);
    var targetId = $button.data('target');
    var $targetSection = $('#' + targetId);

    if ($targetSection.length) {
      $targetSection.slideToggle(300, function() {
        if ($targetSection.is(':visible')) {
          $button.text($button.data('textclose'));
        } else {
          $button.text($button.data('textopen'));
        }
      });
    }
  });

  // Summary-row "View" button — lazy-loads the per-request detail panel via AJAX.
  $(document).on('click', '.tm-log-view', function(e) {
    e.preventDefault();

    var $button = $(this);
    var logUid = $button.data('loguid');
    var targetId = $button.data('target');
    var $row = $('#' + targetId);
    var $content = $row.find('.job-log-detail-content');

    if (!$row.length) {
      return;
    }

    if ($row.is(':visible')) {
      $row.hide();
      $button.text($button.data('textopen'));
      return;
    }

    var showRow = function() {
      $row.show();
      $button.text($button.data('textclose'));
    };

    if ($content.data('loaded') === 1) {
      showRow();
      return;
    }

    $content.html('<em>Loading…</em>');
    showRow();

    $.ajax({
      url: wpmlTmJobLog.ajaxUrl,
      type: 'POST',
      data: {
        action: 'wpml_tm_job_log_get_request_detail',
        nonce: wpmlTmJobLog.nonce,
        loguid: logUid
      },
      success: function(response) {
        if (response && response.success && response.data && typeof response.data.html === 'string') {
          $content.html(response.data.html);
          $content.data('loaded', 1);
        } else {
          $content.html('<em style="color:#dc3232">' + (wpmlTmJobLog.loadDetailFailed || 'Failed to load') + '</em>');
        }
      },
      error: function() {
        $content.html('<em style="color:#dc3232">' + (wpmlTmJobLog.loadDetailFailed || 'Failed to load') + '</em>');
      }
    });
  });

  // Save if logs are enabled in settings.
  $('#job-log-feature-toggle').on('change', function() {
    var $checkbox = $(this);
    var $label = $('.job-log-toggle-label');
    var $loader = $('.job-log-toggle-loader');
    var isEnabled = $checkbox.is(':checked');

    $checkbox.prop('disabled', true);
    $loader.addClass('is-active').css({'visibility': 'visible', 'display': 'inline-block'});
    $label.text(isEnabled ? $label.data('textenabled') : $label.data('textdisabled'));

    $.ajax({
      url: wpmlTmJobLog.ajaxUrl,
      type: 'POST',
      data: {
        action: 'wpml_tm_job_log_toggle_feature',
        nonce: wpmlTmJobLog.nonce,
        enabled: isEnabled ? 1 : 0
      },
      success: function(response) {
        if (!response.success) {
          console.error('Failed to save job log feature state:', response.data);
          $checkbox.prop('checked', !isEnabled);
          $label.text(!isEnabled ? $label.data('textenabled') : $label.data('textdisabled'));
        }
      },
      error: function() {
        console.error('AJAX error while saving job log feature state');
        $checkbox.prop('checked', !isEnabled);
        $label.text(!isEnabled ? $label.data('textenabled') : $label.data('textdisabled'));
      },
      complete: function() {
        $checkbox.prop('disabled', false);
        $loader.removeClass('is-active').css({'visibility': 'hidden', 'display': 'none'});
      }
    });
  });

  // Clear all logs.
  $('#job-log-clear-button').on('click', function() {
    var $button = $(this);
    var $loader = $('.job-log-clear-loader');
    var $message = $('.job-log-clear-message');

    if (!confirm(wpmlTmJobLog.confirmClearLogs)) {
      return;
    }

    $button.prop('disabled', true);
    $loader.addClass('is-active').css({'visibility': 'visible', 'display': 'inline-block'});
    $message.text('');

    $.ajax({
      url: wpmlTmJobLog.ajaxUrl,
      type: 'POST',
      data: {
        action: 'wpml_tm_job_log_clear',
        nonce: wpmlTmJobLog.nonce
      },
      success: function(response) {
        if (response.success) {
          $message.text(wpmlTmJobLog.logsClearedSuccess).css('color', '#46b450');
          setTimeout(function() {
            location.reload();
          }, 1000);
        } else {
          console.error('Failed to clear job logs:', response.data);
          $message.text(wpmlTmJobLog.logsClearedFailed).css('color', '#dc3232');
        }
      },
      error: function() {
        console.error('AJAX error while clearing job logs');
        $message.text(wpmlTmJobLog.logsClearedError).css('color', '#dc3232');
      },
      complete: function() {
        $button.prop('disabled', false);
        $loader.removeClass('is-active').css({'visibility': 'hidden', 'display': 'none'});
      }
    });
  });

  // Bulk-download submit helper — POSTs a hidden form to admin-ajax. Used by
  // both "Download all" and "Download last 100"; the server streams the
  // response so the new tab receives the file as it's generated.
  function submitJobLogBulkDownload(actionName) {
    var form = $('<form>', {
      'method': 'POST',
      'action': wpmlTmJobLog.ajaxUrl,
      'target': '_blank'
    });
    form.append($('<input>', {
      'type': 'hidden',
      'name': 'action',
      'value': actionName
    }));
    form.append($('<input>', {
      'type': 'hidden',
      'name': 'nonce',
      'value': wpmlTmJobLog.nonce
    }));
    $('body').append(form);
    form.submit();
    form.remove();
  }

  $('#job-log-download-all-button').on('click', function(e) {
    e.preventDefault();
    submitJobLogBulkDownload('wpml_tm_job_log_download_all');
  });

  // ----- Storage cap popup -------------------------------------------------
  // Clicking the size badge opens a modal that lets operators raise/lower
  // the byte and file-count caps. Saved via AJAX, page reloads on success
  // so the new caps apply immediately (the resolver helpers in PHP read
  // them on the next request).
  var $capTrigger = $('#job-log-cap-trigger');
  var $capModal   = $('#job-log-cap-modal');
  var $capMb      = $('#job-log-cap-mb');
  var $capFiles   = $('#job-log-cap-files');
  var $capError   = $('#job-log-cap-modal-error');
  var $capLoader  = $('.job-log-cap-modal-loader');

  function openCapModal() {
    var minMb     = parseInt($capTrigger.data('min-mb'), 10);
    var maxMb     = parseInt($capTrigger.data('max-mb'), 10);
    var minFiles  = parseInt($capTrigger.data('min-files'), 10);
    var maxFiles  = parseInt($capTrigger.data('max-files'), 10);
    $capMb.attr({ min: minMb, max: maxMb }).val($capTrigger.data('current-mb'));
    $capFiles.attr({ min: minFiles, max: maxFiles }).val($capTrigger.data('current-files'));
    $('#job-log-cap-mb-range').text('(' + minMb + '–' + maxMb + ' MB)');
    $('#job-log-cap-files-range').text('(' + minFiles + '–' + maxFiles + ')');
    $capError.hide().text('');
    $capModal.show().attr('aria-hidden', 'false');
    $capMb.trigger('focus');
  }

  function closeCapModal() {
    $capModal.hide().attr('aria-hidden', 'true');
  }

  $capTrigger.on('click', function(e) {
    e.preventDefault();
    openCapModal();
  });

  $('#job-log-cap-cancel').on('click', closeCapModal);

  // Click outside the inner dialog closes; clicks inside the dialog stop.
  $capModal.on('click', function(e) {
    if (e.target === this) {
      closeCapModal();
    }
  });

  // Escape key closes.
  $(document).on('keydown.jobLogCap', function(e) {
    if (e.key === 'Escape' && $capModal.is(':visible')) {
      closeCapModal();
    }
  });

  $('#job-log-cap-save').on('click', function() {
    var mb    = parseInt($capMb.val(), 10);
    var files = parseInt($capFiles.val(), 10);
    var minMb = parseInt($capTrigger.data('min-mb'), 10);
    var maxMb = parseInt($capTrigger.data('max-mb'), 10);
    var minFiles = parseInt($capTrigger.data('min-files'), 10);
    var maxFiles = parseInt($capTrigger.data('max-files'), 10);

    if (isNaN(mb) || mb < minMb || mb > maxMb) {
      $capError.text('MB must be ' + minMb + '–' + maxMb).show();
      return;
    }
    if (isNaN(files) || files < minFiles || files > maxFiles) {
      $capError.text('Files must be ' + minFiles + '–' + maxFiles).show();
      return;
    }

    $capError.hide();
    $capLoader.css('visibility', 'visible').show().addClass('is-active');
    $('#job-log-cap-save, #job-log-cap-cancel').prop('disabled', true);

    $.ajax({
      url: wpmlTmJobLog.ajaxUrl,
      type: 'POST',
      data: {
        action: 'wpml_tm_job_log_save_caps',
        nonce: wpmlTmJobLog.nonce,
        max_mb: mb,
        max_files: files
      },
      success: function(response) {
        if (response.success) {
          $capError.text(wpmlTmJobLog.capsSavedReloading || 'Saved — reloading…').css('color', '#1C7D6B').show();
          // Reload so the new caps reflect in the badge and (more
          // importantly) the next request's prune logic uses them.
          setTimeout(function() { location.reload(); }, 400);
        } else {
          $capError.text((response.data || wpmlTmJobLog.capsSaveFailed || 'Failed to save')).css('color', '#dc3232').show();
          $capLoader.css('visibility', 'hidden').hide().removeClass('is-active');
          $('#job-log-cap-save, #job-log-cap-cancel').prop('disabled', false);
        }
      },
      error: function() {
        $capError.text(wpmlTmJobLog.capsSaveFailed || 'Failed to save').css('color', '#dc3232').show();
        $capLoader.css('visibility', 'hidden').hide().removeClass('is-active');
        $('#job-log-cap-save, #job-log-cap-cancel').prop('disabled', false);
      }
    });
  });

  $('#job-log-download-last-100-button').on('click', function(e) {
    e.preventDefault();
    submitJobLogBulkDownload('wpml_tm_job_log_download_last_100');
  });

  // Download log.
  $(document).on('click', '.tm-log-download', function(e) {
    e.preventDefault();
    
    var $button = $(this);
    var logUid = $button.data('loguid');

    var form = $('<form>', {
      'method': 'POST',
      'action': wpmlTmJobLog.ajaxUrl,
      'target': '_blank'
    });
    
    form.append($('<input>', {
      'type': 'hidden',
      'name': 'action',
      'value': 'wpml_tm_job_log_download'
    }));
    
    form.append($('<input>', {
      'type': 'hidden',
      'name': 'nonce',
      'value': wpmlTmJobLog.nonce
    }));
    
    form.append($('<input>', {
      'type': 'hidden',
      'name': 'loguid',
      'value': logUid,
    }));
    
    $('body').append(form);
    form.submit();
    form.remove();
  });

  // ----- By-post tab: picker + entity timeline -----------------------------
  // Lives only on the "By post" tab; if the picker element isn't on the page
  // (e.g., on the Requests tab) the rest is dormant.
  var $picker          = $('#job-log-entity-picker');
  var $pickerDropdown  = $('#job-log-entity-picker-dropdown');
  var $pickerLoader    = $('.job-log-entity-picker-loader');
  var $entityContent   = $('#job-log-entity-content');
  var pickerDebounce;
  var lastQuery = '';

  function renderPickerResults(results) {
    if (!results || results.length === 0) {
      $pickerDropdown.html('<div class="job-log-entity-picker-empty">' +
        (wpmlTmJobLog.entityNoResults || 'No posts found') + '</div>').show();
      return;
    }
    var html = results.map(function (r) {
      var title = r.title || '(no title)';
      var lang = r.language_code ? ' [' + r.language_code + ']' : '';
      return '<a href="#" class="job-log-entity-picker-item" data-post-id="' + r.id + '">' +
        '#' + r.id + ' — <span class="job-log-entity-picker-title">' +
        $('<div>').text(title).html() + '</span>' +
        ' <span class="job-log-entity-picker-meta">(' + (r.post_type || '?') + lang + ')</span></a>';
    }).join('');
    $pickerDropdown.html(html).show();
  }

  function hidePickerDropdown() {
    $pickerDropdown.hide().empty();
  }

  function showPickerLoader(show) {
    if (show) {
      $pickerLoader.css('visibility', 'visible').show().addClass('is-active');
    } else {
      $pickerLoader.css('visibility', 'hidden').hide().removeClass('is-active');
    }
  }

  $picker.on('input', function () {
    var q = $(this).val().trim();
    clearTimeout(pickerDebounce);
    if (q === '') {
      hidePickerDropdown();
      return;
    }
    if (q === lastQuery) {
      return;
    }
    lastQuery = q;
    pickerDebounce = setTimeout(function () {
      showPickerLoader(true);
      $.ajax({
        url: wpmlTmJobLog.ajaxUrl,
        type: 'POST',
        data: {
          action: 'wpml_tm_job_log_search_posts',
          nonce: wpmlTmJobLog.nonce,
          q: q
        },
        success: function (response) {
          if (response && response.success && response.data && response.data.results) {
            renderPickerResults(response.data.results);
          } else {
            $pickerDropdown.html('<div class="job-log-entity-picker-empty" style="color:#dc3232">' +
              (wpmlTmJobLog.entitySearchFailed || 'Search failed') + '</div>').show();
          }
        },
        error: function () {
          $pickerDropdown.html('<div class="job-log-entity-picker-empty" style="color:#dc3232">' +
            (wpmlTmJobLog.entitySearchFailed || 'Search failed') + '</div>').show();
        },
        complete: function () {
          showPickerLoader(false);
        }
      });
    }, 300);
  });

  // Hide dropdown on outside click.
  $(document).on('click.jobLogPicker', function (e) {
    if (!$(e.target).closest('.job-log-entity-picker-wrap').length) {
      hidePickerDropdown();
    }
  });

  // Download-timeline button (lives inside the AJAX-rendered timeline
  // fragment, so use delegated binding on $entityContent). Posts a hidden
  // form to the streaming endpoint so the response is delivered as a
  // file attachment without JS having to assemble blobs.
  $entityContent.on('click', '.job-log-entity-download-button', function (e) {
    e.preventDefault();
    var postId = $(this).data('post-id');
    if (!postId) {
      return;
    }
    var form = $('<form>', {
      'method': 'POST',
      'action': wpmlTmJobLog.ajaxUrl,
      'target': '_blank'
    });
    form.append($('<input>', { 'type': 'hidden', 'name': 'action', 'value': 'wpml_tm_job_log_download_entity_timeline' }));
    form.append($('<input>', { 'type': 'hidden', 'name': 'nonce',   'value': wpmlTmJobLog.nonce }));
    form.append($('<input>', { 'type': 'hidden', 'name': 'post_id', 'value': postId }));
    $('body').append(form);
    form.submit();
    form.remove();
  });

  // Selecting a result loads the timeline fragment.
  $pickerDropdown.on('click', '.job-log-entity-picker-item', function (e) {
    e.preventDefault();
    var postId = $(this).data('post-id');
    var label  = $(this).find('.job-log-entity-picker-title').text();
    $picker.val('#' + postId + ' — ' + label);
    hidePickerDropdown();

    $entityContent.html('<p><em>Loading timeline…</em></p>');
    $.ajax({
      url: wpmlTmJobLog.ajaxUrl,
      type: 'POST',
      data: {
        action: 'wpml_tm_job_log_entity_timeline',
        nonce: wpmlTmJobLog.nonce,
        post_id: postId
      },
      success: function (response) {
        if (response && response.success && response.data && typeof response.data.html === 'string') {
          $entityContent.html(response.data.html);
        } else {
          var msg = (response && response.data) ? response.data : (wpmlTmJobLog.entityTimelineFailed || 'Failed to load timeline');
          $entityContent.html('<p style="color:#dc3232"><em>' + msg + '</em></p>');
        }
      },
      error: function () {
        $entityContent.html('<p style="color:#dc3232"><em>' +
          (wpmlTmJobLog.entityTimelineFailed || 'Failed to load timeline') + '</em></p>');
      }
    });
  });
});
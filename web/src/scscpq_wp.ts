/* Loader for the catalog, dashboard and cart inside the WordPress pages. Top-level functions and the two `var`s are
 * globals on purpose: PHP-generated pages call scscpq_ipc, ajax_fill and ajax_portal and read scscpq_url.
 *
 * Changes from the plain-JS loader this replaces, all about trusting less:
 *  - A `message` is acted on only when it comes from this page's own origin. Every sender in the repo is a page of
 *    this site inside an iframe (scscpq_ipc); before, any window that could reach this one could write HTML into it.
 *  - scscpq_ipc names this origin as the target instead of "*".
 *  - A failed request shows the server's text as text, not as markup.
 *  - ajax_fill no longer logs element ids to the console. */

var scscpq_version = '20261021';
var scscpq_url = window.location.origin + '/scscpq_wp.php';

jQuery(document).ready(function () {
  scscpq_flag_links();
  // hxweb already put the catalog into the page and inlined the loader's answer.
  if (window.hxweb_loader) scscpq_apply(window.hxweb_loader, true);
  else scscpq_event('initial');
});

// A flag click carries hxlang=<code>, so the catalog saves the choice to the account; a plain link in another
// language does not.
function scscpq_flag_links(): void {
  jQuery('li.wpml-ls-item > a[href]').each(function () {
    const link = jQuery(this);
    const lang = (link.closest('li').attr('class')?.match(/wpml-ls-item-(\w\w)\b/) ?? [])[1];
    const href = link.attr('href');
    if (!lang || href === undefined || href === '#' || /[?&]hxlang=/.test(href)) return;
    link.attr('href', href.replace(/(#.*)?$/, (href.indexOf('?') < 0 ? '?' : '&') + 'hxlang=' + lang + '$1'));
  });
}

interface IpcMessage {
  scscpq: string;
  id: string;
  data?: unknown;
}

function isIpcMessage(v: unknown): v is IpcMessage {
  if (typeof v !== 'object' || v === null) return false;
  const m = v as Record<string, unknown>;
  return typeof m.scscpq === 'string' && m.scscpq !== '' && typeof m.id === 'string';
}

jQuery(window).on('message', function (e) {
  const event = e.originalEvent;
  if (!(event instanceof MessageEvent) || event.origin !== window.location.origin) return;
  const msg: unknown = event.data;
  if (!isIpcMessage(msg)) return;
  const target = document.getElementById(msg.id);
  switch (msg.scscpq) {
    case 'resize': {
      if (!(target instanceof HTMLIFrameElement)) return;
      const sent = Number(msg.data);
      const height = msg.data && Number.isFinite(sent) ? sent : (target.contentWindow?.document.body.scrollHeight ?? 0);
      target.style.height = height + 40 + 'px';
      break;
    }
    case 'cart_size':
      if (target && (typeof msg.data === 'string' || typeof msg.data === 'number')) jQuery(target).html(String(msg.data));
      break;
  }
});

function scscpq_ipc(id: string, action: string, data: unknown): void {
  window.parent.postMessage({ scscpq: action, token: sessionStorage.getItem('scscpq_token'), id: id, data: data }, window.location.origin);
}

function scscpq_event(action: string): void {
  const page = jQuery('#scscpq_page').html();
  const documentHtml = jQuery('#scscpq_document').html();

  const spinner: string[] = [];
  spinner.push('<style>');
  spinner.push('.scscpq_loader {display: inline-block; border: 10px solid #f3f3f3; border-radius: 50%; border-top: 10px solid #DC2536; width: 40px; height: 40px; -webkit-animation: spin 2s linear infinite; animation: spin 2s linear infinite; }');
  spinner.push('.scscpq_loader_text { display: inline-block; vertical-align: top; margin-left: 10px; margin-top: 18px; }');
  spinner.push('@-webkit-keyframes spin { 0% { -webkit-transform: rotate(0deg); } 100% { -webkit-transform: rotate(360deg); } }');
  spinner.push('@keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }');
  spinner.push('</style>');
  spinner.push("<div style='margin-top: 50px;margin-bottom: 50px; text-align: center;'><div class=scscpq_loader></div><div class=scscpq_loader_text>Loading ...</div></div>");
  jQuery('#scscpq_page').html(spinner.join('\n'));

  jQuery.ajax({
    url: scscpq_url,
    data: {
      action: action,
      page: page,
      document: documentHtml,
      language: (document.documentElement.lang || '').slice(0, 2),
      remember: /[?&]hxlang=/.test(window.location.search) ? 1 : 0,
      token: sessionStorage.getItem('scscpq_token') ?? undefined,
      url: window.location.href,
    },
    type: 'post',
    dataType: 'json',
    success: function (response: unknown) {
      scscpq_apply(asRecord(response), false);
    },
    error: function (xhr) {
      jQuery('#scscpq_status').text(xhr.responseText ?? '');
    },
  });
}

/** JSON that should be an object. Anything else is an empty answer. */
function asRecord(v: unknown): Record<string, unknown> {
  return typeof v === 'object' && v !== null && !Array.isArray(v) ? (v as Record<string, unknown>) : {};
}

/** The server sends 0 to hide a block and 1 to show it as it is; the PHP side compared loosely, so "0", "" and false count. */
function scscpq_is_zero(v: unknown): boolean {
  return v === 0 || v === '0' || v === '' || v === false;
}

function scscpq_is_one(v: unknown): boolean {
  return v === 1 || v === '1' || v === true;
}

function scscpq_apply(response: Record<string, unknown>, inlined: boolean): void {
  for (const key of Object.keys(response)) {
    const value = response[key];
    if (key === 'token') {
      sessionStorage.setItem('scscpq_token', String(value));
    } else if (key.substring(0, 6) !== 'scscpq') {
      continue;
    } else if (key === 'scscpq_document' && value) {
      jQuery('#scscpq_document').load(String(value));
    } else if (key === 'scscpq_document') {
      jQuery('#scscpq_document').html('');
    } else if (key === 'scscpq_link') {
      if (!inlined) jQuery('#scscpq_page').load(String(value));
    } else if (key === 'scscpq_page' || key === 'scscpq_iframe') {
      jQuery('#scscpq_page').html(String(value));
    } else if (scscpq_is_zero(value)) {
      jQuery('.' + key).hide();
    } else {
      jQuery('.' + key).show();
      if (!scscpq_is_one(value)) jQuery('.' + key).html(String(value));
    }
  }
}

function ajax_fill(table: string, data: Record<string, unknown>): void {
  for (const key of Object.keys(data)) {
    const field = '#ajax_' + table + '_' + key;
    const value = data[key];
    const text = value === null || value === undefined ? '' : String(value);
    const type: unknown = jQuery(field).prop('type');
    switch (type) {
      case 'text':
      case 'textarea':
      case 'select-one':
        jQuery(field).val(text);
        break;
      case 'checkbox':
        jQuery(field).prop('checked', value ? true : false);
        break;
      default:
        jQuery(field).html(text);
    }
  }
}

function ajax_portal(action: string): void {
  jQuery.ajax({
    url: '/portal_ajax.php',
    dataType: 'json',
    data: { action: action },
    success: function (data: unknown) {
      ajax_fill('', asRecord(data));
    },
  });
}

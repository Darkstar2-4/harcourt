( function () {

  /**
   * Intercept fetch requests and css links that match with the ops.domains and rewrite the URL to point to the opts.proxyPath
   * @param opts
   * @returns {(function(): void)|*}
   */
  function enableProxyForDomains ( opts ) {
    const domains = opts.domains
    const proxyPath = opts.proxyPath
    const nonce = opts.nonce
    const excludedRequests = Array.isArray( opts.excludedRequests ) ? opts.excludedRequests : []
    const original = captureOriginalApis()
    const patched = {
      fetch: patchFetch( original.fetch, domains, proxyPath, nonce, excludedRequests ),
    }

    applyPatches( patched )
    // Setup stylesheet link rewriting (existing + future additions)
    const cleanupCss = setupStylesheetProxy( domains, proxyPath, nonce, excludedRequests )
    return function () {
      restoreOriginalApis( original )
      if (typeof cleanupCss === 'function') {
        try { cleanupCss() } catch (e) { /* noop */ }
      }
    }
  }

  function captureOriginalApis () {
    return {
      fetch: window.fetch.bind( window ),
      xhrOpen: XMLHttpRequest.prototype.open,
    }
  }

  function applyPatches ( p ) {
    window.fetch = p.fetch
  }

  function restoreOriginalApis ( o ) {
    window.fetch = o.fetch
  }

  function patchFetch ( originalFetch, domainsToRedirect, proxyPath, nonce, excludedRequests ) {
    const toProxyUrl = createProxyUrlBuilder( domainsToRedirect, proxyPath, nonce )
    return function ( input, init ) {
      // Extract URL to check if we need to proxy
      const url = getUrlFromInput( input )
      const shouldProxy = shouldProxyUrl( url, domainsToRedirect, proxyPath, excludedRequests )

      if (!shouldProxy) {
        return originalFetch( input, init )
      }

      const proxiedUrl = toProxyUrl( url )

      // If input is a Request object, create a new Request with the proxied URL
      if (input instanceof Request) {
        return originalFetch( new Request( proxiedUrl, input ), init )
      }

      // For string or URL input, just use the proxied URL
      return originalFetch( proxiedUrl, init )
    }
  }

  /**
   * Extract URL from fetch input (string, URL, or Request object)
   * @param input
   * @returns {string}
   */
  function getUrlFromInput ( input ) {
    if (typeof input === 'string') {
      return input
    }
    if (input instanceof URL) {
      return input.toString()
    }
    if (input instanceof Request) {
      return input.url
    }
    return String( input )
  }

  function createProxyUrlBuilder ( domain, proxyPath, nonce ) {
    return function ( url ) {
      const isAbsolute = /^https?:\/\//i.test( url )
      const absoluteUrl = isAbsolute ? url : toAbsoluteUrl( url )
      const encoded = encodeURIComponent( absoluteUrl )
      const base = proxyPath.endsWith( '=' ) ? proxyPath : ( proxyPath +
        ( proxyPath.includes( '?' ) ? '&' : '?' ) + 'url=' )
      let out = base + encoded
      if (nonce) {
        out += `&_wpnonce=${encodeURIComponent( String( nonce ) )}`
      }
      return out
    }
  }

  function shouldProxyUrl ( url, domainToRedirect, proxyPath, excludedRequests ) {
    if (isAlreadyProxied( url, proxyPath )) return false
    if (shouldBypassExcluded( url, excludedRequests )) return false
    const hostname = getHostname( url )
    if (!hostname) return false

    for (let i = 0; i < domainToRedirect.length; i++) {
      const d = domainToRedirect[i]
      if (!d) continue
      if (matchesHostname( hostname, d )) return true
    }
    return false
  }

  function isAlreadyProxied ( url, proxyPath ) {
    try {
      const absolute = new URL( url, window.location.origin ).toString()
      const proxyAbs = new URL( proxyPath, window.location.origin ).toString()
      return absolute.indexOf( proxyAbs ) === 0
    } catch (e) {
      return url.indexOf( proxyPath ) === 0
    }
  }

  function getHostname ( url ) {
    try {
      const u = new URL( url, window.location.origin )
      return u.hostname
    } catch (e) { return null }
  }

  function matchesHostname ( hostname, pattern ) {
    function norm ( x ) { return x.replace( /^www\./i, '' ).toLowerCase() }

    const h = norm( hostname )
    const p = norm( pattern )

    // Wildcard support like *.example.com
    if (p.includes( '*' )) {
      const base = p.replace( /^\*\./, '' )
      if (!base) return false
      return h === base || h.endsWith( '.' + base )
    }

    return h === p
  }

  function toAbsoluteUrl ( url ) { return new URL( url, window.location.origin ).toString() }

  function shouldBypassExcluded ( url, excludedUrls ) {
    if (!Array.isArray( excludedUrls ) || !excludedUrls.length) return false
    try {

      for (let excludedUrl of excludedUrls) {
        if (excludedUrl === url) {
          return true
        }
      }
    } catch (e) { /* noop */ }
    return false
  }

  // Rewrites <link rel="stylesheet" href="..."> pointing to configured external domains
  // to go through the same-origin proxy, and observes DOM for newly added/changed links.
  function setupStylesheetProxy ( domains, proxyPath, nonce, excludedRequests ) {
    try {
      const toProxyUrl = createProxyUrlBuilder( domains, proxyPath, nonce )

      function isStylesheetRel ( rel ) {
        if (!rel) return false
        return /\bstylesheet\b/i.test( rel )
      }

      function maybeRewriteLink ( linkEl ) {

        try {
          if (!linkEl || linkEl.nodeType !== 1) return
          if (linkEl.tagName !== 'LINK') return
          const relAttr = linkEl.getAttribute( 'rel' ) || ''
          if (!isStylesheetRel( relAttr )) return
          // Respect SRI; rewriting URL would invalidate integrity
          if (linkEl.integrity) return
          const href = linkEl.getAttribute( 'href' ) || ''
          if (!href) return
          if (!shouldProxyUrl( href, domains, proxyPath, excludedRequests )) return
          const proxied = toProxyUrl( href )
          if (proxied && proxied !== href) {
            linkEl.setAttribute( 'href', proxied )
          }
        } catch (e) { /* noop */ }
      }

      function processExisting () {
        try {
          const links = document.querySelectorAll( 'link[rel~="stylesheet"][href]' )
          links.forEach( maybeRewriteLink )
        } catch (e) { /* noop */ }
      }

      // Observe future additions or attribute changes
      const observer = new MutationObserver( function ( mutations ) {
        try {
          for (let i = 0; i < mutations.length; i++) {
            const m = mutations[i]
            if (m.type === 'childList') {
              if (m.addedNodes && m.addedNodes.length) {
                m.addedNodes.forEach( function ( node ) {
                  if (!node || node.nodeType !== 1) return
                  if (node.tagName === 'LINK') maybeRewriteLink( node )
                  if (node.querySelectorAll) {
                    node.querySelectorAll( 'link[rel~="stylesheet"][href]' ).forEach( maybeRewriteLink )
                  }
                } )
              }
            } else if (m.type === 'attributes') {
              const t = m.target
              if (t && t.tagName === 'LINK') maybeRewriteLink( t )
            }
          }
        } catch (e) { /* noop */ }
      } )

      try {
        observer.observe( document.documentElement || document, {
          childList: true,
          subtree: true,
          attributes: true,
          attributeFilter: ['href', 'rel'],
        } )
      } catch (e) { /* noop */ }

      if (document.readyState === 'loading') {
        document.addEventListener( 'DOMContentLoaded', processExisting, { once: true } )
      } else {
        processExisting()
      }

      return function cleanup () {
        try { observer.disconnect() } catch (e) { /* noop */ }
        try { document.removeEventListener( 'DOMContentLoaded', processExisting ) } catch (e) { /* noop */ }
      }
    } catch (e) {
      return function () {}
    }
  }

  // Activate only when options are provided via window.wpmlProxyOptions
  if (window.wpmlProxyOptions && window.wpmlProxyOptions.domains &&
    window.wpmlProxyOptions.proxyPath) {
    try {
      window.__wpmlDisableAteProxy = enableProxyForDomains( window.wpmlProxyOptions )
    } catch (e) { /* noop */ }
  }
} )()

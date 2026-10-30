/* Globals the page and the PHP-generated markup share with these scripts. */

interface Window {
  /** Set by hx-nav.js. The search box (scscpq_wp.php search_content) calls it; false means "load the page instead". */
  hxGo?: (href: string) => boolean;
  /** Set by hx-nav.js. The gate the page registers in <head> calls it on Back and Forward. */
  hxPopstate?: (e: PopStateEvent) => void;
  /** The loader's answer, inlined into the page by hxweb. */
  hxweb_loader?: Record<string, unknown>;
}

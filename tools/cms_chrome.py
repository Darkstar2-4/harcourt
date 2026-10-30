#!/usr/bin/env python3
"""Derive the theme chrome rust/src/cms/theme/chrome/{en,fr}/{head,header,footer}.html from a static export.

The export's chrome is WordPress's head, header and footer with every per-page value cut out as {{name}}. This
also cuts out what the cms content model now holds (menus, footer texts, the notice banner), so Rust renders them
from SQLite. Every substitution must match exactly once, or nothing is written.

    python3 -I tools/cms_chrome.py <export-root>
"""
import pathlib
import re
import subprocess
import sys

OUT = pathlib.Path(__file__).resolve().parent.parent / 'rust/src/cms/theme/chrome'

SUBS = {
    'head': [
        (r'(body > header \{)height: 125px;( position: fixed;)', r'\1{{header_height}}\2'),
        (r'(body, body\.admin-bar \{)padding-top: 125px;(\})', r'\1{{body_padding}}\2'),
    ],
    'header': [
        (r'(<ul id="menu-[a-z-]*" class="navbar-nav [^"]*">).*?\{\{lang_switcher\}\}\n</ul>', r'\1{{main_menu}}\n</ul>'),
        (r'    </nav>\n\n\n\n  </header>', '    </nav>\n\n\n{{notification}}\n  </header>'),
    ],
    'footer': [
        (r'<div class="menu-footer[a-z-]*-container"><ul id="menu-footer[a-z-]*\{\{footer_menu_id_suffix\}\}" class="menu">.*?</ul></div>', '{{footer_menu}}'),
        (r'(<div class="copyRight">\n          )<p>Copyright [^<]*</p>\n', r'\1{{copyright}}'),
        (r'(Terms-Conditions-2023\.pdf" target="_blank">|Supplier-terms_French\.pdf" target="_blank">)[^<]*</a>', r'\1{{pdf1}}</a>'),
        (r'(Conditions-of-Purchase-2023\.pdf" target="_blank">|terms-and-conditions-of-sales_French\.pdf" target="_blank">)[^<]*</a>', r'\1{{pdf2}}</a>'),
        (r'(Conditions-of-Sale-2025\.pdf" target="_blank">|General-terms_French\.pdf" target="_blank">)[^<]*</a>', r'\1{{terms}}</a>'),
        (r'(<a href="https://www\.harcourt\.co/(?:\?lang=fr)?privacy">)[^<]*</a>', r'\1{{privacy}}</a>'),
        (r'(<a href="https://www\.harcourt\.co/(?:\?lang=fr)?legal">)[^<]*</a>', r'\1{{legal}}</a>'),
    ],
}


def theme_script_version():
    """header.php hard-codes the scscpq_wp.js cache-buster; an older export may carry an older one."""
    header = subprocess.run(['git', 'show', 'HEAD:wp-content/themes/momentum-custom/header.php'], cwd=OUT,
                            capture_output=True, text=True, check=True).stdout
    return re.search(r'scscpq_wp\.js\?ver=([0-9a-z]+)', header).group(1)


def main():
    root = pathlib.Path(sys.argv[1])
    ver = theme_script_version()
    out = {}
    for lang in ('en', 'fr'):
        for part, subs in SUBS.items():
            text = (root / 'chrome' / lang / f'{part}.html').read_bytes().decode('utf-8')
            if part == 'head':
                subs = subs + [(r'(scscpq_wp\.js\?ver=)[0-9a-z]+', r'\g<1>' + ver)]
            for rx, repl in subs:
                text, n = re.subn(rx, repl, text, flags=re.S)
                if n != 1:
                    sys.exit(f'{lang}/{part}: {rx!r} matched {n} times')
            out[OUT / lang / f'{part}.html'] = text
    for path, text in out.items():
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_bytes(text.encode('utf-8'))
        print(path)


if __name__ == '__main__':
    main()

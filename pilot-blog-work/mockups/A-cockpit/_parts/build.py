#!/usr/bin/env python3
"""Assemble the A-cockpit mockup from the CURRENT live page plus the cockpit parts.

Kept byte-for-byte from /home/user/momo/pilot-blog/index.html (sliced by marker strings, never line numbers):
  - <head> and every CSS rule up to the old "SECTIONS (shared)" block (fonts, tokens, nav, the whole hero, static hero)
  - the body from the skip link through </header> (nav + hero markup)
  - the inline shelf JSON
  - the script from its start through applyHeroMode() (helpers, copy object T, hero loader/scrub/priming/tape)
  - the hero diagnostics panel
Replaced: the six sections, the glide-slope dividers, the footer, their CSS and JS (parts/*.css|html|js).
Run: python3 build.py   (writes ../index.html)
"""
import pathlib, re, sys

HERE = pathlib.Path(__file__).resolve().parent
SRC = pathlib.Path('/home/user/momo/pilot-blog/index.html').read_text(encoding='utf-8')
OUT = HERE.parent / 'index.html'


def cut(text, start, end, include_start=True):
    a = text.index(start)
    if not include_start:
        a += len(start)
    b = text.index(end, a + 1)
    return text[a:b]


head_css = SRC[:SRC.index('/* ============ SECTIONS (shared) ============ */')]
# keep the diagnostics panel rule (hero tooling) verbatim
diag_css = re.search(r'/\* hero diagnostics panel.*?\n\.diag\{[^\n]*\n', SRC, re.S).group(0)
body_hero = cut(SRC, '</style>\n</head>', '<!-- ============ 01 ABOUT ============ -->')
shelf = cut(SRC, '<!-- AI shelf data:', '<script>\n(() => {')
js_hero = cut(SRC, '<script>\n(() => {', '/* ============ 02: hold to fly the glide slope ============ */')
js_diag = cut(SRC, '/* ---------- hero diagnostics panel ---------- */', '/* ---------- start ---------- */')

css = (HERE / 'cockpit.css').read_text(encoding='utf-8')
html = (HERE / 'cockpit.html').read_text(encoding='utf-8')
js = (HERE / 'cockpit.js').read_text(encoding='utf-8')
gauges = (HERE / 'gauges.py')
if gauges.exists():
    ns = {}
    exec(gauges.read_text(encoding='utf-8'), ns)
    for key, val in ns['PARTS'].items():
        html = html.replace('{{' + key + '}}', val)
left = re.findall(r'\{\{[A-Z_0-9]+\}\}', html)
if left:
    sys.exit('unfilled placeholders: ' + ', '.join(sorted(set(left))))

out = (head_css + diag_css + '\n' + css + body_hero + html + '\n' + shelf + js_hero + js + '\n' + js_diag +
       '/* ---------- start ---------- */\n'
       'const MQLS = [...STATIC_GATES, PORTRAIT_GATE].map(q => matchMedia(q));    // keep the lists referenced\n'
       'MQLS.forEach(m => m.addEventListener(\'change\', applyHeroMode));\n'
       'if (conn && conn.addEventListener) conn.addEventListener(\'change\', applyHeroMode);   // Data Saver switched mid-visit\n'
       'renderFly(performance.now(), true);\n'
       'applyHeroMode();\n'
       'ckStart();\n'
       'if (RM.matches) pinToFinalStates();\n'
       '})();\n</script>\n</body>\n</html>\n')
OUT.write_text(out, encoding='utf-8')
print('wrote', OUT, len(out.encode('utf-8')), 'bytes')

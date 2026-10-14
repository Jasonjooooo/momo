#!/usr/bin/env python3
"""Assemble the B-chart mockup from the CURRENT pilot-blog/index.html plus the chart parts.
Keeps byte-for-byte: head, global CSS, nav, hero CSS/markup/JS, diagnostics, shelf JSON, chat JS, subscribe JS."""
import io, os, re, sys

SRC = '/home/user/momo/pilot-blog/index.html'
OUT = '/home/user/momo/pilot-blog-work/mockups/B-chart/index.html'
PARTS = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'parts')

src = io.open(SRC, encoding='utf-8').read()
L = src.split('\n')

def idx(pred, start=0):
    for i in range(start, len(L)):
        if pred(L[i]):
            return i
    raise SystemExit('marker not found')

# CSS: keep everything up to the glide-slope dividers block (shared section utilities included)
css_cut = idx(lambda s: s.startswith('/* Glide-slope dividers */'))
diag_css = idx(lambda s: s.startswith('/* hero diagnostics panel'))
style_end = idx(lambda s: s.strip() == '</style>')
# HTML: keep </style> .. end of the hero header
hero_end = idx(lambda s: s.strip() == '</header>', style_end)
shelf_start = idx(lambda s: s.startswith('<!-- AI shelf data'))
shelf_end = idx(lambda s: s.strip() == '</script>', shelf_start)
# JS: keep the script head through the hero, then chat + subscribe, then diagnostics
js_start = idx(lambda s: s.strip() == '<script>', shelf_end)
hold_start = idx(lambda s: s.startswith('/* ============ 02: hold to fly the glide slope'), js_start)
chat_start = idx(lambda s: s.startswith('/* ============ 04: AI assistant'), hold_start)
diag_start = idx(lambda s: s.startswith('/* ---------- hero diagnostics panel'), chat_start)
start_blk = idx(lambda s: s.startswith('/* ---------- start ----------'), diag_start)

css = io.open(os.path.join(PARTS, 'chart.css'), encoding='utf-8').read()
html = io.open(os.path.join(PARTS, 'chart.html'), encoding='utf-8').read()
js = io.open(os.path.join(PARTS, 'chart.js'), encoding='utf-8').read()

start_js = '''/* ---------- start ---------- */
const MQLS = [...STATIC_GATES, PORTRAIT_GATE].map(q => matchMedia(q));    // keep the lists referenced
MQLS.forEach(m => m.addEventListener('change', applyHeroMode));
if (conn && conn.addEventListener) conn.addEventListener('change', applyHeroMode);   // Data Saver switched mid-visit
measurePlate();
renderFly(performance.now(), true);
applyHeroMode();
chartBuild();
if (RM.matches) pinToFinalStates();
})();
</script>
</body>
</html>
'''

out = []
out += L[:css_cut]
out += [css.rstrip('\n'), '']
out += L[diag_css:diag_css + 2]
out += ['']
out += L[style_end:hero_end + 1]
out += [html.rstrip('\n'), '']
out += L[shelf_start:shelf_end + 1]
out += ['']
out += L[js_start:hold_start]
out += [js.rstrip('\n'), '']
out += L[chat_start:diag_start]
out += L[diag_start:start_blk]
out += [start_js]
text = '\n'.join(out)
io.open(OUT, 'w', encoding='utf-8').write(text)
print('wrote', OUT, len(text), 'bytes')
# copy gate
bad = [w for w in ['—', 'leverage', 'seamless', 'empower', 'unlock', 'robust', 'actionable', 'data-driven', 'solutions', '赋能', '无缝', '一站式', '解决方案', '助力', '打造', '颠覆', '神器', '极致'] if w.lower() in text.lower()]
print('copy gate hits:', bad or 'none')
ext = re.findall(r'(?:src|href)=["\'](https?:)?//', text)
print('external refs in src/href:', len(ext))

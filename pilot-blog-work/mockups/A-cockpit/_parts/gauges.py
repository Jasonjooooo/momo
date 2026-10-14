# Instrument drawings for the cockpit pages, generated once at build time (static SVG in the page, no runtime cost).
# Every value drawn is a real value from the page copy: 1200 SHP, five blades, 758 M takeoff, 661 M landing, VS0 67 KIAS.
import math, re, pathlib

_SRC = pathlib.Path('/home/user/momo/pilot-blog/index.html').read_text(encoding='utf-8')
PARTS = {}


def f(v):
    return ('%.1f' % v).rstrip('0').rstrip('.')


# ---------- SHP dial (ENG): 0-1400 SHP over 240 degrees, needle sweeps to 1200 when the page is first painted ----------
def shp_dial():
    cx, cy, r = 100, 90, 64
    ang = lambda v: math.radians(-120 + 240 * v / 1400)
    pt = lambda v, rr: (cx + rr * math.sin(ang(v)), cy - rr * math.cos(ang(v)))
    s = ['<svg class="inst dial" viewBox="0 0 200 150" focusable="false">']
    a0, a1 = pt(0, r), pt(1400, r)
    s.append('<path class="g-arc" d="M%s,%s A%d,%d 0 1,1 %s,%s"/>' % (f(a0[0]), f(a0[1]), r, r, f(a1[0]), f(a1[1])))
    ticks = []
    for v in range(0, 1401, 100):
        major = v % 200 == 0
        p0, p1 = pt(v, r), pt(v, r - (11 if major else 6))
        ticks.append('M%s,%s L%s,%s' % (f(p0[0]), f(p0[1]), f(p1[0]), f(p1[1])))
    s.append('<path class="g-tick" d="%s"/>' % ' '.join(ticks))
    for v in (0, 400, 800, 1200):
        p = pt(v, r - 22)
        s.append('<text class="g-num" x="%s" y="%s">%d</text>' % (f(p[0]), f(p[1] + 4), v // 100))
    # takeoff-power bug, cyan, outside the scale
    b0, b1, b2 = pt(1200, r + 3), pt(1170, r + 12), pt(1230, r + 12)
    s.append('<path class="g-bug" d="M%s,%s L%s,%s L%s,%s Z"/>' % (f(b0[0]), f(b0[1]), f(b1[0]), f(b1[1]), f(b2[0]), f(b2[1])))
    s.append('<g class="needle" style="--a:%sdeg"><path d="M%d,%d L%d,%d"/><circle cx="%d" cy="%d" r="5"/></g>' % (f(-120 + 240 * 1200 / 1400), cx, cy + 8, cx, cy - r + 9, cx, cy))
    s.append('<rect class="g-box" x="66" y="104" width="68" height="26" rx="2"/><text class="g-ro" x="100" y="123">1200</text>')
    s.append('<text class="g-unit" x="100" y="145">SHP</text>')
    s.append('</svg>')
    return ''.join(s)


# ---------- five-blade propeller, front view (PROP); the disc turns slowly while the page is on screen ----------
def prop():
    blade = 'M-5,-12 L5,-12 L3,-52 Q0,-57 -3,-52 Z'
    s = ['<svg class="inst prop" viewBox="0 0 200 150" focusable="false">',
         '<circle class="p-disc" cx="100" cy="70" r="58"/>',
         '<g transform="translate(100 70)"><g class="prop-rot">']
    for i in range(5):
        s.append('<path class="p-blade" d="%s" transform="rotate(%d)"/>' % (blade, i * 72))
    s.append('</g><circle class="p-hub" r="11"/></g>')
    s.append('<text class="g-unit" x="100" y="145">5 BLD · REV</text></svg>')
    return ''.join(s)


# ---------- T-tail, side view (TAIL): the stabilizer sits on top of the fin, clear of the wing downwash ----------
def ttail():
    return ('<svg class="inst ttail" viewBox="0 0 200 150" focusable="false">'
            '<path class="t-flow" d="M6,74 C60,80 110,104 186,126"/><path class="t-flow" d="M6,94 C70,100 120,118 186,136"/>'
            '<path class="t-body" d="M6,106 L120,98 L182,92 L184,100 L122,116 L6,128"/>'
            '<path class="t-body" d="M126,99 L146,34 L168,34 L176,95"/>'
            '<path class="t-stab" d="M134,32 H194"/>'
            '<text class="g-unit" x="100" y="145">T-TAIL</text></svg>')


# ---------- distance tapes (TO / LDG): 0-1000 M, the bar fills to the value and the readout box rides up with it ----------
def tape(val, extra=''):
    y0, k = 136, 0.12
    s = ['<svg class="inst tape2" viewBox="0 0 200 150" focusable="false" style="--v:%s">' % f(val / 1000)]
    ticks = []
    for m in range(0, 1001, 100):
        y = y0 - m * k + .5
        ticks.append('M%d,%s H%d' % (78, f(y), 86 if m % 200 == 0 else 82))
    s.append('<path class="g-tick" d="%s"/>' % ' '.join(ticks))
    s.append('<path class="g-arc" d="M78.5,%s V%s"/>' % (f(y0 - 1000 * k), f(y0 + .5)))
    for m in range(0, 1001, 200):
        s.append('<text class="g-num l" x="72" y="%s">%d</text>' % (f(y0 - m * k + 4), m))
    s.append('<rect class="t2-frame" x="92.5" y="%s" width="14" height="%s"/>' % (f(y0 - 1000 * k + .5), f(1000 * k)))
    s.append('<rect class="t2-fill" x="93" y="%s" width="13" height="%s"/>' % (f(y0 - 1000 * k + 1), f(1000 * k - 1)))
    s.append('<g class="t2-ro" style="--py:%spx"><g transform="translate(0 %d)">'
             '<path class="t2-ptr" d="M110,0 L118,-7 V-11 H170 V11 H118 V7 Z"/><text class="g-ro s" x="144" y="5">%d</text></g></g>' % (f(-val * k), y0, val))
    s.append('<text class="g-unit" x="100" y="149">M</text>%s</svg>' % extra)
    return ''.join(s)


# ---------- unpaved strip (RWY): gravel texture, edge markers ----------
def unpaved():
    s = ['<svg class="inst unpaved" viewBox="0 0 200 150" focusable="false">',
         '<defs><pattern id="gravel" width="7" height="7" patternUnits="userSpaceOnUse"><circle cx="2" cy="2" r=".9"/><circle cx="5.5" cy="5" r=".7"/></pattern></defs>',
         '<path class="u-strip" d="M58,132 L142,132 L116,22 L84,22 Z"/>',
         '<path class="u-edge" d="M58,132 L84,22 M142,132 L116,22"/>']
    for t in (0, .22, .42, .6, .76, .9):
        yl = 132 - t * 110
        xl = 58 + t * 26
        xr = 142 - t * 26
        w = 4 - t * 2.6
        s.append('<rect class="u-mk" x="%s" y="%s" width="%s" height="%s"/>' % (f(xl - w - 3), f(yl - w / 2), f(w), f(w)))
        s.append('<rect class="u-mk" x="%s" y="%s" width="%s" height="%s"/>' % (f(xr + 3), f(yl - w / 2), f(w), f(w)))
    s.append('<path class="g-arc" d="M8,132 H192"/><text class="g-unit" x="100" y="148">GRVL · GRASS</text></svg>')
    return ''.join(s)


def map_ticks():
    out = []
    for a in range(0, 360, 30):
        r = math.radians(a)
        x0, y0 = 70 + 54 * math.sin(r), 70 - 54 * math.cos(r)
        x1, y1 = 70 + 48 * math.sin(r), 70 - 48 * math.cos(r)
        out.append('M%s,%s L%s,%s' % (f(x0), f(y0), f(x1), f(y1)))
    return ' '.join(out)


PARTS['MAP_TICKS'] = map_ticks()
PARTS['G_SHP'] = shp_dial()
PARTS['G_PROP'] = prop()
PARTS['G_TAIL'] = ttail()
PARTS['G_TO'] = tape(758)
PARTS['G_LDG'] = tape(661, '<text class="g-unit vs0" x="196" y="22">VS0 67 KT</text>')
PARTS['G_RWY'] = unpaved()

# ---------- the aircraft outline with its four callouts, reused verbatim from the live page ----------
m = re.search(r'<figure class="outline">.*?</figure>', _SRC, re.S)
PARTS['OUTLINE'] = m.group(0)

# Portrait (3:4) scrub encode for phones: a moving crop window that follows the aircraft,
# then eases over to the runway lights and ends there.
import subprocess, sys
import numpy as np

SRC, OUT = sys.argv[1], sys.argv[2]
CRF = sys.argv[3] if len(sys.argv) > 3 else '23'
W, H, FPS = 1920, 1088, 24
CW = 816                                  # 3:4 window at full height
# After breakout the window eases left once and stays: approach lights and green threshold left of centre, the
# nose, propeller, wing and gear on the right. (The first cut swung to the lights and back to the aircraft,
# 0.85-0.985: on a phone the aircraft left the frame for two seconds and jumped back in; the owner found it jarring.)
KEYS = [(0.00, 0.265), (0.17, 0.40), (0.34, 0.55), (0.68, 0.62), (0.82, 0.65), (0.91, 0.39), (1.00, 0.38)]

def pchip(keys):
    X = [k[0] for k in keys]; Y = [k[1] for k in keys]; n = len(X)
    h = [X[i+1]-X[i] for i in range(n-1)]; d = [(Y[i+1]-Y[i])/h[i] for i in range(n-1)]
    m = [0.0]*n; m[0] = d[0]; m[-1] = d[-1]
    for i in range(1, n-1):
        if d[i-1]*d[i] <= 0: m[i] = 0.0
        else:
            w1, w2 = 2*h[i]+h[i-1], h[i]+2*h[i-1]
            m[i] = (w1+w2)/(w1/d[i-1]+w2/d[i])
    def f(s):
        i = 0
        while i < n-2 and s > X[i+1]: i += 1
        t = (s-X[i])/h[i]; t2 = t*t; t3 = t2*t
        return (2*t3-3*t2+1)*Y[i] + (t3-2*t2+t)*h[i]*m[i] + (-2*t3+3*t2)*Y[i+1] + (t3-t2)*h[i]*m[i+1]
    return f

cx = pchip(KEYS)
dec = subprocess.Popen(['ffmpeg', '-v', 'error', '-i', SRC, '-f', 'rawvideo', '-pix_fmt', 'rgb24', '-'], stdout=subprocess.PIPE)
enc = subprocess.Popen(['ffmpeg', '-v', 'error', '-y', '-f', 'rawvideo', '-pix_fmt', 'rgb24', '-s', f'{CW}x{H}', '-r', str(FPS), '-i', '-',
                        '-vf', 'deblock=filter=strong:block=8,gradfun=1.5:16',
                        '-c:v', 'libx264', '-crf', CRF, '-preset', 'slow', '-x264-params', 'aq-mode=3',
                        '-g', '4', '-keyint_min', '4', '-pix_fmt', 'yuv420p', '-movflags', '+faststart', '-an', OUT], stdin=subprocess.PIPE)
frame_bytes = W*H*3
frames = []
while True:
    buf = dec.stdout.read(frame_bytes)
    if len(buf) < frame_bytes: break
    frames.append(buf)
n = len(frames)
xs = []
for i, buf in enumerate(frames):
    p = i/(n-1)
    x = int(round(cx(p)*W - CW/2)); x = max(0, min(W-CW, x)); xs.append(x)
    img = np.frombuffer(buf, np.uint8).reshape(H, W, 3)
    enc.stdin.write(np.ascontiguousarray(img[:, x:x+CW]).tobytes())
enc.stdin.close(); enc.wait(); dec.wait()
print('frames', n, 'x path', xs[0], xs[n//4], xs[n//2], xs[3*n//4], xs[-1])

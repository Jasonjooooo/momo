#!/usr/bin/env python3
"""把 skill/credit-card-manager 打包成 dist/credit-card-manager.skill(zip 格式)。先确认 core 已同步。"""
import subprocess, sys, zipfile
from pathlib import Path

root = Path(__file__).resolve().parent.parent
src = root / "skill" / "credit-card-manager"
if subprocess.run(["node", str(root / "tools" / "sync-core.js"), "--check"]).returncode != 0:
    sys.exit("core 与 Skill 副本不一致,请先运行 node tools/sync-core.js")
out = root / "dist"; out.mkdir(exist_ok=True)
target = out / "credit-card-manager.skill"
with zipfile.ZipFile(target, "w", zipfile.ZIP_DEFLATED) as z:
    for f in sorted(src.rglob("*")):
        if f.is_file() and "__pycache__" not in f.parts:
            z.write(f, Path("credit-card-manager") / f.relative_to(src))
print("已生成", target, f"({target.stat().st_size} 字节)")

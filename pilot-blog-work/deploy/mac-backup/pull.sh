#!/bin/bash
# Mac side: every day (launchd), fetch the server's backups that are not on this Mac yet.
# Backups land in ~/hxpilot-backups; copies older than 60 days are removed here (the server keeps 7).
# A failure shows a macOS notification and is written to ~/hxpilot-backups/pull.log.
set -u
HOST=45.152.64.62
PORT=49271
KEY="$HOME/.ssh/hxpilot_backup"
DEST="$HOME/hxpilot-backups"
LOG="$DEST/pull.log"
SSH=(ssh -p "$PORT" -i "$KEY" -o BatchMode=yes -o ConnectTimeout=20 -o ServerAliveInterval=30 -o StrictHostKeyChecking=accept-new "root@$HOST")

mkdir -p "$DEST"
log() { echo "$(date '+%Y-%m-%d %H:%M:%S') $*" >> "$LOG"; }
fail() { log "失败：$*"; osascript -e "display notification \"$*\" with title \"破雾追光 备份没下载成功\"" >/dev/null 2>&1; exit 1; }

list=$("${SSH[@]}" list 2>&1) || fail "连不上服务器（${list}）"
got=0
while read -r name size; do
  [ -n "$name" ] || continue
  [[ "$name" =~ ^[A-Za-z0-9._-]+$ ]] || continue
  if [ -f "$DEST/$name" ] && [ "$(stat -f %z "$DEST/$name")" = "$size" ]; then continue; fi
  "${SSH[@]}" get "$name" > "$DEST/.$name.part" 2>>"$LOG" || { rm -f "$DEST/.$name.part"; fail "下载 $name 出错"; }
  [ "$(stat -f %z "$DEST/.$name.part")" = "$size" ] || { rm -f "$DEST/.$name.part"; fail "$name 大小不对，可能没下完"; }
  mv "$DEST/.$name.part" "$DEST/$name"
  got=$((got + 1))
done <<< "$list"
find "$DEST" -maxdepth 1 -type f ! -name 'pull.log' ! -name '.*' -mtime +60 -delete
[ -n "$list" ] || fail "服务器上没有找到备份文件"
log "完成：新下载 $got 个，Mac 上共 $(find "$DEST" -maxdepth 1 -type f ! -name 'pull.log' ! -name '.*' | wc -l | tr -d ' ') 个"

#!/bin/bash
# Run once on the Mac (Terminal): installs pull.sh and a launchd job that runs it every day at 21:00
# (or right after the Mac wakes, if it was asleep then). Needs the key from step 1 already on the server.
set -e
HERE="$(cd "$(dirname "$0")" && pwd)"
APP="$HOME/Library/Application Support/hxpilot-backup"
PLIST="$HOME/Library/LaunchAgents/com.hxpilot.backup.plist"
mkdir -p "$APP" "$HOME/Library/LaunchAgents" "$HOME/hxpilot-backups"
cp "$HERE/pull.sh" "$APP/pull.sh"
chmod 755 "$APP/pull.sh"
cat > "$PLIST" <<PL
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
  <key>Label</key><string>com.hxpilot.backup</string>
  <key>ProgramArguments</key><array><string>/bin/bash</string><string>$APP/pull.sh</string></array>
  <key>StartCalendarInterval</key><dict><key>Hour</key><integer>21</integer><key>Minute</key><integer>7</integer></dict>
  <key>StandardErrorPath</key><string>$HOME/hxpilot-backups/launchd.err</string>
</dict>
</plist>
PL
launchctl bootout "gui/$(id -u)" "$PLIST" 2>/dev/null || true
launchctl bootstrap "gui/$(id -u)" "$PLIST"
echo "已安装。现在先手动跑一次："
/bin/bash "$APP/pull.sh" && tail -1 "$HOME/hxpilot-backups/pull.log"

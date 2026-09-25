@echo off
rem Starts the strategy-ranking server and its own public Cloudflare Quick Tunnel.
rem Separate from the "Most Inspiring Leader" vote (that one uses port 3080).
rem Keep BOTH windows open for the whole session - closing either stops voting.
cd /d "%~dp0"

start "WKC Strategy Ranking - server (keep open)" cmd /k node server.js
timeout /t 2 /nobreak >nul

set CF=cloudflared
if exist "C:\Program Files (x86)\cloudflared\cloudflared.exe" set CF="C:\Program Files (x86)\cloudflared\cloudflared.exe"
if exist "%USERPROFILE%\cloudflared\cloudflared.exe" set CF="%USERPROFILE%\cloudflared\cloudflared.exe"

echo.
echo  Look for the line ending in  .trycloudflare.com  below - that is the public link.
echo.
%CF% tunnel --url http://localhost:3081

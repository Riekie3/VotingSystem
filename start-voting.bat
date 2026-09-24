@echo off
rem Starts the voting server and a public Cloudflare Quick Tunnel.
rem Keep BOTH windows open for the whole retreat — closing either stops voting.
cd /d "%~dp0"

start "WKC Voting - server (keep open)" cmd /k node server.js
timeout /t 2 /nobreak >nul

set CF=cloudflared
if exist "C:\Program Files (x86)\cloudflared\cloudflared.exe" set CF="C:\Program Files (x86)\cloudflared\cloudflared.exe"
if exist "%USERPROFILE%\cloudflared\cloudflared.exe" set CF="%USERPROFILE%\cloudflared\cloudflared.exe"

echo.
echo  Look for the line ending in  .trycloudflare.com  below - that is the public voting link.
echo.
%CF% tunnel --url http://localhost:3080

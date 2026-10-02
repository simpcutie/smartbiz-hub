@echo off
cd /d "%~dp0"
set "SMARTBIZ_PHP=C:\xampp\php\php.exe"
if not exist "%SMARTBIZ_PHP%" set "SMARTBIZ_PHP=php"
echo SmartBiz Hub - One Optics Clinic prototype
echo Open http://127.0.0.1:8099 in your browser.
echo Keep this window open. Press Ctrl+C to stop.
"%SMARTBIZ_PHP%" artisan serve --host=127.0.0.1 --port=8099
pause
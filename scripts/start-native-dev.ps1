# Starts the full native Fleetbase dev stack in separate windows:
#   1. Laravel API dev server  (http://localhost:8000)
#   2. Queue worker
#   3. Scheduler
#   4. Ember console dev server (http://localhost:4200, live reload)
#
# Infrastructure stays in Docker - start it first if not running:
#   docker compose up -d database cache socket
#
# Requires php/composer shims on PATH (C:\schedjuice\tools\bin) and the
# winget PHP 8.2 install with the ini from tools\setup-php-ini.ps1.
#
# Stop: close the spawned windows (or Ctrl+C inside each).

$repo = Split-Path -Parent $PSScriptRoot
$php = 'C:\schedjuice\tools\bin\php.bat'
$api = Join-Path $repo 'api'
$console = Join-Path $repo 'console'

Start-Process -WorkingDirectory $api -FilePath $php -ArgumentList 'artisan', 'serve', '--host=0.0.0.0', '--port=8000'
Write-Host 'API:        http://localhost:8000'

Start-Process -WorkingDirectory $api -FilePath $php -ArgumentList 'artisan', 'queue:work'
Write-Host 'Queue:      php artisan queue:work'

Start-Process -WorkingDirectory $api -FilePath $php -ArgumentList 'artisan', 'schedule:work'
Write-Host 'Scheduler:  php artisan schedule:work'

Start-Process -WorkingDirectory $console -FilePath 'pnpm' -ArgumentList 'start:dev'
Write-Host 'Console:    http://localhost:4200'

Write-Host ''
Write-Host 'All four started in separate windows. Database/cache/socket run in Docker:'
Write-Host '  docker compose up -d database cache socket'

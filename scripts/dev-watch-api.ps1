# Watches api/ source on the host and hot-reloads the Octane workers inside
# the application container.
#
# FrankenPHP's native watcher cannot see edits made on Windows bind mounts
# (inotify does not cross the Docker Desktop boundary), so this script polls
# the host filesystem and runs `php artisan octane:reload` after edits settle.
#
# Usage:  powershell -ExecutionPolicy Bypass -File scripts\dev-watch-api.ps1
# Stop:   Ctrl+C
#
# Reload reloads PHP code only. Full container restart (`docker compose restart
# application`) is still needed for: .env changes, composer.json changes, and
# queue/scheduler workers.

$ErrorActionPreference = 'Stop'

$repo = Split-Path -Parent $PSScriptRoot
$api = Join-Path $repo 'api'
$container = 'fleetbase-application-1'

# Directories whose changes warrant a reload (relative to api/). storage/ and
# vendor/ are deliberately excluded - the running app writes to them itself.
$watched = @('app', 'bootstrap', 'config', 'database', 'public', 'resources', 'routes')
$pattern = '^(' + ($watched -join '|') + ')[\\/].*\.php$'

$fsw = [System.IO.FileSystemWatcher]::new($api, '*.php')
$fsw.IncludeSubdirectories = $true
$fsw.InternalBufferSize = 32KB

Write-Host "Watching $api for PHP edits -> octane:reload on $container"
Write-Host "Watched: $($watched -join ', ')   (Ctrl+C to stop)"

while ($true) {
    $change = $fsw.WaitForChanged([System.IO.WatcherChangeTypes]::All, 2000)
    if ($change.TimedOut) { continue }
    if ($change.Name -notmatch $pattern) { continue }

    # Debounce: editors fire many events per save; wait until quiet.
    do {
        $change = $fsw.WaitForChanged([System.IO.WatcherChangeTypes]::All, 600)
        if (-not $change.TimedOut) { $debounced = $true }
        else { $debounced = $false }
    } while ($debounced)

    Write-Host ("[{0}] change in {1} - reloading workers" -f (Get-Date -Format HH:mm:ss), $change.Name)
    & docker exec $container php artisan octane:reload
}

# Watches console/ source on the host and triggers the Ember rebuild inside
# the console dev container.
#
# The container runs `ember serve` with the events watcher, which cannot see
# edits made on Windows bind mounts (inotify does not cross the Docker Desktop
# boundary). This script polls the host filesystem and touches a watched file
# inside the container after edits settle, which fires the rebuild and pushes
# a live reload to the browser.
#
# Usage:  powershell -ExecutionPolicy Bypass -File scripts\dev-watch-console.ps1
# Stop:   Ctrl+C

$ErrorActionPreference = 'Stop'

$repo = Split-Path -Parent $PSScriptRoot
$console = Join-Path $repo 'console'
$container = 'fleetbase-console-1'

# Source dirs (relative to console/) whose changes trigger a rebuild.
$watched = @('app', 'config', 'public', 'lib', 'translations', 'ember-intl')
$pattern = '^(' + ($watched -join '|') + ')[\\/]'
$rootFiles = @('ember-cli-build.js', 'router.map.js', 'package.json')

$fsw = [System.IO.FileSystemWatcher]::new($console, '*')
$fsw.IncludeSubdirectories = $true
$fsw.InternalBufferSize = 32KB

Write-Host "Watching $console for edits -> touch trigger in $container"
Write-Host "Watched: $($watched -join ', ') + $($rootFiles -join ', ')   (Ctrl+C to stop)"

while ($true) {
    $change = $fsw.WaitForChanged([System.IO.WatcherChangeTypes]::All, 2000)
    if ($change.TimedOut) { continue }

    $name = $change.Name -replace '/', '\'
    $isRootFile = $rootFiles -contains $name
    if (-not $isRootFile -and $name -notmatch $pattern) { continue }

    # Debounce: editors fire many events per save; wait until quiet.
    do {
        $change = $fsw.WaitForChanged([System.IO.WatcherChangeTypes]::All, 800)
        if (-not $change.TimedOut) { $debounced = $true }
        else { $debounced = $false }
    } while ($debounced)

    Write-Host ("[{0}] change in {1} - triggering rebuild" -f (Get-Date -Format HH:mm:ss), $name)
    # Inotify inside the container only fires for container-local paths (the
    # bind mount swallows events in both directions), so touch a file on the
    # node_modules volume - it is part of a watched addon tree, which makes the
    # events watcher rebuild and push a live reload to the browser.
    & docker exec $container sh -c "touch /console/node_modules/@fleetbase/ember-ui/index.js"
}

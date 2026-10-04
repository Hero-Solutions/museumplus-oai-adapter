#!/usr/bin/env bash
set -euo pipefail

cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.."

# Keep this lock file: removing it could allow overlapping imports.
# Open the log only after acquiring the lock, so a skipped run leaves it intact.
exec flock --nonblock --conflict-exit-code 0 "$HOME/.museumplus-import.lock" \
    sh -c 'exec php bin/console app:import-museumplus-records --resume --no-interaction > "$HOME/museumplus-import.log" 2>&1'

#!/bin/sh
set -e

# Disable any MPM modules that might be enabled
a2dismod mpm_event mpm_worker mpm_prefork >/dev/null 2>&1 || true

# Remove any leftover symlinks under mods-enabled (defensive)
rm -f /etc/apache2/mods-enabled/mpm_*.load /etc/apache2/mods-enabled/mpm_*.conf || true

# Enable prefork MPM
a2enmod mpm_prefork >/dev/null 2>&1 || true

# Ensure rewrite is enabled
a2enmod rewrite >/dev/null 2>&1 || true

# (Optional) show enabled modules for debugging
echo "Enabled Apache modules:"
apache2ctl -M || true

# Exec the container CMD (e.g., apache2-foreground)
exec "$@"

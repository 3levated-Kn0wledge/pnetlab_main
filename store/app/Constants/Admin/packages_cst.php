<?php
/**
 * The fork's package mechanism. See docs/PACKAGES.md.
 *
 * PACKAGE_CENTER is where the box looks for packages by device id. It is
 * deliberately EMPTY by default and read from the environment rather than
 * hardcoded: the upstream marketplace at APP_CENTER serves shell scripts, not
 * signed packages, so pointing this at pnetlab.com would not work and
 * pretending otherwise would be the dishonest kind of default. The owner sets
 * PNET_PACKAGE_CENTER to their own repository once they have one.
 */
defined('PACKAGE_CENTER') || define('PACKAGE_CENTER', (string) getenv('PNET_PACKAGE_CENTER'));

/** Root of the package state the applier owns. Root-writable, world-readable. */
defined('PACKAGE_STATE_DIR') || define('PACKAGE_STATE_DIR', '/opt/unetlab/data/packages');

/** Where the web layer stages a download for root to read. Owned by www-data. */
defined('PACKAGE_INCOMING_DIR') || define('PACKAGE_INCOMING_DIR', '/opt/unetlab/data/packages/incoming');

/** Per-install logs, polled by the admin UI through /admin/devices/process. */
defined('PACKAGE_LOG_DIR') || define('PACKAGE_LOG_DIR', '/opt/unetlab/data/Logs/packages');

/** The one privileged entry point the web layer is allowed to use. */
defined('PACKAGE_WRAPPER') || define('PACKAGE_WRAPPER', '/opt/unetlab/wrappers/unl_wrapper');

/** Fields a package-aware marketplace adds to a device record. Absent upstream. */
defined('DEVICE_PACKAGE') || define('DEVICE_PACKAGE', 'device_package');
defined('DEVICE_PACKAGE_SHA256') || define('DEVICE_PACKAGE_SHA256', 'device_package_sha256');

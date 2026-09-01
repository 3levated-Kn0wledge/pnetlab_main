<?php
defined('VERSIONS_TABLE') || define('VERSIONS_TABLE', 'versions');
defined('VERSION_ID') || define('VERSION_ID', 'version_id');
defined('VERSION_NAME') || define('VERSION_NAME', 'version_name');
defined('VERSION_STATUS') || define('VERSION_STATUS', 'version_status');
defined('VERSION_STATUS_ADMIN') || define('VERSION_STATUS_ADMIN', 'version_status_admin');
defined('VERSION_LABID') || define('VERSION_LABID', 'version_labId');
defined('VERSION_UNL') || define('VERSION_UNL', 'version_unl');
defined('VERSION_PATH') || define('VERSION_PATH', 'version_path');
defined('VERSION_MD5') || define('VERSION_MD5', 'version_md5');
defined('VERSION_TIME') || define('VERSION_TIME', 'version_time');
defined('VERSION_NOTE') || define('VERSION_NOTE', 'version_note');

defined('VERSION_STATUS_PUBLIC') || define('VERSION_STATUS_PUBLIC', '1');
defined('VERSION_STATUS_UNPUBLIC') || define('VERSION_STATUS_UNPUBLIC', '0');

defined('VERSION_STATUS_ADMIN_EMPTY') || define('VERSION_STATUS_ADMIN_EMPTY', '0');
defined('VERSION_STATUS_ADMIN_APPROVE') || define('VERSION_STATUS_ADMIN_APPROVE', '1');
defined('VERSION_STATUS_ADMIN_DENY') || define('VERSION_STATUS_ADMIN_DENY', '2');
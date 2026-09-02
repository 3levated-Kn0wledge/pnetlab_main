<?php 
defined('AUTHENTICATION_TABLE') || define('AUTHENTICATION_TABLE', 'authentication');
defined('AUTHEN_ID') || define('AUTHEN_ID', 'authen_id');
defined('AUTHEN_USERNAME') || define('AUTHEN_USERNAME', 'authen_username');
defined('AUTHEN_EMAIL') || define('AUTHEN_EMAIL', 'authen_email');
defined('AUTHEN_PHONE') || define('AUTHEN_PHONE', 'authen_phone');
defined('AUTHEN_PASS') || define('AUTHEN_PASS', 'authen_pass');
defined('AUTHEN_TOKEN') || define('AUTHEN_TOKEN', 'authen_token');
defined('AUTHEN_GROUP') || define('AUTHEN_GROUP', 'authen_group');
defined('AUTHEN_NOTE') || define('AUTHEN_NOTE', 'authen_note');
defined('AUTHEN_PARENT') || define('AUTHEN_PARENT', 'authen_parent');
defined('AUTHEN_TIME') || define('AUTHEN_TIME', 'authen_time');
defined('AUTHEN_ONLINE') || define('AUTHEN_ONLINE', 'authen_online');
defined('AUTHEN_ACTIVE') || define('AUTHEN_ACTIVE', 'authen_active');
defined('AUTHEN_IMG') || define('AUTHEN_IMG', 'authen_img');

defined('AUTHEN_GROUP_ROOT') || define('AUTHEN_GROUP_ROOT', 0);
defined('AUTHEN_GROUP_ADMIN') || define('AUTHEN_GROUP_ADMIN', 1);
defined('AUTHEN_GROUP_USER') || define('AUTHEN_GROUP_USER', 2);

$GROUPS = [
    0 => 'Root',
    1 => 'Administrator',
    2 => 'User',
];



?>
<?php

$databases['default']['default'] = [
  'database' => getenv('DB_NAME') ?: 'mahlzeit_cms',
  'username' => getenv('DB_USER') ?: 'mahlzeit_app',
  'password' => getenv('DB_PASSWORD') ?: '',
  'host' => getenv('DB_HOST') ?: 'db',
  'port' => getenv('DB_PORT') ?: '3306',
  'driver' => 'mysql',
  'namespace' => 'Drupal\\mysql\\Driver\\Database\\mysql',
  'autoload' => 'core/modules/mysql/src/Driver/Database/mysql/',
  'isolation_level' => 'READ COMMITTED',
];

$settings['hash_salt'] = getenv('DRUPAL_HASH_SALT') ?: 'replace-this-value';

$trusted_hosts = getenv('DRUPAL_TRUSTED_HOSTS') ?: '^localhost$';
$settings['trusted_host_patterns'] = array_values(array_filter(array_map(
  'trim',
  explode(',', $trusted_hosts)
)));

$settings['config_sync_directory'] = getenv('DRUPAL_CONFIG_SYNC_DIRECTORY')
  ?: '/var/www/html/config/sync';

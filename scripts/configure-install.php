#!/usr/bin/env php
<?php
/**
 * HAXcms CLI installer helper: template _config/config.php and the secret
 * files the way install.php does, without passing secrets through sed.
 *
 *   php scripts/configure-install.php --user <name> --pass <password>
 *
 * Called by scripts/haxtheweb.sh after it has copied the boilerplate into
 * _config/. Idempotent: every value is written only while its boilerplate
 * placeholder is still present, so re-running never clobbers a configured
 * install. Mirrors install.php (haxcmsInstallerRunInstallBlock):
 *   - superUser->password is stored as password_hash(), never plaintext
 *   - each assignment is replaced exactly once with a var_export()-escaped
 *     literal, so a username/password can never break out of the PHP
 *     string literal or rewrite unrelated text in config.php
 *   - privateKey / refreshPrivateKey / SALT.txt come from random_bytes
 *   - config.php, SALT.txt, .pk and .rpk are chmod 0600
 *
 * Exit code 0 on success, 1 on a usage or write error (message on STDERR).
 */
// Security: CLI only; never executable over HTTP against live config.
if (PHP_SAPI !== 'cli') {
  http_response_code(404);
  exit;
}

function haxcmsConfigureInstallUsage($message = '')
{
  if ($message !== '') {
    fwrite(STDERR, $message . PHP_EOL);
  }
  fwrite(STDERR, 'Usage: php scripts/configure-install.php --user <name> --pass <password> [--root <haxcms root>]' . PHP_EOL);
  exit(1);
}

function haxcmsConfigureInstallSecret()
{
  $parts = array();
  for ($i = 0; $i < 4; $i++) {
    $parts[] = bin2hex(random_bytes(16));
  }
  return implode('-', $parts);
}

/**
 * Same strict allowlist as install.php haxcmsInstallerSanitizeUsername.
 */
function haxcmsConfigureInstallSanitizeUsername($value)
{
  if (!is_string($value) || trim($value) === '') {
    return 'admin';
  }
  $clean = substr(preg_replace('/[^A-Za-z0-9_.-]/', '', trim($value)), 0, 64);
  return $clean !== '' ? $clean : 'admin';
}

/**
 * Replace ONE exact boilerplate assignment
 * (`$HAXCMS-><property> = '<placeholder>';`) with a var_export()-escaped
 * literal. Same contract as install.php haxcmsInstallerSetConfigValue.
 */
function haxcmsConfigureInstallSetValue($configFile, $property, $placeholder, $value)
{
  $pattern = '/(\$HAXCMS->' . preg_quote($property, '/') . '\s*=\s*)([\'"])' . preg_quote($placeholder, '/') . '\2(\s*;)/';
  $result = preg_replace_callback($pattern, function ($m) use ($value) {
    return $m[1] . var_export((string) $value, true) . $m[3];
  }, $configFile, 1);
  return is_string($result) ? $result : $configFile;
}

/**
 * Apply the install to $root/_config. Returns an array of human readable
 * problems (empty on success). Separated from the CLI wrapper so it can be
 * unit tested against a temp root.
 */
function haxcmsConfigureInstall($root, $user, $pass)
{
  $errors = array();
  $configDir = rtrim($root, '/') . '/_config';
  if (!is_dir($configDir)) {
    return array('Missing ' . $configDir . '; run the boilerplate copy first.');
  }
  $configPhpPath = $configDir . '/config.php';
  if (!is_file($configPhpPath)) {
    return array('Missing ' . $configPhpPath . '; copy system/boilerplate/systemsetup/config.php there first.');
  }
  // SALT — generated only if missing or still empty
  $saltPath = $configDir . '/SALT.txt';
  if (!is_file($saltPath) || trim((string) @file_get_contents($saltPath)) === '') {
    if (@file_put_contents($saltPath, haxcmsConfigureInstallSecret(), LOCK_EX) === false) {
      $errors[] = 'Unable to write ' . $saltPath;
    }
  }
  // config.php templating — each placeholder independently
  $configFile = (string) file_get_contents($configPhpPath);
  $updated = haxcmsConfigureInstallSetValue($configFile, 'privateKey', 'HAXTHEWEBPRIVATEKEY', haxcmsConfigureInstallSecret());
  $updated = haxcmsConfigureInstallSetValue($updated, 'refreshPrivateKey', 'HAXTHEWEBREFRESHPRIVATEKEY', haxcmsConfigureInstallSecret());
  $updated = haxcmsConfigureInstallSetValue($updated, 'superUser->name', 'jeff', haxcmsConfigureInstallSanitizeUsername($user));
  $updated = haxcmsConfigureInstallSetValue($updated, 'superUser->password', 'jimmerson', password_hash((string) $pass, PASSWORD_DEFAULT));
  if ($updated !== $configFile) {
    if (@file_put_contents($configPhpPath, $updated, LOCK_EX) === false) {
      $errors[] = 'Unable to write ' . $configPhpPath;
    }
  }
  // secrets are owner-only
  foreach (array($configPhpPath, $saltPath, $configDir . '/.pk', $configDir . '/.rpk') as $secretPath) {
    if (is_file($secretPath)) {
      @chmod($secretPath, 0600);
    }
  }
  return $errors;
}

if (realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
  $options = getopt('', array('user:', 'pass:', 'root:'));
  $user = isset($options['user']) && is_string($options['user']) ? $options['user'] : '';
  $pass = isset($options['pass']) && is_string($options['pass']) ? $options['pass'] : '';
  $root = isset($options['root']) && is_string($options['root']) && $options['root'] !== ''
    ? $options['root']
    : dirname(__DIR__);
  if ($user === '' || $pass === '') {
    haxcmsConfigureInstallUsage('Both --user and --pass are required.');
  }
  $errors = haxcmsConfigureInstall($root, $user, $pass);
  if (count($errors) > 0) {
    foreach ($errors as $error) {
      fwrite(STDERR, $error . PHP_EOL);
    }
    exit(1);
  }
  exit(0);
}

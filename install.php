<?php
// HAXcms installer — Drupal-style 4-step wizard backend.
//
// The back-end lives entirely in this file. The front-end is a web component
// (<hax-app-installer>) loaded by the thin bootstrap HTML page rendered when
// no ?op query param is present.
//
// Steps:
//   1. Choose language      — language select (~100 languages), front-end-driven
//   2. Verify requirements  — status checks grouped into needsConfiguration / allPassed
//   3. Configure system     — admin username + optional password
//   4. Start HAXcms          — credentials, community links, -> index.php
//
// State file: _config/tmp/.install-state.json
//   { "step": 1|2|3|4, "language": "<code>", "username": "<chosen>" }
//   Deleted on successful step 4.
//
// Endpoints:
//   GET  install.php?op=state    — read state, re-evaluate environment, return JSON
//   POST install.php?op=prepare  — auto-create missing runtime dirs + non-secret boilerplate (no credentials), return step-2 JSON
//   POST install.php?op=advance  — validate + persist state, run side effects, return JSON
//   GET  install.php             — thin HTML page loading <hax-app-installer>
//
// Hosting-provider automation (e.g. Reclaim Cloud / haxcms-jps) may POST
// toStep=4 with user + pass + install_token to skip the interactive wizard.
// The credential override is honored ONLY when a pre-dropped _installtoken.txt
// file matches the POSTed install_token (timing-safe hash_equals). Without
// the token file, POST-supplied credentials are ignored and the installer
// uses an auto-generated admin password.

$failed = false;
$failedMessages = array();
$resolvedUsername = 'admin';
$pass = '';
$passwordWasGenerated = false;

include_once __DIR__ . '/system/backend/php/lib/SystemStatusService.php';
include_once __DIR__ . '/system/backend/php/lib/LocalizationSettingsService.php';
include_once __DIR__ . '/system/backend/php/lib/Git.php';

// Ensure _config/tmp/ exists for the wizard state file. This is lightweight
// and non-destructive — it does NOT create config.php or any boilerplate, so
// the index.php half-configured guard still redirects to the installer.
@mkdir(__DIR__ . '/_config/tmp', 0755, true);

// Security best practice (I5): once HAXcms is already installed (the four
// core directories exist AND _config/config.php exists AND config.php has
// been templated with real secrets), the installer must never run setup
// logic again — it is an unauthenticated endpoint that creates credentials.
// Redirect to the dashboard and stop before any POST/file logic executes.
//
// The "not finished installing" flag: if config.php still contains boilerplate
// placeholder tokens (HAXTHEWEBPRIVATEKEY / HAXTHEWEBREFRESHPRIVATEKEY /
// jeff / jimmerson), step 4 has NOT run yet. We must keep serving the wizard
// so the user can enter credentials and complete the install. Redirecting
// away in this state returns HTML to the wizard's ?op=state JSON fetch and
// breaks the front-end (issue #2974).
$installerConfigPhpPath = __DIR__ . '/_config/config.php';
$installerConfigNotTemplated = false;
if (file_exists($installerConfigPhpPath)) {
  $installerConfigRaw = @file_get_contents($installerConfigPhpPath);
  if (is_string($installerConfigRaw) && (
    strpos($installerConfigRaw, 'HAXTHEWEBPRIVATEKEY') !== false ||
    strpos($installerConfigRaw, 'HAXTHEWEBREFRESHPRIVATEKEY') !== false ||
    strpos($installerConfigRaw, 'jeff') !== false ||
    strpos($installerConfigRaw, 'jimmerson') !== false
  )) {
    $installerConfigNotTemplated = true;
  }
}
if (
  is_dir(__DIR__ . '/_sites') &&
  is_dir(__DIR__ . '/_config') &&
  is_dir(__DIR__ . '/_published') &&
  is_dir(__DIR__ . '/_archived') &&
  file_exists($installerConfigPhpPath) &&
  !$installerConfigNotTemplated
) {
  // Absolute, root-relative redirect so a 404 on a static asset (rewritten
  // to this install.php by .htaccess) cannot turn into a redirect loop.
  $installBase = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
  header('Location: ' . ($installBase === '' ? '' : $installBase) . '/index.php');
  exit();
}

// --- version ---------------------------------------------------------------
$version = '';
$versionFiles = array(
  __DIR__ . '/.version',
  __DIR__ . '/VERSION.txt',
);
foreach ($versionFiles as $versionFile) {
  if (file_exists($versionFile)) {
    $version = filter_var(file_get_contents($versionFile));
    break;
  }
}

// --- helpers ---------------------------------------------------------------
if (!function_exists('haxcmsInstallerStatusEscape')) {
  function haxcmsInstallerStatusEscape($value)
  {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
  }
}
if (!function_exists('haxcmsInstallerPasswordMeetsPolicy')) {
  function haxcmsInstallerPasswordMeetsPolicy($password)
  {
    if (!is_string($password) || strlen($password) < 10) {
      return false;
    }
    if (!preg_match('/[a-zA-Z]/', $password) || !preg_match('/[0-9]/', $password)) {
      return false;
    }
    return true;
  }
}
if (!function_exists('haxcmsInstallerGeneratePassword')) {
  function haxcmsInstallerGeneratePassword()
  {
    $alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ123456789';
    $pass = array();
    $alphaLength = strlen($alphabet) - 1;
    for ($i = 0; $i < 14; $i++) {
      $n = random_int(0, $alphaLength);
      $pass[] = $alphabet[$n];
    }
    return implode($pass);
  }
}
if (!function_exists('haxcmsInstallerGenerateSecureSecret')) {
  function haxcmsInstallerGenerateSecureSecret()
  {
    $parts = array();
    for ($i = 0; $i < 4; $i++) {
      $parts[] = bin2hex(random_bytes(16));
    }
    return implode('-', $parts);
  }
}
if (!function_exists('haxcmsInstallerGuardedMkdir')) {
  function haxcmsInstallerGuardedMkdir($path, $permissions, &$failed, &$failedMessages)
  {
    if (!is_dir($path)) {
      if (!@mkdir($path, $permissions)) {
        $failed = true;
        $failedMessages[] = 'Unable to create directory: ' . $path;
        return false;
      }
    }
    return true;
  }
}
if (!function_exists('haxcmsInstallerGuardedGitCreate')) {
  function haxcmsInstallerGuardedGitCreate($path, &$failed, &$failedMessages)
  {
    try {
      $git = new Git();
      $git->create($path);
      return true;
    } catch (Exception $e) {
      $failed = true;
      $failedMessages[] = 'Unable to initialize git repository in ' . $path . ': ' . $e->getMessage();
      return false;
    }
  }
}

if (!function_exists('haxcmsInstallerGuardedCopy')) {
  function haxcmsInstallerGuardedCopy($src, $dst, &$failed, &$failedMessages)
  {
    if (!file_exists($dst)) {
      if (!@copy($src, $dst)) {
        $failed = true;
        $failedMessages[] = 'Unable to copy file: ' . $src . ' -> ' . $dst;
        return false;
      }
    }
    return true;
  }
}

if (!function_exists('haxcmsInstallerEnsureRuntimeDirectories')) {
  function haxcmsInstallerEnsureRuntimeDirectories(&$failed, &$failedMessages)
  {
    // Lightweight and idempotent: create the runtime directory tree AND
    // copy all boilerplate files the system needs to boot without
    // warnings. config.php is copied WITH placeholder tokens (templated
    // at step 4 with real keys + password hash); SALT.txt is generated
    // with a real random secret now so HAXCMS.php never loads an empty
    // salt. The installed-guard's "not finished installing" flag
    // (placeholder tokens in config.php) keeps the wizard active until
    // step 4 completes, and index.php redirects to the installer while
    // placeholders remain, so the system never runs with known keys.
    if (!is_dir(__DIR__ . '/_config')) {
      haxcmsInstallerGuardedMkdir(__DIR__ . '/_config', 0755, $failed, $failedMessages);
    }
    if (!$failed) {
      haxcmsInstallerGuardedMkdir(__DIR__ . '/_config/.ssh', 0755, $failed, $failedMessages);
      haxcmsInstallerGuardedMkdir(__DIR__ . '/_config/tmp', 0755, $failed, $failedMessages);
      haxcmsInstallerGuardedMkdir(__DIR__ . '/_config/cache', 0755, $failed, $failedMessages);
      haxcmsInstallerGuardedMkdir(__DIR__ . '/_config/settings', 0755, $failed, $failedMessages);
      haxcmsInstallerGuardedMkdir(__DIR__ . '/_config/user', 0755, $failed, $failedMessages);
      haxcmsInstallerGuardedMkdir(__DIR__ . '/_config/user/files', 0755, $failed, $failedMessages);
      haxcmsInstallerGuardedMkdir(__DIR__ . '/_config/node_modules', 0755, $failed, $failedMessages);

      // Boilerplate files — copied so _config is functional enough for
      // HAXCMS.php to load config.json / userData.json without warnings
      // (issue #2974). config.php and SALT.txt are included below.
      $bp = __DIR__ . '/system/boilerplate/systemsetup';
      haxcmsInstallerGuardedCopy($bp . '/config.json', __DIR__ . '/_config/config.json', $failed, $failedMessages);
      haxcmsInstallerGuardedCopy($bp . '/my-custom-elements.js', __DIR__ . '/_config/my-custom-elements.js', $failed, $failedMessages);
      haxcmsInstallerGuardedCopy($bp . '/userData.json', __DIR__ . '/_config/userData.json', $failed, $failedMessages);
      haxcmsInstallerGuardedCopy($bp . '/.htaccess', __DIR__ . '/_config/.htaccess', $failed, $failedMessages);
      haxcmsInstallerGuardedCopy($bp . '/.user-files-htaccess', __DIR__ . '/_config/user/files/.htaccess', $failed, $failedMessages);
      // config.php — copy boilerplate (with placeholder tokens; step 4
      // replaces them with real private keys + password hash). The
      // placeholder tokens ARE the "not finished installing" flag.
      haxcmsInstallerGuardedCopy($bp . '/config.php', __DIR__ . '/_config/config.php', $failed, $failedMessages);
      if (file_exists(__DIR__ . '/_config/config.php')) {
        @chmod(__DIR__ . '/_config/config.php', 0600);
      }
      // SALT.txt — generate a real secret now (not the empty boilerplate)
      // so HAXCMS.php never loads an empty salt. Step 4 skips generation
      // since the file already exists.
      if (!file_exists(__DIR__ . '/_config/SALT.txt')) {
        @file_put_contents(__DIR__ . '/_config/SALT.txt', haxcmsInstallerGenerateSecureSecret(), LOCK_EX);
        @chmod(__DIR__ . '/_config/SALT.txt', 0600);
      }
      if (!file_exists(__DIR__ . '/_config/.isHAXcmsConfig')) {
        @file_put_contents(__DIR__ . '/_config/.isHAXcmsConfig', '');
      }
      // readable modes on the copied files
      foreach (array('/_config/config.json', '/_config/userData.json', '/_config/my-custom-elements.js') as $rel) {
        if (file_exists(__DIR__ . $rel)) {
          @chmod(__DIR__ . $rel, 0644);
        }
      }
    }
    foreach (array('/_sites', '/_published', '/_archived') as $rel) {
      if (!is_dir(__DIR__ . $rel)) {
        if (haxcmsInstallerGuardedMkdir(__DIR__ . $rel, 0755, $failed, $failedMessages)) {
          @chmod(__DIR__ . $rel, 0755);
        }
      }
    }
  }
}

// --- state file helpers ----------------------------------------------------
$stateFilePath = __DIR__ . '/_config/tmp/.install-state.json';

if (!function_exists('haxcmsInstallerReadState')) {
  function haxcmsInstallerReadState()
  {
    global $stateFilePath;
    $defaults = array('step' => 1, 'language' => 'en', 'username' => 'admin');
    if (!file_exists($stateFilePath)) {
      return $defaults;
    }
    $raw = @file_get_contents($stateFilePath);
    if (!is_string($raw) || trim($raw) === '') {
      return $defaults;
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
      return $defaults;
    }
    $state = $defaults;
    if (isset($decoded['step']) && is_numeric($decoded['step'])) {
      $stepVal = (int) $decoded['step'];
      if ($stepVal >= 1 && $stepVal <= 4) {
        $state['step'] = $stepVal;
      }
    }
    if (isset($decoded['language']) && is_string($decoded['language'])) {
      $state['language'] = $decoded['language'];
    }
    if (isset($decoded['username']) && is_string($decoded['username'])) {
      $state['username'] = $decoded['username'];
    }
    return $state;
  }
}

if (!function_exists('haxcmsInstallerWriteState')) {
  function haxcmsInstallerWriteState($state)
  {
    global $stateFilePath;
    $payload = array(
      'step' => isset($state['step']) ? (int) $state['step'] : 1,
      'language' => isset($state['language']) ? $state['language'] : 'en',
      'username' => isset($state['username']) ? $state['username'] : 'admin',
    );
    file_put_contents($stateFilePath, json_encode($payload), LOCK_EX);
  }
}

if (!function_exists('haxcmsInstallerSanitizeLanguage')) {
  function haxcmsInstallerSanitizeLanguage($value)
  {
    if (!is_string($value)) {
      return 'en';
    }
    $clean = preg_replace('/[^a-zA-Z0-9_-]/', '', $value);
    if ($clean === '') {
      return 'en';
    }
    return $clean;
  }
}

if (!function_exists('haxcmsInstallerSanitizeUsername')) {
  function haxcmsInstallerSanitizeUsername($value)
  {
    if (!is_string($value) || trim($value) === '') {
      return 'admin';
    }
    $clean = filter_var(trim($value), FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    return $clean !== '' ? $clean : 'admin';
  }
}

// --- build state response for a given step ---------------------------------
if (!function_exists('haxcmsInstallerBuildStateResponse')) {
  function haxcmsInstallerBuildStateResponse($step, $state, $credentials = null, $statusReport = null)
  {
    $language = isset($state['language']) ? $state['language'] : 'en';
    $username = isset($state['username']) ? $state['username'] : 'admin';

    if ($step === 2) {
      $report = HAXCMSSystemStatusService::buildInstallerStatusReport(__DIR__);
      $needsConfiguration = array();
      $allPassed = array();
      $hasErrors = false;
      if (is_array($report) && isset($report['rows']) && is_array($report['rows'])) {
        foreach ($report['rows'] as $row) {
          if (!is_array($row)) {
            continue;
          }
          $tone = isset($row['tone']) ? $row['tone'] : 'info';
          $entry = array(
            'key' => isset($row['key']) ? $row['key'] : '',
            'tone' => $tone,
            'title' => isset($row['title']) ? $row['title'] : '',
            'value' => isset($row['value']) ? $row['value'] : '',
            'description' => isset($row['description']) ? $row['description'] : '',
            'suggestedCommand' => isset($row['suggestedCommand']) ? $row['suggestedCommand'] : '',
          );
          // Only blocking errors stay under "needs configuration". Non-
          // blocking warnings (e.g. security secrets pending before step 4,
          // owner mismatch, version check) fold into "passed checks" so the
          // user sees a clean Continue path once real errors are resolved.
          if ($tone === 'error') {
            $needsConfiguration[] = $entry;
            $hasErrors = true;
          } else if ($tone === 'warning' || $tone === 'ok') {
            $allPassed[] = $entry;
          }
        }
      }
      return array(
        'step' => 2,
        'language' => $language,
        'needsConfiguration' => $needsConfiguration,
        'allPassed' => $allPassed,
        'hasErrors' => $hasErrors,
      );
    }

    if ($step === 3) {
      return array(
        'step' => 3,
        'language' => $language,
        'username' => $username,
      );
    }

    if ($step === 4) {
      $response = array(
        'step' => 4,
        'language' => $language,
      );
      if ($credentials !== null && is_array($credentials)) {
        $response['credentials'] = array(
          'username' => isset($credentials['username']) ? $credentials['username'] : $username,
          'password' => isset($credentials['password']) ? $credentials['password'] : '',
          'passwordWasGenerated' => isset($credentials['passwordWasGenerated']) ? (bool) $credentials['passwordWasGenerated'] : false,
        );
      } else {
        $response['credentials'] = array(
          'username' => $username,
          'password' => '',
          'passwordWasGenerated' => false,
        );
      }
      if ($statusReport !== null) {
        $response['status'] = $statusReport;
      } else {
        $response['status'] = HAXCMSSystemStatusService::buildInstallerStatusReport(__DIR__);
      }
      return $response;
    }

    // step 1 (default)
    return array(
      'step' => 1,
      'language' => $language,
    );
  }
}

// --- install execution (config-bootstrap fix) ------------------------------
//
// Decoupled from directory existence: subdirectories, boilerplate files,
// SALT.txt, config.php templating, and localization are each applied
// idempotently (only if missing / only if placeholders still present).
if (!function_exists('haxcmsInstallerRunInstallBlock')) {
  function haxcmsInstallerRunInstallBlock(
    $selectedLanguage,
    $resolvedUsername,
    $pass,
    $passwordWasGenerated,
    &$failed,
    &$failedMessages
  ) {
    // --- _config directory (create if missing) ---
    if (!is_dir(__DIR__ . '/_config')) {
      haxcmsInstallerGuardedMkdir(__DIR__ . '/_config', 0755, $failed, $failedMessages);
    }

    if (!$failed) {
      // Subdirectories — created if missing (not only when _config is new)
      haxcmsInstallerGuardedMkdir(__DIR__ . '/_config/.ssh', 0755, $failed, $failedMessages);
      haxcmsInstallerGuardedMkdir(__DIR__ . '/_config/tmp', 0755, $failed, $failedMessages);
      haxcmsInstallerGuardedMkdir(__DIR__ . '/_config/cache', 0755, $failed, $failedMessages);
      haxcmsInstallerGuardedMkdir(__DIR__ . '/_config/settings', 0755, $failed, $failedMessages);
      haxcmsInstallerGuardedMkdir(__DIR__ . '/_config/user', 0755, $failed, $failedMessages);
      haxcmsInstallerGuardedMkdir(__DIR__ . '/_config/user/files', 0755, $failed, $failedMessages);
      haxcmsInstallerGuardedMkdir(__DIR__ . '/_config/node_modules', 0755, $failed, $failedMessages);

      // Boilerplate files — copied only if target does not exist
      if (!file_exists(__DIR__ . '/_config/config.json')) {
        copy(__DIR__ . '/system/boilerplate/systemsetup/config.json', __DIR__ . '/_config/config.json');
      }
      if (!file_exists(__DIR__ . '/_config/my-custom-elements.js')) {
        copy(__DIR__ . '/system/boilerplate/systemsetup/my-custom-elements.js', __DIR__ . '/_config/my-custom-elements.js');
      }
      if (!file_exists(__DIR__ . '/_config/userData.json')) {
        copy(__DIR__ . '/system/boilerplate/systemsetup/userData.json', __DIR__ . '/_config/userData.json');
      }
      if (!file_exists(__DIR__ . '/_config/config.php')) {
        copy(__DIR__ . '/system/boilerplate/systemsetup/config.php', __DIR__ . '/_config/config.php');
      }
      if (!file_exists(__DIR__ . '/_config/.htaccess')) {
        copy(__DIR__ . '/system/boilerplate/systemsetup/.htaccess', __DIR__ . '/_config/.htaccess');
      }
      if (!file_exists(__DIR__ . '/_config/user/files/.htaccess')) {
        copy(__DIR__ . '/system/boilerplate/systemsetup/.user-files-htaccess', __DIR__ . '/_config/user/files/.htaccess');
      }

      // Localization defaultLanguage — write only if config.json lacks a
      // localization block
      $configJsonPath = __DIR__ . '/_config/config.json';
      if (file_exists($configJsonPath)) {
        $configJsonRaw = @file_get_contents($configJsonPath);
        $configJson = json_decode($configJsonRaw, true);
        if (is_array($configJson)) {
          if (!isset($configJson['localization']) || !is_array($configJson['localization'])) {
            $configJson['localization'] = array();
            $configJson['localization']['defaultLanguage'] = $selectedLanguage;
            file_put_contents($configJsonPath, json_encode($configJson, JSON_PRETTY_PRINT) . PHP_EOL, LOCK_EX);
          }
        }
      }

      // Permissions
      chmod(__DIR__ . '/_config', 0755);
      chmod(__DIR__ . '/_config/tmp', 0755);
      if (file_exists(__DIR__ . '/_config/config.json')) {
        chmod(__DIR__ . '/_config/config.json', 0644);
      }
      if (file_exists(__DIR__ . '/_config/userData.json')) {
        chmod(__DIR__ . '/_config/userData.json', 0644);
      }

      // Marker file — only if missing
      if (!file_exists(__DIR__ . '/_config/.isHAXcmsConfig')) {
        file_put_contents(__DIR__ . '/_config/.isHAXcmsConfig', '');
      }

      // SALT — generated only if missing; restrict to 0600 (SEC-10)
      if (!file_exists(__DIR__ . '/_config/SALT.txt')) {
        file_put_contents(__DIR__ . '/_config/SALT.txt', haxcmsInstallerGenerateSecureSecret(), LOCK_EX);
        @chmod(__DIR__ . '/_config/SALT.txt', 0600);
      }

      // config.php templating — runs only if placeholders still present
      $configPhpPath = __DIR__ . '/_config/config.php';
      if (file_exists($configPhpPath)) {
        $configFile = file_get_contents($configPhpPath);
        $needsTemplating = (
          strpos($configFile, 'HAXTHEWEBPRIVATEKEY') !== false ||
          strpos($configFile, 'HAXTHEWEBREFRESHPRIVATEKEY') !== false ||
          strpos($configFile, 'jeff') !== false ||
          strpos($configFile, 'jimmerson') !== false
        );
        if ($needsTemplating) {
          $configFile = str_replace('HAXTHEWEBPRIVATEKEY', haxcmsInstallerGenerateSecureSecret(), $configFile);
          $configFile = str_replace('HAXTHEWEBREFRESHPRIVATEKEY', haxcmsInstallerGenerateSecureSecret(), $configFile);
          $configFile = str_replace('jeff', $resolvedUsername, $configFile);
          // persist a password_hash (bcrypt/argon2), never the plaintext
          $configFile = str_replace('jimmerson', password_hash($pass, PASSWORD_DEFAULT), $configFile);
          // basePath locked to where this was installed
          $basePath = str_replace('install.php', '', $_SERVER['SCRIPT_NAME']);
          $configFile = str_replace("->basePath = '/'", "->basePath = '$basePath'", $configFile);
          // config.php holds JWT private key + password hash; restrict to 0600
          file_put_contents($configPhpPath, $configFile, LOCK_EX);
          @chmod($configPhpPath, 0600);
        }
      }

      // git init for _config (only if not already a git repo)
      if (!is_dir(__DIR__ . '/_config/.git')) {
        haxcmsInstallerGuardedGitCreate(__DIR__ . '/_config', $failed, $failedMessages);
      }
    }

    // --- _sites directory ---
    if (!is_dir(__DIR__ . '/_sites')) {
      if (haxcmsInstallerGuardedMkdir(__DIR__ . '/_sites', 0755, $failed, $failedMessages)) {
        chmod(__DIR__ . '/_sites', 0755);
        @chown(__DIR__ . '/_sites', get_current_user());
        @chgrp(__DIR__ . '/_sites', get_current_user());
        haxcmsInstallerGuardedGitCreate(__DIR__ . '/_sites', $failed, $failedMessages);
      }
    }

    // --- _published directory ---
    if (!is_dir(__DIR__ . '/_published')) {
      if (haxcmsInstallerGuardedMkdir(__DIR__ . '/_published', 0755, $failed, $failedMessages)) {
        chmod(__DIR__ . '/_published', 0755);
        @chown(__DIR__ . '/_published', get_current_user());
        @chgrp(__DIR__ . '/_published', get_current_user());
      }
    }

    // --- _archived directory ---
    if (!is_dir(__DIR__ . '/_archived')) {
      if (haxcmsInstallerGuardedMkdir(__DIR__ . '/_archived', 0755, $failed, $failedMessages)) {
        chmod(__DIR__ . '/_archived', 0755);
        @chown(__DIR__ . '/_archived', get_current_user());
        @chgrp(__DIR__ . '/_archived', get_current_user());
      }
    }
  }
}

// --- ?op handling ----------------------------------------------------------
$op = isset($_GET['op']) ? $_GET['op'] : '';

// ?op=state — GET: read state, re-evaluate environment, return JSON
if ($op === 'state') {
  $state = haxcmsInstallerReadState();
  $response = haxcmsInstallerBuildStateResponse($state['step'], $state);
  header('Content-Type: application/json');
  print json_encode($response);
  exit();
}

// ?op=prepare — auto-create missing runtime directories AND non-secret
// boilerplate files (no credentials), then return the refreshed step-2 state
// so the wizard can re-render checks. This is the "run the script that
// automatically creates the required missing files" resolution path for
// directory/file precondition errors (issue #2974). Method-agnostic: works
// for both POST (wizard fetch) and GET (direct visit), always returning JSON.
if ($op === 'prepare') {
  haxcmsInstallerEnsureRuntimeDirectories($failed, $failedMessages);
  $state = haxcmsInstallerReadState();
  $response = haxcmsInstallerBuildStateResponse(2, $state);
  if ($failed) {
    $response['hasErrors'] = true;
    $response['errors'] = $failedMessages;
    // When the web server user cannot create the _config tree / copy
    // boilerplate (owner mismatch, read-only webroot), surface a single
    // command the user can run over SSH as the file owner. We deliberately
    // avoid 777: the shell installer runs as the owner and sets safe
    // 0755/0644 modes, which is the secure equivalent of the user's
    // "request write permission, do the work, revert" idea.
    $response['permissionCommand'] = 'bash ' . __DIR__ . '/scripts/haxtheweb.sh';
    $response['permissionHint'] =
      'Run that command over SSH as the file owner, then click "Re-check ' .
      'requirements". It creates the full _config tree and boilerplate with ' .
      'safe permissions — no 777 needed.';
  }
  header('Content-Type: application/json');
  print json_encode($response);
  exit();
}

// ?op=advance — POST: validate + persist state, run side effects, return JSON
if ($op === 'advance') {
  $rawInput = file_get_contents('php://input');
  $postData = json_decode($rawInput, true);
  if (!is_array($postData)) {
    $postData = $_POST;
  }

  $toStep = isset($postData['toStep']) ? (int) $postData['toStep'] : 1;
  if ($toStep < 1 || $toStep > 4) {
    header('HTTP/1.1 400 Bad Request');
    header('Content-Type: application/json');
    print json_encode(array('error' => 'Invalid toStep. Must be 1-4.'));
    exit();
  }

  $language = 'en';
  if (isset($postData['language']) && is_string($postData['language'])) {
    $language = haxcmsInstallerSanitizeLanguage($postData['language']);
  }

  $username = 'admin';
  if (isset($postData['username']) && is_string($postData['username'])) {
    $username = haxcmsInstallerSanitizeUsername($postData['username']);
  }

  $password = '';
  if (isset($postData['password']) && is_string($postData['password'])) {
    $password = $postData['password'];
  }

  // --- install token gating (hosting-provider direct install) ---
  $installTokenFilePath = __DIR__ . '/_installtoken.txt';
  $installTokenFromFile = '';
  if (file_exists($installTokenFilePath) && is_file($installTokenFilePath)) {
    $installTokenFromFile = trim(file_get_contents($installTokenFilePath));
  }
  $installTokenEnabled = ($installTokenFromFile !== '');

  $installTokenPosted = '';
  if (isset($postData['install_token']) && is_string($postData['install_token'])) {
    $installTokenPosted = (string) $postData['install_token'];
  }

  $isDirectInstall = false;
  if ($installTokenPosted !== '') {
    if (!$installTokenEnabled || !hash_equals($installTokenFromFile, $installTokenPosted)) {
      header('HTTP/1.1 403 Forbidden');
      header('Content-Type: application/json');
      print json_encode(array(
        'error' => 'Invalid or missing install token. POST-supplied credentials were not applied.',
      ));
      exit();
    }
    $isDirectInstall = true;
    // Honor hosting-provider credential fields (user / pass)
    if (isset($postData['user']) && is_string($postData['user']) && trim($postData['user']) !== '') {
      $username = haxcmsInstallerSanitizeUsername($postData['user']);
    }
    if (isset($postData['pass']) && is_string($postData['pass']) && $postData['pass'] !== '') {
      $password = $postData['pass'];
    }
  }

  // Persist state
  $newState = array(
    'step' => $toStep,
    'language' => $language,
    'username' => $username,
  );
  haxcmsInstallerWriteState($newState);

  $credentials = null;
  $statusReport = null;
  $runInstall = ($toStep === 4);

  if ($runInstall && !$failed) {
    // Resolve username
    $resolvedUsername = $username;

    // Resolve password
    if ($isDirectInstall && $password !== '') {
      $pass = $password;
      if (!haxcmsInstallerPasswordMeetsPolicy($pass)) {
        $failed = true;
        $failedMessages[] = 'POST-supplied password does not meet the minimum policy (10+ chars, at least one letter and one number).';
      }
    } else if ($password !== '') {
      // Wizard-supplied optional password
      $pass = $password;
      if (!haxcmsInstallerPasswordMeetsPolicy($pass)) {
        $failed = true;
        $failedMessages[] = 'Password does not meet the minimum policy (10+ chars, at least one letter and one number).';
      }
    } else {
      $pass = haxcmsInstallerGeneratePassword();
      $passwordWasGenerated = true;
    }

    if (!$failed) {
      haxcmsInstallerRunInstallBlock(
        $language,
        $resolvedUsername,
        $pass,
        $passwordWasGenerated,
        $failed,
        $failedMessages
      );
    }

    // Delete install token file (one-time use)
    if (!$failed && $installTokenEnabled && file_exists($installTokenFilePath)) {
      @unlink($installTokenFilePath);
    }

    // On successful step 4, delete the state file so it doesn't linger
    if (!$failed) {
      global $stateFilePath;
      @unlink($stateFilePath);
    }

    $credentials = array(
      'username' => $resolvedUsername,
      'password' => $pass,
      'passwordWasGenerated' => $passwordWasGenerated,
    );
    $statusReport = HAXCMSSystemStatusService::buildInstallerStatusReport(__DIR__);
  }

  $response = haxcmsInstallerBuildStateResponse($toStep, $newState, $credentials, $statusReport);
  if ($failed) {
    $response['hasErrors'] = true;
    $response['errors'] = $failedMessages;
  }
  header('Content-Type: application/json');
  print json_encode($response);
  exit();
}

// --- default: thin bootstrap HTML ------------------------------------------
$state = haxcmsInstallerReadState();
$htmlLang = haxcmsInstallerSanitizeLanguage($state['language']);
?>
<!DOCTYPE html>
<html lang="<?php print haxcmsInstallerStatusEscape($htmlLang); ?>">
  <head>
    <meta charset="utf-8">
    <title>HAXcms Installation</title>
    <link rel="modulepreload" href="./build/es6/node_modules/@haxtheweb/simple-icon/lib/simple-icons.js" crossorigin="anonymous">
    <link rel="modulepreload" href="./build/es6/node_modules/@haxtheweb/simple-icon/lib/simple-icon-lite.js" crossorigin="anonymous">
    <link rel="modulepreload" href="./build/es6/node_modules/@haxtheweb/simple-icon/lib/simple-icon-button-lite.js" crossorigin="anonymous">
    <link rel="modulepreload" href="./build/es6/node_modules/@haxtheweb/git-corner/git-corner.js" crossorigin="anonymous">
    <link rel="modulepreload" href="./build/es6/node_modules/@haxtheweb/hax-app-installer/hax-app-installer.js" crossorigin="anonymous">
    <style>
      /*
        Installer bootstrap styling. The hax-app-installer element provides
        its own full DDD styling; this page only needs enough to avoid a
        broken layout if the element has not been built yet (pre-ubiquity).
        DDD :root token subset inlined from elements/d-d-d/lib/DDDStyles.js
        so the page is self-contained and dark-mode-aware.
      */
      :root {
        color-scheme: light dark;
        --ddd-theme-default-beaverBlue: #1e407c;
        --ddd-theme-default-nittanyNavy: #001e44;
        --ddd-theme-default-potentialMidnight: #000321;
        --ddd-theme-default-coalyGray: #262626;
        --ddd-theme-default-limestoneLight: #e4e5e7;
        --ddd-theme-default-limestoneMaxLight: #f2f2f4;
        --ddd-theme-default-white: #ffffff;
        --ddd-theme-default-black: #000000;
        --ddd-theme-default-navy40: rgba(0, 30, 68, 0.4);
        --ddd-theme-default-background: #eff2f5;
        --ddd-primary-1: var(--ddd-theme-default-beaverBlue);
        --ddd-primary-2: var(--ddd-theme-default-nittanyNavy);
        --ddd-primary-3: var(--ddd-theme-default-potentialMidnight);
        --ddd-primary-4: var(--ddd-theme-default-coalyGray);
        --ddd-accent-2: var(--ddd-theme-default-limestoneMaxLight);
        --ddd-accent-6: var(--ddd-theme-default-white);
        --ddd-font-primary: "Roboto", "Franklin Gothic Medium", Tahoma, sans-serif;
        --ddd-spacing-2: 8px;
        --ddd-spacing-4: 16px;
        --ddd-font-size-s: 24px;
        --ddd-font-size-l: 40px;
        --ddd-font-size-m: 32px;
        --ddd-radius-xs: 4px;
        --ddd-radius-lg: 16px;
        --ddd-boxShadow-md: light-dark(rgba(0, 3, 33, 0.15), rgba(150, 190, 230, 0.1)) 0px 8px 16px 0px;
      }
      body {
        margin: 0;
        padding: 0;
        overflow-x: hidden;
        color-scheme: light dark;
        background-color: light-dark(var(--ddd-theme-default-background), var(--ddd-theme-default-potentialMidnight));
        color: light-dark(var(--ddd-theme-default-coalyGray), var(--ddd-theme-default-white));
        font-family: var(--ddd-font-primary);
        --github-corner-background: var(--ddd-primary-1);
        --github-corner-color: var(--ddd-theme-default-white);
      }
      git-corner {
        right: 0;
        top: 0;
        position: fixed;
      }
      hax-app-installer {
        display: block;
      }
    </style>
  </head>
  <body>
    <git-corner alt="Join HAX on Github!" source="https://github.com/haxtheweb/haxcms-php"></git-corner>
    <div class="wrapper">
      <hax-app-installer api-endpoint="install.php"></hax-app-installer>
    </div>
    <script type="module">
      import "./build/es6/node_modules/@haxtheweb/simple-icon/lib/simple-icons.js";
      import "./build/es6/node_modules/@haxtheweb/simple-icon/lib/simple-icon-lite.js";
      import "./build/es6/node_modules/@haxtheweb/simple-icon/lib/simple-icon-button-lite.js";
      import "./build/es6/node_modules/@haxtheweb/git-corner/git-corner.js";
      import "./build/es6/node_modules/@haxtheweb/hax-app-installer/hax-app-installer.js";
    </script>
  </body>
</html>

<?php
// HAXcms installer — stepped wizard with precondition checks, secure
// hosting-provider credential override, and default-language selection.
// See haxtheweb/issues#2974 for the design rationale.
//
// Flow:
//   Step 1 (status)   — precondition / environment check report
//   Step 2 (confirm)  — confirm admin username + select default language
//   Step 3 (install)  — run setup, then render success screen with credentials
//
// Hosting-provider automation (e.g. Reclaim Cloud / haxcms-jps) may POST
// user + pass + install_token directly to skip the interactive wizard. The
// credential override is honored ONLY when a pre-dropped _installtoken.txt
// file matches the POSTed install_token (timing-safe hash_equals). Without
// the token file, POST-supplied credentials are ignored and the installer
// uses an auto-generated admin password — closing the unauthenticated
// credential-race for the default/common case.

$failed = false;
$failedMessages = array();
$resolvedUsername = 'admin';
$pass = '';
$passwordWasGenerated = false;

include_once __DIR__ . '/system/backend/php/lib/SystemStatusService.php';
include_once __DIR__ . '/system/backend/php/lib/LocalizationSettingsService.php';
include_once __DIR__ . '/system/backend/php/lib/Git.php';

// Security best practice (I5): once HAXcms is already installed (the four
// core directories exist), the installer must never run setup logic again —
// it is an unauthenticated endpoint that creates credentials and secrets.
// Redirect to the dashboard and stop before any POST/file logic executes.
if (
  is_dir(__DIR__ . '/_sites') &&
  is_dir(__DIR__ . '/_config') &&
  is_dir(__DIR__ . '/_published') &&
  is_dir(__DIR__ . '/_archived')
) {
  header('Location: index.php');
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
if (!function_exists('haxcmsInstallerStatusToneClass')) {
  function haxcmsInstallerStatusToneClass($tone)
  {
    if ($tone === 'ok') { return 'status-tone-ok'; }
    if ($tone === 'warning') { return 'status-tone-warning'; }
    if ($tone === 'error') { return 'status-tone-error'; }
    return 'status-tone-info';
  }
}
if (!function_exists('haxcmsInstallerStatusEscape')) {
  function haxcmsInstallerStatusEscape($value)
  {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
  }
}
if (!function_exists('haxcmsInstallerStatusRender')) {
  function haxcmsInstallerStatusRender($statusReport)
  {
    if (!is_array($statusReport) || !isset($statusReport['rows']) || !is_array($statusReport['rows'])) {
      return;
    }
    $summary = isset($statusReport['summary']) && is_array($statusReport['summary'])
      ? $statusReport['summary']
      : array();
    $runtime = isset($summary['programmingLanguage']) ? $summary['programmingLanguage'] : 'unknown';
    $server = isset($summary['serverVersion']) ? $summary['serverVersion'] : 'unknown';
    $currentVersion = isset($summary['haxcmsVersionCurrent']) ? $summary['haxcmsVersionCurrent'] : 'unknown';
    $latestVersion = isset($summary['haxcmsVersionLatest']) ? $summary['haxcmsVersionLatest'] : 'unknown';
    print '<div class="status-panel">';
    print '<h2>System status checks</h2>';
    print '<p class="status-summary">';
    print 'Runtime: ' . haxcmsInstallerStatusEscape($runtime);
    print ' &middot; Server: ' . haxcmsInstallerStatusEscape($server);
    print ' &middot; HAXcms: ' . haxcmsInstallerStatusEscape($currentVersion);
    print ' (latest: ' . haxcmsInstallerStatusEscape($latestVersion) . ')';
    print '</p>';
    print '<table class="status-table" aria-label="Installer system status checks">';
    print '<thead><tr><th>Check</th><th>Status</th><th>Details</th></tr></thead><tbody>';
    foreach ($statusReport['rows'] as $row) {
      if (!is_array($row)) { continue; }
      $tone = isset($row['tone']) ? $row['tone'] : 'info';
      $title = isset($row['title']) ? $row['title'] : '';
      $value = isset($row['value']) ? $row['value'] : '';
      $description = isset($row['description']) ? $row['description'] : '';
      print '<tr class="' . haxcmsInstallerStatusToneClass($tone) . '">';
      print '<td>' . haxcmsInstallerStatusEscape($title) . '</td>';
      print '<td>' . haxcmsInstallerStatusEscape($value) . '</td>';
      print '<td>' . haxcmsInstallerStatusEscape($description) . '</td>';
      print '</tr>';
    }
    print '</tbody></table></div>';
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

// --- install token gating --------------------------------------------------
// Hosting providers (e.g. Reclaim Cloud via haxcms-jps) may pre-drop a
// _installtoken.txt file containing a random secret, then POST user/pass/
// install_token to automate the install. When the token file is present,
// POST-supplied credentials are honored ONLY if install_token matches
// (timing-safe hash_equals). When the token file is absent, POST-supplied
// credentials are ignored entirely and the installer always uses the
// auto-generated admin/password path.
$installTokenFilePath = __DIR__ . '/_installtoken.txt';
$installTokenFromFile = '';
if (file_exists($installTokenFilePath) && is_file($installTokenFilePath)) {
  $installTokenFromFile = trim(file_get_contents($installTokenFilePath));
}
$installTokenEnabled = ($installTokenFromFile !== '');

// --- step detection --------------------------------------------------------
$step = 'status';
$isDirectInstall = false;
// Hosting-provider automation sends user/pass directly (no interactive wizard)
if (isset($_POST['user']) || isset($_POST['pass'])) {
  $isDirectInstall = true;
  $step = 'install';
} else if (isset($_POST['step'])) {
  $step = filter_var($_POST['step'], FILTER_SANITIZE_FULL_SPECIAL_CHARS);
}

// --- selected language -----------------------------------------------------
$selectedLanguage = 'en-US';
if (isset($_POST['language']) && is_string($_POST['language'])) {
  $normalized = HAXCMSLocalizationSettingsService::normalizeDefaultLanguageValue($_POST['language']);
  if ($normalized !== null) {
    $selectedLanguage = $normalized;
  }
}

// --- token validation for direct install -----------------------------------
$directInstallTokenError = '';
if ($isDirectInstall) {
  if ($installTokenEnabled) {
    $postedToken = isset($_POST['install_token']) ? (string) $_POST['install_token'] : '';
    if ($postedToken === '' || !hash_equals($installTokenFromFile, $postedToken)) {
      $directInstallTokenError = 'Invalid or missing install token. POST-supplied credentials were not applied.';
      $isDirectInstall = false;
      $step = 'status';
    }
  } else {
    // No token file — ignore POSTed credentials entirely (race-closed)
    $isDirectInstall = false;
    $step = 'status';
  }
}

// --- install execution (step=install) --------------------------------------
$installerStatusReport = HAXCMSSystemStatusService::buildInstallerStatusReport(__DIR__);
$configExisted = is_dir(__DIR__ . '/_config');

if ($step === 'install' && !$failed) {
  $generateSecureSecret = function () {
    $parts = array();
    for ($i = 0; $i < 4; $i++) {
      $parts[] = bin2hex(random_bytes(16));
    }
    return implode('-', $parts);
  };

  // resolve username (POSTed from wizard form, or from hosting-provider POST)
  if ($isDirectInstall && isset($_POST['user']) && is_string($_POST['user']) && trim($_POST['user']) !== '') {
    $resolvedUsername = filter_var($_POST['user'], FILTER_SANITIZE_FULL_SPECIAL_CHARS);
  } else if (isset($_POST['username']) && is_string($_POST['username']) && trim($_POST['username']) !== '') {
    $resolvedUsername = filter_var($_POST['username'], FILTER_SANITIZE_FULL_SPECIAL_CHARS);
  } else {
    $resolvedUsername = 'admin';
  }

  // resolve password
  if ($isDirectInstall && isset($_POST['pass']) && is_string($_POST['pass']) && $_POST['pass'] !== '') {
    $pass = $_POST['pass'];
    if (!haxcmsInstallerPasswordMeetsPolicy($pass)) {
      $failed = true;
      $failedMessages[] = 'POST-supplied password does not meet the minimum policy (10+ chars, at least one letter and one number).';
    }
  } else {
    $pass = haxcmsInstallerGeneratePassword();
    $passwordWasGenerated = true;
  }

  if (!$failed) {
    // --- _config directory + boilerplate ---
    if (!is_dir(__DIR__ . '/_config')) {
      if (haxcmsInstallerGuardedMkdir(__DIR__ . '/_config', 0755, $failed, $failedMessages)) {
        haxcmsInstallerGuardedMkdir(__DIR__ . '/_config/.ssh', 0755, $failed, $failedMessages);
        haxcmsInstallerGuardedMkdir(__DIR__ . '/_config/tmp', 0755, $failed, $failedMessages);
        haxcmsInstallerGuardedMkdir(__DIR__ . '/_config/cache', 0755, $failed, $failedMessages);
        haxcmsInstallerGuardedMkdir(__DIR__ . '/_config/settings', 0755, $failed, $failedMessages);
        haxcmsInstallerGuardedMkdir(__DIR__ . '/_config/user', 0755, $failed, $failedMessages);
        haxcmsInstallerGuardedMkdir(__DIR__ . '/_config/user/files', 0755, $failed, $failedMessages);
        haxcmsInstallerGuardedMkdir(__DIR__ . '/_config/node_modules', 0755, $failed, $failedMessages);

        // boilerplate files
        copy(__DIR__ . '/system/boilerplate/systemsetup/config.json', __DIR__ . '/_config/config.json');
        copy(__DIR__ . '/system/boilerplate/systemsetup/my-custom-elements.js', __DIR__ . '/_config/my-custom-elements.js');
        copy(__DIR__ . '/system/boilerplate/systemsetup/userData.json', __DIR__ . '/_config/userData.json');
        copy(__DIR__ . '/system/boilerplate/systemsetup/config.php', __DIR__ . '/_config/config.php');
        copy(__DIR__ . '/system/boilerplate/systemsetup/.htaccess', __DIR__ . '/_config/.htaccess');
        copy(__DIR__ . '/system/boilerplate/systemsetup/.user-files-htaccess', __DIR__ . '/_config/user/files/.htaccess');

        // set the default language in config.json localization block
        $configJsonPath = __DIR__ . '/_config/config.json';
        $configJsonRaw = @file_get_contents($configJsonPath);
        $configJson = json_decode($configJsonRaw, true);
        if (is_array($configJson)) {
          if (!isset($configJson['localization']) || !is_array($configJson['localization'])) {
            $configJson['localization'] = array();
          }
          $configJson['localization']['defaultLanguage'] = $selectedLanguage;
          file_put_contents($configJsonPath, json_encode($configJson, JSON_PRETTY_PRINT) . PHP_EOL, LOCK_EX);
        }

        // permissions
        chmod(__DIR__ . '/_config', 0755);
        chmod(__DIR__ . '/_config/tmp', 0755);
        chmod(__DIR__ . '/_config/config.json', 0644);
        chmod(__DIR__ . '/_config/userData.json', 0644);

        // marker file
        file_put_contents(__DIR__ . '/_config/.isHAXcmsConfig', '');

        // SALT — restrict to 0600, write with LOCK_EX (SEC-10)
        file_put_contents(__DIR__ . '/_config/SALT.txt', $generateSecureSecret(), LOCK_EX);
        @chmod(__DIR__ . '/_config/SALT.txt', 0600);

        // config.php templating
        $configFile = file_get_contents(__DIR__ . '/_config/config.php');
        $configFile = str_replace('HAXTHEWEBPRIVATEKEY', $generateSecureSecret(), $configFile);
        $configFile = str_replace('HAXTHEWEBREFRESHPRIVATEKEY', $generateSecureSecret(), $configFile);
        $configFile = str_replace('jeff', $resolvedUsername, $configFile);
        // persist a password_hash (bcrypt/argon2), never the plaintext
        $configFile = str_replace('jimmerson', password_hash($pass, PASSWORD_DEFAULT), $configFile);
        // basePath locked to where this was installed
        $basePath = str_replace('install.php', '', $_SERVER['SCRIPT_NAME']);
        $configFile = str_replace("->basePath = '/'", "->basePath = '$basePath'", $configFile);
        // config.php holds JWT private key + password hash; restrict to 0600 (SEC-10)
        file_put_contents(__DIR__ . '/_config/config.php', $configFile, LOCK_EX);
        @chmod(__DIR__ . '/_config/config.php', 0600);

        // git init for _config
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

    // --- delete install token file (one-time use) ---
    if (!$failed && $installTokenEnabled && file_exists($installTokenFilePath)) {
      @unlink($installTokenFilePath);
    }
  }

  // rebuild status report after install attempt
  $installerStatusReport = HAXCMSSystemStatusService::buildInstallerStatusReport(__DIR__);
}

// determine whether any status rows are errors (for step 1 gating display)
$statusHasErrors = false;
if (is_array($installerStatusReport) && isset($installerStatusReport['rows'])) {
  foreach ($installerStatusReport['rows'] as $row) {
    if (is_array($row) && isset($row['tone']) && $row['tone'] === 'error') {
      $statusHasErrors = true;
      break;
    }
  }
}
?>
<!DOCTYPE html>
<html lang="<?php print haxcmsInstallerStatusEscape($selectedLanguage); ?>">
  <head>
    <meta charset="utf-8">
    <title>HAXcms Installation</title>
    <link rel="preload" href="./build/es6/dist/build-install.js" as="script" crossorigin="anonymous">
    <link rel="preload" href="./build/es6/node_modules/@haxtheweb/app-hax/app-hax.js"
      as="script" crossorigin="anonymous">
    <style>
      /* DDD-aware installer styling with dark-mode support */
      :root {
        --installer-bg: #ffffff;
        --installer-surface: #f6f6f6;
        --installer-text: #1a1a1a;
        --installer-border: #d8d8d8;
        --installer-accent: var(--ddd-primary-1, #1a73e8);
        --installer-accent-hover: var(--ddd-primary-2, #1557b0);
        --installer-success: #2e7d32;
        --installer-warning: #f9a825;
        --installer-error: #c62828;
        --installer-info: #1565c0;
        --installer-code-bg: #333333;
        --installer-code-text: #ffd700;
      }
      @media (prefers-color-scheme: dark) {
        :root {
          --installer-bg: #121212;
          --installer-surface: #1e1e1e;
          --installer-text: #e0e0e0;
          --installer-border: #333333;
          --installer-code-bg: #1a1a1a;
        }
      }
      body {
        margin: 0;
        padding: 0;
        overflow-x: hidden;
        background-color: var(--installer-bg);
        color: var(--installer-text);
        --app-hax-accent-color: var(--installer-text);
        --app-hax-background-color: var(--installer-bg);
        --simple-tooltip-background: #000000;
        --simple-tooltip-opacity: 1;
        --simple-tooltip-text-color: #ffffff;
        --simple-tooltip-delay-in: 0;
        --simple-tooltip-duration-in: 200ms;
        --simple-tooltip-duration-out: 0;
        --simple-tooltip-border-radius: 0;
        --simple-tooltip-font-size: 14px;
      }
      pre {
        background-color: var(--installer-code-bg);
        color: var(--installer-code-text);
        padding: 8px;
        border-radius: 4px;
        overflow-x: auto;
      }
      .version {
        position: fixed;
        left: 0;
        bottom: 0;
        background-color: var(--installer-accent);
        display: inline-block;
        padding: 8px;
        color: #ffffff;
        border-right: 3px solid var(--installer-text);
        border-top: 3px solid var(--installer-text);
        font-weight: bold;
        font-size: 14px;
      }
      p, ul, li {
        font-size: 18px;
      }
      hax-logo {
        --hax-logo-letter-spacing: 1px;
        text-align: center;
        --hax-logo-font-size: 60px;
        margin: 16px 0 50px;
      }
      @media screen and (max-width: 600px) {
        hax-logo { --hax-logo-font-size: 20px; }
      }
      ul li { padding: 4px; }
      ul li strong {
        padding: 8px;
        font-size: 24px;
        line-height: 1.5;
        background-color: var(--installer-surface);
        margin-left: 16px;
        border-radius: 4px;
      }
      .wrapper {
        padding: 16px;
        margin: 5vh 15vw;
        display: flex;
        justify-content: center;
      }
      .card {
        width: 60vw;
        max-width: 800px;
        background-color: var(--installer-bg);
        padding: 0 16px;
      }
      git-corner {
        right: 0;
        top: 0;
        position: fixed;
      }
      h1 {
        margin: 16px;
        padding: 0;
        font-size: 30px;
        text-align: center;
      }
      .step-indicator {
        display: flex;
        justify-content: center;
        gap: 8px;
        margin: 16px 0 32px;
      }
      .step-dot {
        width: 10px;
        height: 10px;
        border-radius: 50%;
        background-color: var(--installer-border);
      }
      .step-dot.active { background-color: var(--installer-accent); }
      .step-dot.done { background-color: var(--installer-success); }
      .install-form {
        max-width: 480px;
        margin: 0 auto;
      }
      .install-form label {
        display: block;
        font-size: 16px;
        font-weight: 600;
        margin: 16px 0 4px;
      }
      .install-form input,
      .install-form select {
        width: 100%;
        padding: 8px 12px;
        font-size: 16px;
        border: 2px solid var(--installer-border);
        border-radius: 4px;
        background-color: var(--installer-bg);
        color: var(--installer-text);
        box-sizing: border-box;
      }
      .install-form input:focus,
      .install-form select:focus {
        border-color: var(--installer-accent);
        outline: none;
      }
      .install-form .help-text {
        font-size: 14px;
        opacity: 0.7;
        margin: 4px 0 0;
      }
      .btn-row {
        display: flex;
        justify-content: center;
        gap: 12px;
        margin: 32px 0;
      }
      .hax-btn {
        font-size: 18px;
        padding: 10px 24px;
        color: #ffffff;
        background-color: var(--installer-accent);
        border: none;
        border-radius: 4px;
        cursor: pointer;
        transition: background-color 0.2s ease-in-out;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 8px;
      }
      .hax-btn:hover,
      .hax-btn:focus {
        background-color: var(--installer-accent-hover);
      }
      .hax-btn.secondary {
        background-color: var(--installer-surface);
        color: var(--installer-text);
        border: 2px solid var(--installer-border);
      }
      .hax-btn.secondary:hover,
      .hax-btn.secondary:focus {
        border-color: var(--installer-accent);
      }
      .credential-box {
        background-color: var(--installer-surface);
        border: 1px solid var(--installer-border);
        border-radius: 8px;
        padding: 16px;
        margin: 16px 0;
      }
      .credential-box .credential-row {
        display: flex;
        align-items: center;
        gap: 12px;
        margin: 8px 0;
      }
      .credential-box .credential-row strong {
        font-size: 20px;
        font-family: monospace;
        background: none;
        padding: 0;
        margin: 0;
      }
      .copy-btn {
        font-size: 14px;
        padding: 4px 12px;
        background-color: var(--installer-bg);
        color: var(--installer-text);
        border: 1px solid var(--installer-border);
        border-radius: 4px;
        cursor: pointer;
      }
      .copy-btn:hover { border-color: var(--installer-accent); }
      .warning-box {
        background-color: var(--installer-surface);
        border-left: 4px solid var(--installer-warning);
        border-radius: 4px;
        padding: 12px 16px;
        margin: 16px 0;
        font-size: 16px;
      }
      .error-box {
        background-color: var(--installer-surface);
        border-left: 4px solid var(--installer-error);
        border-radius: 4px;
        padding: 12px 16px;
        margin: 16px 0;
        font-size: 16px;
      }
      .error-box ul { margin: 8px 0 0; padding-left: 20px; }
      .error-box li { font-size: 16px; }
      .status-panel {
        margin-top: 32px;
        background-color: var(--installer-surface);
        border: 1px solid var(--installer-border);
        border-radius: 8px;
        padding: 12px;
      }
      .status-panel h2 { margin: 0 0 8px; font-size: 24px; }
      .status-summary { margin: 0 0 12px; font-size: 16px; }
      .status-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 14px;
      }
      .status-table th,
      .status-table td {
        padding: 8px;
        text-align: left;
        border-bottom: 1px solid var(--installer-border);
        vertical-align: top;
      }
      .status-table tbody tr.status-tone-ok td:first-child {
        border-left: 4px solid var(--installer-success);
      }
      .status-table tbody tr.status-tone-warning td:first-child {
        border-left: 4px solid var(--installer-warning);
      }
      .status-table tbody tr.status-tone-error td:first-child {
        border-left: 4px solid var(--installer-error);
      }
      .status-table tbody tr.status-tone-info td:first-child {
        border-left: 4px solid var(--installer-info);
      }
      code {
        background-color: var(--installer-surface);
        padding: 2px 6px;
        border-radius: 3px;
        font-size: 14px;
      }
      /* World traveler globe icon — the HAX icon, reflecting the
         worldwide / localization reach of the installer. */
      .world-traveler-icon {
        display: block;
        margin: 0 auto 8px;
        color: var(--installer-accent);
        --simple-icon-width: var(--ddd-icon-size-8, 64px);
        --simple-icon-height: var(--ddd-icon-size-8, 64px);
      }
    </style>
  </head>
  <body>
    <git-corner alt="Join HAX on Github!" source="https://github.com/haxtheweb/haxcms"></git-corner>
    <div class="wrapper">
      <div class="card">
<?php
  // --- step indicators ---
  $stepNum = 1;
  if ($step === 'confirm') { $stepNum = 2; }
  if ($step === 'install') { $stepNum = 3; }
  print '<div class="step-indicator" aria-label="Installation progress">';
  for ($i = 1; $i <= 3; $i++) {
    $cls = 'step-dot';
    if ($i < $stepNum) { $cls .= ' done'; }
    if ($i === $stepNum) { $cls .= ' active'; }
    print '<span class="' . $cls . '"></span>';
  }
  print '</div>';
?>

<?php if ($directInstallTokenError !== '') { ?>
        <hax-logo hide-hax>install-issue</hax-logo><div class="version">V<?php print haxcmsInstallerStatusEscape($version);?></div>
        <h1>Install token validation failed</h1>
        <div class="error-box">
          <p><?php print haxcmsInstallerStatusEscape($directInstallTokenError); ?></p>
          <p>If you are a hosting provider integrating with HAXcms, ensure you pre-drop a <code>_installtoken.txt</code> file in the webroot before POSTing credentials, and include the matching value as <code>install_token</code> in your POST.</p>
        </div>
        <?php haxcmsInstallerStatusRender($installerStatusReport); ?>
<?php } else if ($failed) { ?>
        <hax-logo hide-hax>install-issue</hax-logo><div class="version">V<?php print haxcmsInstallerStatusEscape($version);?></div>
        <h1>HAXcms installation encountered errors</h1>
        <?php if (count($failedMessages) > 0) { ?>
        <div class="error-box">
          <ul>
            <?php foreach ($failedMessages as $msg) { ?>
            <li><?php print haxcmsInstallerStatusEscape($msg); ?></li>
            <?php } ?>
          </ul>
        </div>
        <?php } ?>
        <p>
          You can modify permissions in order to achieve this
          <pre>chmod 0755 <?php print haxcmsInstallerStatusEscape(__DIR__); ?></pre>
          Or the preferred method is to run:
          <pre><?php print haxcmsInstallerStatusEscape("bash " . __DIR__ . "/scripts/haxtheweb.sh"); ?></pre>
          A complete installation guide can be read on
          <a href="https://haxtheweb.org/installation" target="_blank" rel="noopener noreferrer">
            <simple-icon-button-lite icon="icons:public" label="HAXTheWeb"></simple-icon-button-lite>
          </a>
        </p>
        <?php haxcmsInstallerStatusRender($installerStatusReport); ?>
<?php } else if ($step === 'install') { ?>
        <hax-logo hide-hax>HAX</hax-logo><div class="version">V<?php print haxcmsInstallerStatusEscape($version);?></div>
        <simple-icon-lite icon="icons:public" class="world-traveler-icon" aria-hidden="true"></simple-icon-lite>
        <h1>Install successful</h1>
        <p>If you don't see any errors then that means HAXcms has been successfully installed!
        Configuration settings were saved to <strong>_config/config.php</strong></p>
        <?php if ($passwordWasGenerated || $pass !== '') { ?>
        <div class="credential-box">
          <div class="credential-row">
            <span>Username:</span>
            <strong id="install-username"><?php print haxcmsInstallerStatusEscape($resolvedUsername); ?></strong>
            <button class="copy-btn" onclick="haxcmsInstallerCopy('install-username')">Copy</button>
          </div>
          <div class="credential-row">
            <span>Password:</span>
            <strong id="install-password"><?php print haxcmsInstallerStatusEscape($pass); ?></strong>
            <button class="copy-btn" onclick="haxcmsInstallerCopy('install-password')">Copy</button>
          </div>
        </div>
        <div class="warning-box">
          <strong>Important:</strong> These credentials will not be shown again. Please copy them now.
          For security, change your password after your first login.
        </div>
        <?php } else { ?>
        <div class="warning-box">
          <strong>Configuration was already present.</strong> Your existing admin credentials are unchanged.
          If you need to reset them, edit <code>_config/config.php</code> directly.
        </div>
        <?php } ?>
        <div class="btn-row">
          <a href="index.php" tabindex="-1"><button class="hax-btn">Access HAXcms</button></a>
          <a href="http://github.com/haxtheweb/issues/issues" target="_blank" rel="noopener noreferrer" tabindex="-1">
            <button class="hax-btn secondary">Join our community</button></a>
        </div>
        <?php haxcmsInstallerStatusRender($installerStatusReport); ?>
<?php } else if ($step === 'confirm') { ?>
        <hax-logo hide-hax>HAX</hax-logo><div class="version">V<?php print haxcmsInstallerStatusEscape($version);?></div>
        <h1>Configure your installation</h1>
        <p>Review the status checks below, then customize your admin username and default language.</p>
        <?php haxcmsInstallerStatusRender($installerStatusReport); ?>
        <?php if ($statusHasErrors) { ?>
        <div class="error-box">
          <strong>Some precondition checks failed.</strong> You can still attempt installation, but errors above may cause problems. Consider resolving them first.
        </div>
        <?php } ?>
        <form method="POST" class="install-form">
          <input type="hidden" name="step" value="install">
          <label for="username">Admin username</label>
          <input type="text" id="username" name="username" value="admin" autocomplete="username" required>
          <p class="help-text">A secure password will be auto-generated and shown on the next screen.</p>
          <label for="language">Default language</label>
          <select id="language" name="language">
            <?php
            foreach (HAXCMSLocalizationSettingsService::$SUPPORTED_LANGUAGES as $code => $label) {
              $sel = ($code === $selectedLanguage) ? ' selected' : '';
              print '<option value="' . haxcmsInstallerStatusEscape($code) . '"' . $sel . '>' . haxcmsInstallerStatusEscape($label) . '</option>';
            }
            ?>
          </select>
          <p class="help-text">New sites will default to this language. You can change it later in Configuration settings.</p>
          <div class="btn-row">
            <button type="submit" class="hax-btn">Install HAXcms</button>
            <a href="install.php"><button type="button" class="hax-btn secondary">Back</button></a>
          </div>
        </form>
<?php } else { /* step === 'status' */ ?>
        <hax-logo hide-hax>HAX</hax-logo><div class="version">V<?php print haxcmsInstallerStatusEscape($version);?></div>
        <simple-icon-lite icon="icons:public" class="world-traveler-icon" aria-hidden="true"></simple-icon-lite>
        <h1>Welcome to HAXcms</h1>
        <p>Let's get your HAXcms instance set up. First, we'll check your server environment to make sure everything is ready. HAXcms speaks your language — choose a default language for new sites in the next step.</p>
        <?php haxcmsInstallerStatusRender($installerStatusReport); ?>
        <?php if ($statusHasErrors) { ?>
        <div class="warning-box">
          <strong>Some precondition checks reported errors.</strong> You can still proceed, but you may want to resolve the issues above first. Use the preferred CLI method below if directory permissions are the issue:
          <pre><?php print haxcmsInstallerStatusEscape("bash " . __DIR__ . "/scripts/haxtheweb.sh"); ?></pre>
        </div>
        <?php } ?>
        <div class="btn-row">
          <form method="POST">
            <input type="hidden" name="step" value="confirm">
            <button type="submit" class="hax-btn">Continue</button>
          </form>
        </div>
<?php } ?>
      </div>
    </div>
    <script type="module">
      import "./build/es6/dist/build-install.js";
    </script>
    <script>
      function haxcmsInstallerCopy(elementId) {
        var el = document.getElementById(elementId);
        if (!el) return;
        var text = el.textContent;
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(text).then(function() {
            var btn = el.nextElementSibling;
            if (btn) { btn.textContent = 'Copied!'; setTimeout(function() { btn.textContent = 'Copy'; }, 2000); }
          });
        } else {
          var range = document.createRange();
          range.selectNode(el);
          globalThis.getSelection().removeAllRanges();
          globalThis.getSelection().addRange(range);
          document.execCommand('copy');
          globalThis.getSelection().removeAllRanges();
          var btn = el.nextElementSibling;
          if (btn) { btn.textContent = 'Copied!'; setTimeout(function() { btn.textContent = 'Copy'; }, 2000); }
        }
      }
    </script>
  </body>
</html>

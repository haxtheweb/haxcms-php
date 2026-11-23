<?php
/**
 * Which paths the dashboard (index.php, app-hax) answers as its own pages.
 * Everything else that reaches index.php still gets the dashboard shell, so
 * the app can show its own "not found" view, but with a 404 status: a stray
 * or mistyped URL must not look like a real page to browsers, crawlers and
 * assistive tech that read the status.
 *
 * haxcms-nodejs keeps the same list in src/lib/dashboardRoutes.js; change
 * both together.
 */
class HAXCMSDashboardRoutes
{
    const DASHBOARD_PAGES = array('', 'home', 'index.html', 'index.php', 'search');
    const DASHBOARD_PAGE_PREFIXES = array('createSite-step-');

    /**
     * The request path relative to where HAXcms is served, without query,
     * fragment or surrounding slashes.
     */
    public static function relativePath($requestPath, $basePath = '/')
    {
        $value = is_string($requestPath) ? $requestPath : '';
        $value = explode('#', explode('?', $value)[0])[0];
        $base = '/';
        if (is_string($basePath) && $basePath !== '' && $basePath !== '/') {
            $base = '/' . trim($basePath, '/') . '/';
        }
        if ($base !== '/' && strpos($value . '/', $base) === 0) {
            $value = substr($value, strlen($base) - 1);
        }
        return trim($value, '/');
    }

    /**
     * TRUE when the dashboard answers this path as one of its pages.
     */
    public static function isDashboardRoute($requestPath, $basePath = '/')
    {
        $relative = self::relativePath($requestPath, $basePath);
        if (in_array($relative, self::DASHBOARD_PAGES, true)) {
            return true;
        }
        foreach (self::DASHBOARD_PAGE_PREFIXES as $prefix) {
            if (strpos($relative, $prefix) === 0) {
                return true;
            }
        }
        return false;
    }
}

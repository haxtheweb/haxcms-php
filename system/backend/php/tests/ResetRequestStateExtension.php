<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use PHPUnit\Event\Test\PreparationStarted;
use PHPUnit\Event\Test\PreparationStartedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * Gives every test the request-scoped state a fresh PHP request starts with.
 *
 * Under PHP-FPM/mod_php, HAXCMS statics live for one request. PHPUnit runs
 * the whole suite in one process, so without this a static carries over from
 * one test to the next. The login throttle's no-store fallback counts failed
 * attempts per request in HAXCMS::$loginThrottleRequestAttempts; failed
 * logins in earlier test classes used to push it past
 * $loginThrottleFallbackMaxPerRequest, after which any later Basic-auth test
 * saw "blocked". Which test failed then depended on execution order.
 *
 * Tests that exercise the counter itself (LoginThrottleTest) still set it
 * explicitly; they now also start from 0.
 */
final class HaxcmsResetRequestStateSubscriber implements PreparationStartedSubscriber
{
    public function notify(PreparationStarted $event): void
    {
        if (!class_exists('HAXCMS', false)) {
            return;
        }
        if (property_exists('HAXCMS', 'loginThrottleRequestAttempts')) {
            $attempts = new ReflectionProperty('HAXCMS', 'loginThrottleRequestAttempts');
            $attempts->setValue(null, 0);
        }
    }
}

final class HaxcmsResetRequestStateExtension implements Extension
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $facade->registerSubscriber(new HaxcmsResetRequestStateSubscriber());
    }
}

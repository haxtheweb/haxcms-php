<?php
use PHPUnit\Framework\TestCase;

/**
 * scripts/ sits inside the web root, so a PHP file there is run by the web
 * server when someone asks for its address. Every script there is a command
 * line tool: each one refuses anything but the CLI before doing any work.
 */
class ScriptsCliOnlyTest extends TestCase
{
    public function testEveryScriptRefusesWebRequests(): void
    {
        $repoRoot = dirname(__DIR__, 5);
        $scripts = glob($repoRoot . '/scripts/*.php');
        $this->assertNotEmpty($scripts);
        foreach ($scripts as $script) {
            $src = (string) file_get_contents($script);
            $guard = strpos($src, "PHP_SAPI !== 'cli'");
            $this->assertNotFalse($guard, basename($script) . ' has no CLI-only guard');
            // the guard comes before the script reads its arguments or a file
            foreach (array('$argv', 'file_get_contents(', 'include', 'require') as $work) {
                $at = strpos($src, $work);
                if ($at !== false) {
                    $this->assertGreaterThan($guard, $at, basename($script) . ' does work before its CLI-only guard');
                }
            }
        }
    }
}

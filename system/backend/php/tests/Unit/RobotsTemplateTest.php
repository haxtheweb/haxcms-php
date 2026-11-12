<?php
use PHPUnit\Framework\TestCase;

/**
 * Renders the boilerplate robots.txt managed template with Twig (the same
 * engine HAXCMSSite::updateAlternateFormats uses) and checks the agent
 * discovery rules. Kept in step with haxcms-nodejs
 * test/unit/robots-template.test.cjs.
 */
class RobotsTemplateTest extends TestCase
{
    private function render(array $vars): string
    {
        $dir = dirname(__DIR__, 4) . '/boilerplate/site';
        $twig = new \Twig\Environment(new \Twig\Loader\FilesystemLoader($dir));
        return $twig->render('robots.txt', $vars);
    }

    private function groupFor(string $output, string $userAgent): array
    {
        $rules = array();
        $inGroup = false;
        foreach (explode("\n", $output) as $raw) {
            $line = trim($raw);
            if (strpos($line, 'User-agent:') === 0) {
                if ($inGroup && count($rules) > 0) {
                    break;
                }
                if ($line === 'User-agent: ' . $userAgent) {
                    $inGroup = true;
                }
                continue;
            }
            if ($inGroup && preg_match('/^(Allow|Disallow|Crawl-delay):/', $line)) {
                $rules[] = $line;
            }
        }
        return $rules;
    }

    private function publicOutput(): string
    {
        return $this->render(array('privateSite' => false, 'domain' => 'https://example.org/sites/demo/'));
    }

    public function testPublicSitesExposePageMarkdownAndApiDiscovery(): void
    {
        $rules = $this->groupFor($this->publicOutput(), '*');
        $this->assertContains('Allow: /pages/*/index.md$', $rules);
        $this->assertContains('Allow: /x/api$', $rules);
        $this->assertContains('Allow: /x/api/openapi', $rules);
        $this->assertContains('Allow: /.well-known/', $rules);
        $this->assertContains('Disallow: /x/', $rules);
    }

    public function testPublicSitesKeepRenderFilesFetchable(): void
    {
        $output = $this->publicOutput();
        foreach (array('*', 'ClaudeBot') as $agent) {
            $rules = $this->groupFor($output, $agent);
            // crawlers that render need the scripts, styles and page HTML
            foreach (array('/build/', '/pages/', '/theme/', '/assets/', '/custom/') as $blocked) {
                $this->assertNotContains('Disallow: ' . $blocked, $rules, $agent . ' ' . $blocked);
            }
        }
    }

    public function testNoCrawlDelay(): void
    {
        // Bing honors Crawl-delay; a per-request delay keeps big sites out of
        // the index AI answer engines draw on
        $this->assertDoesNotMatchRegularExpression('/^Crawl-delay:/mi', $this->publicOutput());
    }

    public function testAiCrawlersGetOwnGroupWithoutCrawlDelay(): void
    {
        $output = $this->publicOutput();
        foreach (array('GPTBot', 'ClaudeBot', 'PerplexityBot', 'Google-Extended') as $agent) {
            $rules = $this->groupFor($output, $agent);
            $this->assertNotEmpty($rules, $agent);
            $this->assertContains('Allow: /pages/*/index.md$', $rules, $agent);
            $this->assertContains('Disallow: /x/', $rules, $agent);
            foreach ($rules as $rule) {
                $this->assertStringStartsNotWith('Crawl-delay', $rule, $agent);
            }
        }
    }

    public function testReservedSitesAskAiCrawlersToStayOut(): void
    {
        $output = $this->render(array('privateSite' => false, 'reservedSite' => true, 'domain' => 'https://example.org/sites/demo/'));
        foreach (array('GPTBot', 'ClaudeBot', 'PerplexityBot', 'Google-Extended') as $agent) {
            $this->assertSame(array('Disallow: /'), $this->groupFor($output, $agent), $agent);
        }
        $this->assertContains('Allow: /', $this->groupFor($output, '*'));
    }

    public function testSitemapAndLlmsAdvertisedWithDomain(): void
    {
        $output = $this->publicOutput();
        $this->assertStringContainsString('Sitemap: https://example.org/sites/demo/sitemap.xml', $output);
        $this->assertStringContainsString('# LLMS: https://example.org/sites/demo/llms.txt', $output);
        // robots.txt parsers (and Lighthouse) reject unknown directives
        $this->assertDoesNotMatchRegularExpression('/^LLMS:/m', $output);
    }

    public function testPrivateSitesDisallowEverything(): void
    {
        $output = $this->render(array('privateSite' => true, 'domain' => 'https://example.org/sites/demo/'));
        $this->assertSame(array('Disallow: /'), $this->groupFor($output, '*'));
        $this->assertStringNotContainsString('GPTBot', $output);
        $this->assertStringNotContainsString('Sitemap:', $output);
    }
}

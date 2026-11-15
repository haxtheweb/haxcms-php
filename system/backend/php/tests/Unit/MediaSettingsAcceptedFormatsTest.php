<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/../../lib/MediaSettingsService.php';

/**
 * haxtheweb/issues#3054: the Media settings' accepted formats narrow uploads
 * within the safe upload list. Same cases as haxcms-nodejs
 * test/unit/settings.test.cjs ("mediaSettings accepted upload formats").
 */
class MediaSettingsAcceptedFormatsTest extends TestCase
{
    public function testDefaultAcceptsEverySafeFormat(): void
    {
        $this->assertSame(
            implode(',', HAXCMSMediaSettingsService::SAFE_UPLOAD_FORMATS),
            HAXCMSMediaSettingsService::getDefaultAcceptedFormats()
        );
        foreach (array('xml', 'vtt', 'pdf', 'h5p', 'jpg') as $ext) {
            $this->assertTrue(HAXCMSMediaSettingsService::isUploadFormatAccepted(array(), $ext), $ext);
        }
    }

    public function testSavedListNarrowsUploads(): void
    {
        $settings = HAXCMSMediaSettingsService::normalizeMediaSettings(array('acceptedFormats' => 'png,pdf,.CSV'));
        $this->assertTrue(HAXCMSMediaSettingsService::isUploadFormatAccepted($settings, 'png'));
        $this->assertTrue(HAXCMSMediaSettingsService::isUploadFormatAccepted($settings, '.PDF'));
        $this->assertTrue(HAXCMSMediaSettingsService::isUploadFormatAccepted($settings, 'csv'));
        $this->assertFalse(HAXCMSMediaSettingsService::isUploadFormatAccepted($settings, 'jpg'));
        $this->assertFalse(HAXCMSMediaSettingsService::isUploadFormatAccepted($settings, 'vtt'));
    }

    public function testSavedListNeverWidensTheSafeList(): void
    {
        $settings = HAXCMSMediaSettingsService::normalizeMediaSettings(array('acceptedFormats' => 'png,svg,exe,php'));
        $this->assertTrue(HAXCMSMediaSettingsService::isUploadFormatAccepted($settings, 'png'));
        foreach (array('svg', 'exe', 'php', '') as $ext) {
            $this->assertFalse(HAXCMSMediaSettingsService::isUploadFormatAccepted($settings, $ext), $ext);
        }
    }

    public function testSavedEarlierDefaultReadsAsTheDefault(): void
    {
        foreach (array('jpg,jpeg,png,gif,webp,svg', 'jpg,jpeg,png,webp,gif') as $legacy) {
            $settings = HAXCMSMediaSettingsService::normalizeMediaSettings(array('acceptedFormats' => $legacy));
            $effective = HAXCMSMediaSettingsService::getEffectiveMediaSettings($settings);
            $this->assertSame(HAXCMSMediaSettingsService::getDefaultAcceptedFormats(), $effective['acceptedFormats']);
            $this->assertTrue(HAXCMSMediaSettingsService::isUploadFormatAccepted($settings, 'pdf'));
        }
    }

    public function testEffectiveDefaultsMatchNode(): void
    {
        $this->assertSame(
            array(
                'jpegQuality' => 80,
                'maxUploadSizeMb' => 256,
                'acceptedFormats' => HAXCMSMediaSettingsService::getDefaultAcceptedFormats(),
            ),
            HAXCMSMediaSettingsService::getEffectiveMediaSettings(array())
        );
    }
}

<?php
trait OperationsRouteSaveManifest {
  public function saveManifest() {
    if (isset($this->params['site_token']) && $GLOBALS['HAXCMS']->validateRequestToken($this->params['site_token'], $this->getSiteTokenUserName() . ':' . $this->params['site']['name'])) {
      // load the site from name
      $site = $GLOBALS['HAXCMS']->loadSite($this->params['site']['name']);

      // preserve existing platform settings regardless of what the client sends
      // (platform settings are saved via savePlatformSettings)
      $existingPlatform = null;
      if (isset($site->manifest->metadata) && isset($site->manifest->metadata->platform)) {
        $existingPlatform = $site->manifest->metadata->platform;
      }
      
      // Check platform configuration
      if (!$this->platformAllows($site, 'siteManifest')) {
        return array(
          '__failed' => array(
            'status' => 403,
            'message' => 'Manifest editing is disabled for this site',
          )
        );
      }
      // standard form submit
      // @todo 
      // make the form point to a form submission endpoint with appropriate name
      // add a hidden field to the output that always has the haxcms_form_id as well
      // as a dynamically generated Request token relative to the name of the
      // form
      // pull the form schema for the form itself internally
      // ensure ONLY the things that appear in that schema get set
      // if something DID NOT COME ACROSS, don't unset it, only set what shows up
      // if something DID COME ACROSS WE DIDN'T SET, kill the transaction (xss)

      // - snag the form
      // @todo see if we can dynamically save the valus in the same format we loaded
      // the original form in. This would involve removing the vast majority of
      // what's below
      /*if ($GLOBALS['HAXCMS']->validateRequestToken(null, 'form')) {
        $context = array(
          'site' => array(),
          'node' => array(),
        );
        if (isset($this->params['site'])) {
          $context['site'] = $this->params['site'];
        }
        if (isset($this->params['node'])) {
          $context['node'] = $this->params['node'];
        }
        $form = $GLOBALS['HAXCMS']->loadForm($this->params['haxcms_form_id'], $context);
      }*/
      $isScopedDetailsPayload = $this->isScopedDetailsManifestPayload($this->params);
      $formToken = isset($this->params['haxcms_form_token']) ? $this->params['haxcms_form_token'] : null;
      $formId = isset($this->params['haxcms_form_id']) ? $this->params['haxcms_form_id'] : null;
      if ($isScopedDetailsPayload || $GLOBALS['HAXCMS']->validateRequestToken($formToken, $formId)) {
        if ($isScopedDetailsPayload) {
          $this->applyScopedDetailsManifestPayload($site, $this->params);
        }
        else {
        $site->manifest->title = strip_tags(
            $this->params['manifest']['site']['manifest-title']
        );
        $site->manifest->description = strip_tags(
            $this->params['manifest']['site']['manifest-description']
        );
        // the running HAXcms version is never written to site.json: the file is
        // public and the version must not be shown to unauthenticated users.
        // Scrub values older saves left behind.
        Operations::stripPublicVersion($site->manifest);
        $site->manifest->metadata->site->domain = filter_var(
            $this->params['manifest']['site']['manifest-metadata-site-domain'],
            FILTER_SANITIZE_URL
        );
        $site->manifest->metadata->site->domain = SanitizeContent::sanitizeURLValue(
          $site->manifest->metadata->site->domain,
          ''
        );
        // only update the logo when it came across; if it did not come across
        // don't unset the existing value (matches the form-save contract above)
        if (isset($this->params['manifest']['site']['manifest-metadata-site-logo'])) {
          $site->manifest->metadata->site->logo = filter_var(
              $this->params['manifest']['site']['manifest-metadata-site-logo'],
              FILTER_SANITIZE_URL
          );
          $site->manifest->metadata->site->logo = SanitizeContent::sanitizeURLValue(
            $site->manifest->metadata->site->logo,
            ''
          );
        }
        $site->manifest->metadata->site->tags = filter_var(
          $this->params['manifest']['site']['manifest-metadata-site-tags'],
          FILTER_SANITIZE_FULL_SPECIAL_CHARS
        );
        if (!isset($site->manifest->metadata->site->static)) {
          $site->manifest->metadata->site->static = new stdClass();
        }
        if (!isset($site->manifest->metadata->site->settings)) {
          $site->manifest->metadata->site->settings = new stdClass();
        }
        if (isset($this->params['manifest']['site']['manifest-domain'])) {
            $domain = filter_var(
                $this->params['manifest']['site']['manifest-domain'],
                FILTER_SANITIZE_URL
            );
            $domain = SanitizeContent::sanitizeURLValue($domain, '');
            // support updating the domain CNAME value
            if ($site->manifest->metadata->site->domain != $domain) {
                $site->manifest->metadata->site->domain = $domain;
                // the site's own folder, as haxcms-nodejs writes it
                // (<site>/CNAME); this read manifest->site->name, which does
                // not exist, so the file went to the shared sites folder
                @file_put_contents(
                    $site->directory .
                        '/' .
                        $site->manifest->metadata->site->name .
                        '/CNAME',
                    $domain
                );
            }
        }
        // the design system is the site's choice, not the theme's: keep it
        // when the theme changes
        $keptDesignSystem = (
          isset($site->manifest->metadata->theme->designSystem) &&
          is_string($site->manifest->metadata->theme->designSystem)
        ) ? $site->manifest->metadata->theme->designSystem : '';
        // look for a match so we can set the correct data
        foreach ($GLOBALS['HAXCMS']->getThemes() as $key => $theme) {
          if (
              filter_var($this->params['manifest']['theme']['manifest-metadata-theme-element'], FILTER_SANITIZE_FULL_SPECIAL_CHARS) ==
              $key
          ) {
              // a copy, so the edits below never reach the shared themes list
              $site->manifest->metadata->theme = json_decode(json_encode($theme));
          }
        }
        if ($keptDesignSystem !== '') {
          $site->manifest->metadata->theme->designSystem = $keptDesignSystem;
        }
        if (array_key_exists('manifest-metadata-theme-designSystem', $this->params['manifest']['theme'])) {
          // same rule as saveAppearanceSettings: '' or 'ddd' is DDD (no
          // value); anything else must be a lowercase name, or it is ignored
          $designSystem = $this->normalizeDesignSystem(
            $this->params['manifest']['theme']['manifest-metadata-theme-designSystem']
          );
          if ($designSystem === '') {
            unset($site->manifest->metadata->theme->designSystem);
          }
          else if ($designSystem !== false) {
            $site->manifest->metadata->theme->designSystem = $designSystem;
          }
        }
        if (!isset($site->manifest->metadata->theme->variables)) {
          $site->manifest->metadata->theme->variables = new stdClass();
        }
        if (isset($this->params['manifest']['theme']['manifest-metadata-theme-variables-image'])) {
          $site->manifest->metadata->theme->variables->image = filter_var(
            $this->params['manifest']['theme']['manifest-metadata-theme-variables-image'],FILTER_SANITIZE_URL
          );
          $site->manifest->metadata->theme->variables->image = SanitizeContent::sanitizeURLValue(
            $site->manifest->metadata->theme->variables->image,
            ''
          );
        }
        if (isset($this->params['manifest']['theme']['manifest-metadata-theme-variables-imageAlt'])) {
          $site->manifest->metadata->theme->variables->imageAlt = filter_var(
            $this->params['manifest']['theme']['manifest-metadata-theme-variables-imageAlt'], FILTER_SANITIZE_FULL_SPECIAL_CHARS
          );
        }
        if (isset($this->params['manifest']['theme']['manifest-metadata-theme-variables-imageLink'])) {
          $site->manifest->metadata->theme->variables->imageLink = filter_var(
            $this->params['manifest']['theme']['manifest-metadata-theme-variables-imageLink'], FILTER_SANITIZE_URL
          );
          $site->manifest->metadata->theme->variables->imageLink = SanitizeContent::sanitizeURLValue(
            $site->manifest->metadata->theme->variables->imageLink,
            ''
          );
        }
        // REGIONS SUPPORT
        if (!isset($site->manifest->metadata->theme->regions)) {
          $site->manifest->metadata->theme->regions = new stdClass();
        }
        // look for a match so we can set the correct data
        $validRegions = array(
          "header",
          "sidebarFirst",
          "sidebarSecond",
          "contentTop",
          "contentBottom",
          "footerPrimary",
          "footerSecondary"
        );
        foreach ($validRegions as $i => $value) {
          if (isset($this->params['manifest']['theme']['manifest-metadata-theme-regions-' . $value])) {
            foreach ($this->params['manifest']['theme']['manifest-metadata-theme-regions-' . $value] as $j => $id) {
              $this->params['manifest']['theme']['manifest-metadata-theme-regions-' . $value][$j] = filter_var($id, FILTER_SANITIZE_FULL_SPECIAL_CHARS);
            }
            $site->manifest->metadata->theme->regions->{$value} = $this->params['manifest']['theme']['manifest-metadata-theme-regions-' . $value];
          }
        }
        if (isset($this->params['manifest']['theme']['manifest-metadata-theme-variables-hexCode'])) {
          $site->manifest->metadata->theme->variables->hexCode = filter_var(
            $this->params['manifest']['theme']['manifest-metadata-theme-variables-hexCode'],FILTER_SANITIZE_FULL_SPECIAL_CHARS
          );
        }
        $site->manifest->metadata->theme->variables->cssVariable = "--simple-colors-default-theme-" . filter_var(
          $this->params['manifest']['theme']['manifest-metadata-theme-variables-cssVariable'], FILTER_SANITIZE_FULL_SPECIAL_CHARS
        ). "-7";
        if (isset($this->params['manifest']['theme']['manifest-metadata-theme-variables-palette'])) {
          $palette = filter_var(
            $this->params['manifest']['theme']['manifest-metadata-theme-variables-palette'],
            FILTER_SANITIZE_FULL_SPECIAL_CHARS
          );
          if (is_string($palette)) {
            $palette = strtolower(trim($palette));
            if ($palette === '') {
              if (isset($site->manifest->metadata->theme->variables->palette)) {
                unset($site->manifest->metadata->theme->variables->palette);
              }
            }
            else if (preg_match('/^[a-z0-9-]+$/', $palette)) {
              $site->manifest->metadata->theme->variables->palette = $palette;
            }
          }
        }
        $site->manifest->metadata->theme->variables->icon = filter_var(
          $this->params['manifest']['theme']['manifest-metadata-theme-variables-icon'],FILTER_SANITIZE_FULL_SPECIAL_CHARS
        );
        if (isset($this->params['manifest']['author']['manifest-license'])) {
            $site->manifest->license = filter_var(
                $this->params['manifest']['author']['manifest-license'],
                FILTER_SANITIZE_FULL_SPECIAL_CHARS
            );
            if (!isset($site->manifest->metadata->author)) {
              $site->manifest->metadata->author = new stdClass();
            }
            $site->manifest->metadata->author->image = filter_var(
                $this->params['manifest']['author']['manifest-metadata-author-image'],
                FILTER_SANITIZE_URL
            );
            $site->manifest->metadata->author->image = SanitizeContent::sanitizeURLValue(
              $site->manifest->metadata->author->image,
              ''
            );
            $site->manifest->metadata->author->name = filter_var(
                $this->params['manifest']['author']['manifest-metadata-author-name'],
                FILTER_SANITIZE_FULL_SPECIAL_CHARS
            );
            $site->manifest->metadata->author->email = filter_var(
                $this->params['manifest']['author']['manifest-metadata-author-email'],
                FILTER_SANITIZE_EMAIL
            );
            $site->manifest->metadata->author->socialLink = filter_var(
                $this->params['manifest']['author']['manifest-metadata-author-socialLink'],
                FILTER_SANITIZE_URL
            );
            $site->manifest->metadata->author->socialLink = SanitizeContent::sanitizeURLValue(
              $site->manifest->metadata->author->socialLink,
              ''
            );
            if (isset($this->params['manifest']['author']['manifest-metadata-author-socialLink2'])) {
              $site->manifest->metadata->author->socialLink2 = filter_var(
                $this->params['manifest']['author']['manifest-metadata-author-socialLink2'],
                FILTER_SANITIZE_URL
              );
              $site->manifest->metadata->author->socialLink2 = SanitizeContent::sanitizeURLValue(
                $site->manifest->metadata->author->socialLink2,
                ''
              );
            }
            if (isset($this->params['manifest']['author']['manifest-metadata-author-phone'])) {
              $site->manifest->metadata->author->phone = filter_var(
                $this->params['manifest']['author']['manifest-metadata-author-phone'],
                FILTER_SANITIZE_FULL_SPECIAL_CHARS
              );
            }
            if (isset($this->params['manifest']['author']['manifest-metadata-author-location'])) {
              $site->manifest->metadata->author->location = filter_var(
                $this->params['manifest']['author']['manifest-metadata-author-location'],
                FILTER_SANITIZE_FULL_SPECIAL_CHARS
              );
            }
            if (isset($this->params['manifest']['author']['manifest-metadata-author-website'])) {
              $site->manifest->metadata->author->website = filter_var(
                $this->params['manifest']['author']['manifest-metadata-author-website'],
                FILTER_SANITIZE_URL
              );
              $site->manifest->metadata->author->website = SanitizeContent::sanitizeURLValue(
                $site->manifest->metadata->author->website,
                ''
              );
            }
            if (isset($this->params['manifest']['author']['manifest-metadata-author-website2'])) {
              $site->manifest->metadata->author->website2 = filter_var(
                $this->params['manifest']['author']['manifest-metadata-author-website2'],
                FILTER_SANITIZE_URL
              );
              $site->manifest->metadata->author->website2 = SanitizeContent::sanitizeURLValue(
                $site->manifest->metadata->author->website2,
                ''
              );
            }
        }
        if (isset($this->params['manifest']['seo']['manifest-metadata-site-settings-private'])) {
          $site->manifest->metadata->site->settings->private = filter_var(
          $this->params['manifest']['seo']['manifest-metadata-site-settings-private'],
          FILTER_VALIDATE_BOOLEAN
          );
        }
        if (isset($this->params['manifest']['seo']['manifest-metadata-site-settings-canonical'])) {
          $site->manifest->metadata->site->settings->canonical = filter_var(
          $this->params['manifest']['seo']['manifest-metadata-site-settings-canonical'],
          FILTER_VALIDATE_BOOLEAN
          );
        }
        if (isset($this->params['manifest']['seo']['manifest-metadata-site-settings-lang'])) {
          $site->manifest->metadata->site->settings->lang = filter_var(
          $this->params['manifest']['seo']['manifest-metadata-site-settings-lang'],
          FILTER_SANITIZE_FULL_SPECIAL_CHARS
          );
        }
        if (isset($this->params['manifest']['seo']['manifest-metadata-site-settings-pathauto'])) {
          $site->manifest->metadata->site->settings->pathauto = filter_var(
          $this->params['manifest']['seo']['manifest-metadata-site-settings-pathauto'],
          FILTER_VALIDATE_BOOLEAN
          );
        }
        if (isset($this->params['manifest']['seo']['manifest-metadata-site-settings-publishPagesOn'])) {
          $site->manifest->metadata->site->settings->publishPagesOn = filter_var(
          $this->params['manifest']['seo']['manifest-metadata-site-settings-publishPagesOn'],
          FILTER_VALIDATE_BOOLEAN
          );
        }
        if (isset($this->params['manifest']['seo']['manifest-metadata-site-settings-sw'])) {
          $site->manifest->metadata->site->settings->sw = filter_var(
          $this->params['manifest']['seo']['manifest-metadata-site-settings-sw'],
          FILTER_VALIDATE_BOOLEAN
          );
        }
        if (isset($this->params['manifest']['seo']['manifest-metadata-site-settings-forceUpgrade'])) {
          $site->manifest->metadata->site->settings->forceUpgrade = filter_var(
          $this->params['manifest']['seo']['manifest-metadata-site-settings-forceUpgrade'],
          FILTER_VALIDATE_BOOLEAN
          );
        }
        if (isset($this->params['manifest']['seo']['manifest-metadata-site-settings-gaID'])) {
          $site->manifest->metadata->site->settings->gaID = filter_var(
          $this->params['manifest']['seo']['manifest-metadata-site-settings-gaID'],
          FILTER_SANITIZE_FULL_SPECIAL_CHARS
          );
        }
        // Handle homepage setting - validate it exists in the site outline
        if (isset($this->params['manifest']['site']['manifest-metadata-site-homePageId'])) {
          $homePageId = filter_var(
            $this->params['manifest']['site']['manifest-metadata-site-homePageId'],
            FILTER_SANITIZE_FULL_SPECIAL_CHARS
          );
          // Validate that the page exists in the site manifest
          $validPage = false;
          if ($homePageId && $homePageId !== '' && $site->manifest->items) {
            foreach ($site->manifest->items as $item) {
              if ($item->id === $homePageId) {
                $validPage = true;
                break;
              }
            }
          }
          // Only set if valid, otherwise leave as null/unset
          if ($validPage) {
            $site->manifest->metadata->site->homePageId = $homePageId;
          } else {
            // Remove the setting if it was previously set but is now invalid
            if (isset($site->manifest->metadata->site->homePageId)) {
              unset($site->manifest->metadata->site->homePageId);
            }
            // Also remove from settings path in case it was previously saved there
            if (isset($site->manifest->metadata->site->settings->homePageId)) {
              unset($site->manifest->metadata->site->settings->homePageId);
            }
          }
        }
        }
        // ensure platform exists; do not overwrite existing platform settings
        if (!isset($site->manifest->metadata->platform)) {
          $site->manifest->metadata->platform = new stdClass();
          $site->manifest->metadata->platform->audience = 'expert';
          $site->manifest->metadata->platform->features = new stdClass();
          $site->manifest->metadata->platform->allowedBlocks = array();
        }
        if (!is_null($existingPlatform)) {
          $site->manifest->metadata->platform = $existingPlatform;
        }

        $site->manifest->metadata->site->updated = time();
        // DRAFT plugin platform (haxtheweb/issues#3356, mirrors haxcms-nodejs#138): plugins see the proposed site
        // record (entity.before.update on site, aliased as manifest.before.save) and may veto; the stored record
        // before this request is ctx.previous
        $pluginSiteRecord = null;
        $pluginSitePrevious = null;
        if (getenv('HAXCMS_PLUGINS') !== 'off') {
          include_once __DIR__ . '/../plugins/HAXCMSPluginSystem.php';
          $stored = $GLOBALS['HAXCMS']->loadSite($this->params['site']['name']);
          $pluginSitePrevious = array(
            'id' => $stored->manifest->id,
            'title' => $stored->manifest->title,
            'description' => $stored->manifest->description,
            'metadata' => json_decode(json_encode($stored->manifest->metadata ?: new stdClass()), true),
          );
          $proposed = array(
            'id' => $site->manifest->id,
            'title' => $site->manifest->title,
            'description' => $site->manifest->description,
            'metadata' => json_decode(json_encode($site->manifest->metadata ?: new stdClass()), true),
          );
          $pre = HAXCMSPluginSystem::before($site, 'site', $proposed, $pluginSitePrevious, array('user' => HAXCMSPluginSystem::userRecord($this->getSiteTokenUserName())));
          if (!empty($pre['veto'])) {
            return HAXCMSPluginSystem::vetoFailure($pre['veto']);
          }
          $pluginSiteRecord = $pre['record'];
          if (isset($pluginSiteRecord['metadata']['plugins'])) {
            $site->manifest->metadata->plugins = json_decode(json_encode($pluginSiteRecord['metadata']['plugins'] ?: new stdClass()));
          }
        }
        // don't reorganize the structure
        $site->manifest->save(false);
        $site->gitCommit('Manifest updated');
        if ($pluginSiteRecord) {
          HAXCMSPluginSystem::after($site, 'site', $pluginSiteRecord, $pluginSitePrevious);
        }
        // rebuild the files that twig processes
        $site->rebuildManagedFiles();
        $site->updateAlternateFormats();
        $site->gitCommit('Managed files updated');
        return $site->manifest;
      }
      else {
        return array(
          '__failed' => array(
            'status' => 403,
            'message' => 'invalid request token',
          )
        );
      }
    }
    else {
      return array(
        '__failed' => array(
          'status' => 403,
          'message' => 'invalid site token',
        )
      );
    }
  }
}

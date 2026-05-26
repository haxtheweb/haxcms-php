<?php
trait OperationsRouteSaveManifest {
  /**
   * @OA\Post(
   *    path="/saveManifest",
   *    tags={"cms","authenticated"},
   *    @OA\Parameter(
   *         name="site_token",
   *         description="Site-specific validation token",
   *         in="query",
   *         required=true,
   *         @OA\Schema(type="string")
   *    ),
   *    @OA\Response(
   *        response="200",
   *        description="Save the manifest of the site"
   *   )
   * )
   */
  public function saveManifest() {
    if (isset($this->params['site_token']) && $GLOBALS['HAXCMS']->validateRequestToken($this->params['site_token'], $GLOBALS['HAXCMS']->getActiveUserName() . ':' . $this->params['site']['name'])) {
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
        // store version data here so we know where we were when last globally saved
        $site->manifest->metadata->site->version = $GLOBALS['HAXCMS']->getHAXCMSVersion();
        $site->manifest->metadata->site->domain = filter_var(
            $this->params['manifest']['site']['manifest-metadata-site-domain'],
            FILTER_SANITIZE_URL
        );
        $site->manifest->metadata->site->domain = SanitizeContent::sanitizeURLValue(
          $site->manifest->metadata->site->domain,
          ''
        );
        $site->manifest->metadata->site->logo = filter_var(
            $this->params['manifest']['site']['manifest-metadata-site-logo'],
            FILTER_SANITIZE_URL
        );
        $site->manifest->metadata->site->logo = SanitizeContent::sanitizeURLValue(
          $site->manifest->metadata->site->logo,
          ''
        );
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
                @file_put_contents(
                    $site->directory .
                        '/' .
                        $site->manifest->site->name .
                        '/CNAME',
                    $domain
                );
            }
        }
        // look for a match so we can set the correct data
        foreach ($GLOBALS['HAXCMS']->getThemes() as $key => $theme) {
          if (
              filter_var($this->params['manifest']['theme']['manifest-metadata-theme-element'], FILTER_SANITIZE_FULL_SPECIAL_CHARS) ==
              $key
          ) {
              $site->manifest->metadata->theme = $theme;
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
        // don't reorganize the structure
        $site->manifest->save(false);
        $site->gitCommit('Manifest updated');
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

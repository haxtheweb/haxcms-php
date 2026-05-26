<?php
trait OperationsRouteSaveAppearanceSettings {
  /**
   * @OA\Post(
   *    path="/saveAppearanceSettings",
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
   *        description="Save appearance settings into site.json metadata.theme"
   *   )
   * )
   */
  public function saveAppearanceSettings() {
    if (!isset($this->params['site']) || !isset($this->params['site']['name'])) {
      return array(
        '__failed' => array(
          'status' => 400,
          'message' => 'missing site name',
        )
      );
    }
    if (isset($this->params['site_token']) && $GLOBALS['HAXCMS']->validateRequestToken($this->params['site_token'], $GLOBALS['HAXCMS']->getActiveUserName() . ':' . $this->params['site']['name'])) {
      $site = $GLOBALS['HAXCMS']->loadSite($this->params['site']['name']);
      if (!$site || !isset($site->manifest)) {
        return array(
          '__failed' => array(
            'status' => 400,
            'message' => 'invalid site',
          )
        );
      }
      if (!$this->platformAllows($site, 'themeManifest')) {
        return array(
          '__failed' => array(
            'status' => 403,
            'message' => 'Theme settings are disabled for this site',
          )
        );
      }

      $siteParams = isset($this->params['site']) ? $this->params['site'] : null;
      if (is_object($siteParams)) {
        $siteParams = (array) $siteParams;
      }
      if (!$this->hasOnlyAllowedKeys($siteParams, array('name'))) {
        return array(
          '__failed' => array(
            'status' => 400,
            'message' => 'invalid site payload',
          )
        );
      }

      $manifestParams = isset($this->params['manifest']) ? $this->params['manifest'] : null;
      if (is_object($manifestParams)) {
        $manifestParams = (array) $manifestParams;
      }
      if (!$this->hasOnlyAllowedKeys($manifestParams, array('theme'))) {
        return array(
          '__failed' => array(
            'status' => 400,
            'message' => 'invalid manifest payload',
          )
        );
      }

      $themeParams = isset($manifestParams['theme']) ? $manifestParams['theme'] : null;
      if (is_object($themeParams)) {
        $themeParams = (array) $themeParams;
      }
      $regionFieldMap = array(
        'manifest-metadata-theme-regions-header' => 'header',
        'manifest-metadata-theme-regions-sidebarFirst' => 'sidebarFirst',
        'manifest-metadata-theme-regions-sidebarSecond' => 'sidebarSecond',
        'manifest-metadata-theme-regions-contentTop' => 'contentTop',
        'manifest-metadata-theme-regions-contentBottom' => 'contentBottom',
        'manifest-metadata-theme-regions-footerPrimary' => 'footerPrimary',
        'manifest-metadata-theme-regions-footerSecondary' => 'footerSecondary',
      );
      $allowedThemeKeys = array_merge(
        array(
          'manifest-metadata-theme-element',
          'manifest-metadata-theme-variables-image',
          'manifest-metadata-theme-variables-imageAlt',
          'manifest-metadata-theme-variables-imageLink',
          'manifest-metadata-theme-variables-cssVariable',
          'manifest-metadata-theme-variables-palette',
          'manifest-metadata-theme-variables-icon',
        ),
        array_keys($regionFieldMap)
      );
      if (!$this->hasOnlyAllowedKeys($themeParams, $allowedThemeKeys)) {
        return array(
          '__failed' => array(
            'status' => 400,
            'message' => 'invalid appearance payload',
          )
        );
      }

      if (!isset($site->manifest->metadata) || !is_object($site->manifest->metadata)) {
        $site->manifest->metadata = new stdClass();
      }
      if (!isset($site->manifest->metadata->site) || !is_object($site->manifest->metadata->site)) {
        $site->manifest->metadata->site = new stdClass();
      }
      if (!isset($site->manifest->metadata->theme) || !is_object($site->manifest->metadata->theme)) {
        $site->manifest->metadata->theme = new stdClass();
      }

      if (array_key_exists('manifest-metadata-theme-element', $themeParams)) {
        if (!is_string($themeParams['manifest-metadata-theme-element'])) {
          return array(
            '__failed' => array(
              'status' => 400,
              'message' => 'invalid theme element',
            )
          );
        }
        $themeElement = trim(filter_var($themeParams['manifest-metadata-theme-element'], FILTER_SANITIZE_FULL_SPECIAL_CHARS));
        if ($themeElement === '') {
          return array(
            '__failed' => array(
              'status' => 400,
              'message' => 'invalid theme element',
            )
          );
        }
        $themes = $GLOBALS['HAXCMS']->getThemes();
        $themeValue = null;
        if (is_object($themes) && isset($themes->{$themeElement})) {
          $themeValue = $themes->{$themeElement};
        }
        else if (is_array($themes) && isset($themes[$themeElement])) {
          $themeValue = $themes[$themeElement];
        }
        if (is_null($themeValue)) {
          return array(
            '__failed' => array(
              'status' => 400,
              'message' => 'invalid theme element',
            )
          );
        }
        $site->manifest->metadata->theme = $themeValue;
      }

      if (!isset($site->manifest->metadata->theme->variables) || !is_object($site->manifest->metadata->theme->variables)) {
        $site->manifest->metadata->theme->variables = new stdClass();
      }
      if (!isset($site->manifest->metadata->theme->regions) || !is_object($site->manifest->metadata->theme->regions)) {
        $site->manifest->metadata->theme->regions = new stdClass();
      }

      if (array_key_exists('manifest-metadata-theme-variables-image', $themeParams)) {
        $imageValue = $themeParams['manifest-metadata-theme-variables-image'];
        if (!is_null($imageValue) && !is_string($imageValue)) {
          return array(
            '__failed' => array(
              'status' => 400,
              'message' => 'invalid image value',
            )
          );
        }
        $site->manifest->metadata->theme->variables->image = SanitizeContent::sanitizeURLValue(
          filter_var($imageValue, FILTER_SANITIZE_URL),
          ''
        );
      }
      if (array_key_exists('manifest-metadata-theme-variables-imageAlt', $themeParams)) {
        $imageAltValue = $themeParams['manifest-metadata-theme-variables-imageAlt'];
        if (!is_null($imageAltValue) && !is_string($imageAltValue)) {
          return array(
            '__failed' => array(
              'status' => 400,
              'message' => 'invalid imageAlt value',
            )
          );
        }
        $site->manifest->metadata->theme->variables->imageAlt = filter_var(
          $imageAltValue,
          FILTER_SANITIZE_FULL_SPECIAL_CHARS
        );
      }
      if (array_key_exists('manifest-metadata-theme-variables-imageLink', $themeParams)) {
        $imageLinkValue = $themeParams['manifest-metadata-theme-variables-imageLink'];
        if (!is_null($imageLinkValue) && !is_string($imageLinkValue)) {
          return array(
            '__failed' => array(
              'status' => 400,
              'message' => 'invalid imageLink value',
            )
          );
        }
        $site->manifest->metadata->theme->variables->imageLink = SanitizeContent::sanitizeURLValue(
          filter_var($imageLinkValue, FILTER_SANITIZE_URL),
          ''
        );
      }
      if (array_key_exists('manifest-metadata-theme-variables-cssVariable', $themeParams)) {
        $cssVariable = $this->normalizeAppearanceCssVariable(
          $themeParams['manifest-metadata-theme-variables-cssVariable']
        );
        if ($cssVariable === false) {
          return array(
            '__failed' => array(
              'status' => 400,
              'message' => 'invalid cssVariable value',
            )
          );
        }
        $site->manifest->metadata->theme->variables->cssVariable =
          '--simple-colors-default-theme-' . $cssVariable . '-7';
      }
      if (array_key_exists('manifest-metadata-theme-variables-palette', $themeParams)) {
        $paletteValue = $themeParams['manifest-metadata-theme-variables-palette'];
        if (!is_null($paletteValue) && !is_string($paletteValue)) {
          return array(
            '__failed' => array(
              'status' => 400,
              'message' => 'invalid palette value',
            )
          );
        }
        if (is_null($paletteValue)) {
          if (isset($site->manifest->metadata->theme->variables->palette)) {
            unset($site->manifest->metadata->theme->variables->palette);
          }
        }
        else {
          $palette = filter_var($paletteValue, FILTER_SANITIZE_FULL_SPECIAL_CHARS);
          if (!is_string($palette)) {
            return array(
              '__failed' => array(
                'status' => 400,
                'message' => 'invalid palette value',
              )
            );
          }
          $palette = strtolower(trim($palette));
          if ($palette === '') {
            if (isset($site->manifest->metadata->theme->variables->palette)) {
              unset($site->manifest->metadata->theme->variables->palette);
            }
          }
          else if (preg_match('/^[a-z0-9-]+$/', $palette) === 1) {
            $site->manifest->metadata->theme->variables->palette = $palette;
          }
          else {
            return array(
              '__failed' => array(
                'status' => 400,
                'message' => 'invalid palette value',
              )
            );
          }
        }
      }
      if (array_key_exists('manifest-metadata-theme-variables-icon', $themeParams)) {
        $iconValue = $themeParams['manifest-metadata-theme-variables-icon'];
        if (!is_null($iconValue) && !is_string($iconValue)) {
          return array(
            '__failed' => array(
              'status' => 400,
              'message' => 'invalid icon value',
            )
          );
        }
        $site->manifest->metadata->theme->variables->icon = filter_var(
          $iconValue,
          FILTER_SANITIZE_FULL_SPECIAL_CHARS
        );
      }

      foreach ($regionFieldMap as $field => $regionName) {
        if (array_key_exists($field, $themeParams)) {
          $cleanRegionIds = $this->sanitizeAppearanceRegionIds($themeParams[$field]);
          if ($cleanRegionIds === false) {
            return array(
              '__failed' => array(
                'status' => 400,
                'message' => 'invalid region value',
              )
            );
          }
          $site->manifest->metadata->theme->regions->{$regionName} = $cleanRegionIds;
        }
      }

      $site->manifest->metadata->site->updated = time();
      $site->manifest->save(false);
      $site->gitCommit('Appearance settings updated');
      $site->rebuildManagedFiles();
      $site->updateAlternateFormats();
      $site->gitCommit('Managed files updated');

      return array(
        'status' => 200,
        'data' => array(
          'saved' => true,
          'appearance' => array(
            'theme' => true,
          )
        )
      );
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

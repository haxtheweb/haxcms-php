<?php
trait OperationsRouteSaveSeoSettings {
  /**
   * @OA\Post(
   *    path="/saveSeoSettings",
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
   *        description="Save SEO and author settings into site.json"
   *   )
   * )
   */
  public function saveSeoSettings() {
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
      if (!$this->platformAllows($site, 'seoManifest')) {
        return array(
          '__failed' => array(
            'status' => 403,
            'message' => 'SEO settings are disabled for this site',
          )
        );
      }
      if (!isset($site->manifest->metadata)) {
        $site->manifest->metadata = new stdClass();
      }
      if (!isset($site->manifest->metadata->site)) {
        $site->manifest->metadata->site = new stdClass();
      }
      if (!isset($site->manifest->metadata->site->settings)) {
        $site->manifest->metadata->site->settings = new stdClass();
      }
      if (!isset($site->manifest->metadata->author)) {
        $site->manifest->metadata->author = new stdClass();
      }

      $author = array();
      if (isset($this->params['author']) && is_array($this->params['author'])) {
        $author = $this->params['author'];
      }
      $seo = array();
      if (isset($this->params['seo']) && is_array($this->params['seo'])) {
        $seo = $this->params['seo'];
      }
      $manifestAuthor = array();
      if (
        isset($this->params['manifest']) &&
        isset($this->params['manifest']['author']) &&
        is_array($this->params['manifest']['author'])
      ) {
        $manifestAuthor = $this->params['manifest']['author'];
      }
      $manifestSeo = array();
      if (
        isset($this->params['manifest']) &&
        isset($this->params['manifest']['seo']) &&
        is_array($this->params['manifest']['seo'])
      ) {
        $manifestSeo = $this->params['manifest']['seo'];
      }

      $licenseValue = null;
      if (array_key_exists('license', $author)) {
        $licenseValue = $author['license'];
      }
      else if (array_key_exists('manifest.license', $manifestAuthor)) {
        $licenseValue = $manifestAuthor['manifest.license'];
      }
      if (!is_null($licenseValue)) {
        $site->manifest->license = filter_var(
          strval($licenseValue),
          FILTER_SANITIZE_FULL_SPECIAL_CHARS
        );
      }

      $authorImageValue = null;
      if (array_key_exists('image', $author)) {
        $authorImageValue = $author['image'];
      }
      else if (array_key_exists('manifest.metadata.author.image', $manifestAuthor)) {
        $authorImageValue = $manifestAuthor['manifest.metadata.author.image'];
      }
      if (!is_null($authorImageValue)) {
        $site->manifest->metadata->author->image = filter_var(
          strval($authorImageValue),
          FILTER_SANITIZE_URL
        );
        $site->manifest->metadata->author->image = SanitizeContent::sanitizeURLValue(
          $site->manifest->metadata->author->image,
          ''
        );
      }

      $authorNameValue = null;
      if (array_key_exists('name', $author)) {
        $authorNameValue = $author['name'];
      }
      else if (array_key_exists('manifest.metadata.author.name', $manifestAuthor)) {
        $authorNameValue = $manifestAuthor['manifest.metadata.author.name'];
      }
      if (!is_null($authorNameValue)) {
        $site->manifest->metadata->author->name = filter_var(
          strval($authorNameValue),
          FILTER_SANITIZE_FULL_SPECIAL_CHARS
        );
      }

      $authorEmailValue = null;
      if (array_key_exists('email', $author)) {
        $authorEmailValue = $author['email'];
      }
      else if (array_key_exists('manifest.metadata.author.email', $manifestAuthor)) {
        $authorEmailValue = $manifestAuthor['manifest.metadata.author.email'];
      }
      if (!is_null($authorEmailValue)) {
        $site->manifest->metadata->author->email = filter_var(
          strval($authorEmailValue),
          FILTER_SANITIZE_EMAIL
        );
      }

      $authorSocialLinkValue = null;
      if (array_key_exists('socialLink', $author)) {
        $authorSocialLinkValue = $author['socialLink'];
      }
      else if (array_key_exists('manifest.metadata.author.socialLink', $manifestAuthor)) {
        $authorSocialLinkValue = $manifestAuthor['manifest.metadata.author.socialLink'];
      }
      if (!is_null($authorSocialLinkValue)) {
        $site->manifest->metadata->author->socialLink = filter_var(
          strval($authorSocialLinkValue),
          FILTER_SANITIZE_URL
        );
        $site->manifest->metadata->author->socialLink = SanitizeContent::sanitizeURLValue(
          $site->manifest->metadata->author->socialLink,
          ''
        );
      }

      $descriptionValue = null;
      if (array_key_exists('description', $seo)) {
        $descriptionValue = $seo['description'];
      }
      else if (array_key_exists('manifest.description', $manifestSeo)) {
        $descriptionValue = $manifestSeo['manifest.description'];
      }
      if (!is_null($descriptionValue)) {
        $site->manifest->description = filter_var(
          strval($descriptionValue),
          FILTER_SANITIZE_FULL_SPECIAL_CHARS
        );
      }

      $logoValue = null;
      if (array_key_exists('logo', $seo)) {
        $logoValue = $seo['logo'];
      }
      else if (array_key_exists('manifest.metadata.site.logo', $manifestSeo)) {
        $logoValue = $manifestSeo['manifest.metadata.site.logo'];
      }
      if (!is_null($logoValue)) {
        $site->manifest->metadata->site->logo = filter_var(
          strval($logoValue),
          FILTER_SANITIZE_URL
        );
        $site->manifest->metadata->site->logo = SanitizeContent::sanitizeURLValue(
          $site->manifest->metadata->site->logo,
          ''
        );
      }

      $domainValue = null;
      if (array_key_exists('domain', $seo)) {
        $domainValue = $seo['domain'];
      }
      else if (array_key_exists('manifest.metadata.site.domain', $manifestSeo)) {
        $domainValue = $manifestSeo['manifest.metadata.site.domain'];
      }
      if (!is_null($domainValue)) {
        $site->manifest->metadata->site->domain = filter_var(
          strval($domainValue),
          FILTER_SANITIZE_URL
        );
        $site->manifest->metadata->site->domain = SanitizeContent::sanitizeURLValue(
          $site->manifest->metadata->site->domain,
          ''
        );
      }

      $langValue = null;
      if (array_key_exists('lang', $seo)) {
        $langValue = $seo['lang'];
      }
      else if (array_key_exists('manifest.metadata.site.settings.lang', $manifestSeo)) {
        $langValue = $manifestSeo['manifest.metadata.site.settings.lang'];
      }
      if (!is_null($langValue)) {
        $site->manifest->metadata->site->settings->lang = filter_var(
          strval($langValue),
          FILTER_SANITIZE_FULL_SPECIAL_CHARS
        );
      }

      $gaIDValue = null;
      if (array_key_exists('gaID', $seo)) {
        $gaIDValue = $seo['gaID'];
      }
      else if (array_key_exists('manifest.metadata.site.settings.gaID', $manifestSeo)) {
        $gaIDValue = $manifestSeo['manifest.metadata.site.settings.gaID'];
      }
      if (!is_null($gaIDValue)) {
        $site->manifest->metadata->site->settings->gaID = filter_var(
          strval($gaIDValue),
          FILTER_SANITIZE_FULL_SPECIAL_CHARS
        );
      }

      $privateInput = null;
      $privateHasValue = false;
      if (array_key_exists('private', $seo)) {
        $privateInput = $seo['private'];
        $privateHasValue = true;
      }
      else if (array_key_exists('manifest.metadata.site.settings.private', $manifestSeo)) {
        $privateInput = $manifestSeo['manifest.metadata.site.settings.private'];
        $privateHasValue = true;
      }
      if ($privateHasValue && !is_null($privateInput) && $privateInput !== '') {
        $privateValue = filter_var(
          $privateInput,
          FILTER_VALIDATE_BOOLEAN,
          FILTER_NULL_ON_FAILURE
        );
        if (!is_null($privateValue)) {
          $site->manifest->metadata->site->settings->private = $privateValue;
        }
      }

      $canonicalInput = null;
      $canonicalHasValue = false;
      if (array_key_exists('canonical', $seo)) {
        $canonicalInput = $seo['canonical'];
        $canonicalHasValue = true;
      }
      else if (array_key_exists('manifest.metadata.site.settings.canonical', $manifestSeo)) {
        $canonicalInput = $manifestSeo['manifest.metadata.site.settings.canonical'];
        $canonicalHasValue = true;
      }
      if ($canonicalHasValue && !is_null($canonicalInput) && $canonicalInput !== '') {
        $canonicalValue = filter_var(
          $canonicalInput,
          FILTER_VALIDATE_BOOLEAN,
          FILTER_NULL_ON_FAILURE
        );
        if (!is_null($canonicalValue)) {
          $site->manifest->metadata->site->settings->canonical = $canonicalValue;
        }
      }

      $pathautoInput = null;
      $pathautoHasValue = false;
      if (array_key_exists('pathauto', $seo)) {
        $pathautoInput = $seo['pathauto'];
        $pathautoHasValue = true;
      }
      else if (array_key_exists('manifest.metadata.site.settings.pathauto', $manifestSeo)) {
        $pathautoInput = $manifestSeo['manifest.metadata.site.settings.pathauto'];
        $pathautoHasValue = true;
      }
      if ($pathautoHasValue && !is_null($pathautoInput) && $pathautoInput !== '') {
        $pathautoValue = filter_var(
          $pathautoInput,
          FILTER_VALIDATE_BOOLEAN,
          FILTER_NULL_ON_FAILURE
        );
        if (!is_null($pathautoValue)) {
          $site->manifest->metadata->site->settings->pathauto = $pathautoValue;
        }
      }

      $publishPagesOnInput = null;
      $publishPagesOnHasValue = false;
      if (array_key_exists('publishPagesOn', $seo)) {
        $publishPagesOnInput = $seo['publishPagesOn'];
        $publishPagesOnHasValue = true;
      }
      else if (array_key_exists('manifest.metadata.site.settings.publishPagesOn', $manifestSeo)) {
        $publishPagesOnInput = $manifestSeo['manifest.metadata.site.settings.publishPagesOn'];
        $publishPagesOnHasValue = true;
      }
      if ($publishPagesOnHasValue && !is_null($publishPagesOnInput) && $publishPagesOnInput !== '') {
        $publishPagesOnValue = filter_var(
          $publishPagesOnInput,
          FILTER_VALIDATE_BOOLEAN,
          FILTER_NULL_ON_FAILURE
        );
        if (!is_null($publishPagesOnValue)) {
          $site->manifest->metadata->site->settings->publishPagesOn = $publishPagesOnValue;
        }
      }

      $site->manifest->metadata->site->updated = time();
      $site->manifest->save(false);
      $site->gitCommit('SEO settings updated');
      return $site->manifest;
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

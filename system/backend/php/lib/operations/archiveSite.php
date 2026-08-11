<?php
trait OperationsRouteArchiveSite {
  public function archiveSite() {
    if (isset($this->params['user_token']) && $GLOBALS['HAXCMS']->validateRequestToken($this->params['user_token'], $GLOBALS['HAXCMS']->getActiveUserName())) {
      $site = $GLOBALS['HAXCMS']->loadSite($this->params['site']['name']);
      if ($site->manifest->metadata->site->name) {
        $siteName = $site->manifest->metadata->site->name;
        $archivedRoot = HAXCMS_ROOT . '/' . $GLOBALS['HAXCMS']->archivedDirectory;
        // ensure archived directory exists
        if (!file_exists($archivedRoot)) {
          mkdir($archivedRoot, 0755, true);
        }
        $sourcePath = HAXCMS_ROOT . '/' . $GLOBALS['HAXCMS']->sitesDirectory . '/' . $siteName;
        $destinationPath = $archivedRoot . '/' . $siteName;
        // collision handling: find next available path if destination exists
        if (file_exists($destinationPath)) {
          $index = 1;
          $candidatePath = $archivedRoot . '/' . $siteName . '-' . $index;
          while (file_exists($candidatePath)) {
            $index += 1;
            $candidatePath = $archivedRoot . '/' . $siteName . '-' . $index;
          }
          $destinationPath = $candidatePath;
        }
        $renameResult = rename($sourcePath, $destinationPath);
        if ($renameResult === false) {
          return array(
            '__failed' => array(
              'status' => 500,
              'message' => 'Unable to archive site',
            )
          );
        }
        $archivedName = basename($destinationPath);
        $detail = ($archivedName === $siteName)
          ? 'Site archived'
          : 'Site archived as ' . $archivedName . ' because an archived copy already existed';
        return array(
          'status' => 200,
          'data' => array(
            'name' => $siteName,
            'detail' => $detail,
            'archivedName' => $archivedName,
          )
        );
      }
      else {
        return array(
          '__failed' => array(
            'status' => 500,
            'message' => 'Site does not exist',
          )
        );
      }
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
}

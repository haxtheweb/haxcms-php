<?php
trait OperationsRouteHaxiamAddUserAccess {
  public function haxiamAddUserAccess() {
    // Only allow this operation in HAXIAM mode
    if (!isset($GLOBALS['HAXCMS']->config->iam) || !$GLOBALS['HAXCMS']->config->iam) {
      return array(
        '__failed' => array(
          'status' => 400,
          'message' => 'HAXIAM mode is not enabled',
        )
      );
    }

    // Validate user token for security (same as other user operations like archiveSite)
    if (!isset($this->params['user_token']) || !$GLOBALS['HAXCMS']->validateRequestToken($this->params['user_token'], $GLOBALS['HAXCMS']->getActiveUserName())) {
      return array(
        '__failed' => array(
          'status' => 403,
          'message' => 'invalid request token',
        )
      );
    }

    // Validate required parameters
    if (!isset($this->params['userName']) || empty(trim($this->params['userName']))) {
      return array(
        '__failed' => array(
          'status' => 400,
          'message' => 'userName is required',
        )
      );
    }

    if (!isset($this->params['siteName']) || empty(trim($this->params['siteName']))) {
      return array(
        '__failed' => array(
          'status' => 400,
          'message' => 'siteName is required',
        )
      );
    }

    // Clean and validate inputs using HAXCMS machine name sanitization
    $rawUserName = trim($this->params['userName']);
    $rawSiteName = trim($this->params['siteName']);
    
    // Validate and sanitize userName using the enhanced generateMachineName method
    $targetUserName = $GLOBALS['HAXCMS']->generateMachineName($rawUserName);
    if ($targetUserName !== $rawUserName || empty($targetUserName)) {
      return array(
        '__failed' => array(
          'status' => 400,
          'message' => 'userName must be a valid machine name (alphanumeric, hyphens, underscores only)',
        )
      );
    }
    
    // Validate and sanitize siteName using the enhanced generateMachineName method
    $siteName = $GLOBALS['HAXCMS']->generateMachineName($rawSiteName);
    if ($siteName !== $rawSiteName || empty($siteName)) {
      return array(
        '__failed' => array(
          'status' => 400,
          'message' => 'siteName must be a valid machine name (alphanumeric, hyphens, underscores only)',
        )
      );
    }

    $currentUser = $GLOBALS['HAXCMS']->getActiveUserName();
    
    // Prevent self-access grants
    if ($targetUserName === $currentUser) {
      return array(
        '__failed' => array(
          'status' => 400,
          'message' => 'Cannot grant access to yourself',
        )
      );
    }

    // Validate that the target user exists in HAXIAM and has a sites directory
    if (!$this->_validateHAXIAMUser($targetUserName)) {
      return array(
        '__failed' => array(
          'status' => 403,
          'message' => 'User not found or has not set up HAXIAM yet',
        )
      );
    }

    // Validate that the current user owns the specified site
    if (!$this->_validateUserOwnsSite($currentUser, $siteName)) {
      return array(
        '__failed' => array(
          'status' => 403,
          'message' => 'You do not own this site or site does not exist',
        )
      );
    }

    // Create the symlink for the target user
    try {
      $result = $this->_createUserSiteSymlink($currentUser, $targetUserName, $siteName);
      if ($result['success']) {
        // Log the access grant
        error_log("HAXIAM: User '{$currentUser}' granted access to site '{$siteName}' to user '{$targetUserName}'");
        
        return array(
          'status' => 'success',
          'message' => 'User access granted successfully',
          'userName' => $targetUserName,
          'timestamp' => date('c')
        );
      } else {
        return array(
          '__failed' => array(
            'status' => 500,
            'message' => $result['error'],
          )
        );
      }
    } catch (Exception $e) {
      error_log("HAXIAM addUserAccess error: " . $e->getMessage());
      return array(
        '__failed' => array(
          'status' => 500,
          'message' => 'Failed to create user access',
        )
      );
    }
  }
}

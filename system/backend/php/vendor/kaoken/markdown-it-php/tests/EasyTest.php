<?php
namespace Kaoken\Test;


class EasyTest
{
    private $html;
    private $pageTitle;

    public function __construct($pageTitle="")
    {
        if( empty($pageTitle))$pageTitle="Test case!!";
        $this->pageTitle = $pageTitle;
        $this->viewHeader();
    }
    public function __destruct()
    {
        $this->viewFooter();
    }

    protected function viewHeader()
    {
        echo  <<< __HTML__
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="utf-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title> {$this->pageTitle} </title>

  <!-- Fonts -->
  <link href="https://fonts.googleapis.com/css?family=Lato:100,300,400,700" rel='stylesheet' type='text/css'>

  <!-- Styles -->
  <link href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/angular-ui-bootstrap/2.3.0/ui-bootstrap.min.js" rel="stylesheet">
</head>
<body id="app-layout" ng-app="myApp">
  <nav class="navbar navbar-inverse navbar-static-top">
    <div class="container-fluid">
    <!-- Brand and toggle get grouped for better mobile display -->
    <div class="navbar-header">
      <button type="button" class="navbar-toggle collapsed" data-toggle="collapse" data-target="#bs-example-navbar-collapse-1" aria-expanded="false">
        <span class="sr-only">Toggle navigation</span>
        <span class="icon-bar"></span>
        <span class="icon-bar"></span>
        <span class="icon-bar"></span>
      </button>
      <span class="navbar-brand"><i class="fa fa-check-square" aria-hidden="true"></i> {$this->pageTitle}</span>
    </div>
    </div>
  </nav>
  <div class="container" style="visibility:hidden" ng-style="{'visibility':'visible'}" ng-controller="TestCtrl">
    <uib-accordion close-others="oneAtATime">

__HTML__;
    }
    protected function viewFooter()
    {
        echo $this->html;
        echo  <<< __HTML__
    </uib-accordion>
  </div>
  <script src="https://code.jquery.com/jquery-2.2.4.min.js"></script>
  <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/angular.js/1.5.9/angular.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/angular.js/1.5.9/angular-sanitize.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/angular.js/1.5.9/angular-animate.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/angular.js/1.5.9/i18n/angular-locale_en.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/angular-ui-bootstrap/2.3.0/ui-bootstrap-tpls.min.js"></script>
  <script language="javascript">
    angular.module('myApp', ['ngAnimate', 'ngSanitize', 'ui.bootstrap']);
    angular.module('myApp').controller('TestCtrl', function () {
      var self = this;
      self.oneAtATime = true;
    });
  </script>
</body>
</html>
__HTML__;
    }

    /**
     * @param string   $title
     * @param callable $closure
     */
    public function group($title, $closure)
    {
        $g = new GroupTest($title);
        $closure($g);
        $this->html .= $g->getHtml();
    }

    protected function getLinesFromFile($path)
    {
        return preg_split('/\r?\n/',file_get_contents ($path));
    }
}



<?php
namespace Kaoken\Test;


class GroupTest
{
    private $html;
    private $errCount = 0;
    private $title;
    private $level=0;
    private $childCount = 0;
    private $itemCount = 0;


    /**
     * GroupTest constructor.
     * @param string $title
     * @param int $level
     */
    public function __construct($title, $level=0)
    {
        $this->title = $title;
        $this->level = $level;
    }

    public function getHtml()
    {
        $errCnt = $this->errCount;
        $sucCnt = $this->itemCount-$errCnt;

        if( $this->level === 0){
            $class = $this->errCount ? "panel-danger":"panel-success";
            $this->html = <<< __HTML__
    <div uib-accordion-group class="{$class}">
      <uib-accordion-heading>
        {$this->title}<span class="pull-right">Success[{$sucCnt}] Error[{$errCnt}]</span>
      </uib-accordion-heading>
      {$this->html}
    </div>
__HTML__;
        }else if( $this->level === 1) {
            $class = $this->errCount ? "panel-danger":"panel-success";
            $this->html = <<< __HTML__
<div class="panel {$class}">
    <div class="panel-heading">{$this->title}<span class="pull-right">Success[{$sucCnt}] Error[{$errCnt}]</span></div>
        <div class="panel-body">
        {$this->html}
    </div>
</div>
__HTML__;
        }else{
            $class = $this->errCount ? "alert-danger":"alert-success";
            $this->html = <<< __HTML__
    <div class="alert {$class}" role="alert">
        <h4>
            {$this->title}
            <span class="pull-right">Success[{$sucCnt}] Error[{$errCnt}]</span>
        </h4>
        {$this->html}
    </div>
__HTML__;
        }

        return $this->html;
    }
    public function getErrCount(){return $this->errCount;}
    public function getItemCount(){return$this->itemCount;}
    /**
     * @param string   $title
     * @param callable $closure
     */
    public function group($title, $closure)
    {
        $g = new GroupTest($title, $this->level+1);
        $closure($g);
        $this->html .= $g->getHtml();
        $this->errCount += $g->getErrCount();
        $this->itemCount += $g->getItemCount();
        $this->childCount++;
    }
    

    public function h1($title){ $this->html.= "<h1>".htmlentities($title)."</h1>\n"; }
    public function h2($title){ $this->html.= "<h2>".htmlentities($title)."</h2>\n"; }
    public function h3($title){ $this->html.= "<h3>".htmlentities($title)."</h3>\n"; }
    public function h4($title){ $this->html.= "<h4>".htmlentities($title)."</h4>\n"; }

    public function ok($is, $message="")
    {
        $this->itemCount++;
        if (!$is) $this->fail($is, true, $message, '==', 'ok');
        else $this->html .= $this->successText($message);
    }
    public function notOk($is, $message="")
    {
        $this->itemCount++;
        if ($is) $this->fail($is, false, $message, '!=', 'notOk');
        else $this->html .= $this->successText($message);
    }
    public function strictEqual($a, $b, $message="")
    {
        $this->itemCount++;
        if ($a !== $b)
            $this->fail($a, $b, $message, '===', 'strictEqual');
        else $this->html .= $this->successText($message);
    }
    public function notStrictEqual($a, $b, $message="")
    {
        $this->itemCount++;
        if ($a === $b) $this->fail($a, $b, $message, '!==', 'notStrictEqual');
        else $this->html .= $this->successText($message);
    }
    public function equal($a, $b, $message="")
    {
        $this->itemCount++;
        if ($a != $b) $this->fail($a, $b, $message, '==', 'equal');
        else $this->html .= $this->successText($message);
    }
    public function assert($a, $message){
        $this->itemCount++;
        if (!$a) $this->fail($a, '', $message, '', 'assert');
        else $this->html .= $this->successText($message);
    }
    public function notEqual($a, $b, $message="")
    {
        $this->itemCount++;
        if ($a == $b) $this->fail($a, $b, $message, '!=', 'notEqual');
        else $this->html .= $this->successText($message);
    }
    protected function _deepEqual($actual, $expected) {
        if (is_array($actual) === is_array($expected) ||
            is_object($actual) === is_object($expected)) {
            if(is_array($actual)){
                sort($actual);
                sort($expected);
            }
            return json_encode($actual) === json_encode($expected);
        } else if ($actual === $expected) {
            return true;
        }
        return false;
    }
    public function deepEqual($a, $b, $message="")
    {
        $this->itemCount++;
        if (!$this->_deepEqual($a,$b)) $this->fail($a, $b, $message, 'deepEqual', 'deepEqual');
        else $this->html .= $this->successText($message);
    }
    public function notDeepEqual($a, $b, $message="")
    {
        $this->itemCount++;
        if ($this->_deepEqual($a,$b,$message)) $this->fail($a, $b, $message, '!DeepEqual', 'notDeepEqual');
        else $this->html .= $this->successText($message);
    }

    public function throws($closure, $message="")
    {
        $this->itemCount++;
        if(!$this->_throws($closure, $message, true))
            $this->html .= $this->successText($message);
    }
    public function doesNotThrow($closure, $message="")
    {
        $this->itemCount++;
        if(!$this->_throws($closure, $message, false))
            $this->html .= $this->successText($message);
    }
    protected function _throws( $closure, $message, $type )
    {
        $actual = null;
        if( !is_callable($closure) )
            throw new \Exception('"throw" of "doesNotThrow", no closure.');

        try{
            $closure();
        }catch (\Exception $e){
            $actual = $e;
        }


        if (!$type && $actual) {
            $this->fail('"Closure"...', "Missing exception >> {$actual->getMessage()} >> ", $message, 'thow', 'thows');
            return true;
        }else if ($type && !$actual) {
            $this->fail('"Closure"...', "Got unwanted exception >> ", $message, 'thow', 'doesNotThrow');
            return true;
        }
        return false;
    }


    protected function fail($actual, $expected, $message, $operator='', $funcName='')
    {
        ++$this->errCount;
        if(is_null($actual)) $actual = 'null';
        else if(is_array($actual)||is_object($actual)) $actual = json_encode($actual);
        else if(is_bool($actual)) $actual = $actual ? 'true' : 'false';
//        else if(is_callable($actual)) $actual = 'closure';
        //
        if(is_null($expected)) $expected = 'null';
        else if(is_array($expected)||is_object($expected)) $expected = json_encode($expected);
        else if(is_bool($expected)) $expected = $expected ? 'true' : 'false';
//        else if(is_callable($expected)) $expected = 'closure';

        if( empty($message)) $message = $this->itemCount;

        $a1 = [
            "/\n/","/\r/","/\t/","/\v/","/\\\\/"
        ];
        $a2 = [
            "\\n","\\r","\\t","\\v","\\\\"
        ];
        $actual = preg_replace($a1, $a2, $actual);
        $expected = preg_replace($a1, $a2, $expected);

        $out = "";
        if( $operator === 'thow')
            $out .= $actual . $expected;
        else
            $out .= $actual . " {$operator} " . $expected ;
        $this->html .= "❌ ". $this->dangerText($message) . " <span class=\"label label-danger\">{$funcName}</span>"
            . "<div style=\"margin-left:20px\">"
            . $this->dangerText($out)
            . "</div>" ;
    }

    protected function successText($message)
    {
        if( empty($message)) $message = $this->itemCount;
        return "<span class='text-success'>✔️ " . htmlentities($message) ."</span><br />\n";
    }
    protected function dangerText($message)
    {
        return "<span class='text-danger'>" . htmlentities($message) ."</span><br />\n";
    }
    protected function br(){return "<br />\n";}


}
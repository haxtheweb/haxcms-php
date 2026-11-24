<?php
namespace Kaoken\Test\MDUrl;

use Kaoken\Test\MDUrl\Fixtures\Url;
use Kaoken\MDUrl\MDUrl;
use Kaoken\Test\EasyTest;


class CTest extends EasyTest
{
    use DecodeTrait, EncodeTrait, FormatTrait, ParseTrait;
    /**
     * @var \Kaoken\Test\MDUrl\MDUrl
     */
    private $mdurl;
    /**
     * @var Kaoken\Test\MDUrl\Fixtures\Url
     */
    private $dt;
    public function __construct()
    {
        parent::__construct("MDUrl test case !!");
        $this->mdurl = new MDUrl();
        $this->dt = Url::get();
        $this->decode();
        $this->encode();
        $this->parse();
        $this->format();
    }


}
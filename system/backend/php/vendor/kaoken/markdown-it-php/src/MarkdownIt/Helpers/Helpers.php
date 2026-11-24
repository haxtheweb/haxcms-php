<?php
namespace Kaoken\MarkdownIt\Helpers;


use Kaoken\MarkdownIt\Common\Utils;

class Helpers extends \stdClass
{
    use ParseLinkDestination, ParseLinkLabel, ParseLinkTitle;
    /**
     * @var Utils|null
     */
    public ?Utils $utils;

    public function __construct()
    {
        $this->utils = Utils::getInstance();
    }
}
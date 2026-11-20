<?php
namespace Kaoken\Test\MDUrl;

/**
 * @package Kaoken\Test\MDUrl
 * @property \Kaoken\Test\MDUrl\Fixtures\Url $dt
 * @property \Kaoken\MDUrl\MDUrl             $mdurl
 */
Trait FormatTrait
{
    private function format()
    {
        $this->group("format", function ($g){
            foreach ( $this->dt as $url =>&$value) {
                $parsed = $this->mdurl->parse($url);

                $tr = $this->mdurl->format($parsed);
                $g->equal($url, $tr, $url);
            }
        });
    }
}
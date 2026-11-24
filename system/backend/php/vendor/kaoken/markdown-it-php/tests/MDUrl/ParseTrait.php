<?php
namespace Kaoken\Test\MDUrl;

/**
 * @package Kaoken\Test\MDUrl
 * @property \Kaoken\Test\MDUrl\Fixtures\Url $dt
 * @property \Kaoken\MDUrl\MDUrl             $mdurl
 */
trait ParseTrait
{
    private function parse()
    {
        $this->group("parse", function ($g){
            foreach ( $this->dt as $url =>&$value) {
                $parsed = $this->mdurl->parse($url);
                foreach ( $parsed as $key=>&$x) {
                    if ($parsed->{$key} === null) {
                        unset($parsed->{$key});
                    }
                }
                $g->group($url,function ($gg) use($parsed, $url){
                    foreach ( $this->dt->{$url} as $key =>&$value) {
                        $gg->equal($parsed->{$key},$value);
                    }
                });
            }
        });
    }
}
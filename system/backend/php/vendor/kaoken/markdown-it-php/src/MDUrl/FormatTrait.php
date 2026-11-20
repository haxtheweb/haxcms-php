<?php
/**
 * Copyright (c) 2015 Vitaly Puzrin, Alex Kocharin.
 *
 * This software is released under the MIT License.
 * http://opensource.org/licenses/mit-license.php
 */
/**
 * Copyright (c) 2016 Kaoken
 *
 * This software is released under the MIT License.
 * http://opensource.org/licenses/mit-license.php
 *
 * use javascript version 1.0.1
 * @see https://github.com/markdown-it/mdurl/tree/1.0.1
 */
namespace Kaoken\MDUrl;


use stdClass;

Trait FormatTrait
{
    /**
     * @param stdClass $url
     * @return string
     */
    public function format(stdClass $url): string
    {
        $result = '';

        $result .= $url->protocol ?? '';
        $result .= $url->slashes ? '//' : '';
        $result .= isset($url->auth) ? $url->auth . '@' : '';

        if (isset($url->hostname) && strpos($url->hostname, ':') !== false) {
            // ipv6 address
            $result .= '[' . $url->hostname . ']';
        } else {
            $result .= $url->hostname ?? '';
        }

        $result .= isset($url->port) ? ':' . $url->port : '';
        $result .= $url->pathname ?? '';
        $result .= $url->search ?? '';
        $result .= $url->hash ?? '';

        return $result;
    }
}
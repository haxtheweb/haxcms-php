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
 * use javascript version 2.0.0
 * @see https://github.com/markdown-it/mdurl/tree/2.0.0
 */
namespace Kaoken\MDUrl;



class MDUrl
{
    use DecodeTrait, EncodeTrait, FormatTrait, ParseTrait;

    public function __construct()
    {
    }
}
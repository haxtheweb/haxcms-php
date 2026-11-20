<?php

declare(strict_types=1);

uses()->in(__DIR__);

function fixture(string $name): string
{
    return __DIR__.'/fixtures/'.$name;
}

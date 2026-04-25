<?php

namespace Boogle;

use Illuminate\Support\Facades\Facade as BaseFacade;

class Facade extends BaseFacade
{
    protected static function getFacadeAccessor(): string
    {
        return Boogle::class;
    }
}

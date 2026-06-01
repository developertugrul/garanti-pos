<?php

namespace Developertugrul\GarantiPos\Facades;

use Illuminate\Support\Facades\Facade;

class GarantiPos extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return 'garanti-pos';
    }
}

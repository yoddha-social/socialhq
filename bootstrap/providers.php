<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\PassportServiceProvider;
use App\SocialHq\SocialHqServiceProvider;

return [
    AppServiceProvider::class,
    HorizonServiceProvider::class,
    PassportServiceProvider::class,
    SocialHqServiceProvider::class,
];

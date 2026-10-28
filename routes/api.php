<?php

use App\Core\Api;

/*
 * Every endpoint is declared inside its module (app/Modules/<Module>.php) with Api::get/post/...
 * This file only loads the modules and registers their routes under /api/v1.
 */
foreach (config('modules') as $module) {
    $module::routes();
}
Api::register();

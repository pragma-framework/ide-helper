<?php

use Pragma\Router\Router;
use Pragma\Idehelper\IdeHelperController;

$app = Router::getInstance();

$app->group('ide-helper:', function () use ($app) {
    $app->cli('models', function () {
        IdeHelperController::generateModels();
    });
});

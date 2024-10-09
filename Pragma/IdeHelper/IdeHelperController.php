<?php

namespace Pragma\IdeHelper;

use Pragma\Helpers\TaskLock;

/**
 * Controller de gestion pour le module IdeHelper
 *
 * @package Pragma\IdeHelper
 */
class IdeHelperController
{
    /**
     * Route pour générer des docs pour les models pragma
     *
     * Options :
     *      -c | --clases        Select class
     *      -m | --mixin        Chose if doc bloc is save in class file or mixin
     *      -R | --reset        Remove the original phpdocs instead of appending
     *      -r | --smart-reset    Refresh the properties/methods list, but keep the text
     *
     * @return void
     * @throws \ReflectionException
     */
    public static function generateModels()
    {
        TaskLock::check_lock(realpath('.') . '/locks', 'ide-helper-generate-models');

        $options = \Pragma\Router\Request::getRequest()->parse_params(false);
        
        if (isset($options['c']) || isset($options['classes'])) {
            $classes = explode(',', str_replace(" ", "", $options['c'] ?? $options['classes']));
        } else {
            $classes = self::loadClasses();
        }
        
        if (empty($classes)) {
            print_r("Your have no class. You may consider to run `composer dump-autoload -o` before generate ide helper" . PHP_EOL);
        }

        $writeInMixin = isset($options['mixin']) || isset($options['m']);
        $classMixinContents = '';
        
        $options = [
            'write' => true,
            'write_mixin' => $writeInMixin,
            'reset' => isset($options['reset']) || isset($options['R']),
            'keep_text' => isset($options['smart-reset']) || isset($options['r']),
        ];

        foreach ($classes as $class) {
            $ideHelperModel = new IdeHelperModel($class, $options);

            $classMixinContents .= $ideHelperModel->generateDocs();
        }

        if ($writeInMixin) {
            $filename = ROOT_PATH . '/_ide_helper_models.php';

            $header = "<?php

// @formatter:off
/**
 * A helper file for your Pragma Models
 * Copy the phpDocs from this file to the correct Model,
 * And remove them from this file, to prevent double declarations.
 *
 */
\n\n";

            $file = fopen($filename, 'w');
            fwrite($file, $header . $classMixinContents);
            fclose($file);
            print_r("Model information was written to $filename" . PHP_EOL);
        }

        TaskLock::flush(realpath('.') . '/locks', 'ide-helper-generate-models');
    }

    /**
     * Load classes
     * @return array
     * @throws \ReflectionException
     */
    protected static function loadClasses(): array
    {
        ini_set('max_execution_time', 0);
        ini_set('memory_limit', "2048M");

        $loader = require realpath(__DIR__ . '/../../../..') . '/autoload.php';
        $classes = array_keys($loader->getClassMap());

        $classesList = [];
        

        foreach ($classes as $c) {
            if (strpos($c, 'App\\Models\\') !== false) {
                $ref = new \ReflectionClass($c);
                if ($ref->isInstantiable() && $ref->hasMethod('build')) {
                    new $c();
                    $classesList[] = $c;
                }
            }
        }

        return $classesList;
    }
}

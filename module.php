<?php

namespace Pragma\IdeHelper;

class Module
{
    /**
     * Renvoi les routes CLI du controller IdeHelper
     * @return array
     */
    public static function getDescription(): array
    {
        return [
            "Pragma-Framework/ide-helper",
            [
                "index.php ide-helper:models\tCreate models docs",
            ],
        ];
    }
}

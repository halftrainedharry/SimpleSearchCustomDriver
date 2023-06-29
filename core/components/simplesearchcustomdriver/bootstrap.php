<?php
/**
 * @var \MODX\Revolution\modX $modx
 * @var array $namespace
 */
use xPDO\xPDO;
use MODX\Revolution\modX;

try {
    modX::getLoader()->addPsr4('SimpleSearchCustomDriver\\', $namespace['path'] . 'src/');
}
catch (\Throwable $t) {
    $modx->log(xPDO::LOG_LEVEL_ERROR, $t->getMessage());
}
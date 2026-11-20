<?php
declare(strict_types=1);

namespace Wichtelberg\Tests;

require __DIR__ . '/lib.php';
foreach (glob(__DIR__ . '/*Test.php') as $f) {
    require $f;
}
exit(T::run());

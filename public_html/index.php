<?php

/** Raiz do editor: leva para /editor/. */

declare(strict_types=1);

header('Location: /editor/', true, 302);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

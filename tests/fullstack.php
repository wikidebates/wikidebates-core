<?php

/** Run the full native suite in a disposable MediaWiki/SMW laboratory. */
putenv('WD_FULLSTACK_NATIVE_RENDER=1');
require __DIR__ . '/fullstackNativeSemantic.php';

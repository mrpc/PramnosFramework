<?php

/**
 * Fixture app config WITHOUT a 'namespace' key.
 *
 * Used by ApplicationTest to cover the else branch in getInstance() that runs
 * when isset($tmpConfig['namespace']) is false (Application.php line 892).
 */
return [
    'features' => [],
];

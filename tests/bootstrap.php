<?php

require __DIR__ .'/../vendor/autoload.php';

use Google\Cloud\Spanner\SpannerClient;

/**
 * database must be dropped after all tests
 */
register_shutdown_function(function(){
    $client = new SpannerClient([
        'projectId' => getenv('DB_SPANNER_PROJECT_ID'),
    ]);
    $db = $client->connect(getenv('DB_SPANNER_INSTANCE_ID'), getenv('DB_SPANNER_DATABASE_ID'));
    if ($db->exists()) {
        $db->drop();
    }
});

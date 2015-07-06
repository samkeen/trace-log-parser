<?php

namespace Io\Samk\LogRender;

use Io\Samk\Logging\AwsClient;
use Io\Samk\Logging\LogTraceParser;

require 'vendor/autoload.php';

$awsArgs = [
    'profile' => 'test',
    'region' => 'us-west-2',
];
$cloudWatchLogGroup = 'export-demo';
$renderTemplatePath = __DIR__ . '/src/template.html';

$traceToken = isset($_GET['trace']) ? $_GET['trace'] : null;
$markup = "No Trace";

if ($traceToken) {
    $awsClient = new AwsClient($awsArgs);
    $awsClient->init();
    $logStatements = $awsClient->getStatementsForTrace($traceToken, $cloudWatchLogGroup);
    if(!$logStatements['events']) {
        echo "No Log statements retrieved from AWS Cloud Watch Logs for trace token: {$traceToken}";
        exit;
    }
    $parser = new LogTraceParser(
        'exportDemo',
        [
            '/INFO: Matched route/'
        ]
    );
    $markup = $parser->run($logStatements, $renderTemplatePath);
}

echo $markup;

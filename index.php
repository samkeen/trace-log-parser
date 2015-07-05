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
    $parser = new LogTraceParser('exportDemo');
    $markup = $parser->run($logStatements, $renderTemplatePath);
}

echo $markup;

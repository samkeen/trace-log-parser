<?php
namespace Io\Samk\Logging;

use Aws\CloudWatchLogs\CloudWatchLogsClient;
use Aws\CloudWatchLogs\Exception\CloudWatchLogsException;

/**
 * Class AwsClient
 */
class AwsClient
{
    /**
     * @var CloudWatchLogsClient
     */
    protected $client;

    public function __construct(array $awsArgs = [])
    {
        $this->awsArgs = $awsArgs;
    }

    public function init()
    {
        $this->client = CloudWatchLogsClient::factory($this->awsArgs);
    }

    /**
     * @param string $traceToken
     * @param string $logGroup
     * @return \Guzzle\Service\Resource\Model
     */
    public function getStatementsForTrace($traceToken, $logGroup)
    {
        $logStatements = [];
        try {
            $logStatements = $this->client->filterLogEvents([
                'logGroupName' => $logGroup,
                'filterPattern' => $traceToken
            ]);
        } catch(CloudWatchLogsException $e) {
            /**
             * @TODO fix when i am a real app
             */
            throw $e;
        }

        return $logStatements;
    }


}
<?php
namespace Io\Samk\Logging;

/**
 * Class Parser
 * @package Io\Samk\Logging
 *
 * example log line
 *
 *     |---timestamp-------|---------statement----------------|-----------trace------------------|
 *
 *     [2015-07-02 05:28:16] FileExporterApp.INFO: > GET / [] #TRACE#{"token":"5594cbf031252fee5"}
 */
class LogTraceParser
{

    public $serviceName = "";

    const OVERALL_PATTERN = '/^\[(?P<timestamp>[\d\s-:]+)\] +(?P<statement>.*)(?P<trace>#TRACE#{.*})$/';
    const MESSAGE_PATTERN = '/^(?P<level>[\w\.]+): +(?P<message>.*)$/';
    const TRACE_MARKER = '#TRACE#';

    /**
     * @var array Array of regex's for patterns to skip
     */
    public $statementIgnorePatterns = [];

    const INDENT = '  ';

    private $indentCount = 1;

    function __construct($serviceName, $statementIgnorePatterns = [])
    {
        $this->serviceName = $serviceName;
        $this->statementIgnorePatterns = $statementIgnorePatterns;
    }

    public function run($logStatements, $templatePath, $targetPath = null)
    {
        list($rawStatements, $parsedStatements) = $this->parseCloudWatchFormat($logStatements);
        return $this->render($rawStatements, $parsedStatements, $templatePath, $targetPath);
    }

    public function parseCloudWatchFormat($logStatements)
    {
        $parsedStatements = $rawStatements =[];
        foreach ($logStatements['events'] as $index => $logStatement) {
            $rawStatements[] = $logStatement['message'];
            $matchedLine = $this->matchLine($logStatement['message']);
            if($matchedLine) {
                $parsedStatements[] = $matchedLine;
            }
        }

        return [$rawStatements, $parsedStatements];
    }

    /**
     * Render Jumly DSL
     * See: http://jumly.tmtk.net/
     *
     * @param $rawStatements
     * @param $parsedStatements
     * @param $templatePath
     * @param null $targetPath
     * @return mixed|null
     */
    public function render($rawStatements, $parsedStatements, $templatePath, $targetPath = null)
    {
        // Start the diagram with our initial Actor
        $sequenceMarkup = '@found "Client", ->' . PHP_EOL;
        /*
         * @assume First statement is the init statement
         *  Create the initial HTML that proceeds the script sequence markup
         */
        $firstRecord = $parsedStatements[0];
        $traceToken = (string)$firstRecord['token'];
        $initMessage = "Initialize Contact";
        if(isset($firstRecord['time'])) {
            $initTime = $this->formatMicrotime($firstRecord['time']);
            $initMessage .= " @ {$initTime}";
        }
        $matchedRouteStatement = array_shift($parsedStatements);
        $matchedRouteStatement['message'] = $this->getRouteMessage($matchedRouteStatement);
        $sequenceMarkup .= ($this->indent() . $this->renderMessage($matchedRouteStatement) . PHP_EOL);
        $this->indentCount++;
        foreach ($parsedStatements as $parsedLine) {
            $traceEvent = new TraceEvent($parsedLine);
            if($traceEvent->isBoundaryEntry()) {
                $sequenceMarkup .= ($this->indent()
                    . $this->renderDbInterAction($parsedLine, $traceEvent)
                    . PHP_EOL
                );
            } else if($traceEvent->isResponseSend()) {
                $sequenceMarkup .= ($this->indent()
                    . $this->renderResponseToClient($parsedLine, $traceEvent)
                    . PHP_EOL
                );
            } else {
                $sequenceMarkup .= ($this->indent() . $this->renderNote($parsedLine) . PHP_EOL);
            }
        }
        $template = file_get_contents($templatePath);
        $renderedTemplate = preg_replace(
            [
                '/{{traceToken}}/',
                '/{{initMessage}}/',
                '/{{sequenceMarkup}}/',
                '/{{rawLogs}}/',
            ],
            [
                $traceToken,
                $initMessage,
                $sequenceMarkup,
                join("\n", $rawStatements),
            ],
            $template
        );
        if ($targetPath) {
            file_put_contents($targetPath, $renderedTemplate);

            return $targetPath;
        }

        return $renderedTemplate;
    }

    protected function matchLine($logStatement)
    {
        $parsedLine = '';
        if (!preg_match(static::OVERALL_PATTERN, $logStatement, $matches)) {
            $parsedLine = "There was no match on: {$logStatement}";
        } else {
            $statement = preg_replace('/(\[\])$/', '', trim($matches['statement']));
            $ignore = false;
            foreach ($this->statementIgnorePatterns as $messageIgnorePattern) {
                if (preg_match($messageIgnorePattern, $statement)) {
                    $ignore = true;
                    break;
                }
            }
            if (!$ignore) {
                $parsedLine = $this->parseStatement($statement) + $this->parseTrace($matches['trace']);
            }
        }

        return $parsedLine;
    }

    private function parseStatement($middle)
    {
        $parsedStatement = [
            'level' => null,
            'message' => null
        ];
        if (preg_match(static::MESSAGE_PATTERN, $middle, $matches)) {

            $parsedStatement['level'] = $matches['level'];
            $parsedStatement['message'] = $matches['message'];
        }

        return $parsedStatement;
    }

    private function parseTrace($extra)
    {
        return json_decode(substr($extra, strlen(self::TRACE_MARKER)), true);
    }

    private function formatMicrotime($microtime)
    {
        $parts = explode('.', $microtime);
        $date = date('r', $parts[0]);

        return preg_replace('/(\d\d:\d\d:\d\d)/', '${1}.' . $parts[1], $date);
    }

    private function indent()
    {
        return str_repeat(static::INDENT, $this->indentCount);
    }

    /**
     * @param $parsedLine
     * @param TraceEvent $traceEvent
     * @return string
     */
    private function renderDbInteraction($parsedLine, TraceEvent $traceEvent)
    {
        $sequenceMarkup = '@message "'.$traceEvent->getEventAction().'", "'.$traceEvent->getEventContext().'", ->' . PHP_EOL;
        $this->indentCount++;
        $sequenceMarkup .= $this->indent() . '@note "' . $parsedLine['message'] . '"' . PHP_EOL;
        $sequenceMarkup .= $this->indent() . '@reply "", "' . $this->serviceName . '"';
        $this->indentCount--;

        return $sequenceMarkup;
    }

    private function getRouteMessage($parsedLine)
    {
        preg_match('%(POST|GET|PUT|DELETE|PATCH|HEAD|OPTIONS) +/[^\?]+%', $parsedLine['message'], $match);
        return $match ? $match[0] : substr($parsedLine['message'], 0, 20) . '...';
    }

    private function renderNote($parsedLine)
    {
        $message = str_replace('"', "'", $parsedLine['message']);

        return '@note "' . $message . '"';
    }

    private function renderMessage($parsedLine)
    {
        $message = str_replace('"', "'", $parsedLine['message']);

        return '@message "' . $message . '", "' . $this->serviceName . '", ->';
    }

    private function renderResponseToClient($parsedLine, TraceEvent $traceEvent)
    {
        return '@reply "'.$parsedLine['message'].'", "Client"';
    }
}
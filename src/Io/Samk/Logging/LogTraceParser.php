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
            $parsedStatements[] = $this->matchLine($logStatement['message']);
        }

        return [$rawStatements, $parsedStatements];
    }

    public function render($rawStatements, $parsedStatements, $templatePath, $targetPath = null)
    {
        $sequenceMarkup = '@found "Client", ->' . PHP_EOL;
        /*
         * @assume First statement is the init statement
         *  Create the initial HTML the proceeds the script sequence markup
         */
        $firstRecord = array_shift($parsedStatements);
        $traceToken = (string)$firstRecord['token'];
        $initTime = $this->formatMicrotime($firstRecord['time']);
        $initMessage = "Initialize Request @ {$initTime}";

        $matchedRouteStatement = array_shift($parsedStatements);
        $matchedRouteStatement['message'] = $this->getRouteMessage($matchedRouteStatement);
        $sequenceMarkup .= ($this->indent() . $this->renderMessage($matchedRouteStatement) . PHP_EOL);
        $this->indentCount++;


        foreach ($parsedStatements as $parsedLine) {
            if ($this->isDbInteraction($parsedLine)) {
                $sequenceMarkup .= ($this->indent()
                    . $this->renderDbInterAction($parsedLine)
                    . PHP_EOL
                );
            } else {
                $sequenceMarkup .= ($this->indent() . $this->renderNote($parsedLine) . PHP_EOL);
            }
        }

        $sequenceMarkup .= ($this->indent() . $this->finalizeSequenceMarkup());

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
            $statement = trim($matches['statement']);
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

    private function renderDbInteraction($parsedLine)
    {
        $sequenceMarkup = '@message "", "DB", ->' . PHP_EOL;
        $this->indentCount++;
        $sequenceMarkup .= $this->indent() . '@note "' . $parsedLine['message'] . '"' . PHP_EOL;
        $sequenceMarkup .= $this->indent() . '@reply "", "' . $this->serviceName . '"';
        $this->indentCount--;

        return $sequenceMarkup;
    }

    private function getRouteMessage($parsedLine)
    {
        return substr($parsedLine['message'], 0, 28);
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

    private function isDbInteraction($parsedLine)
    {
        return $parsedLine['level'] == 'doctrine.DEBUG';
    }

    private function finalizeSequenceMarkup()
    {
        return '@reply "", "Client"';
    }
}
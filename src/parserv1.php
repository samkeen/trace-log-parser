<?php
namespace Io\Samk\TraceLog;

$logFile = dirname(__DIR__) . "/source-logs/sample-single-raw-v1.log";
$templatePath = __DIR__ . "/template.html";
$targetPath = dirname(__DIR__) . '/public/rendered_output.html';

Parser::$serviceName = 'pages-api';
Parser::$messageIgnorePatterns = [
    '/^([\w\.]+): +Notified event ".*$/',
    '/^app.INFO: TraceRequest START$/i',
    '/^([\w\.]+): +Listener +.*$/'
];
Parser::run($logFile, $templatePath, $targetPath);

class Parser
{

    public static $serviceName = "";

    const OVERALL_PATTERN = '/^\[(.*)\] +(.*)({.*})$/';
    const MIDDLE_PATTERN = '/^([\w\.]+): +(.*)$/';
    public static $messageIgnorePatterns = [];

    const INDENT = '  ';
    private static $indentCount = 1;

    public static function run($logFilePath, $templatePath, $targetPath)
    {
        $handle = @fopen($logFilePath, "r");
        $parsed = Parser::parse($handle);
        static::render($parsed, $logFilePath, $templatePath, $targetPath);
    }

    public static function parse($handle)
    {
        $parsedLines = [];
        if ($handle) {
            while (($buffer = fgets($handle, 4096)) !== false) {
                if (!preg_match(static::OVERALL_PATTERN, $buffer, $matches)) {
                    $parsedLines[] = "There was no match on: {$buffer}";
                } else {
                    $middle = trim($matches[2]);
                    $ignore = false;
                    foreach (static::$messageIgnorePatterns as $messageIgnorePattern) {
                        if (preg_match($messageIgnorePattern, $middle)) {
                            $ignore = true;
                            break;
                        }
                    }
                    if (!$ignore) {
                        $parsedLines[] = static::parseMiddle($middle) + static::parseExtra($matches[3]);
                    }
                }
            }
            if (!feof($handle)) {
                echo "Error: unexpected fgets() fail\n";
            }
            fclose($handle);
        }

        return $parsedLines;
    }

    protected static function render($parsedLines, $logFilePath, $templatePath, $targetPath)
    {
        $traceToken = "";
        $initMessage = "";
        $sequenceMarkup = '@found "Client", ->' . PHP_EOL;
        $rawLogs = "";

        /*
         * @assume First statement is the init statement
         *  Create the initial HTML the proceeds the script sequence markup
         */
        $firstRecord = array_shift($parsedLines);
        $traceToken = $firstRecord['token'];
        $initTime = static::formatMicrotime($firstRecord['time']);
        $initMessage = "Initialize Request @ {$initTime}";

        $matchedRouteStatement = array_shift($parsedLines);
        $matchedRouteStatement['message'] = static::getRouteMessage($matchedRouteStatement);
        $sequenceMarkup .= (static::indent() . static::renderMessage($matchedRouteStatement) . PHP_EOL);
        static::$indentCount++;


        foreach ($parsedLines as $parsedLine) {
            if (static::isDbInteraction($parsedLine)) {
                $sequenceMarkup .= (static::indent()
                    . static::renderDbInterAction($parsedLine)
                    . PHP_EOL
                );
            } else {
                $sequenceMarkup .= (static::indent() . static::renderNote($parsedLine) . PHP_EOL);
            }
        }

        $sequenceMarkup .= (static::indent() . static::finalizeSequenceMarkup());

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
                file_get_contents($logFilePath),
            ],
            $template
        );
        file_put_contents($targetPath, $renderedTemplate);

    }

    private static function parseMiddle($middle)
    {
        $parsedMiddle = [
            'event' => null,
            'message' => null
        ];
        if (preg_match(static::MIDDLE_PATTERN, $middle, $matches)) {

            $parsedMiddle['event'] = $matches[1];
            $parsedMiddle['message'] = $matches[2];
        }

        return $parsedMiddle;
    }

    private static function parseExtra($extra)
    {
        return json_decode($extra, true);
    }

    private static function formatMicrotime($microtime)
    {
        $parts = explode('.', $microtime);
        $date = date('r', $parts[0]);

        return preg_replace('/(\d\d:\d\d:\d\d)/', '${1}.' . $parts[1], $date);
    }

    private static function indent()
    {
        return str_repeat(static::INDENT, static::$indentCount);
    }

    private static function renderDbInteraction($parsedLine)
    {
        $sequenceMarkup = '@message "", "DB", ->' . PHP_EOL;
        static::$indentCount++;
        $sequenceMarkup .= static::indent() . '@note "' . $parsedLine['message'] . '"' . PHP_EOL;
        $sequenceMarkup .= static::indent() . '@reply "", "' . static::$serviceName . '"';
        static::$indentCount--;

        return $sequenceMarkup;
    }

    private static function getRouteMessage($parsedLine)
    {
        return substr($parsedLine['message'], 0, 28);
    }

    private static function renderNote($parsedLine)
    {
        $message = str_replace('"', "'", $parsedLine['message']);

        return '@note "' . $message . '"';
    }

    private static function renderMessage($parsedLine)
    {
        $message = str_replace('"', "'", $parsedLine['message']);

        return '@message "' . $message . '", "' . static::$serviceName . '", ->';
    }

    private static function isDbInteraction($parsedLine)
    {
        return $parsedLine['event'] == 'doctrine.DEBUG';
    }

    private static function finalizeSequenceMarkup()
    {
        return '@reply "", "Client"';
    }
}
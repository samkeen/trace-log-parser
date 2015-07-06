<?php

namespace Io\Samk\Logging;


class TraceEvent
{
    /**
     * @var string
     */
    protected $eventType;
    protected $eventContext;
    protected $eventAction;
    protected $eventEntity;

    function __construct(array $parsedLogLine)
    {
        $event = isset($parsedLogLine['event']) ? $parsedLogLine['event'] : null;
        if($event) {
            $eventParts = explode(':', $parsedLogLine['event']);
            $this->eventType = strtolower($eventParts[0]);
            $this->eventContext = isset($eventParts[1]) ? $eventParts[1] : null;
            $this->eventAction = isset($eventParts[2]) ? $eventParts[2] : null;
            $this->eventEntity = isset($eventParts[3]) ? $eventParts[3] : null;
        }
    }

    public function isBoundaryEntry()
    {
        return $this->eventType == 'boundary.enter';
    }

    public function isResponseSend()
    {
        return $this->eventType == 'response.send';
    }

    /**
     * @return string
     */
    public function getEventType()
    {
        return $this->eventType;
    }

    /**
     * @return null
     */
    public function getEventContext()
    {
        return $this->eventContext;
    }

    /**
     * @return null
     */
    public function getEventAction()
    {
        return $this->eventAction;
    }

    /**
     * @return null
     */
    public function getEventEntity()
    {
        return $this->eventEntity;
    }

}
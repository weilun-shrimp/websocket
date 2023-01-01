<?php

namespace Weilun\WebSocket\Exceptions;

use Exception;

class FlowException extends Exception {

    protected string $type = '';
    
    public function __construct(string $type, string $message)
    {
        $this->type = $type;
        parent::__construct($message);
    }

    public function getType() 
    {
        return $this->type;
    }
}
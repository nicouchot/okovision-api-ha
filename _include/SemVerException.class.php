<?php

declare(strict_types=1);

//namespace vierbergenlars\SemVer;

class SemVerException extends Exception
{
    protected string $version;

    public function __construct(string $message, string $version)
    {
        $this->version = $version;
        parent::__construct($message.' [['.$version.']]');
    }

    public function getVersion(): string
    {
        return $this->version;
    }
}

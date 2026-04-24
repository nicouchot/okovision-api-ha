<?php

declare(strict_types=1);

/**
 * Logger class.
 * Usefull to log notices, warnings, errors or fatal errors into a logfile.
 *
 * @author gehaxelt
 *
 * @version 1.1
 */
class logger
{
    const NOTICE = 'INFO   ';
    const WARNING = 'WARNING';
    const ERROR = 'ERROR  ';
    const FATAL = 'FATAL  ';
    const DEBUG = 'DEBUG  ';

    private mixed $logfilehandle = null;

    public function __construct()
    {
        if ($this->logfilehandle === null) {
            $this->openLogFile(LOGFILE);
        }
    }

    public function __destruct()
    {
        $this->closeLogFile();
    }

    /**
     * @throws LogFileNotOpenException
     * @throws InvalidMessageTypeException
     */
    public function log(string $message, string $messageType = self::WARNING): void
    {
        if ($this->logfilehandle === null) {
            throw new LogFileNotOpenException('Logfile is not opened.');
        }
        if (!in_array($messageType, [self::NOTICE, self::WARNING, self::ERROR, self::FATAL, self::DEBUG], true)) {
            throw new InvalidMessageTypeException('Wrong $messagetype given');
        }
        $this->writeToLogFile($this->getTime().' | '.$messageType.' | '.$message);
    }

    public function closeLogFile(): void
    {
        if ($this->logfilehandle !== null) {
            fclose($this->logfilehandle);
            $this->logfilehandle = null;
        }
    }

    /** @throws LogFileOpenErrorException */
    public function openLogFile(string $logfile): void
    {
        $this->closeLogFile();

        $handle = fopen($logfile, 'a');
        if ($handle === false) {
            throw new LogFileOpenErrorException('Could not open Logfile in append-mode');
        }
        $this->logfilehandle = $handle;
    }

    public function info(string $message): void
    {
        $this->log($message, self::NOTICE);
    }

    public function warn(string $message): void
    {
        $this->log($message, self::WARNING);
    }

    public function error(string $message): void
    {
        $this->log($message, self::ERROR);
    }

    public function fatal(string $message): void
    {
        $this->log($message, self::FATAL);
    }

    public function debug(string $message): void
    {
        if (DEBUG) {
            $this->log($message, self::DEBUG);
        }
    }

    private function writeToLogFile(string $message): void
    {
        flock($this->logfilehandle, LOCK_EX);
        fwrite($this->logfilehandle, $message."\n");
        flock($this->logfilehandle, LOCK_UN);
    }

    private function getTime(): string
    {
        return (new DateTime('now'))->format('d.m.Y | H:i:s');
    }
}

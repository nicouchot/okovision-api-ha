<?php

declare(strict_types=1);

class session extends connectDb
{
    private string $lang = 'en';
    private array $dico = [];
    private static ?self $_instance = null;

    public function __construct()
    {
        session_start();

        if (!$this->exist('sid')) {
            $this->setVar('sid', bin2hex(random_bytes(16)));
        }

        $cf = json_decode(file_get_contents('config.json'), true);

        $this->setLang(isset($cf['lang']) ? $cf['lang'] : 'en');
        $this->dico = $this->getDictionnary($this->getLang());
    }

    public function __destruct()
    {
    }

    private function __clone()
    {
    }

    public static function getInstance(): static
    {
        if (self::$_instance === null) {
            self::$_instance = new self();
        }

        return self::$_instance;
    }

    public function getLabel(string $label): string
    {
        return (string) ($this->dico[$label] ?? '');
    }

    public function getLang(): string
    {
        return $this->lang;
    }

    public function setVar(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function exist(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    public function getVar(string $key): mixed
    {
        return $_SESSION[$key] ?? null;
    }

    public function deleteVar(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public function getSensorName(string $sensor): string
    {
        return (string) ($this->dico[$sensor] ?? '');
    }

    private function getDictionnary(string $lg): array
    {
        $file = '_langs/'.$lg.'.text.json';
        if (file_exists($file)) {
            return (array) json_decode(file_get_contents($file));
        }

        return [];
    }

    private function setLang(string $lg): void
    {
        $this->lang = $lg;
    }
}

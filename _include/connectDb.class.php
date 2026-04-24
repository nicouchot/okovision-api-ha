<?php

declare(strict_types=1);

/*
* Projet : Okovision - Supervision chaudiere OeKofen
* Auteur : Stawen Dronek
* Utilisation commerciale interdite sans mon accord
*/

class connectDb
{
    protected logger $log;

    private ?mysqli $db = null;
    private string $_ip = BDD_IP;
    private string $_user = BDD_USER;
    private string $_pass = BDD_PASS;
    private string $_schema = BDD_SCHEMA;

    private static ?self $_instance = null;

    public function __construct()
    {
        $this->log = new logger();
    }

    public function __destruct()
    {
    }

    private function __clone()
    {
    }

    protected function getConnection(): mysqli
    {
        if ($this->db === null) {
            $this->connect();
        }

        return $this->db;
    }

    protected function realEscapeString(string $s): string
    {
        return self::getInstance()->getConnection()->real_escape_string($s);
    }

    protected function query(string $q): \mysqli_result|bool
    {
        return self::getInstance()->getConnection()->query($q);
    }

    /**
     * Execute a parameterized query using prepared statements.
     *
     * @param string $sql    SQL with ? placeholders
     * @param array  $params Values bound to placeholders (int|float|string)
     *
     * @return \mysqli_result|bool mysqli_result for SELECT, true for DML, false on error
     */
    protected function prepare(string $sql, array $params = []): \mysqli_result|bool
    {
        $con = self::getInstance()->getConnection();
        $stmt = $con->prepare($sql);

        if ($stmt === false) {
            $this->log->error('GLOBAL | Prepare failed: ' . $con->error);
            return false;
        }

        if (!empty($params)) {
            $types = implode('', array_map(fn($v) => match (true) {
                is_int($v)   => 'i',
                is_float($v) => 'd',
                default      => 's',
            }, $params));
            $stmt->bind_param($types, ...$params);
        }

        if (!$stmt->execute()) {
            $this->log->error('GLOBAL | Execute failed: ' . $stmt->error);
            $stmt->close();
            return false;
        }

        $result = $stmt->get_result();
        $stmt->close();

        return $result !== false ? $result : true;
    }

    protected function multi_query(string $q): bool
    {
        return self::getInstance()->getConnection()->multi_query($q);
    }

    protected function flush_multi_queries(): bool
    {
        $con = self::getInstance()->getConnection();

        return $con->next_result() && $con->more_results();
    }

    private static function getInstance(): static
    {
        if (self::$_instance === null) {
            self::$_instance = new self();
        }

        return self::$_instance;
    }

    private function connect(): void
    {
        $this->db = new mysqli($this->_ip, $this->_user, $this->_pass, $this->_schema);

        if ($this->db->connect_errno) {
            $this->log->error('GLOBAL | Connection MySQL impossible : '.$this->db->connect_error);
            exit(22);
        }

        if (!$this->db->set_charset('utf8')) {
            $this->log->error('GLOBAL | Erreur lors du chargement du jeu de caractères utf8 :'.$this->db->error);
        }

        $this->query("SET time_zone='+00:00'");
        $this->query("SET @@SESSION.SQL_MODE = REPLACE(@@SQL_MODE, 'ONLY_FULL_GROUP_BY,', '')");
    }

    private function disconnect(): void
    {
        $this->db->close();
    }
}

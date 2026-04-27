<?php

declare(strict_types=1);

/*
* Projet : Okovision - Supervision chaudiere OeKofen
* Gestion de l'authentification (login / logout / changement de mot de passe).
* Extrait de administration.class.php — Phase 5 sous-commit 2.
*/
class AdminAuth extends connectDb
{
    public function __construct()
    {
        parent::__construct();
    }

    public function __destruct()
    {
        parent::__destruct();
    }

    public function login(string $user, string $pass): void
    {
        $user = $this->realEscapeString($user);
        $pass = sha1($pass);

        $q = "SELECT count(*) as nb, id, type FROM oko_user WHERE user='{$user}' AND pass='{$pass}' GROUP BY id";
        $this->log->debug('Class '.__CLASS__.' | '.__FUNCTION__.' | '.$q);

        $result = $this->query($q);
        $r = ['response' => false];

        if ($result) {
            $res = $result->fetch_object();
            if ($res && 1 == $res->nb) {
                $r['response'] = true;
                session::getInstance()->setVar('typeUser', $res->type);
                session::getInstance()->setVar('logged', true);
                session::getInstance()->setVar('userId', $res->id);
            }
        }

        $this->sendResponse($r);
    }

    public function logout(): void
    {
        session::getInstance()->deleteVar('logged');
        session::getInstance()->deleteVar('typeUser');
        session::getInstance()->deleteVar('userId');
        $this->sendResponse(['response' => true]);
    }

    public function changePassword(string $pass): void
    {
        $pass   = sha1($pass);
        $userId = session::getInstance()->getVar('userId');

        $q = "UPDATE oko_user SET pass='{$pass}' WHERE id={$userId}";
        $this->log->debug('Class '.__CLASS__.' | '.__FUNCTION__.' | '.$q);

        $this->sendResponse(['response' => (bool) $this->query($q)]);
    }
}

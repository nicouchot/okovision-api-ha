<?php
/**
 * Projet : Okovision - Supervision chaudiere OeKofen
 *
 * Façade centralisée sur l'extension IMAP pour les endpoints
 * _include/bin_v4/*mail*.php. Expose des retours structurés
 * (connexion + erreurs) plutôt que l'actuel retour silencieux
 * qui masquait toutes les défaillances.
 */
class mail
{
    /**
     * True si l'extension IMAP est compilée/chargée dans ce PHP.
     */
    public static function isAvailable(): bool
    {
        return function_exists('imap_open');
    }

    /**
     * Ouvre une connexion IMAP. Retourne la ressource/objet IMAP\Connection
     * en cas de succès, false sinon. Les éventuelles erreurs/alertes sont
     * ensuite récupérables via allErrors().
     *
     * Les E_DEPRECATED éventuels (imap dépréciée en PHP 8.4) sont masqués
     * le temps de l'appel pour ne pas polluer les logs applicatifs — les
     * vraies erreurs restent remontées par imap_errors().
     */
    public static function open(string $host, string $login, string $pwd)
    {
        if (!self::isAvailable()) {
            return false;
        }

        // On purge la file d'erreurs IMAP avant l'appel pour ne remonter
        // que celles liées à cette tentative précise.
        if (function_exists('imap_errors')) {
            imap_errors();
        }
        if (function_exists('imap_alerts')) {
            imap_alerts();
        }

        set_error_handler(static function ($severity, $message) {
            return $severity === E_DEPRECATED;
        });

        try {
            $conn = @imap_open($host, $login, $pwd);
        } finally {
            restore_error_handler();
        }

        return $conn;
    }

    public static function close($conn): void
    {
        if ($conn) {
            @imap_close($conn);
        }
    }

    /**
     * Dernière erreur IMAP formattée en string (chaîne vide si aucune).
     */
    public static function lastError(): string
    {
        if (!function_exists('imap_last_error')) {
            return '';
        }

        $err = imap_last_error();

        return $err === false ? '' : (string) $err;
    }

    /**
     * Toutes les erreurs + alertes IMAP accumulées depuis le dernier appel.
     * Consomme les files internes imap_errors() / imap_alerts().
     */
    public static function allErrors(): array
    {
        $errors = [];
        $alerts = [];

        if (function_exists('imap_errors')) {
            $errors = imap_errors() ?: [];
        }
        if (function_exists('imap_alerts')) {
            $alerts = imap_alerts() ?: [];
        }

        return array_merge($errors, $alerts);
    }

    /**
     * Snapshot de l'état IMAP actuel, utilisable en payload de diagnostic
     * renvoyé aux endpoints JSON.
     */
    public static function diagnose(): array
    {
        return [
            'imap_loaded' => self::isAvailable(),
            'php_version' => PHP_VERSION,
            'last_error'  => self::lastError(),
            'errors'      => self::allErrors(),
        ];
    }

    /**
     * Tente de classer la raison d'un open() échoué à partir du dernier
     * message d'erreur IMAP. Retourne un code court exploitable côté UI :
     *   - ext_missing       → extension IMAP absente
     *   - auth_failed       → identifiants refusés
     *   - connection_failed → tout le reste (réseau, TLS, host inconnu…)
     */
    public static function classifyOpenFailure(): string
    {
        if (!self::isAvailable()) {
            return 'ext_missing';
        }

        $err = strtolower(self::lastError());

        if ($err === '') {
            return 'connection_failed';
        }

        $authTokens = ['login', 'auth', 'authentication', 'credentials', 'password'];
        foreach ($authTokens as $t) {
            if (strpos($err, $t) !== false) {
                return 'auth_failed';
            }
        }

        return 'connection_failed';
    }

    /**
     * Liste les parties "pièce jointe CSV" d'un email.
     *
     * @return array<int, array{name: string, partIndex: int, encoding: int}>
     *               partIndex est 1-based (attendu par imap_fetchbody)
     */
    public static function listCsvParts($conn, int $emailNumber): array
    {
        $structure = @imap_fetchstructure($conn, $emailNumber);

        if (!$structure || !isset($structure->parts) || !count($structure->parts)) {
            return [];
        }

        $parts = [];

        foreach ($structure->parts as $i => $part) {
            $isAttach   = false;
            $attachName = '';

            if (!empty($part->ifdparameters)) {
                foreach ($part->dparameters as $obj) {
                    if (strtolower($obj->attribute) === 'filename') {
                        $isAttach   = true;
                        $attachName = $obj->value;
                    }
                }
            }

            if (!empty($part->ifparameters)) {
                foreach ($part->parameters as $obj) {
                    if (strtolower($obj->attribute) === 'name') {
                        $isAttach   = true;
                        $attachName = $obj->value;
                    }
                }
            }

            if (!$isAttach || $attachName === '') {
                continue;
            }

            $ext = strtolower(pathinfo($attachName, PATHINFO_EXTENSION));
            if ($ext !== 'csv') {
                continue;
            }

            $parts[] = [
                'name'      => $attachName,
                'partIndex' => $i + 1,
                'encoding'  => (int) ($part->encoding ?? 0),
            ];
        }

        return $parts;
    }

    /**
     * Récupère et décode le corps d'une pièce jointe.
     */
    public static function fetchPartBody($conn, int $emailNumber, int $partIndex, int $encoding): string
    {
        $raw = @imap_fetchbody($conn, $emailNumber, (string) $partIndex);

        if ($raw === false || $raw === '') {
            return '';
        }

        // 3 = BASE64, 4 = QUOTED-PRINTABLE (constantes imap_* non exposées en PHP userland)
        if ($encoding === 3) {
            return base64_decode($raw, true) ?: '';
        }
        if ($encoding === 4) {
            return quoted_printable_decode($raw);
        }

        return $raw;
    }

    /**
     * Ajoute un suffixe "déjà présent" / "already present" au nom de fichier
     * si présent dans la liste fournie. Utilisé pour décorer la liste mail
     * côté UI amImpMail.
     */
    public static function decorateName(string $name, array $existingFiles, string $lang): string
    {
        if (array_search($name, $existingFiles, true) === false) {
            return $name;
        }

        return $lang === 'fr'
            ? $name . ' <b class="red">déjà présent</b>'
            : $name . ' <b class="red">already present</b>';
    }

    /**
     * Session guard commun aux endpoints mail.
     * Si la session n'est pas authentifiée, renvoie un JSON 401 et termine.
     */
    public static function requireLoggedSession(): void
    {
        if (!class_exists('session') || !session::getInstance()->getVar('logged')) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'error'   => [
                    'code'    => 'unauthorized',
                    'message' => 'Session non authentifiée',
                ],
            ]);
            exit;
        }
    }

    /**
     * Helper d'émission JSON structuré pour les endpoints.
     */
    public static function respond(array $payload, int $httpCode = 200): void
    {
        http_response_code($httpCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Forme standard d'une réponse d'erreur.
     */
    public static function errorResponse(string $code, string $message, int $httpCode = 200): void
    {
        self::respond([
            'success' => false,
            'error'   => [
                'code'     => $code,
                'message'  => $message,
                'diagnose' => self::diagnose(),
            ],
        ], $httpCode);
    }
}

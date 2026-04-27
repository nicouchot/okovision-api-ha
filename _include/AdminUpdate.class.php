<?php

declare(strict_types=1);

/*
* Projet : Okovision - Supervision chaudiere OeKofen
* Gestion des mises à jour et de la version (check / install / stat).
* Extrait de administration.class.php — Phase 5 sous-commit 2.
*/
class AdminUpdate extends connectDb
{
    private string $_urlApi = 'http://api.okovision.dronek.com';

    public function __construct()
    {
        parent::__construct();
    }

    public function __destruct()
    {
        parent::__destruct();
    }

    public function getVersion(): void
    {
        $this->sendResponse(self::getCurrentVersion());
    }

    public static function getCurrentVersion(): string
    {
        return trim((string) file_get_contents('_include/version.json'));
    }

    public function checkUpdate(): void
    {
        $r = ['newVersion' => false, 'information' => ''];

        $this->addOkoStat();

        $update = new AutoUpdate();
        $update->setCurrentVersion(self::getCurrentVersion());

        if (false === $update->checkUpdate()) {
            $r['information'] = session::getInstance()->getLabel('lang.error.maj.information');
        } elseif ($update->newVersionAvailable()) {
            $r['newVersion'] = true;
            $r['list'] = $update->getVersionsInformationToUpdate();
        } else {
            $r['information'] = session::getInstance()->getLabel('lang.valid.maj.information');
        }

        $this->sendResponse($r);
    }

    public function addOkoStat(): void
    {
        $host   = $_SERVER['HTTP_HOST'];
        $folder = dirname($_SERVER['SCRIPT_NAME']);
        $source = $host.$folder;

        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_URL            => $this->_urlApi,
            CURLOPT_USERAGENT      => 'Okovision :-:'.TOKEN.':-:',
            CURLOPT_POST           => 1,
            CURLOPT_POSTFIELDS     => [
                'token'   => TOKEN,
                'source'  => $source,
                'version' => self::getCurrentVersion(),
            ],
        ]);
        curl_exec($curl);
        curl_close($curl);
    }

    public function makeUpdate(): void
    {
        $r = ['install' => false];

        $update = new AutoUpdate();
        $update->setCurrentVersion(self::getCurrentVersion());

        $result = $update->update();
        if (true === $result) {
            $r['install'] = true;
        } elseif ($result === AutoUpdate::ERROR_SIMULATE) {
            $r['information'] = '<pre>'.var_export($update->getSimulationResults(), true).'</pre>';
        }

        $this->sendResponse($r);
    }

    private function setCurrentVersion(string $v): int|false
    {
        return file_put_contents('_include/version.json', $v);
    }
}

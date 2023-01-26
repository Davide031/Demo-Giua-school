<?php
/*
 * SPDX-FileCopyrightText: 2017 I.I.S. Michele Giua - Cagliari - Assemini
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */


namespace App\Install;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Filesystem\Filesystem;
use App\Kernel;


/**
 * Installer - Gestione procedura di installazione
 *
 *
 * @author Antonello Dessì
 */
class Installer {


  //==================== ATTRIBUTI DELLA CLASSE  ====================

  /**
   * Conserva le variabili d'ambiente
   *
   * @var array $env Lista delle variabili d'ambiente
   */
  private $env;

  /**
   * Conserva la connessione al database come istanza PDO
   *
   * @var \PDO $pdo Connessione al database
   */
  private $pdo;

  /**
   * Conserva la Versione corrente di giua@school
   *
   * @var string $version Versione corrente di giua@school
   */
  private $version;

  /**
   * Conserva la modalità di installazione: Create o Update
   *
   * @var string $mode Modalità di installazione
   */
  private $mode;

  /**
   * Conserva il passo in esecuzione della procedura di installazione
   *
   * @var int $step Passo di installazione
   */
  private $step;

  /**
   * Conserva il token univoco di installazione
   *
   * @var string $token Token univoco di installazione
   */
  private $token;

  /**
   * Conserva il percorso della cartella pubblica (accessibile dal web)
   *
   * @var string $publicPath Percorso della cartella pubblica
   */
  private $publicPath;

  /**
   * Conserva il percorso della directory dell'applicazione
   *
   * @var string $projectPath Percorso della directory dell'applicazione
   */
  private $projectPath;

  /**
   * Conserva il percorso base della URL di esecuzione dell'applicazione
   *
   * @var string $urlPath Percorso base della URL di esecuzione
   */
  private $urlPath;

  /**
   * Conserva le procedure da eseguire a seconda della modalità e del passo
   *
   * @var array $procedure Percorso da eseguire
   */
  private $procedure = [
    'Create' => [
      '1' => 'pageInstall',
      '2' => 'pageAuthenticate',
      '3' => 'pageMandatory',
      '4' => 'pageOptional',
      '5' => 'pageDatabase',
      '6' => 'pageSchema',
      '7' => 'pageAdmin',
      '8' => 'pageSpid',
      '9' => 'pageSpidRequirements',
      '10' => 'pageSpidData',
      '11' => 'pageSpidConfig',
      '12' => 'pageClean',
      '13' => 'pageEnd',
    ],
  ];

  /**
   * Conserva la lista dei comandi sql per l'aggiornamento di versione
   *
   * @var array $dataUpdate Lista di comandi sql per l'aggiornamento di versione
   */
  private $dataUpdate = [];

  /**
   * Conserva la lista dei controlli sull'esecuzione dei comandi corrispondenti nella varie versioni
   *  Ogni elemento della lista è una SELECT SQL che restistuisce un insieme vuoto se
   *  il comando corrispondente è necessario.
   *
   * @var array $checkUpdate Lista di comandi sql per il controllo sui comandi da eseguire
   */
  private $checkUpdate = [];

  /**
   * Conserva la lista dei file da rimuovere per l'aggiornamento di versione.
   *
   * @var array $fileDelete Lista di file da rimuovere
   */
  private $fileDelete = [];


  //==================== METODI DELLA CLASSE ====================

  /**
   * Costruttore
   * Inizializza variabili di classe
   *
   * @param string $path Percorso della directory di esecuzione
   */
  public function __construct($path) {
    $this->env = [];
    $this->pdo = null;
    $this->version = null;
    $this->mode = null;
    $this->step = null;
    $this->token = null;
    $this->publicPath = $path;
    $this->projectPath = dirname($path);
    $this->urlPath = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on' ? 'https' : 'http').
      '://'.$_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI'];
    $this->urlPath = substr($this->urlPath, 0, -strlen('/install/index.php'));
  }

  /**
   * Avvia la procedura di installazione
   *
   */
  public function run() {
    // inizializza la sessione
    session_start();
    // determina pagina
    if (isset($_SESSION['GS_INSTALL']) && $_SESSION['GS_INSTALL']) {
      // installazione iniziata: recupera dati
      $this->env = $_SESSION['GS_INSTALL_ENV'];
      $this->version = $_SESSION['GS_INSTALL_VERSION'];
      $this->mode = $_SESSION['GS_INSTALL_MODE'];
      $this->step = $_SESSION['GS_INSTALL_STEP'];
      $this->token = $_SESSION['GS_INSTALL_TOKEN'];
    } else {
      // inizia la procedura di installazione
      $this->init();
      $_SESSION['GS_INSTALL'] = 1;
      $_SESSION['GS_INSTALL_ENV'] = $this->env;
      $_SESSION['GS_INSTALL_VERSION'] = $this->version;
      $_SESSION['GS_INSTALL_MODE'] = $this->mode;
      $_SESSION['GS_INSTALL_STEP'] = $this->step;
      $_SESSION['GS_INSTALL_TOKEN'] = $this->token;
    }
    try {
      // controllo token
      if ($this->step != 1) {
        $token = $_POST['install']['_token'] ?? null;
        if ($this->token !== $token) {
          // identificatore della procedura di installazione errato
          throw new \Exception('Errore di sicurezza nell\'invio dei dati');
        }
      }
      // esegue pagina
      $this->{$this->procedure[$this->mode][$this->step]}();
    } catch (\Exception $e) {
      // errore
      $this->pageError($e->getMessage(), $e->getCode());
    }
  }


  //==================== METODI PRIVATI DELLA CLASSE ====================

  /**
   * Esegue i controlli iniziali e determina se fare un'installazione iniziale o di aggiornamento
   *
   */
  private function init() {
    // imposta passo iniziale
    $this->step = 1;
    // imposta il token univoco di installazione
    $this->token = bin2hex(random_bytes(16));
    // controlla esistenza file .env
    $envPath = $this->projectPath.'/.env';
    if (!file_exists($envPath)) {
      // non esiste file .env
      $this->mode = 'Create';
      return;
    }
    // legge .env e carica variabili di ambiente
    $this->env = parse_ini_file($envPath);
    $this->env['APP_SECRET'] = '';
    // solo modalità installazione
    $this->mode = 'Create';
  }

  /**
   * Restituisce il valore del parametro di configurazione
   *
   * @param string $parameter Nome del parametro
   *
   * @return null|string Valore del parametro
   */
  private function getParameter($parameter) {
    // init
    $valore = null;
    if (!$this->pdo) {
      // connessione al db
      $this->connectDb();
    }
    // imposta query
    $sql = "SELECT valore FROM gs_configurazione WHERE parametro=:parameter";
    $stm = $this->pdo->prepare($sql);
    $stm->execute(['parameter' => $parameter]);
    $data = $stm->fetchAll();
    if (isset($data[0]['valore'])) {
      $valore = $data[0]['valore'];
    }
    // restituisce valore
    return $valore;
  }

  /**
   * Modifica il valore del parametro di configurazione
   *
   * @param string $parameter Nome del parametro
   * @param string $value Valore del parametro
   */
  private function setParameter($parameter, $value) {
    if (!$this->pdo) {
      // connessione al db
      $this->connectDb();
    }
    // modifica parametro
    $sql = "UPDATE gs_configurazione SET valore=:value WHERE parametro=:parameter";
    $stm = $this->pdo->prepare($sql);
    $stm->execute(['value' => $value, 'parameter' => $parameter]);
  }

  /**
   * Controlla i requisiti obbligatori per l'applicazione
   * Il vettore restituito contiene 4 campi per ogni requisito:
   *  [0] = descrizione del requisito (string)
   *  [1] = impostazione attuale (string)
   *  [2] = se il requisito è soddisfatto (bool)
   *  [3] = 'mandatory' o 'optional'
   *
   * @return array Vettore associativo con le informazioni sui requisiti controllati
   */
  private function mandatoryRequirements() {
    // init
    $data = [];
    // versione PHP
    $test = version_compare(PHP_VERSION, '7.4', '>=');
    $data[] = [
      'Versione PHP 7.4 o superiore',
      PHP_VERSION,
      $test, 'mandatory'];
    // estensioni PHP: Ctype
    $test = function_exists('ctype_alpha');
    $data[] = [
      'Estensione PHP: Ctype',
      $test ? 'INSTALLATA' : 'NON INSTALLATA',
      $test, 'mandatory'];
    // estensioni PHP: iconv
    $test = function_exists('iconv');
    $data[] = [
      'Estensione PHP: iconv',
      $test ? 'INSTALLATA' : 'NON INSTALLATA',
      $test, 'mandatory'];
    // estensioni PHP: JSON
    $test = function_exists('json_encode');
    $data[] = [
      'Estensione PHP: JSON',
      $test ? 'INSTALLATA' : 'NON INSTALLATA',
      $test, 'mandatory'];
    // estensioni PHP: mysqli
    $test = function_exists('mysqli_connect');
    $data[] = [
      'Estensione PHP: mysqli',
      $test ? 'INSTALLATA' : 'NON INSTALLATA',
      $test, 'mandatory'];
    // estensioni PHP: PCRE
    $test = defined('PCRE_VERSION');
    $data[] = [
      'Estensione PHP: PCRE',
      $test ? PCRE_VERSION : 'NON INSTALLATA',
      $test, 'mandatory'];
    // estensioni PHP: PDO
    $test = class_exists('PDO');
    $data[] = [
      'Estensione PHP: PDO',
      $test ? 'INSTALLATA' : 'NON INSTALLATA',
      $test, 'mandatory'];
    // estensioni PHP: Session
    $test = function_exists('session_start');
    $data[] = [
      'Estensione PHP: Session',
      $test ? 'INSTALLATA' : 'NON INSTALLATA',
      $test, 'mandatory'];
    // estensioni PHP: SimpleXML
    $test = function_exists('simplexml_import_dom');
    $data[] = [
      'Estensione PHP: SimpleXML',
      $test ? 'INSTALLATA' : 'NON INSTALLATA',
      $test, 'mandatory'];
    // estensioni PHP: Tokenizer
    $test = function_exists('token_get_all');
    $data[] = [
      'Estensione PHP: Tokenizer',
      $test ? 'INSTALLATA' : 'NON INSTALLATA',
      $test, 'mandatory'];
    // directory scrivibili: .
    $path = $this->projectPath;
    $test = is_dir($path) && is_writable($path);
    $data[] = [
      'Cartella principale dell\'applicazione con permessi di scrittura',
      $test ? 'SI' : 'NO (controlla: "'.$path.'")',
      $test, 'mandatory'];
    // directory scrivibili: var/cache
    $path = $this->projectPath.'/var/cache';
    $test = is_dir($path) && is_writable($path);
    $data[] = [
      'Cartella principale della cache di sistema con permessi di scrittura',
      $test ? 'SI' : 'NO (controlla: "'.$path.'")',
      $test, 'mandatory'];
    // directory scrivibili: var/cache/prod
    $path = $this->projectPath.'/var/cache/prod';
    $test = is_dir($path) && is_writable($path);
    $data[] = [
      'Cartella della cache di sistema con permessi di scrittura',
      $test ? 'SI' : 'NO (controlla: "'.$path.'")',
      $test, 'mandatory'];
    // directory scrivibili: log
    $path = $this->projectPath.'/var/log';
    $test = is_dir($path) && is_writable($path);
    $data[] = [
      'Cartella dei log di sistema con permessi di scrittura',
      $test ? 'SI' : 'NO (controlla: "'.$path.'")',
      $test, 'mandatory'];
    // directory scrivibili: sessions/prod
    $path = $this->projectPath.'/var/sessions/prod';
    $test = is_dir($path) && is_writable($path);
    $data[] = [
      'Cartella delle sessioni con permessi di scrittura',
      $test ? 'SI' : 'NO (controlla: "'.$path.'")',
      $test, 'mandatory'];
    // file scrivibili: .env
    $path = $this->projectPath.'/.env';
    $test = is_writable($path);
    $data[] = [
      'File di configurazione ".env" con permessi di scrittura',
      $test ? 'SI' : 'NO (controlla: "'.$path.'")',
      $test, 'mandatory'];
    // restituisce dati
    return $data;
  }

  /**
   * Controlla i requisiti opzionali per l'applicazione
   * Il vettore restituito contiene 4 campi per ogni requisito:
   *  [0] = descrizione del requisito (string)
   *  [1] = impostazione attuale (string)
   *  [2] = se il requisito è soddisfatto (bool)
   *  [3] = 'mandatory' o 'optional'
   *
   * @return array Vettore associativo con le informazioni sui requisiti controllati
   */
  private function optionalRequirements() {
    // init
    $data = [];
    // estensioni PHP: curl
    $test = function_exists('curl_version');
    $data[] = [
      'Estensione PHP: curl',
      $test ? 'INSTALLATA' : 'NON INSTALLATA',
      $test, 'optional'];
    // estensioni PHP: gd
    $test = function_exists('gd_info');
    $data[] = [
      'Estensione PHP: gd',
      $test ? 'INSTALLATA' : 'NON INSTALLATA',
      $test, 'optional'];
    // estensioni PHP: intl
    $test = extension_loaded('intl');
    $data[] = [
      'Estensione PHP: intl',
      $test ? 'INSTALLATA' : 'NON INSTALLATA',
      $test, 'optional'];
    // estensioni PHP: mbstring
    $test = function_exists('mb_strlen');
    $data[] = [
      'Estensione PHP: mbstring',
      $test ? 'INSTALLATA' : 'NON INSTALLATA',
      $test, 'optional'];
    // estensioni PHP: xml
    $test = extension_loaded('xml');
    $data[] = [
      'Estensione PHP: xml',
      $test ? 'INSTALLATA' : 'NON INSTALLATA',
      $test, 'optional'];
    // estensioni PHP: zip
    $test = extension_loaded('zip');
    $data[] = [
      'Estensione PHP: zip',
      $test ? 'INSTALLATA' : 'NON INSTALLATA',
      $test, 'optional'];
    // applicazione: unoconv
    $path = '/usr/bin/unoconv';
    $test = is_executable($path);
    $data[] = [
      'Applicazione UNOCONV per la conversione in PDF',
      $test ? 'INSTALLATA' : 'NON INSTALLATA',
      $test, 'optional'];
    // restituisce dati
    return $data;
  }

  /**
   * Controlla i requisiti per lo SPID
   * Il vettore restituito contiene 4 campi per ogni requisito:
   *  [0] = descrizione del requisito (string)
   *  [1] = impostazione attuale (string)
   *  [2] = se il requisito è soddisfatto (bool)
   *  [3] = 'mandatory' o 'optional'
   *
   * @return array Vettore associativo con le informazioni sui requisiti controllati
   */
  private function spidRequirements() {
    // init
    $data = [];
    // estensioni PHP: openssl
    $test = extension_loaded('openssl');
    $data[] = [
      'Estensione PHP: openssl',
      $test ? 'INSTALLATA' : 'NON INSTALLATA',
      $test, 'mandatory'];
    // directory scrivibili: vendor/italia/spid-php
    $path = $this->projectPath.'/vendor/italia/spid-php';
    $test = is_dir($path) && is_writable($path);
    $data[] = [
      'Cartella di configurazione dello SPID con permessi di scrittura',
      $test ? 'SI' : 'NO (controlla: "'.$path.'")',
      $test, 'mandatory'];
    // directory scrivibili: vendor/italia/spid-php/vendor/simplesamlphp/simplesamlphp/cert
    $path = $this->projectPath.'/vendor/italia/spid-php/vendor/simplesamlphp/simplesamlphp/cert';
    $test = is_dir($path) && is_writable($path);
    $data[] = [
      'Cartella di utilizzo del certificato SPID con permessi di scrittura',
      $test ? 'SI' : 'NO (controlla: "'.$path.'")',
      $test, 'mandatory'];
    // directory scrivibili: vendor/italia/spid-php/cert
    $path = $this->projectPath.'/vendor/italia/spid-php/cert';
    $test = is_dir($path) && is_writable($path);
    $data[] = [
      'Cartella di archivio del certificato SPID con permessi di scrittura',
      $test ? 'SI' : 'NO (controlla: "'.$path.'")',
      $test, 'mandatory'];
    // directory scrivibili: config/metadata
    $path = $this->projectPath.'/config/metadata';
    $test = is_dir($path) && is_writable($path);
    $data[] = [
      'Cartella di memorizzazione dei metadata con permessi di scrittura',
      $test ? 'SI' : 'NO (controlla: "'.$path.'")',
      $test, 'mandatory'];
    // directory scrivibili: vendor/italia/spid-php/vendor/simplesamlphp/simplesamlphp/log
    $path = $this->projectPath.'/vendor/italia/spid-php/vendor/simplesamlphp/simplesamlphp/log';
    $test = is_dir($path) && is_writable($path);
    $data[] = [
      'Cartella di log dello SPID con permessi di scrittura',
      $test ? 'SI' : 'NO (controlla: "'.$path.'")',
      $test, 'mandatory'];
    // restituisce dati
    return $data;
  }

  /**
   * Configura la libreria SPID-PHP
   *
   */
  private function spidSetup() {
    // inizializza
    $fs = new Filesystem();
    // legge configurazione e imposta validazione
    $validate = ($this->getParameter('spid') == 'validazione');
    $spid = json_decode(file_get_contents(
      $this->projectPath.'/vendor/italia/spid-php/spid-php-setup.json'), true);
    $spid['addValidatorIDP'] = $validate;
    // salva configurazione modificata
    unlink($this->projectPath.'/vendor/italia/spid-php/spid-php-setup.json');
    file_put_contents($this->projectPath.'/vendor/italia/spid-php/spid-php-setup.json',
      json_encode($spid));
    // crea certificati
    if (file_exists($spid['installDir'].'/cert/spid-sp.crt') && file_exists($spid['installDir'].'/cert/spid-sp.pem')) {
      // certificato esiste: aggiorna configurazione SAML
      $fs->mirror($spid['installDir'].'/cert',
        $spid['installDir'].'/vendor/simplesamlphp/simplesamlphp/cert');
    } else {
      // crea file configurazione SSL
      unlink($spid['installDir'].'/spid-php-openssl.cnf');
      $sslFile = fopen($spid['installDir'].'/spid-php-openssl.cnf', 'w');
      fwrite($sslFile, 'oid_section = spid_oids'."\n");
      fwrite($sslFile, "\n".'[ req ]'."\n");
      fwrite($sslFile, 'default_bits = 3072'."\n");
      fwrite($sslFile, 'default_md = sha256'."\n");
      fwrite($sslFile, 'distinguished_name = dn'."\n");
      fwrite($sslFile, 'encrypt_key = no'."\n");
      fwrite($sslFile, 'prompt = no'."\n");
      fwrite($sslFile, 'req_extensions  = req_ext'."\n");
      fwrite($sslFile, "\n".'[ spid_oids ]'."\n");
      fwrite($sslFile, 'spid-privatesector-SP=1.3.76.16.4.3.1'."\n");
      fwrite($sslFile, 'spid-publicsector-SP=1.3.76.16.4.2.1'."\n");
      fwrite($sslFile, 'uri=2.5.4.83'."\n");
      fwrite($sslFile, "\n".'[ dn ]'."\n");
      fwrite($sslFile, 'organizationName='.$spid['spOrganizationName']."\n");
      fwrite($sslFile, 'commonName='.$spid['spOrganizationDisplayName']."\n");
      fwrite($sslFile, 'uri='.$spid['entityID']."\n");
      fwrite($sslFile, 'organizationIdentifier='.$spid['spOrganizationIdentifier']."\n");
      fwrite($sslFile, 'countryName='.$spid['spCountryName']."\n");
      fwrite($sslFile, 'localityName='.$spid['spLocalityName']."\n");
      fwrite($sslFile, "\n".'[ req_ext ]'."\n");
      fwrite($sslFile, 'certificatePolicies = @spid_policies'."\n");
      fwrite($sslFile, "\n".'[ spid_policies ]'."\n");
      fwrite($sslFile, 'policyIdentifier = spid-publicsector-SP'."\n");
      fclose($sslFile);
      // crea certificato
      $errors = '';
      $sslParams = array(
        'config' => $spid['installDir'].'/spid-php-openssl.cnf',
        'x509_extensions' => 'req_ext');
  	 	if (($sslPkey = openssl_pkey_new($sslParams)) === false) {
        // errore di creazione del certificato
        while (($e = openssl_error_string()) !== false) {
          $errors .= '<br>'.$e;
        }
        $this->pageError('Impossibile creare il certificato per lo SPID (openssl_pkey_new).'.$errors, $this->step);
      }
      $sslDn = [
        'organizationName' => $spid['spOrganizationName'],
        'commonName' => $spid['spOrganizationDisplayName'],
        'uri' => $spid['entityID'],
        'organizationIdentifier' => $spid['spOrganizationIdentifier'],
        'countryName' => $spid['spCountryName'],
        'localityName' => $spid['spLocalityName']];
      if (($sslCsr = openssl_csr_new($sslDn, $sslPkey, $sslParams)) === false) {
        // errore di creazione del certificato
        while (($e = openssl_error_string()) !== false) {
          $errors .= '<br>'.$e;
        }
        $this->pageError('Impossibile creare il certificato per lo SPID (openssl_csr_new).'.$errors, $this->step);
      }
      if (($sslCert = openssl_csr_sign($sslCsr, null, $sslPkey, 730, $sslParams, time())) === false) {
        // errore di creazione del certificato
        while (($e = openssl_error_string()) !== false) {
          $errors .= '<br>'.$e;
        }
        $this->pageError('Impossibile creare il certificato per lo SPID (openssl_csr_sign).'.$errors, $this->step);
      }
      if (openssl_x509_export_to_file($sslCert, $spid['installDir'].'/vendor/simplesamlphp/simplesamlphp/cert/spid-sp.crt') === false) {
        // errore di creazione del certificato
        while (($e = openssl_error_string()) !== false) {
          $errors .= '<br>'.$e;
        }
        $this->pageError('Impossibile creare il certificato per lo SPID (openssl_x509_export_to_file).'.$errors, $this->step);
      }
      if (openssl_pkey_export_to_file($sslPkey, $spid['installDir'].'/vendor/simplesamlphp/simplesamlphp/cert/spid-sp.pem', null, $sslParams) === false) {
        // errore di creazione del certificato
        while (($e = openssl_error_string()) !== false) {
          $errors .= '<br>'.$e;
        }
        $this->pageError('Impossibile creare il certificato per lo SPID (openssl_pkey_export_to_file).'.$errors, $this->step);
      }
      // copia in directory di configurazione SPID
      $fs->mirror($spid['installDir'].'/vendor/simplesamlphp/simplesamlphp/cert',
        $spid['installDir'].'/cert');
    }
    // crea link a dir pubblica
    $fs->symlink($spid['installDir'].'/vendor/simplesamlphp/simplesamlphp/www',
      $spid['wwwDir'].'/'.$spid['serviceName']);
    // crea link a dir log
    $fs->symlink($spid['installDir'].'/vendor/simplesamlphp/simplesamlphp/log',
      $this->projectPath.'/var/log/'.$spid['serviceName']);
    // personalizza configurazione SAML
    $db = parse_url($this->env['DATABASE_URL']);
    $vars = array(
      '{{BASEURLPATH}}' => "'".$spid['serviceName']."/'",
      '{{ADMIN_PASSWORD}}' => "'".$spid['adminPassword']."'",
      '{{SECRETSALT}}' => "'".$spid['secretsalt']."'",
      '{{TECHCONTACT_NAME}}' => "'".$spid['technicalContactName']."'",
      '{{TECHCONTACT_EMAIL}}' => "'".$spid['technicalContactEmail']."'",
      '{{ACSCUSTOMLOCATION}}' => "'".$spid['acsCustomLocation']."'",
      '{{SLOCUSTOMLOCATION}}' => "'".$spid['sloCustomLocation']."'",
      '{{SP_DOMAIN}}' => "'".$spid['spDomain']."'",
      '{{DB_DSN}}' => "'".$db['scheme'].':host='.$db['host'].';port='.$db['port'].';dbname='.substr($db['path'], 1)."'",
      '{{DB_USER}}' => "'".$db['user']."'",
      '{{DB_PASW}}' => "'".$db['pass']."'");
    $template = file_get_contents($spid['installDir'].'/setup/config/config.tpl');
    $customized = str_replace(array_keys($vars), $vars, $template);
    $dest = $spid['installDir'].'/vendor/simplesamlphp/simplesamlphp/config/config.php';
    if (file_put_contents($dest, $customized) === false) {
      // errore di creazione del file
      $this->pageError('Impossibile creare il file di configurazione SAML (config.php).', $this->step);
    }
    // personalizza configurazione SP
    $vars = array(
      '{{ENTITYID}}' => "'".$spid['entityID']."'",
      '{{NAME}}' => "'".$spid['spName']."'",
      '{{DESCRIPTION}}' => "'".$spid['spDescription']."'",
      '{{ORGANIZATIONNAME}}' => "'".$spid['spOrganizationName']."'",
      '{{ORGANIZATIONDISPLAYNAME}}' => "'".$spid['spOrganizationDisplayName']."'",
      '{{ORGANIZATIONURL}}' => "'".$spid['spOrganizationURL']."'",
      '{{ACSINDEX}}' => $spid['acsIndex'],
      '{{ATTRIBUTES}}' => implode(',', $spid['attr']),
      '{{ORGANIZATIONCODETYPE}}' => "'".$spid['spOrganizationCodeType']."'",
      '{{ORGANIZATIONCODE}}' => "'".$spid['spOrganizationCode']."'",
      '{{ORGANIZATIONEMAILADDRESS}}' => "'".$spid['spOrganizationEmailAddress']."'",
      '{{ORGANIZATIONTELEPHONENUMBER}}' => "'".$spid['spOrganizationTelephoneNumber']."'");
    $template = file_get_contents($spid['installDir'].'/setup/config/authsources_public.tpl');
    $customized = str_replace(array_keys($vars), $vars, $template);
    $dest = $spid['installDir'].'/vendor/simplesamlphp/simplesamlphp/config/authsources.php';
    if (file_put_contents($dest, $customized) === false) {
      // errore di creazione del file
      $this->pageError('Impossibile creare il file di configurazione del Service Provider (authsources.php).', $this->step);
    }
    // aggiorna metadata
    require ($spid['installDir'].'/setup/Setup.php');
    require ($spid['installDir'].'/setup/Colors.php');
    chdir($spid['installDir']);
    try {
      ob_start();
      \SPID_PHP\Setup::updateMetadata();
      ob_end_clean();
      chdir($this->projectPath.'/public/install');
    } catch (\Exception $e) {
      // errore
      chdir($this->projectPath.'/public/install');
      $this->pageError($e->getMessage(), $this->step);
    }
    // copia HTML pulsante SPID
    $pathSource = $spid['installDir'].'/vendor/italia/spid-sp-access-button/src/production';
    $pathDest = $spid['installDir'].'/vendor/simplesamlphp/simplesamlphp/www/spid-sp-access-button';
    foreach (['/css', '/img', '/js'] as $value) {
      $source = $pathSource.$value;
      $dest = $pathDest.$value;
      $fs->mkdir($dest);
      $fs->mirror($source, $dest);
    }
    // copia template twig per SPID
    $fs->mirror($spid['installDir'].'/setup/simplesamlphp/simplesamlphp/templates',
      $spid['installDir'].'/vendor/simplesamlphp/simplesamlphp/templates');
  }

  /**
   * Verifica le credenziali di accesso alla procedura
   *
   * @param string $password Password di installazione
   *
   */
  private function authenticate($password) {
    // carica variabili di ambiente
    $envPath = $this->projectPath.'/.env';
    if (!file_exists($envPath)) {
      // non esiste file .env
      throw new \Exception('Il file ".env" non esiste', $this->step);
    }
    // legge .env e carica variabili di ambiente
    $env = parse_ini_file($envPath);
    if (!isset($env['INSTALLATION_PSW']) || empty($env['INSTALLATION_PSW'])) {
      // non esiste password di installazione
      throw new \Exception('Il parametro "INSTALLATION_PSW" non è configurato all\'interno del file .env', $this->step);
    }
    // controlla password
    if ($env['INSTALLATION_PSW'] !== $password) {
      // password di installazione diversa
      throw new \Exception('La password di installazione non corrisponde a quelle del parametro "INSTALLATION_PSW"', $this->step);
    }
    // memorizza password in configurazione
    $this->env['INSTALLATION_PSW'] = $password;
    $_SESSION['GS_INSTALL_ENV'] = $this->env;
  }

  /**
   * Crea il database iniziale
   *
   */
  private function createSchema() {
    // comandi per la creazione del db
    $commands = [
      new ArrayInput(['command' => 'doctrine:database:create', '--if-not-exists' => null]),
      new ArrayInput(['command' => 'doctrine:schema:drop', '--full-database' => null, '--force' => null]),
      new ArrayInput(['command' => 'doctrine:schema:create'])
    ];
    // esegue comandi
    $kernel = new Kernel('prod', false);
    $application = new Application($kernel);
    $application->setAutoExit(false);
    $output = new BufferedOutput();
    try {
      foreach ($commands as $com) {
        $status = $application->run($com, $output);
        $content = $output->fetch();
        if ($status != 0) {
          break;
        }
      }
    } catch (\Exception $e) {
      // errore di sistema
      $status = -1;
      $content = $e->getMessage();
    }
    // controlla errori
    if ($status != 0) {
      // errore di sistema
      throw new \Exception('Impossibile eseguire i comandi per creare il database.<br><br>'.$content, $this->step);
    }
    if (!$this->pdo) {
      // connessione al db
      $this->connectDb();
    }
    // inizializza il database
    $file = file($this->projectPath.'/src/Install/init-db.sql', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    try {
      foreach ($file as $sql) {
        $this->pdo->exec($sql);
      }
    } catch (\Exception $e) {
      $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
      throw new \Exception('Errore nell\'esecuzione dei comandi per l\'inizializzazione del database.<br>'.
        $e->getMessage(), $this->step);
    }
    $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
  }

  /**
   * Aggiorna il database alla nuova versione
   *
   */
  private function updateSchema() {
    if (!$this->pdo) {
      // connessione al db
      $this->connectDb();
    }
    // esegue prima l'aggiornamento dei file
    $this->updateFiles();
    // legge versione attuale
    $version = $this->getParameter('versione');
    foreach ($this->dataUpdate as $newVersion=>$data) {
      if ($newVersion != 'build' && version_compare($newVersion, $version, '<=')) {
        // salta versione
        continue;
      }
      // controlla comandi da eseguire
      if (in_array($newVersion, array_keys($this->checkUpdate))) {
        try {
          foreach ($this->checkUpdate[$newVersion] as $key=>$sql) {
            $stm = $this->pdo->prepare($sql);
            $stm->execute();
            if (!empty($stm->fetchAll())) {
              // evita esecuzione comando non necessario
              unset($data[$key]);
            }
          }
        } catch (\Exception $e) {
          throw new \Exception('Errore nell\'esecuzione dei comandi per l\'aggiornamento del database.<br>'.
            $e->getMessage(), $this->step);
        }
      }
      // esegue i comandi
      try {
        foreach ($data as $sql) {
          $this->pdo->exec($sql);
        }
      } catch (\Exception $e) {
        throw new \Exception('Errore nell\'esecuzione dei comandi per l\'aggiornamento del database.<br>'.
          $e->getMessage(), $this->step);
      }
      // nuova versione installata
      if ($newVersion != 'build') {
        $this->setParameter('versione', $newVersion);
      } else {
        $newVersion = $version.(empty($data) ? '' : '#build');
      }
      // esegue un aggiornamento alla volta
      return $newVersion;
    }
    // nessun aggiornamento eseguito
    return $version;
  }

  /**
   * Crea l'utente amministratore
   *
   * @param string $username Nome utente
   * @param string $password Password utente in chiaro
   */
  private function createAdmin($username, $password) {
    // comandi per la codifica della password
    $commands = [
      new ArrayInput(['command' => 'security:encode-password',
        'password' => $password,
        'user-class' => '\App\Entity\Amministratore',
        '-n' => null])
    ];
    // ricarica ambiente modificato
    (new Dotenv(false))->loadEnv($this->projectPath.'/.env');
    // esegue comandi
    $kernel = new Kernel('prod', false);
    $application = new Application($kernel);
    $application->setAutoExit(false);
    $output = new BufferedOutput();
    try {
      foreach ($commands as $com) {
        $status = $application->run($com, $output);
        $content = $output->fetch();
        if ($status != 0) {
          break;
        }
      }
    } catch (\Exception $e) {
      // errore di sistema
      $status = -1;
      $content = $e->getMessage();
    }
    // controlla errori
    if ($status != 0) {
      // errore di sistema
      throw new \Exception('Impossibile eseguire i comandi per cifrare la password.<br><br>'.$content, $this->step);
    }
    // legge password
    preg_match('/Encoded password\s+(.*)\s+/', $content, $matches);
    $pswd = trim($matches[1]);
    if (!$this->pdo) {
      // connessione al db
      $this->connectDb();
    }
    // modifica l'utente amministratore
    $sql = "UPDATE gs_utente SET username='$username', password='$pswd', email='$username@noemail.local' WHERE username='admin';";
    // esegue i comandi
    try {
      $this->pdo->exec($sql);
    } catch (\Exception $e) {
      throw new \Exception('Errore nell\'esecuzione del comando per la creazione dell\'utente amministratore<br>'.
        $e->getMessage(), $this->step);
    }
  }

  /**
   * Pulisce la cache di sistema
   *
   */
  private function clean() {
    // cancella contenuto cache
    $this->fileDelete($this->projectPath.'/var/cache/prod');
    // cancella contenuto delle sessioni
    $this->fileDelete($this->projectPath.'/var/sessions/prod');
  }

  /**
   * Cancella i file e le sottodirectory del percorso indicato
   *
   * @param string $dir Percorso della directory da cancellare
   */
  private function fileDelete($dir) {
    foreach(glob($dir . '/*') as $file) {
      if ($file == '.' || $file == '..') {
        // salta
        continue;
      } elseif(is_dir($file)) {
        // rimuove directory e suo contenuto
        $this->fileDelete($file);
        rmdir($file);
      } else {
        // rimuove file
        unlink($file);
      }
    }
  }

  /**
   * Legge la configurazione attuale e la prepara per la scrittura
   *
   * @return string Configurazione formattata
   */
  private function formatEnv(): string {
    // imposta configurazione
    $envData =
      "### definisce l'ambiente correntemente utilizzato\n".
      "APP_ENV='".$this->env['APP_ENV']."'\n\n".
      "### codice segreto univoco usato nella gestione della sicurezza\n".
      "APP_SECRET='".$this->env['APP_SECRET']."'\n\n".
      "### parametri di connessione al database\n".
      "DATABASE_URL='".$this->env['DATABASE_URL']."'\n\n".
      "### parametri di connessione al server email\n".
      "MAILER_DSN='".$this->env['MAILER_DSN']."'\n\n".
      "### parametri di configurazione per l'invio dei messaggi\n".
      "MESSENGER_TRANSPORT_DSN='".$this->env['MESSENGER_TRANSPORT_DSN']."'\n\n".
      "### autenticazione tramite Google Workspace\n".
      "GOOGLE_API_KEY='".$this->env['GOOGLE_API_KEY']."'\n".
      "GOOGLE_CLIENT_ID='".$this->env['GOOGLE_CLIENT_ID']."'\n".
      "GOOGLE_CLIENT_SECRET='".$this->env['GOOGLE_CLIENT_SECRET']."'\n".
      "OAUTH_GOOGLE_CLIENT_ID='".$this->env['OAUTH_GOOGLE_CLIENT_ID']."'\n".
      "OAUTH_GOOGLE_CLIENT_SECRET='".$this->env['OAUTH_GOOGLE_CLIENT_SECRET']."'\n".
      "OAUTH_GOOGLE_CLIENT_HD='".$this->env['OAUTH_GOOGLE_CLIENT_HD']."'\n\n".
      "### imposta il livello del log del sistema in produzione\n".
      "LOG_LEVEL='".$this->env['LOG_LEVEL']."'\n\n".
      "### imposta la password di installazione\n".
      "INSTALLATION_PSW='".$this->env['INSTALLATION_PSW']."'\n\n";
    // restituisce configurazione
    return $envData;
  }

  /**
   * Scrive la configurazione sul file .env
   *
   */
  private function writeEnv() {
    // imposta nuove variabili d'ambiente
    $env = [];
    $env['APP_ENV'] = (empty($this->env['APP_ENV']) ? 'prod' : $this->env['APP_ENV']);
    $env['APP_SECRET'] = (empty($this->env['APP_SECRET']) ? bin2hex(random_bytes(20)) : $this->env['APP_SECRET']);
    $env['DATABASE_URL'] = (empty($this->env['DATABASE_URL']) ? 'mysql://root:root@localhost:3306/giuaschool' : $this->env['DATABASE_URL']);
    $env['MAILER_DSN'] = (empty($this->env['MAILER_DSN']) ? 'null://null' : $this->env['MAILER_DSN']);
    $env['MESSENGER_TRANSPORT_DSN'] = (empty($this->env['MESSENGER_TRANSPORT_DSN']) ? 'doctrine://default' : $this->env['MESSENGER_TRANSPORT_DSN']);
    $env['GOOGLE_API_KEY'] = (empty($this->env['GOOGLE_API_KEY']) ? '' : $this->env['GOOGLE_API_KEY']);
    $env['GOOGLE_CLIENT_ID'] = (empty($this->env['GOOGLE_CLIENT_ID']) ? '' : $this->env['GOOGLE_CLIENT_ID']);
    $env['GOOGLE_CLIENT_SECRET'] = (empty($this->env['GOOGLE_CLIENT_SECRET']) ? '' : $this->env['GOOGLE_CLIENT_SECRET']);
    $env['OAUTH_GOOGLE_CLIENT_ID'] = (empty($this->env['OAUTH_GOOGLE_CLIENT_ID']) ? '' : $this->env['OAUTH_GOOGLE_CLIENT_ID']);
    $env['OAUTH_GOOGLE_CLIENT_SECRET'] = (empty($this->env['OAUTH_GOOGLE_CLIENT_SECRET']) ? '' : $this->env['OAUTH_GOOGLE_CLIENT_SECRET']);
    $env['OAUTH_GOOGLE_CLIENT_HD'] = (empty($this->env['OAUTH_GOOGLE_CLIENT_HD']) ? '' : $this->env['OAUTH_GOOGLE_CLIENT_HD']);
    $env['LOG_LEVEL'] = (empty($this->env['LOG_LEVEL']) ? 'warning' : $this->env['LOG_LEVEL']);
    $env['INSTALLATION_PSW'] = (empty($this->env['INSTALLATION_PSW']) ? '' : $this->env['INSTALLATION_PSW']);
    $this->env = $env;
    $_SESSION['GS_INSTALL_ENV'] = $this->env;
    // scrive nuova configurazione
    $envPath = $this->projectPath.'/';
    $envData = $this->formatEnv();
    try {
      unlink($envPath.'.env');
      file_put_contents($envPath.'.env', $envData);
    } catch (\Exception $e) {
      // errore: impossibile scriver configurazione
      throw new \Exception('Impossibile scrivere la nuova configurazione nel file ".env"<br>'.
        $e->getMessage(), $this->step);
    }
  }

  /**
   * Scrive la configurazione sul file .env
   *
   * @param bool $onlyserver Se vero, si connette al server senza indicare il nome del database
   */
  private function connectDb($onlyserver=false) {
    // connessione al database
    $db = parse_url($this->env['DATABASE_URL']);
    $dsn = $db['scheme'].':host='.$db['host'].';port='.$db['port'].
      ($onlyserver ? '' : (';dbname='.substr($db['path'], 1)));
    try {
      $this->pdo = new \PDO($dsn, $db['user'], $db['pass']);
    } catch (\Exception $e) {
      // errore di connessione
      $this->pdo = null;
      throw new \Exception('Impossibile connettersi al database', $this->step);
    }
  }

  /**
   * Aggiorna i file alla nuova versione, cancellando quelli indicati
   *
   */
  private function updateFiles() {
    $fs = new Filesystem();
    // legge versione attuale
    $version = $this->getParameter('versione');
    // esegue aggiornamento per tutte versioni necessarie
    foreach ($this->fileDelete as $newVersion=>$data) {
      if ($newVersion != 'build' && version_compare($newVersion, $version, '<=')) {
        // salta versione
        continue;
      }
      // esegue i comandi
      try {
        if (count($data) > 0) {
          $files = array_map(function($f) { return $this->projectPath.'/'.$f; }, $data);
          $fs->remove($files);
        }
      } catch (\Exception $e) {
        throw new \Exception('Errore nell\'esecuzione dei comandi per l\'aggiornamento dei file.<br>'.
          $e->getMessage(), $this->step);
      }
      // passa alla versione successiva
      $version = $newVersion;
    }
  }

  /**
   * Mostra errore e blocca installazione
   *
   * @param string $error Messaggio di errore
   * @param int $step Numero passo a cui riportare la pagina
   */
  private function pageError($error, $step) {
    // imposta dati della pagina
    $page['step'] = 'Errore';
    $page['title'] = 'Si è verificato un errore';
    $page['_token'] = $this->token;
    $page['danger'] = $error;
    $page['text'] = "Correggi l'errore e riprova.";
    // visualizza pagina
    include('page_error.php');
    if ($step > 0) {
      // imposta passo
      $_SESSION['GS_INSTALL_STEP'] = $step;
    } else {
      // resetta la sessione (riparte dall'inizio)
      $_SESSION = [];
      session_destroy();
    }
    // termina esecuzione
    die();
  }

  /**
   * Pagina per la scelta della procedura di installazione
   *
   */
  private function pageInstall() {
    if (isset($_POST['install']['step']) && $_POST['install']['step'] == $this->step) {
      if (isset($_POST['install']['create'])) {
        // installazione iniziale
        $this->mode = 'Create';
        $_SESSION['GS_INSTALL_MODE'] = $this->mode;
      }
      // va al passo successivo
      $page = [];
      $this->step++;
      $_SESSION['GS_INSTALL_STEP'] = $this->step;
      $this->{$this->procedure[$this->mode][$this->step]}();
    } else {
      // imposta dati della pagina
      $page['step'] = $this->step.' - Installazione';
      $page['title'] = 'Procedura di installazione';
      $page['_token'] = $this->token;
      if ($this->mode == 'Create') {
        // installazione iniziale
        $page['warning'] = 'Verrà eseguita una nuova installazione.<br>'.
          "ATTENZIONE: l'eventuale contenuto del database sarà cancellato.";
        $page['update'] = false;
      } else {
        // aggiornamento alla versione
        $page['info'] = 'Verrà eseguita la procedura di aggiornamento.<br>'.
          'Il contenuto esistente del database non sarà modificato.<br><br>'.
          '<em>In alternativa, puoi eseguire la procedura di installazione iniziale, '.
          'che prevede la cancellazione del database esistente.</em>';
        $page['update'] = true;
      }
      // visualizza pagina
      include('page_install.php');
    }
  }

  /**
   * Pagina per l'autenticazione iniziale
   *
   */
  private function pageAuthenticate() {
    if (isset($_POST['install']['step']) && $_POST['install']['step'] == $this->step) {
      // effettua l'autenticazione
      $password = $_POST['install']['password'];
      $this->authenticate($password);
      // va al passo successivo
      $page = [];
      $this->step++;
      $_SESSION['GS_INSTALL_STEP'] = $this->step;
      $this->{$this->procedure[$this->mode][$this->step]}();
    } else {
      // imposta dati della pagina
      $page['step'] = $this->step.' - Autenticazione';
      $page['title'] = 'Autenticazione iniziale';
      $page['_token'] = $this->token;
      // visualizza pagina
      include('page_authenticate.php');
    }
  }

  /**
   * Pagina per i requisiti tecnici obbligatori
   *
   */
  private function pageMandatory() {
    // imposta dati della pagina
    $page['step'] = $this->step.' - Requisiti obbligatori';
    $page['title'] = 'Requisiti tecnici obbligatori';
    $page['_token'] = $this->token;
    $page['requirements'] = $this->mandatoryRequirements();
    // controlla errori
    $error = false;
    foreach ($page['requirements'] as $req) {
      if (!$req[2]) {
        $error = true;
        break;
      }
    }
    if ($error) {
      // messaggio di errore
      $page['danger'] = "Non si può continuare con l'installazione.<br>".
        "Il sistema non soddisfa i requisiti tecnici indispensabili per il funzionameno dell'applicazione.";
    }
    // visualizza pagina
    include('page_requirements.php');
    // imposta nuova pagina
    if (!$error) {
      // pagina successiva
      $_SESSION['GS_INSTALL_STEP'] = $this->step + 1;
    }
  }

  /**
   * Pagina per i requisiti tecnici opzionali
   *
   */
  private function pageOptional() {
    // imposta dati della pagina
    $page['step'] = $this->step.' - Requisiti opzionali';
    $page['title'] = 'Requisiti tecnici opzionali';
    $page['_token'] = $this->token;
    $page['requirements'] = $this->optionalRequirements();
    // controlla errori
    $error = false;
    foreach ($page['requirements'] as $req) {
      if (!$req[2]) {
        $error = true;
        break;
      }
    }
    if ($error) {
      // messaggio di errore
      $page['warning'] = "La procedura di installazione può continuare.<br>".
        "Alcune funzionalità non essenziali potrebbero non funzionare correttamente.";
    }
    // visualizza pagina
    include('page_requirements.php');
    // imposta nuova pagina
    $_SESSION['GS_INSTALL_STEP'] = $this->step + 1;
  }

  /**
   * Pagina per le impostazioni del database
   *
   */
  private function pageDatabase() {
    if (isset($_POST['install']['step']) && $_POST['install']['step'] == $this->step) {
      // connessione di test al db (solo server, senza nome database)
      $this->connectDb(true);
      // chiude connessione di test
      $this->pdo = null;
      // salva configurazione
      $this->env['DATABASE_URL'] = 'mysql://'.$_POST['install']['db_user'].':'.
        $_POST['install']['db_password'].'@'.$_POST['install']['db_server'].':'.
        $_POST['install']['db_port'].'/'.$_POST['install']['db_name'];
      $_SESSION['GS_INSTALL_ENV'] = $this->env;
      $this->writeEnv();
      // ricarica ambiente modificato
      (new Dotenv(false))->loadEnv($this->projectPath.'/.env');
      // va al passo successivo
      $page = [];
      $this->step++;
      $_SESSION['GS_INSTALL_STEP'] = $this->step;
      $this->{$this->procedure[$this->mode][$this->step]}();
    } else {
      // imposta dati della pagina
      $page['step'] = $this->step.' - Impostazioni database';
      $page['title'] = 'Impostazioni per la connessione al database';
      $page['_token'] = $this->token;
      $page['warning'] = "ATTENZIONE: l'eventuale contenuto del database sarà cancellato.";
      $page['db_server'] = 'localhost';
      $page['db_port'] = '3306';
      $page['db_user'] = '';
      $page['db_password'] = '';
      $page['db_name'] = 'giuaschool';
      if (isset($this->env['DATABASE_URL']) && !empty($this->env['DATABASE_URL'])) {
        // legge configurazione
        $db = parse_url($this->env['DATABASE_URL']);
        $page['db_server'] = $db['host'];
        $page['db_port'] = $db['port'];
        $page['db_user'] = $db['user'];
        $page['db_password'] = $db['pass'];
        $page['db_name'] = substr($db['path'], 1);
      }
      // visualizza pagina
      include('page_database.php');
    }
  }

  /**
   * Pagina per la creazione dello schema sul database
   *
   */
  private function pageSchema() {
    // crea il database iniziale
    $this->createSchema();
    // imposta dati della pagina
    $page['step'] = $this->step.' - Creazione database';
    $page['title'] = 'Creazione del database iniziale';
    $page['_token'] = $this->token;
    $page['success'] = 'Il nuovo database è stato creato correttamente.';
    // visualizza pagina
    include('page_message.php');
    // imposta nuovo passo
    $_SESSION['GS_INSTALL_STEP'] = $this->step + 1;
  }

  /**
   * Pagina per la creazione dell'amministratore
   *
   */
  private function pageAdmin() {
    if (isset($_POST['install']['step']) && $_POST['install']['step'] == $this->step) {
      // controllo credenziali
      $username = trim($_POST['install']['username']);
      if (strlen($username) < 4) {
        // username troppo corto
        throw new \Exception('Il nome utente deve avere una lunghezza di almeno 4 caratteri', $this->step);
      }
      $password = trim($_POST['install']['password']);
      if (strlen($password) < 8) {
        // password troppo corta
        throw new \Exception('La password deve avere una lunghezza di almeno 8 caratteri', $this->step);
      }
      // crea utente
      $this->createAdmin($username, $password);
      // va al passo successivo
      $page = [];
      $this->step++;
      $_SESSION['GS_INSTALL_STEP'] = $this->step;
      $this->{$this->procedure[$this->mode][$this->step]}();
    } else {
      // imposta dati della pagina
      $page['step'] = $this->step.' - Utente amministratore';
      $page['title'] = 'Credenziali di accesso per l\'utente amministratore';
      $page['_token'] = $this->token;
      // visualizza pagina
      include('page_admin.php');
    }
  }

  /**
   * Pagina per la configurazione dello SPID
   *
   */
  private function pageSpid() {
    if (isset($_POST['install']['step']) && $_POST['install']['step'] == $this->step) {
      // imposta l'utilizzo dello SPID
      $spid = $_POST['install']['spid'];
      if ($spid == 'validazione') {
        // validazione: va alla pagina successiva
        $this->step++;
      } elseif ($spid == 'si') {
        // spid attivo: salta configurazione
        $this->step += 3;
      } else {
        // spid non usato: salta tutto
        $spid = 'no';
        $this->step += 4;
      }
      // scrive su db
      $this->setParameter('spid', $spid);
      // salta alla prossima pagina
      $page = [];
      $_SESSION['GS_INSTALL_STEP'] = $this->step;
      $this->{$this->procedure[$this->mode][$this->step]}();
    } else {
      // imposta dati della pagina
      $page['step'] = $this->step.' - Configurazione SPID';
      $page['title'] = 'Configurazione dell\'accesso tramite SPID';
      $page['_token'] = $this->token;
      $page['spid'] = $this->getParameter('spid');
      // visualizza pagina
      include('page_spid.php');
    }
  }

  /**
   * Pagina per i requisiti tecnici dello SPID
   *
   */
  private function pageSpidRequirements() {
    // imposta dati della pagina
    $page['step'] = $this->step.' - Requisiti SPID';
    $page['title'] = 'Requisiti tecnici obbligatori per l\'utilizzo dello SPID';
    $page['_token'] = $this->token;
    $page['requirements'] = $this->spidRequirements();
    // controlla errori
    $error = false;
    foreach ($page['requirements'] as $req) {
      if (!$req[2]) {
        $error = true;
        break;
      }
    }
    if ($error) {
      // messaggio di errore
      $page['danger'] = "Non si può continuare con la configurazione dello SPID.<br>".
        "Il sistema non soddisfa i requisiti tecnici indispensabili per il funzionameno dell'accesso SPID.";
    }
    // visualizza pagina
    include('page_requirements.php');
    // imposta nuova pagina
    if (!$error) {
      // pagina successiva
      $_SESSION['GS_INSTALL_STEP'] = $this->step + 1;
    } else {
      // pagina precedente
      $_SESSION['GS_INSTALL_STEP'] = $this->step - 1;
    }
  }

  /**
   * Pagina per le impostazioni dello SPID
   *
   */
  private function pageSpidData() {
    // legge configurazione esistente
    $spid = json_decode(file_get_contents(
      $this->projectPath.'/vendor/italia/spid-php/spid-php-setup.json'), true);
    // controlla pagina
    if (isset($_POST['install']['step']) && $_POST['install']['step'] == $this->step) {
      // controlla i dati
      $spid['entityID'] = strtolower(trim($_POST['install']['entityID']));
      if (empty($spid['entityID'])) {
        // errore
        throw new \Exception('Non è stato indicato l\'identificativo del service provider', $this->step);
      }
      if (substr($spid['entityID'], 0, 7) != 'http://' && substr($spid['entityID'], 0, 8) != 'https://') {
        // errore
        throw new \Exception('L\'identificativo del service provider deve essere un indirizzo internet', $this->step);
      }
      $spid['spLocalityName'] = str_replace("'", "\\'", trim($_POST['install']['spLocalityName']));
      if (empty($spid['spLocalityName'])) {
        // errore
        throw new \Exception('Non è stata indicata la sede legale del service provider', $this->step);
      }
      $spid['spName'] = str_replace("'", "\\'", trim($_POST['install']['spName']));
      if (empty($spid['spName'])) {
        // errore
        throw new \Exception('Non è stato indicato il nome del service provider', $this->step);
      }
      $spid['spDescription'] = str_replace("'", "\\'", trim($_POST['install']['spDescription']));
      if (empty($spid['spDescription'])) {
        // errore
        throw new \Exception('Non è stata indicata la descrizione del service provider', $this->step);
      }
      $spid['spOrganizationName'] = str_replace("'", "\\'", trim($_POST['install']['spOrganizationName']));
      if (empty($spid['spOrganizationName'])) {
        // errore
        throw new \Exception('Non è stato indicato il nome completo dell\'ente', $this->step);
      }
      $spid['spOrganizationDisplayName'] = str_replace("'", "\\'", trim($_POST['install']['spOrganizationDisplayName']));
      if (empty($spid['spOrganizationDisplayName'])) {
        // errore
        throw new \Exception('Non è stato indicato il nome abbreviato dell\'ente', $this->step);
      }
      $spid['spOrganizationURL'] = trim($_POST['install']['spOrganizationURL']);
      if (empty($spid['spOrganizationURL'])) {
        // errore
        throw new \Exception('Non è stata indicato l\'indirizzo internet dell\'ente', $this->step);
      }
      if (substr($spid['spOrganizationURL'], 0, 7) != 'http://' && substr($spid['spOrganizationURL'], 0, 8) != 'https://') {
        // errore
        throw new \Exception('L\'indirizzo internet dell\'ente non è valido', $this->step);
      }
      $spid['spOrganizationCode'] = trim($_POST['install']['spOrganizationCode']);
      if (empty($spid['spOrganizationCode'])) {
        // errore
        throw new \Exception('Non è stato indicato il codice IPA dell\'ente', $this->step);
      }
      $spid['spOrganizationEmailAddress'] = trim($_POST['install']['spOrganizationEmailAddress']);
      if (empty($spid['spOrganizationEmailAddress'])) {
        // errore
        throw new \Exception('Non è stato indicato l\'indirizzo email dell\'ente', $this->step);
      }
      if (strpos($spid['spOrganizationEmailAddress'], '@') === false) {
        // errore
        throw new \Exception('L\'indirizzo email dell\'ente non è valido', $this->step);
      }
      $spid['spOrganizationTelephoneNumber'] = str_replace(' ', '', trim($_POST['install']['spOrganizationTelephoneNumber']));
      if (empty($spid['spOrganizationTelephoneNumber'])) {
        // errore
        throw new \Exception('Non è stato indicato il numero di telefono dell\'ente', $this->step);
      }
      if ($spid['spOrganizationTelephoneNumber'][0] != '+' && substr($spid['spOrganizationTelephoneNumber'], 0, 2) != '00') {
        // aggiunge prefisso internazionale
        $spid['spOrganizationTelephoneNumber'] = '+39'.$spid['spOrganizationTelephoneNumber'];
      }
      // imposta dominio service provider
      $spid['spDomain'] = parse_url($spid['entityID'], PHP_URL_HOST);
      if (substr($spid['spDomain'], 0, 4) == 'www.') {
        $spid['spDomain'] = substr($spid['spDomain'], 4);
      }
      // imposta identificatore ente
      $spid['spOrganizationIdentifier'] = 'PA:IT-'. $spid['spOrganizationCode'];
      if (empty($spid['installDir'])) {
        // imposta directory di installazione SPID
        $spid['installDir'] = $this->projectPath.'/vendor/italia/spid-php';
      }
      if (empty($spid['wwwDir'])) {
        // imposta directory pubblica dello SPID
        $spid['wwwDir'] = $this->publicPath;
      }
      if (empty($spid['adminPassword'])) {
        // imposta password admin SPID
        $spid['adminPassword'] = uniqid();
      }
      if (empty($spid['secretsalt'])) {
        // imposta salt per crittografia
        $spid['secretsalt'] = bin2hex(random_bytes(16));
      }
      // salva configurazione
      unlink($this->projectPath.'/vendor/italia/spid-php/spid-php-setup.json');
      file_put_contents($this->projectPath.'/vendor/italia/spid-php/spid-php-setup.json',
        json_encode($spid));
      // rimuove certificato esistente
      if (file_exists($this->projectPath.'/vendor/italia/spid-php/cert/spid-sp.crt')) {
        unlink($this->projectPath.'/vendor/italia/spid-php/cert/spid-sp.crt');
        unlink($this->projectPath.'/vendor/italia/spid-php/cert/spid-sp.pem');
      }
      // va al passo successivo
      $page = [];
      $this->step++;
      $_SESSION['GS_INSTALL_STEP'] = $this->step;
      $this->{$this->procedure[$this->mode][$this->step]}();
    } else {
      // imposta dati della pagina
      $page['step'] = $this->step.' - Impostazioni SPID';
      $page['title'] = 'Impostazioni per l\'accesso tramite SPID';
      $page['_token'] = $this->token;
      if (empty($spid['entityID'])) {
        // imposta default
        $spid['entityID'] = $this->urlPath;
      }
      // rimuove escaped chars
      $spid['spLocalityName'] = htmlspecialchars(str_replace("\\'", "'", $spid['spLocalityName']));
      $spid['spName'] = htmlspecialchars(str_replace("\\'", "'", $spid['spName']));
      $spid['spDescription'] = htmlspecialchars(str_replace("\\'", "'", $spid['spDescription']));
      $spid['spOrganizationName'] = htmlspecialchars(str_replace("\\'", "'", $spid['spOrganizationName']));
      $spid['spOrganizationDisplayName'] = htmlspecialchars(str_replace("\\'", "'", $spid['spOrganizationDisplayName']));
      // visualizza pagina
      include('page_spid_data.php');
    }
  }

  /**
   * Pagina per la configurazione dello SPID
   *
   */
  private function pageSpidConfig() {
    // controlla pagina
    if (isset($_POST['install']['next'])) {
      // legge metadata
      $xml = base64_decode($_POST['install']['xml']);
      // scrive metadata
      if (file_put_contents($this->projectPath.'/config/metadata/registro-spid.xml', $xml) === false) {
        // errore di creazione del file
        $this->pageError('Impossibile memorizzare il file dei metadata (registro-spid.xml).', $this->step);
      }
      // pagina successiva
      $page = [];
      $this->step++;
      $_SESSION['GS_INSTALL_STEP'] = $this->step;
      $this->{$this->procedure[$this->mode][$this->step]}();
    } elseif (isset($_POST['install']['step']) && $_POST['install']['step'] == $this->step) {
      // configura SPID-PHP
      $this->spidSetup();
      // JS per scaricare metadata
      $page['javascript'] = <<<EOT
        $('#gs-waiting').modal('show');
        $.get({
          'url': '/spid/module.php/saml/sp/metadata.php/service',
          'dataType': 'text'
        }).done(function(xml) {
          $('#install_xml').val(btoa(xml));
          $('#install_submit').click();
        });
        EOT;
      // imposta dati della pagina
      $page['step'] = $this->step.' - Configurazione SPID';
      $page['title'] = 'Configurazione dello SPID';
      $page['_token'] = $this->token;
      $page['submitType'] = 'next';
      // visualizza pagina
      include('page_spid_config.php');
    } else {
      // imposta dati della pagina
      $page['step'] = $this->step.' - Configurazione SPID';
      $page['title'] = 'Configurazione dello SPID';
      $page['_token'] = $this->token;
      $page['submitType'] = 'submit';
      $page['info'] = 'Si procede ora alla configurazione dell\'applicazione per l\'utilizzo dello SPID.';
      // visualizza pagina
      include('page_spid_config.php');
    }
  }

  /**
   * Pagina per la pulizia finale della cache
   *
   */
  private function pageClean() {
    // controlla pagina
    if (isset($_POST['install']['step']) && $_POST['install']['step'] == $this->step) {
      // pulisce cache
      $this->clean();
      // va al passo successivo
      $page = [];
      $this->step++;
      $_SESSION['GS_INSTALL_STEP'] = $this->step;
      $this->{$this->procedure[$this->mode][$this->step]}();
    } else {
      // imposta dati della pagina
      $page['step'] = $this->step.' - Pulizia cache';
      $page['title'] = 'Pulizia della cache di sistema';
      $page['_token'] = $this->token;
      $page['info'] = 'Verrà effettuata la pulizia finale della cache di sistema.';
      // visualizza pagina
      include('page_message.php');
    }
  }

  /**
   * Pagina per la fine dell'installazione
   *
   */
  private function pageEnd() {
    // salva .env agggiornato
    $this->writeEnv();
    // toglie la modalità manutenzione (se presente)
    $this->setParameter('manutenzione_inizio', '');
    $this->setParameter('manutenzione_fine', '');
    // resetta sessione
    $_SESSION = [];
    session_destroy();
    // rinomina file di installazione in .txt
    rename($this->publicPath.'/install/index.php', $this->publicPath.'/install/index.txt');
    // imposta dati della pagina
    $page['step'] = $this->step.' - Fine installazione';
    $page['title'] = 'Procedura di installazione terminata';
    $page['success'] = 'La procedura di installazione è terminata con successo.<br>'.
      'Ora puoi andare alla pagina principale.';
    // visualizza pagina
    include('page_message.php');
  }

  /**
   * Pagina per l'aggiornamento di versione
   *
   */
  private function pageUpdate() {
    // controlla pagina
    if (isset($_POST['install']['step']) && $_POST['install']['step'] == $this->step) {
      // aggiorna database
      $lastVersion = array_slice(array_keys($this->dataUpdate), -2)[0].
        (empty($this->dataUpdate['build']) ? '' : '#build');
      $updateVersion = $this->updateSchema();
      // imposta nuovo passo
      if (isset($_POST['install']['exit'])) {
        // va al passo successivo
        $page = [];
        $this->step++;
        $_SESSION['GS_INSTALL_STEP'] = $this->step;
        $this->{$this->procedure[$this->mode][$this->step]}();
      } else {
        // riesegue procedura
        $page['step'] = $this->step.' - Aggiornamento';
        $page['title'] = 'Aggiornamento del database';
        $page['_token'] = $this->token;
        $page['submitType'] = version_compare($updateVersion, $lastVersion, '==') ? 'exit' : 'submit';
        $page['success'] = 'Il database è stato correttamente aggiornato alla versione <em>'.$updateVersion.'</em>.';
        // visualizza pagina
        include('page_update.php');
      }
    } else {
      // imposta dati della pagina
      $page['step'] = $this->step.' - Aggiornamento';
      $page['title'] = 'Aggiornamento del database';
      $page['_token'] = $this->token;
      $page['submitType'] = 'submit';
      $page['info'] = 'Saranno effettuate le modifiche necessarie al database.<br>'.
        'I dati esistenti non saranno modificati.';
      // visualizza pagina
      include('page_update.php');
    }
  }

}

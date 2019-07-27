<?php
/**
 * giua@school
 *
 * Copyright (c) 2017-2019 Antonello Dessì
 *
 * @author    Antonello Dessì
 * @license   http://www.gnu.org/licenses/agpl.html AGPL
 * @copyright Antonello Dessì 2017-2019
 */


namespace AppBundle\Controller;

use Psr\Log\LoggerInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Annotation\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\Security\Core\Encoder\UserPasswordEncoderInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Security\Csrf\TokenGenerator\UriSafeTokenGenerator;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use AppBundle\Entity\App;
use AppBundle\Entity\Utente;
use AppBundle\Entity\Alunno;
use AppBundle\Entity\Genitore;
use AppBundle\Entity\Docente;
use AppBundle\Entity\Ata;
use AppBundle\Entity\Notifica;
use AppBundle\Util\ConfigLoader;
use AppBundle\Util\LogHandler;


/**
 * AppController - gestione delle funzioni per le app
 */
class AppController extends Controller {

  /**
   * Login dell'utente tramite l'app
   *
   * @param SessionInterface $session Gestore delle sessioni
   * @param ConfigLoader $config Gestore della configurazione su database
   * @param string $codice Codifica delle credenziali in BASE64
   * @param int $lusr Lunghezza della username
   * @param int $lpsw Lunghezza della password
   * @param int $lapp Lunghezza del token identificativo dell'app
   *
   * @return Response Pagina di risposta
   *
   * @Route("/app/login/{codice}/{lusr}/{lpsw}/{lapp}", name="app_login",
   *    requirements={"codice": "[\w-=]+", "lusr": "\d+", "lpsw": "\d+", "lapp": "\d+"},
   *    defaults={"codice": "0", "lusr": 0, "lpsw": 0, "lapp": 0},
   *    methods={"GET"})
   */
  public function loginAction(SessionInterface $session, ConfigLoader $config, $codice, $lusr, $lpsw, $lapp) {
    $errore = null;
    $manutenzione = null;
    $modo_manutenzione = false;
    // carica configurazione di sistema
    $config->load('SISTEMA');
    // controlla manutenzione programmata
    $dati_manutenzione = $session->get('/CONFIG/SISTEMA/manutenzione');
    if (!empty($dati_manutenzione)) {
      // manutenzione programmata
      $dati = explode(',', $dati_manutenzione);
      $ora = new \DateTime();
      if ($ora->format('Y-m-d') == $dati[0] && $ora->format('H:i') < $dati[1]) {
        // manutenzione programmata per oggi
        $manutenzione = $dati;
      } elseif ($ora->format('Y-m-d') == $dati[0] && $ora->format('H:i') <= $dati[2]) {
        // in modalità manutenzione
        $manutenzione = $dati;
        $modo_manutenzione = true;
      }
    }
    if (!$modo_manutenzione) {
      // esegue autenticazione
      $auth = $this->get('security.authentication_utils');
      // conserva ultimo errore del login, se presente
      $errore = $auth->getLastAuthenticationError();
    }
    // mostra la pagina di risposta
    return $this->render('app/login.html.twig', array(
      'pagina_titolo' => 'page.app_login',
      'errore' => $errore,
      'manutenzione' => $manutenzione,
      ));
  }

  /**
   * Pre-login dell'utente tramite l'app
   *
   * @param Request $request Pagina richiesta
   * @param EntityManagerInterface $em Gestore delle entità
   * @param UserPasswordEncoderInterface $encoder Gestore della codifica delle password
   * @param UriSafeTokenGenerator $tok Generatore di token per CSRF
   *
   * @return JsonResponse Informazioni di risposta
   *
   * @Route("/app/prelogin/", name="app_prelogin",
   *    methods={"POST"})
   */
  public function preloginAction(Request $request, EntityManagerInterface $em, UserPasswordEncoderInterface $encoder) {
    $risposta = array();
    // legge dati
    $codice = $request->request->get('codice');
    $lusr = intval($request->request->get('lusr'));
    $lpsw = intval($request->request->get('lpsw'));
    $lapp = intval($request->request->get('lapp'));
    // decodifica credenziali
    $testo = base64_decode(str_replace(array('-', '_'), array('+', '/'), $codice));
    $username = substr($testo, 0, $lusr);
    $password = substr($testo, $lusr, $lpsw);
    $appId = substr($testo, $lusr + $lpsw, $lapp);
    // controlla utente
    $user = $em->getRepository('AppBundle:Utente')->findOneBy(['username' => $username, 'abilitato' => 1]);
    if ($user && $encoder->isPasswordValid($user, $password)) {
      // utente autenticato
      $token = (new UriSafeTokenGenerator())->generateToken();
      $risposta['risposta'] = rtrim(strtr(base64_encode($username.$password.$appId.$token), '+/', '-_'), '=');
      // salva codice di pre-login
      $user->setPrelogin($risposta['risposta']);
      $user->setPreloginCreato(new \DateTime());
      $em->flush();
    }
    // restituisce risposta
    return new JsonResponse($risposta);
  }

  /**
   * Mostra la pagina informativa sulle app ufficiali
   *
   * @param EntityManagerInterface $em Gestore delle entità
   *
   * @return Response Pagina di risposta
   *
   * @Route("/app/info/", name="app_info",
   *    methods={"GET"})
   */
  public function infoAction(EntityManagerInterface $em) {
    $applist = array();
    // legge app abilitate
    $apps = $em->getRepository('AppBundle:App')->findBy(['attiva' => 1]);
    foreach ($apps as $app) {
      $applist[$app->getNome()] = $app;
    }
    // mostra la pagina di risposta
    return $this->render('app/info.html.twig', array(
      'pagina_titolo' => 'page.app_info',
      'applist' => $applist,
      ));
  }

  /**
   * Esegue il download dell'app indicata.
   *
   * @param EntityManagerInterface $em Gestore delle entità
   * @param int $id ID dell'app da scaricare
   *
   * @return Response File inviato in risposta
   *
   * @Route("/app/download/{id}", name="app_download",
   *    requirements={"id": "\d+"},
   *    methods={"GET"})
   */
  public function downloadAction(EntityManagerInterface $em, $id) {
    // controllo app
    $app = $em->getRepository('AppBundle:App')->findOneBy(['id' => $id, 'attiva' => 1]);
    if (!$app || empty($app->getDownload())) {
      // errore
      throw $this->createNotFoundException('exception.id_notfound');
    }
    // file
    $file = new File($this->getParameter('kernel.project_dir').'/web/app/app-'.$app->getToken().$app->getDownload());
    // nome da visualizzare
    $nome = $app->getNome().$app->getDownload();
    // invia il documento
    return $this->file($file, $nome);
  }

  /**
   * Registrazione dell'utente per l'utilizzo delle notifiche via Telegram
   *
   * @param Request $request Pagina richiesta
   * @param EntityManagerInterface $em Gestore delle entità
   * @param SessionInterface $session Gestore delle sessioni
   * @param UserPasswordEncoderInterface $encoder Gestore della codifica delle password
   * @param ConfigLoader $config Gestore della configurazione su database
   * @param LoggerInterface $logger Gestore dei log su file
   * @param LogHandler $dblogger Gestore dei log su database
   * @param string $token Token identificativo dell'app
   * @param string $chat ID della chat dell'utente su Telegram
   *
   * @return Response Pagina di risposta
   *
   * @Route("/app/telegram/{token}/{chat}", name="app_telegram",
   *    methods={"GET", "POST"})
   */
  public function telegramAction(Request $request, EntityManagerInterface $em, SessionInterface $session,
                                  UserPasswordEncoderInterface $encoder, ConfigLoader $config, LoggerInterface $logger,
                                  LogHandler $dblogger, $token, $chat) {
    $successo = null;
    $manutenzione = null;
    $modo_manutenzione = false;
    // carica configurazione di sistema
    $config->load('SISTEMA');
    // controlla manutenzione programmata
    $dati_manutenzione = $session->get('/CONFIG/SISTEMA/manutenzione');
    if (!empty($dati_manutenzione)) {
      // manutenzione programmata
      $dati = explode(',', $dati_manutenzione);
      $ora = new \DateTime();
      if ($ora->format('Y-m-d') == $dati[0] && $ora->format('H:i') < $dati[1]) {
        // manutenzione programmata per oggi
        $manutenzione = $dati;
      } elseif ($ora->format('Y-m-d') == $dati[0] && $ora->format('H:i') <= $dati[2]) {
        // in modalità manutenzione
        $manutenzione = $dati;
        $modo_manutenzione = true;
      }
    }
    if (!$modo_manutenzione) {
      // controllo REFERER
      $referer = 'http://giuabot.it/registrazione/';
      if ($request->getMethod() == 'GET' && $request->headers->get('referer') != $referer) {
        // pagina di provenienza non riconosciuta: redirect a inizio procedura
        $url = 'http://www.andreasiddi.cloud/wp-content/1427538690/bot/GiuaBot/redirect-registrazione-errore-permessi.php';
        return new RedirectResponse($url);
      }
      // crea form
      $form = $this->container->get('form.factory')->createNamedBuilder('app_telegram', FormType::class)
        ->add('username', TextType::class, array('label' => 'label.username',
          'required' => true,
          'trim' => true,
          'attr' => array('placeholder' => 'label.username')))
        ->add('password', PasswordType::class, array('label' => 'label.password',
          'required' => true,
          'attr' => array('placeholder' => 'label.password')))
        ->add('privacy', CheckboxType::class, array('label' => 'label.privacy_app',
          'required' => true))
        ->getForm();
      $form->handleRequest($request);
      if ($form->isSubmitted() && $form->isValid()) {
        // legge dati
        $username = $form->get('username')->getData();
        $password = $form->get('password')->getData();
        $privacy = $form->get('privacy')->getData();
        // controlla dati
        $utente = $em->getRepository('AppBundle:Utente')->findOneBy(['username' => $username, 'abilitato' => 1]);
        $app = $em->getRepository('AppBundle:App')->findOneBy(['token' => $token, 'attiva' => 1, 'notifica' => 'T']);
        $tipo = (!$utente ? '' : ($utente instanceof Alunno ? 'A' : ($utente instanceof Genitore ? 'G' :
          ($utente instanceof Docente ? 'D' : ($utente instanceof Ata ? 'T' : '')))));
        if (!$utente) {
          // utente non valido
          $logger->error('Username non valido o utente non abilitato nella richiesta di registrazione Telegram.', array(
            'username' => $username,
            'token' => $token,
            'chat' => $chat,
            'ip' => $request->getClientIp()));
          $form->addError(new FormError($this->get('translator')->trans('exception.invalid_user')));
        } elseif (!$encoder->isPasswordValid($utente, $password)) {
          // password errata
          $logger->error('Password errata nella richiesta di registrazione Telegram.', array(
            'username' => $username,
            'token' => $token,
            'chat' => $chat,
            'ip' => $request->getClientIp()));
          $form->addError(new FormError($this->get('translator')->trans('exception.invalid_user')));
        } elseif (!$app) {
          // app non valida
          $logger->error('App non valida nella richiesta di registrazione Telegram.', array(
            'username' => $username,
            'token' => $token,
            'chat' => $chat,
            'ip' => $request->getClientIp()));
          $form->addError(new FormError($this->get('translator')->trans('exception.invalid_app')));
        } elseif (!$tipo || strpos($app->getAbilitati(), $tipo) === false) {
          // tipo utente non valido
          $logger->error('Tipo utente non valido nella richiesta di registrazione Telegram.', array(
            'username' => $username,
            'token' => $token,
            'chat' => $chat,
            'ip' => $request->getClientIp()));
          $form->addError(new FormError($this->get('translator')->trans('exception.invalid_user_type')));
          // redirect esterno: pagina errore
          $url = 'http://www.andreasiddi.cloud/wp-content/1427538690/bot/GiuaBot/redirect-registro-utente-non-ammesso.php';
          return new RedirectResponse($url);
        } elseif (!$privacy) {
          // privacy non selezionata
          $logger->error('Clausola privacy non accettata nella richiesta di registrazione Telegram.', array(
            'username' => $username,
            'token' => $token,
            'chat' => $chat,
            'ip' => $request->getClientIp()));
          $form->addError(new FormError($this->get('translator')->trans('exception.no_privacy')));
        } elseif (!$this->registraTelegram($app, $utente, $chat, $em)) {
          // registrazione fallita
          $logger->error('Errore sulla chiamata al servizio esterno di registrazione Telegram.', array(
            'username' => $username,
            'token' => $token,
            'chat' => $chat,
            'ip' => $request->getClientIp()));
          $form->addError(new FormError($this->get('translator')->trans('exception.error_registration_service')));
        } else {
          // ok: crea notifica benvenuto
          if (isset($app->getDati()['benvenuto']) && $app->getDati()['benvenuto']) {
            $notifica = (new Notifica())
              ->setOggettoNome('Utente')
              ->setOggettoId($utente->getId())
              ->setAzione('A');
            $em->persist($notifica);
          }
          // memorizza dati
          $notifica_dati = array(
            'app' => $app->getId(),
            'chat' => $chat);
          $notifica_old = $utente->getNotifica();
          $utente->setNotifica($notifica_dati);
          // memorizza su db
          $em->flush();
          // log azione
          $dblogger->write($utente, $request->getClientIp(), 'SICUREZZA', 'Registrazione telegram', __METHOD__, array(
            'Username' => $utente->getUsername(),
            'Notifica' => $notifica_old,
            ));
          // messaggio
          $successo = (isset($app->getDati()['benvenuto']) && $app->getDati()['benvenuto']) ?
            'message.registrazione_telegram_ok_benvenuto' : 'message.registrazione_telegram_ok';
        }
      }
    }
    // mostra la pagina di risposta
    return $this->render('app/telegram.html.twig', array(
      'pagina_titolo' => 'page.app_telegram',
      'form' => $form->createView(),
      'successo' => $successo,
      'manutenzione' => $manutenzione,
      'modo_manutenzione' => $modo_manutenzione,
      ));
  }

  /**
   * Restituisce la lista dei presenti per le procedure di evacuazione
   *
   * @param Request $request Pagina richiesta
   * @param EntityManagerInterface $em Gestore delle entità
   * @param string $token Token identificativo dell'app
   *
   * @return Response Pagina di risposta
   *
   * @Route("/app/presenti/{token}", name="app_presenti",
   *    methods={"GET"})
   */
  public function presentiAction(Request $request, EntityManagerInterface $em, $token) {
    // inizializza
    $dati = array();
    // controlla servizio
    $app = $em->getRepository('AppBundle:App')->findOneBy(['token' => $token, 'attiva' => 1]);
    if ($app) {
      $dati_app = $app->getDati();
/*
 * FIXME: eliminare riga seguente
 */
$dati_app['ip'] = $request->getClientIp();
      if ($dati_app['route'] == 'app_presenti' && $dati_app['ip'] == $request->getClientIp()) {
        // controlla ora
        $adesso = new \DateTime();
        $oggi = $adesso->format('Y-m-d');
        $ora = $adesso->format('H:i');
        if ($ora >= '08:00' && $ora <= '14:00') {
          // legge presenti
          $dql = "SELECT CONCAT(c.anno,c.sezione) AS classe,a.nome,a.cognome,DATE_FORMAT(a.dataNascita,'%d/%m/%Y') AS dataNascita,DATE_FORMAT(e.ora,'%H:%i') AS entrata,DATE_FORMAT(u.ora,'%H:%i') AS uscita
                  FROM AppBundle\Entity\Alunno a
                  INNER JOIN a.classe c
                  LEFT JOIN AppBundle:Entrata e WHERE e.alunno=a.id AND e.data=:oggi
                  LEFT JOIN AppBundle:Uscita u WHERE u.alunno=a.id AND u.data=:oggi
                  WHERE a.abilitato=1
                  AND (NOT EXISTS (SELECT ass FROM AppBundle\Entity\Assenza ass WHERE ass.alunno=a.id AND ass.data=:oggi))
                  ORDER BY classe,a.cognome,a.nome,a.dataNascita ASC";
          $dati = $em->createQuery($dql)
            ->setParameters(['oggi' => $oggi])
            ->getArrayResult();
        }
      }
    }
    // mostra la pagina di risposta
    $risposta = $this->render('app/presenti.xml.twig', array(
      'dati' => $dati,
      ));
    $risposta->headers->set('Content-Type', 'application/xml; charset=utf-8');
    return $risposta;
  }


  //==================== FUNZIONI PRIVATE  ====================

  /**
   * Invia i dati per la registrazione al bot e crea notifica di benvenuto
   *
   * @param App $app Servizio per la notifica via Telegram
   * @param Utente $utente Utente che effettua la registrazione al servizio
   * @param string $chat ID della chat dell'utente a cui inviare le notifiche
   * @param EntityManagerInterface $em Gestore delle entità
   *
   * @return bool True se registrazione è andata a buon fine, False altrimenti
   */
  private function registraTelegram(App $app, Utente $utente, $chat, EntityManagerInterface $em) {
    // dati da inviare
    $dati = array();
    if ($utente instanceof Alunno) {
      $dati['id'] = $chat;
      $dati['nome'] = str_replace('+', '%20', urlencode($utente->getNome()));
      $dati['cognome'] = str_replace('+', '%20', urlencode($utente->getCognome()));
      $dati['scuola'] = ($utente->getClasse() ? $utente->getClasse()->getSede()->getCitta() : '');
      $dati['scuola'] = ($dati['scuola'] == 'Cagliari' ? 'Pirri' : $dati['scuola']);
      $dati['classe'] = ($utente->getClasse() ? $utente->getClasse()->getAnno() : '');
      $dati['sezione'] = ($utente->getClasse() ? $utente->getClasse()->getSezione() : '');
    }
    $query_url = array_reduce(array_keys($dati),
      function($r,$k) use ($dati) { return $r.'&'.$k.'='.$dati[$k]; },
      '');
    $url = $app->getDati()['registrazione'].
      (strpos($app->getDati()['registrazione'], '?') === false ? '?'.substr($query_url, 1) : $query_url);
    // parametri curl
    $telegram_opts = array(
      CURLOPT_URL => $url,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_HEADER => false,
      CURLOPT_CONNECTTIMEOUT => 10,
      CURLOPT_TIMEOUT => 10);
    // invia tramite curl
    $cu = curl_init();
    if (!curl_setopt_array($cu, $telegram_opts)) {
      // errore invio parametri
      curl_close($cu);
      return false;
    }
    $risposta = curl_exec($cu);
    curl_close($cu);
    if (!$risposta) {
      // errore registrazione
      return false;
    }
    // ok: terminato senza errori
    return true;
  }

}


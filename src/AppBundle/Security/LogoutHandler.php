<?php
/**
 * giua@school
 *
 * Copyright (c) 2017 Antonello Dessì
 *
 * @author    Antonello Dessì
 * @license   http://www.gnu.org/licenses/agpl.html AGPL
 * @copyright Antonello Dessì 2017
 */


namespace AppBundle\Security;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Http\Logout\LogoutHandlerInterface;
use AppBundle\Util\LogHandler;


/**
 * LogoutHandler - Usato per gestire la disconnessione di un utente
 */
class LogoutHandler implements LogoutHandlerInterface {


  //==================== ATTRIBUTI DELLA CLASSE  ====================

  /**
   * @var EntityManagerInterface $em Gestore delle entità
   */
  private $em;

  /**
   * @var LogHandler $dblogger Gestore dei log su database
   */
  private $dblogger;


  //==================== METODI DELLA CLASSE ====================

  /**
   * Costruttore
   *
   * @param EntityManagerInterface $em Gestore delle entità
   * @param LogHandler $dblogger Gestore dei log su database
   */
  public function __construct(EntityManagerInterface $em, LogHandler $dblogger) {
    $this->em = $em;
    $this->dblogger = $dblogger;
  }

  /**
   * Richiamato da LogoutListener quando un utente richiede la disconnessione.
   * Di solito usato per invalidare la sessione, rimuovere i cookie, ecc.
   *
   * @param Request $request Pagina richiesta
   * @param Response $response Pagina di risposta
   * @param TokenInterface $token Token di autenticazione (contiene l'utente)
   */
  public function logout(Request $request, Response $response, TokenInterface $token) {
    // la sessione è già invalidata se è settato il parametro 'invalidate_session' in 'security.yml'
    $request->getSession()->invalidate();
    // log azione
    $this->dblogger->write($token->getUser(), $request->getClientIp(), 'ACCESSO', 'Logout', __METHOD__, array(
      'Username' => $token->getUsername(),
      'Ruolo' => $token->getRoles()[0]->getRole()
      ));
  }

}


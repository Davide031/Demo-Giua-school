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


namespace AppBundle\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use AppBundle\Entity\Genitore;
use AppBundle\Entity\Staff;
use AppBundle\Entity\Preside;
use AppBundle\Entity\Segreteria;
use AppBundle\Util\PagelleUtil;
use AppBundle\Util\GenitoriUtil;


/**
 * PagelleController - gestione della visualizzazione delle pagelle e altre comunicazioni
 */
class PagelleController extends Controller {

  /**
   * Scarica il documento della classe generato per lo scrutinio.
   *
   * @param EntityManagerInterface $em Gestore delle entità
   * @param SessionInterface $session Gestore delle sessioni
   * @param PagelleUtil $pag Funzioni di utilità per le pagelle/comunicazioni
   * @param int $classe Identificativo della classe
   * @param string $tipo Tipo del documento da scaricare
   * @param string $periodo Periodo dello scrutinio
   *
   * @return Response Pagina di risposta
   *
   * @Route("/pagelle/classe/{classe}/{tipo}/{periodo}", name="pagelle_classe",
   *    requirements={"classe": "\d+", "tipo": "R|T|F|I|V|C", "periodo": "P|S|F|R|1|2"})
   * @Method("GET")
   *
   * @Security("has_role('ROLE_DOCENTE') or has_role('ROLE_SEGRETERIA')")
   */
  public function documentoClasseAction(EntityManagerInterface $em, SessionInterface $session, PagelleUtil $pag,
                                         $classe, $tipo, $periodo) {
    // inizializza
    $nomefile = null;
    // controllo classe
    $classe = $em->getRepository('AppBundle:Classe')->find($classe);
    if (!$classe) {
      // errore
      throw $this->createNotFoundException('exception.id_notfound');
    }
    // controllo accesso alla funzione
    if (!($this->getUser() instanceOf Staff) && !($this->getUser() instanceOf Segreteria)) {
      // coordinatore
      $classi = explode(',', $session->get('/APP/DOCENTE/coordinatore'));
      if (!in_array($classe->getId(), $classi)) {
        // errore
        throw $this->createNotFoundException('exception.invalid_params');
      }
    }
    // controllo periodo (scrutinio deve essere chiuso)
    $scrutinio = $em->getRepository('AppBundle:Scrutinio')->findOneBy(['classe' => $classe,
      'periodo' => $periodo, 'stato' => 'C']);
    if (!$scrutinio) {
      // errore
      throw $this->createNotFoundException('exception.id_notfound');
    }
    // scarica documento
    if ($periodo == 'P') {
      // primo trimestre
      switch ($tipo) {
        case 'R':
          // riepilogo voti
          $nomefile = $pag->riepilogoVoti($classe, $periodo);
          break;
        case 'I':
          // firme registro voti
          $nomefile = $pag->firmeRegistro($classe, $periodo);
          break;
        case 'V':
          // verbale
          $nomefile = $pag->verbale($classe, $periodo);
          break;
      }
    } elseif ($periodo == 'F') {
      // scrutinio finale
      switch ($tipo) {
        case 'V':
          // verbale
          $nomefile = $pag->verbale($classe, $periodo);
          break;
        case 'R':
          // riepilogo voti
          $nomefile = $pag->riepilogoVoti($classe, $periodo);
          break;
        case 'T':
          // tabellone voti
          $nomefile = $pag->tabelloneVoti($classe, $periodo);
          break;
        case 'F':
          // firme verbale
          $nomefile = $pag->firmeVerbale($classe, $periodo);
          break;
        case 'I':
          // firme registro voti
          $nomefile = $pag->firmeRegistro($classe, $periodo);
          break;
        case 'C':
          // certificazioni
          $nomefile = $pag->certificazioni($classe, $periodo);
          break;
      }
    } elseif ($periodo == 'R') {
      // ripresa scrutinio finale
      switch ($tipo) {
        case 'V':
          // verbale
          $nomefile = $pag->verbale($classe, $periodo);
          break;
        case 'R':
          // riepilogo voti
          $nomefile = $pag->riepilogoVoti($classe, $periodo);
          break;
        case 'T':
          // tabellone voti
          $nomefile = $pag->tabelloneVoti($classe, $periodo);
          break;
        case 'F':
          // firme verbale
          $nomefile = $pag->firmeVerbale($classe, $periodo);
          break;
        case 'I':
          // firme registro voti
          $nomefile = $pag->firmeRegistro($classe, $periodo);
          break;
        case 'C':
          // certificazioni
          $nomefile = $pag->certificazioni($classe, $periodo);
          break;
      }
    }
    // invia documento
    if (!$nomefile) {
      // errore
      throw $this->createNotFoundException('exception.invalid_params');
    }
    // invia il documento
    return $this->file($nomefile);
  }

  /**
   * Scarica il documento dell'alunno generato per lo scrutinio.
   *
   * @param EntityManagerInterface $em Gestore delle entità
   * @param SessionInterface $session Gestore delle sessioni
   * @param PagelleUtil $pag Funzioni di utilità per le pagelle/comunicazioni
   * @param GenitoriUtil $gen Funzioni di utilità per i genitori
   * @param int $classe Identificativo della classe
   * @param string $tipo Tipo del documento da scaricare
   * @param string $periodo Periodo dello scrutinio
   *
   * @return Response Pagina di risposta
   *
   * @Route("/pagelle/alunno/{classe}/{alunno}/{tipo}/{periodo}", name="pagelle_alunno",
   *    requirements={"classe": "\d+", "alunno": "\d+", "tipo": "P|N|D|C", "periodo": "P|S|F|R|1|2"})
   * @Method("GET")
   *
   * @Security("has_role('ROLE_DOCENTE') or has_role('ROLE_GENITORE') or has_role('ROLE_SEGRETERIA')")
   */
  public function documentoAlunnoAction(EntityManagerInterface $em, SessionInterface $session, PagelleUtil $pag,
                                         GenitoriUtil $gen, $classe, $alunno, $tipo, $periodo) {
    // inizializza
    $nomefile = null;
    // controllo classe
    $classe = $em->getRepository('AppBundle:Classe')->find($classe);
    if (!$classe) {
      // errore
      throw $this->createNotFoundException('exception.id_notfound');
    }
    // controllo alunno
    $alunno = $pag->alunnoInScrutinio($classe, $alunno, $periodo);
    if (!$alunno) {
      // errore
      throw $this->createNotFoundException('exception.id_notfound');
    }
    // controllo genitore
    if (($this->getUser() instanceOf Genitore) && $gen->alunno($this->getUser()) !== $alunno) {
      // errore
      throw $this->createNotFoundException('exception.id_notfound');
    }
    // controllo periodo (scrutinio deve essere chiuso)
    $scrutinio = $em->getRepository('AppBundle:Scrutinio')->findOneBy(['classe' => $classe,
      'periodo' => $periodo, 'stato' => 'C']);
    if (!$scrutinio) {
      // errore
      throw $this->createNotFoundException('exception.id_notfound');
    }
    // scarica documento
    if ($periodo == 'P') {
      // primo trimestre
      switch ($tipo) {
        case 'P':
          // pagella
          $nomefile = $pag->pagella($classe, $alunno, $periodo);
          break;
        case 'D':
          // debiti
          $nomefile = $pag->debiti($classe, $alunno, $periodo);
          break;
      }
    } elseif ($periodo == '1' && $tipo == 'P') {
      // valutazione intermedia
      $nomefile = $pag->pagella($classe, $alunno, $periodo);
    } elseif ($periodo == 'F') {
      // scrutinio finale
      switch ($tipo) {
        case 'N':
          // non ammesso
          $nomefile = $pag->nonAmmesso($classe, $alunno, $periodo);
          break;
        case 'D':
          // debiti
          $nomefile = $pag->debiti($classe, $alunno, $periodo);
          break;
        case 'C':
          // carenze
          $nomefile = $pag->carenze($classe, $alunno, $periodo);
          break;
        case 'P':
          // pagella
          $nomefile = $pag->pagella($classe, $alunno, $periodo);
          break;
      }
    } elseif ($periodo == 'R') {
      // ripresa scrutinio finale
      switch ($tipo) {
        case 'N':
          // non ammesso
          $nomefile = $pag->nonAmmesso($classe, $alunno, $periodo);
          break;
        case 'P':
          // pagella
          $nomefile = $pag->pagella($classe, $alunno, $periodo);
          break;
      }
    }
    // invia documento
    if (!$nomefile) {
      // errore
      throw $this->createNotFoundException('exception.invalid_params');
    }
    // invia il documento
    return $this->file($nomefile);
  }

}


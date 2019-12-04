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


namespace App\Controller;

use Symfony\Component\Routing\Annotation\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use App\Util\ConfigLoader;


/**
 * InfoController - pagine informative
 */
class InfoController extends BaseController {

  /**
   * Note legali
   *
   * @param ConfigLoader $config Gestore della configurazione su database
   *
   * @return Response Pagina di risposta
   *
   * @Route("/info/note-legali/", name="info_notelegali",
   *    methods={"GET"})
   */
  public function noteLegaliAction(ConfigLoader $config) {
    // carica configurazione di sistema
    $config->loadAll();
    //-- return $this->render('info/notelegali.html.twig', array(
      //-- 'pagina_titolo' => 'page.notelegali',
      //-- 'breadcrumb' =>$dati,
    //-- ));
    return $this->renderHtml('info', 'notelegali');
  }

  /**
   * Privacy
   *
   * @param ConfigLoader $config Gestore della configurazione su database
   *
   * @return Response Pagina di risposta
   *
   * @Route("/info/privacy/", name="info_privacy",
   *    methods={"GET"})
   */
  public function privacyAction(ConfigLoader $config) {
    // carica configurazione di sistema
    $config->loadAll();
    return $this->render('info/privacy.html.twig', array(
      'pagina_titolo' => 'page.privacy',
    ));
  }

}

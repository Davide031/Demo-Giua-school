<?php
/**
 * giua@school
 *
 * Copyright (c) 2017-2020 Antonello Dessì
 *
 * @author    Antonello Dessì
 * @license   http://www.gnu.org/licenses/agpl.html AGPL
 * @copyright Antonello Dessì 2017-2020
 */


namespace App\Util;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Translation\TranslatorInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use App\Entity\Classe;
use App\Entity\Alunno;


/**
 * PagelleUtil - classe di utilità per le funzioni per le pagelle e altre comunicazioni
 */
class PagelleUtil {


  //==================== ATTRIBUTI DELLA CLASSE  ====================

  /**
   * @var EntityManagerInterface $em Gestore delle entità
   */
  private $em;

  /**
   * @var TranslatorInterface $trans Gestore delle traduzioni
   */
  private $trans;

  /**
   * @var SessionInterface $session Gestore delle sessioni
   */
  private $session;

  /**
   * @var \Twig\Environment $tpl Gestione template
   */
  private $tpl;

  /**
   * @var PdfManager $pdf Gestore dei documenti PDF
   */
  private $pdf;

  /**
   * @var string $root Directory principale dell'applicazione
   */
  private $root;


  //==================== METODI DELLA CLASSE ====================

  /**
   * Construttore
   *
   * @param EntityManagerInterface $em Gestore delle entità
   * @param TranslatorInterface $trans Gestore delle traduzioni
   * @param SessionInterface $session Gestore delle sessioni
   * @param \Twig\Environment $tpl Gestione template
   * @param PdfManager $pdf Gestore dei documenti PDF
   * @param string $root Directory principale dell'applicazione
   */
  public function __construct(EntityManagerInterface $em, TranslatorInterface $trans,
                               SessionInterface $session, \Twig\Environment $tpl, PdfManager $pdf, $root) {
    $this->em = $em;
    $this->trans = $trans;
    $this->session = $session;
    $this->tpl = $tpl;
    $this->pdf = $pdf;
    $this->root = $root;
  }

  /**
   * Restituisce i dati per creare il riepilogo dei voti dello scrutinio
   *
   * @param Classe $classe Classe dello scrutinio
   * @param string $periodo Periodo dello scrutinio
   *
   * @return Array Dati formattati come array associativo
   */
  public function riepilogoVotiDati(Classe $classe, $periodo) {
    $dati = array();
    if ($periodo == 'P') {
      // legge scrutinio
      $scrutinio = $this->em->getRepository('App:Scrutinio')->findOneBy(['classe' => $classe,
        'periodo' => $periodo, 'stato' => 'C']);
      // legge alunni
      $alunni = $this->em->getRepository('App:Alunno')->createQueryBuilder('a')
        ->select('a.id,a.nome,a.cognome,a.dataNascita,a.sesso,a.religione,a.bes,a.note')
        ->where('a.id IN (:lista)')
        ->orderBy('a.cognome,a.nome,a.dataNascita', 'ASC')
        ->setParameters(['lista' => $scrutinio->getDato('alunni')])
        ->getQuery()
        ->getArrayResult();
      foreach ($alunni as $alu) {
        $dati['alunni'][$alu['id']] = $alu;
      }
      // legge materie
      $materie = $this->em->getRepository('App:Materia')->createQueryBuilder('m')
        ->select('DISTINCT m.id,m.nome,m.nomeBreve,m.tipo')
        ->join('App:Cattedra', 'c', 'WITH', 'c.materia=m.id')
        ->where('c.classe=:classe AND c.attiva=:attiva AND c.tipo=:tipo AND m.tipo!=:sostegno')
        ->orderBy('m.ordinamento,m.nome', 'ASC')
        ->setParameters(['classe' => $classe, 'attiva' => 1, 'tipo' => 'N', 'sostegno' => 'S'])
        ->getQuery()
        ->getArrayResult();
      foreach ($materie as $mat) {
        $dati['materie'][$mat['id']] = $mat;
      }
      $condotta = $this->em->getRepository('App:Materia')->findOneByTipo('C');
      $dati['materie'][$condotta->getId()] = array(
        'id' => $condotta->getId(),
        'nome' => $condotta->getNome(),
        'nomeBreve' => $condotta->getNomeBreve(),
        'tipo' => $condotta->getTipo());
      // legge i voti
      $voti = $this->em->getRepository('App:VotoScrutinio')->createQueryBuilder('vs')
        ->where('vs.scrutinio=:scrutinio AND vs.alunno IN (:lista) AND vs.unico IS NOT NULL')
        ->setParameters(['scrutinio' => $scrutinio, 'lista' => $scrutinio->getDato('alunni')])
        ->getQuery()
        ->getResult();
      foreach ($voti as $v) {
        // inserisce voti/assenze
        $dati['voti'][$v->getAlunno()->getId()][$v->getMateria()->getId()] = array(
          'id' => $v->getId(),
          'unico' => $v->getUnico(),
          'assenze' => $v->getAssenze(),
          'recupero' => $v->getRecupero(),
          'debito' => $v->getDebito());
        if ($v->getMateria()->getMedia()) {
          // esclude religione dalla media
          if (!isset($somma[$v->getAlunno()->getId()])) {
            $somma[$v->getAlunno()->getId()] =
              ($v->getMateria()->getTipo() == 'C' && $v->getUnico() == 4) ? 0 : $v->getUnico();
            $numero[$v->getAlunno()->getId()] = 1;
          } else {
            $somma[$v->getAlunno()->getId()] +=
              ($v->getMateria()->getTipo() == 'C' && $v->getUnico() == 4) ? 0 : $v->getUnico();
            $numero[$v->getAlunno()->getId()]++;
          }
        }
      }
      // calcola medie
      foreach ($somma as $alu=>$s) {
        $dati['medie'][$alu] = number_format($somma[$alu] / $numero[$alu], 2, ',', null);
      }
      // data scrutinio
      $dati['scrutinio']['data'] = $scrutinio->getData()->format('d/m/Y');
    } elseif ($periodo == 'F') {
      // scrutinio finale
      $dati['scrutinio'] = $this->em->getRepository('App:Scrutinio')->findOneBy(['classe' => $classe,
        'periodo' => $periodo, 'stato' => 'C']);
      $dati['classe'] = $classe;
      // alunni scrutinati
      $dati['scrutinati'] = ($dati['scrutinio']->getDato('scrutinabili') == null ? [] :
        array_keys($dati['scrutinio']->getDato('scrutinabili')));
      // alunni non scrutinati per cessata frequenza
      $dati['cessata_frequenza'] = ($dati['scrutinio']->getDato('cessata_frequenza') == null ? [] :
        $dati['scrutinio']->getDato('cessata_frequenza'));
      // alunni non scrutinabili per limite di assenza
      $dati['no_scrutinabili'] = array();
      $no_scrut = ($dati['scrutinio']->getDato('no_scrutinabili') == null ? [] :
        $dati['scrutinio']->getDato('no_scrutinabili'));
      foreach ($no_scrut as $alu=>$ns) {
        if (!isset($ns['deroga'])) {
          $dati['no_scrutinabili'][] = $alu;
        }
      }
      // alunni all'estero
      $estero = $this->em->getRepository('App:Alunno')->createQueryBuilder('a')
        ->select('a.id')
        ->join('App:CambioClasse', 'cc', 'WITH', 'cc.alunno=a.id')
        ->where('a.id IN (:lista) AND cc.classe=:classe AND a.frequenzaEstero=:estero')
        ->setParameters(['lista' => ($dati['scrutinio']->getDato('ritirati') == null ? [] : $dati['scrutinio']->getDato('ritirati')),
          'classe' => $classe, 'estero' => 1])
        ->getQuery()
        ->getArrayResult();
      $dati['estero'] = ($estero == null ? [] : array_column($estero, 'id'));
      // dati degli alunni (scrutinati/cessata frequenza/non scrutinabili/all'estero, sono esclusi i ritirati)
      $alunni = $this->em->getRepository('App:Alunno')->createQueryBuilder('a')
        ->select('a.id,a.nome,a.cognome,a.dataNascita,a.sesso,a.religione,a.bes,a.note,a.frequenzaEstero')
        ->where('a.id IN (:lista)')
        ->orderBy('a.cognome,a.nome,a.dataNascita', 'ASC')
        ->setParameters(['lista' =>
          array_merge($dati['scrutinati'], $dati['cessata_frequenza'], $dati['no_scrutinabili'], $dati['estero'])])
        ->getQuery()
        ->getResult();
      foreach ($alunni as $alu) {
        $dati['alunni'][$alu['id']] = $alu;
      }
      // legge materie
      $materie = $this->em->getRepository('App:Materia')->createQueryBuilder('m')
        ->select('DISTINCT m.id,m.nome,m.nomeBreve,m.tipo')
        ->join('App:Cattedra', 'c', 'WITH', 'c.materia=m.id')
        ->where('c.classe=:classe AND c.attiva=:attiva AND c.tipo=:tipo AND m.tipo!=:sostegno')
        ->orderBy('m.ordinamento', 'ASC')
        ->setParameters(['classe' => $classe, 'attiva' => 1, 'tipo' => 'N', 'sostegno' => 'S'])
        ->getQuery()
        ->getArrayResult();
      foreach ($materie as $mat) {
        $dati['materie'][$mat['id']] = $mat;
      }
      $condotta = $this->em->getRepository('App:Materia')->findOneByTipo('C');
      $dati['materie'][$condotta->getId()] = array(
        'id' => $condotta->getId(),
        'nome' => $condotta->getNome(),
        'nomeBreve' => $condotta->getNomeBreve(),
        'tipo' => $condotta->getTipo());
      // legge i voti (alunni scrutinati e non scrutinabili per assenze)
      $voti = $this->em->getRepository('App:VotoScrutinio')->createQueryBuilder('vs')
        ->where('vs.scrutinio=:scrutinio AND vs.alunno IN (:lista)')
        ->setParameters(['scrutinio' => $dati['scrutinio'],
          'lista' => array_merge($dati['scrutinati'], $dati['no_scrutinabili'])])
        ->getQuery()
        ->getResult();
      foreach ($voti as $v) {
        // inserisce voti/assenze
        $dati['voti'][$v->getAlunno()->getId()][$v->getMateria()->getId()] = array(
          'id' => $v->getId(),
          'unico' => $v->getUnico(),
          'assenze' => $v->getAssenze(),
          'recupero' => $v->getRecupero(),
          'debito' => $v->getDebito());
      }
      // legge esiti (scrutinati)
      $esiti = $this->em->getRepository('App:Esito')->createQueryBuilder('e')
        ->where('e.alunno IN (:lista) AND e.scrutinio=:scrutinio')
        ->setParameters(['lista' => $dati['scrutinati'], 'scrutinio' => $dati['scrutinio']])
        ->getQuery()
        ->getResult();
      foreach ($esiti as $e) {
        $dati['esiti'][$e->getAlunno()->getId()] = $e;
      }
    } elseif ($periodo == 'I' || $periodo == 'X') {
      // scrutinio integrativo
      $dati['scrutinio'] = $this->em->getRepository('App:Scrutinio')->findOneBy(['classe' => $classe,
        'periodo' => $periodo, 'stato' => 'C']);
      $dati['classe'] = $classe;
      // legge dati di alunni
      $sospesi = ($periodo == 'I' ? $dati['scrutinio']->getDato('sospesi') : $dati['scrutinio']->getDato('rinviati'));
      $alunni = $this->em->getRepository('App:Alunno')->createQueryBuilder('a')
        ->select('a.id,a.nome,a.cognome,a.dataNascita,a.sesso,a.religione,a.bes,a.note')
        ->where('a.id IN (:lista)')
        ->orderBy('a.cognome,a.nome,a.dataNascita', 'ASC')
        ->setParameters(['lista' => $sospesi])
        ->getQuery()
        ->getArrayResult();
      foreach ($alunni as $alu) {
        $dati['alunni'][$alu['id']] = $alu;
      }
      // legge materie
      $materie = $this->em->getRepository('App:Materia')->createQueryBuilder('m')
        ->select('DISTINCT m.id,m.nome,m.nomeBreve,m.tipo')
        ->join('App:Cattedra', 'c', 'WITH', 'c.materia=m.id')
        ->where('c.classe=:classe AND c.attiva=:attiva AND c.tipo=:tipo AND m.tipo!=:sostegno')
        ->orderBy('m.ordinamento', 'ASC')
        ->setParameters(['classe' => $classe, 'attiva' => 1, 'tipo' => 'N', 'sostegno' => 'S'])
        ->getQuery()
        ->getArrayResult();
      foreach ($materie as $mat) {
        $dati['materie'][$mat['id']] = $mat;
      }
      $condotta = $this->em->getRepository('App:Materia')->findOneByTipo('C');
      $dati['materie'][$condotta->getId()] = array(
        'id' => $condotta->getId(),
        'nome' => $condotta->getNome(),
        'nomeBreve' => $condotta->getNomeBreve(),
        'tipo' => $condotta->getTipo());
      // legge i voti
      $voti = $this->em->getRepository('App:VotoScrutinio')->createQueryBuilder('vs')
        ->where('vs.scrutinio=:scrutinio AND vs.alunno IN (:lista)')
        ->setParameters(['scrutinio' => $dati['scrutinio'], 'lista' => $sospesi])
        ->getQuery()
        ->getResult();
      foreach ($voti as $v) {
        // inserisce voti/assenze
        $dati['voti'][$v->getAlunno()->getId()][$v->getMateria()->getId()] = array(
          'id' => $v->getId(),
          'unico' => $v->getUnico(),
          'assenze' => $v->getAssenze(),
          'recupero' => $v->getRecupero(),
          'debito' => $v->getDebito());
      }
      // legge esiti
      $esiti = $this->em->getRepository('App:Esito')->createQueryBuilder('e')
        ->where('e.alunno IN (:lista) AND e.scrutinio=:scrutinio')
        ->setParameters(['lista' => $sospesi, 'scrutinio' => $dati['scrutinio']])
        ->getQuery()
        ->getResult();
      foreach ($esiti as $e) {
        $dati['esiti'][$e->getAlunno()->getId()] = $e;
      }
    }
    // restituisce dati
    return $dati;
  }

  /**
   * Crea il riepilogo dei voti
   *
   * @param Classe $classe Classe dello scrutinio
   * @param string $periodo Periodo dello scrutinio
   *
   * @return Percorso completo del file da inviare
   */
  public function riepilogoVoti(Classe $classe, $periodo) {
    // inizializza
    $fs = new Filesystem();
    if ($periodo == 'P') {
      // primo trimestre
      $percorso = $this->root.'/trimestre/'.$classe->getAnno().$classe->getSezione();
      if (!$fs->exists($percorso)) {
        // crea directory
        $fs->mkdir($percorso, 0775);
      }
      // nome documento
      $nomefile = $classe->getAnno().$classe->getSezione().'-primo-trimestre-riepilogo-voti.pdf';
      if (!$fs->exists($percorso.'/'.$nomefile)) {
        // crea documento
        $this->pdf->configure($this->session->get('/CONFIG/ISTITUTO/intestazione'),
          'Scrutinio Primo Trimestre - Riepilogo voti - Classe '.$classe->getAnno().'ª '.$classe->getSezione());
        $dati = $this->riepilogoVotiDati($classe, $periodo);
        // crea il documento
        $nome_classe = $classe->getAnno().'ª '.$classe->getSezione();
        $nome_classe_lungo = $nome_classe.' '.$classe->getCorso()->getNomeBreve().' - '.$classe->getSede()->getCitta();
        $this->creaRiepilogoVoti_P($this->pdf->getHandler(), $nome_classe, $nome_classe_lungo, $dati);
        // salva il documento
        $this->pdf->save($percorso.'/'.$nomefile);
      }
      // restituisce nome del file
      return $percorso.'/'.$nomefile;
    } elseif ($periodo == 'F') {
      // scrutinio finale
      $percorso = $this->root.'/finale/'.$classe->getAnno().$classe->getSezione();
      if (!$fs->exists($percorso)) {
        // crea directory
        $fs->mkdir($percorso, 0775);
      }
      // nome documento
      $nomefile = $classe->getAnno().$classe->getSezione().'-scrutinio-finale-riepilogo-voti.pdf';
      if (!$fs->exists($percorso.'/'.$nomefile)) {
        // crea pdf
        $this->pdf->configure($this->session->get('/CONFIG/ISTITUTO/intestazione'),
          'Scrutinio Finale - Riepilogo voti - Classe '.$classe->getAnno().'ª '.$classe->getSezione());
        $dati = $this->riepilogoVotiDati($classe, $periodo);
        // crea il documento
        $nome_classe = $classe->getAnno().'ª '.$classe->getSezione();
        $nome_classe_lungo = $nome_classe.' '.$classe->getCorso()->getNomeBreve().' - '.$classe->getSede()->getCitta();
        $this->creaRiepilogoVoti_F($this->pdf->getHandler(), $nome_classe, $nome_classe_lungo, $dati);
        // salva il documento
        $this->pdf->save($percorso.'/'.$nomefile);
      }
      // restituisce nome del file
      return $percorso.'/'.$nomefile;
    } elseif ($periodo == 'I') {
      // scrutinio integrativo
      $percorso = $this->root.'/integrativo/'.$classe->getAnno().$classe->getSezione();
      if (!$fs->exists($percorso)) {
        // crea directory
        $fs->mkdir($percorso, 0775);
      }
      // nome documento
      $nomefile = $classe->getAnno().$classe->getSezione().'-scrutinio-integrativo-riepilogo-voti.pdf';
      if (!$fs->exists($percorso.'/'.$nomefile)) {
        // crea pdf
        $this->pdf->configure($this->session->get('/CONFIG/ISTITUTO/intestazione'),
          'Scrutinio Integrativo - Riepilogo voti - Classe '.$classe->getAnno().'ª '.$classe->getSezione());
        $dati = $this->riepilogoVotiDati($classe, $periodo);
        // crea il documento
        $nome_classe = $classe->getAnno().'ª '.$classe->getSezione();
        $nome_classe_lungo = $nome_classe.' '.$classe->getCorso()->getNomeBreve().' - '.$classe->getSede()->getCitta();
        $this->creaRiepilogoVoti_I($this->pdf->getHandler(), $nome_classe, $nome_classe_lungo, $dati);
        // salva il documento
        $this->pdf->save($percorso.'/'.$nomefile);
      }
      // restituisce nome del file
      return $percorso.'/'.$nomefile;
    } elseif ($periodo == 'X') {
      // scrutinio integrativo
      $percorso = $this->root.'/rinviato/'.$classe->getAnno().$classe->getSezione();
      if (!$fs->exists($percorso)) {
        // crea directory
        $fs->mkdir($percorso, 0775);
      }
      // nome documento
      $nomefile = $classe->getAnno().$classe->getSezione().'-scrutinio-rinviato-riepilogo-voti.pdf';
      if (!$fs->exists($percorso.'/'.$nomefile)) {
        // crea pdf
        $this->pdf->configure($this->session->get('/CONFIG/ISTITUTO/intestazione'),
          'Scrutinio Integrativo - Riepilogo voti - Classe '.$classe->getAnno().'ª '.$classe->getSezione());
        $dati = $this->riepilogoVotiDati($classe, $periodo);
        // crea il documento
        $nome_classe = $classe->getAnno().'ª '.$classe->getSezione();
        $nome_classe_lungo = $nome_classe.' '.$classe->getCorso()->getNomeBreve().' - '.$classe->getSede()->getCitta();
        $this->creaRiepilogoVoti_I($this->pdf->getHandler(), $nome_classe, $nome_classe_lungo, $dati);
        // salva il documento
        $this->pdf->save($percorso.'/'.$nomefile);
      }
      // restituisce nome del file
      return $percorso.'/'.$nomefile;
    }
    // errore
    return null;
  }

  /**
   * Crea il riepilogo dei voti come documento PDF
   *
   * @param TCPDF $pdf Gestore del documento PDF
   * @param string $classe Nome della classe
   * @param string $classe_completa Nome della classe con corso e sede
   * @param array $dati Dati dello scrutinio
   */
  public function creaRiepilogoVoti_P($pdf, $classe, $classe_completa, $dati) {
    // set margins
    $pdf->SetMargins(10, 15, 10, true);
    // set auto page breaks
    $pdf->SetAutoPageBreak(false, 15);
    // set font
    $pdf->SetFont('helvetica', '', 10);
    // inizio pagina
    $pdf->SetHeaderMargin(12);
    $pdf->SetFooterMargin(12);
    $pdf->setHeaderFont(Array('helvetica', 'B', 6));
    $pdf->setFooterFont(Array('helvetica', '', 8));
    $pdf->setHeaderData('', 0, $this->session->get('/CONFIG/ISTITUTO/intestazione')."      ***      RIEPILOGO VOTI CLASSE ".$classe, '', array(0,0,0), array(255,255,255));
    $pdf->setFooterData(array(0,0,0), array(255,255,255));
    $pdf->setPrintHeader(true);
    $pdf->setPrintFooter(true);
    $pdf->AddPage('P');
    // intestazione pagina
    $pdf->SetFont('helvetica', 'B', 10);
    $this->cella($pdf, 15, 5, 0, 2, 'Classe:', 0, 'C', 'B');
    $pdf->SetFont('helvetica', '', 10);
    $this->cella($pdf, 85, 5, 0, 0, $classe_completa, 0, 'L', 'B');
    $pdf->SetFont('helvetica', 'B', 10);
    $this->cella($pdf, 31, 5, 0, 0, 'Anno Scolastico:', 0, 'C', 'B');
    $pdf->SetFont('helvetica', '', 10);
    $as = $this->session->get('/CONFIG/SCUOLA/anno_scolastico');
    $this->cella($pdf, 20, 5, 0, 0, $as, 0, 'L', 'B');
    $pdf->SetFont('helvetica', 'B', 10);
    $this->cella($pdf, 0, 5, 0, 0, 'PRIMO TRIMESTRE', 0, 'R', 'B');
    $this->acapo($pdf, 5);
    // intestazione tabella
    $pdf->SetFont('helvetica', 'B', 8);
    $this->cella($pdf, 10, 30, 0, 0, 'Pr.', 1, 'C', 'B');
    $this->cella($pdf, 50, 30, 0, 0, 'Alunno', 1, 'C', 'B');
    $pdf->SetX($pdf->GetX() - 6); // aggiusta prima posizione
    $pdf->StartTransform();
    $pdf->Rotate(90);
    $numrot = 1;
    $etichetterot = array();
    $last_width = 6;
    foreach ($dati['materie'] as $materia=>$mat) {
      $text = strtoupper($mat['nomeBreve']);
      if ($mat['tipo'] != 'R') {
        $etichetterot[] = array('nome' => $text, 'dim' => 6);
        $this->cella($pdf, 30, 6, -30, $last_width, $text, 1, 'L', 'M');
        $last_width = 6;
      } else {
        $etichetterot[] = array('nome' => $text, 'dim' => 12);
        $this->cella($pdf, 30, 12, -30, $last_width, $text, 1, 'L', 'M');
        $last_width = 12;
      }
      $numrot++;
    }
    $pdf->StopTransform();
    $this->cella($pdf, 20, 30, $numrot*6+6, -$numrot*6, 'Media', 1, 'C', 'B');
    $this->acapo($pdf, 30);
    // dati alunni
    $pdf->SetFont('helvetica', '', 8);
    $numalunni = 0;
    $next_height = 26;
    foreach ($dati['alunni'] as $idalunno=>$alu) {
      // nuovo alunno
      $numalunni++;
      $this->cella($pdf, 10, 11, 0, 0, $numalunni, 1, 'C', 'T');
      $nomealunno = strtoupper($alu['cognome'].' '.$alu['nome']);
      $sessoalunno = $alu['sesso'];
      $dataalunno = $alu['dataNascita']->format('d/m/Y');
      $this->cella($pdf, 50, 11, 0, 0, $nomealunno, 1, 'L', 'T');
      $this->cella($pdf, 50, 11, -50, 0, $dataalunno, 1, 'L', 'B');
      $this->cella($pdf, 50, 11, -50, 0, 'Assenze ->', 1, 'R', 'B');
      $pdf->SetXY($pdf->GetX(), $pdf->GetY() + 5.50);
      // voti e assenze
      foreach ($dati['materie'] as $idmateria=>$mat) {
        $pdf->SetTextColor(0,0,0);
        $voto = '';
        $assenze = '';
        if ($mat['tipo'] == 'R') {
          // religione
          if ($alu['religione'] != 'S') {
            $voto = '///';
            $assenze = '';
          } else {
            $voto = $dati['voti'][$idalunno][$idmateria]['unico'];
            $assenze = $dati['voti'][$idalunno][$idmateria]['assenze'];
            switch ($voto) {
              case 20:
                $pdf->SetTextColor(255,0,0);
                $voto = 'NC';
                break;
              case 21:
                $pdf->SetTextColor(255,0,0);
                $voto = 'Insuff.';
                break;
              case 22:
                $voto = 'Suff.';
                break;
              case 23:
                $voto = 'Buono';
                break;
              case 24:
                $voto = 'Distinto';
                break;
              case 25:
                $voto = 'Ottimo';
                break;
            }
          }
          // voto religione
          $this->cella($pdf, 12, 5.50, 0, -5.50, $voto, 1, 'C', 'M');
          $pdf->SetTextColor(0,0,0);
          $this->cella($pdf, 12, 5.50, -12, 5.50, $assenze, 1, 'C', 'M');
        } elseif ($mat['tipo'] == 'C') {
          // condotta
          $voto = $dati['voti'][$idalunno][$idmateria]['unico'];
          $assenze = '';
          switch ($voto) {
            case 4:
              $voto = 'NC';
              $pdf->SetTextColor(255,0,0);
              break;
            case 5:
              $pdf->SetTextColor(255,0,0);
              break;
          }
          // voto numerico
          $this->cella($pdf, 6, 5.50, 0, -5.50, $voto, 1, 'C', 'M');
          $pdf->SetTextColor(0,0,0);
          $this->cella($pdf, 6, 5.50, -6, 5.50, '', 1, 'C', 'M');
        } else {
          // altre materie
          $voto = $dati['voti'][$idalunno][$idmateria]['unico'];
          $assenze = $dati['voti'][$idalunno][$idmateria]['assenze'];
          switch ($voto) {
            case 0:
              $voto = 'NC';
              $pdf->SetTextColor(255,0,0);
              break;
            case 1:
            case 2:
            case 3:
            case 4:
            case 5:
              $pdf->SetTextColor(255,0,0);
              break;
          }
          // voto numerico
          $this->cella($pdf, 6, 5.50, 0, -5.50, $voto, 1, 'C', 'M');
          $pdf->SetTextColor(0,0,0);
          $this->cella($pdf, 6, 5.50, -6, 5.50, $assenze, 1, 'C', 'M');
        }
      }
      // media
      $this->cella($pdf, 20, 5.50, 0, -5.50, $dati['medie'][$idalunno], 1, 'C', 'M');
      $this->cella($pdf, 20, 5.50, -20, 5.50, '', 1, 'C', 'M');
      // nuova riga
      if ($numalunni < count($dati['alunni'])) {
        $this->acapo($pdf, 5.50, $next_height, $etichetterot, [10, 50, 20, false]);
      } else {
        $this->acapo($pdf, 5.50, $next_height);
      }
    }
    // data e firma
    $pdf->SetFont('helvetica', '', 12);
    $this->cella($pdf, 30, 15, 0, 0, 'Data', 0, 'R', 'B');
    $this->cella($pdf, 30, 15, 0, 0, $dati['scrutinio']['data'], 'B', 'C', 'B');
    $pdf->SetXY(-80, $pdf->GetY());
    $preside = $this->session->get('/CONFIG/ISTITUTO/firma_preside');
    $text = '(Il Dirigente Scolastico)'."\n".$preside;
    $this->cella($pdf, 60, 15, 0, 0, $text, 'B', 'C', 'B');
  }

  /**
   * Restituisce i dati per creare il foglio firme per il verbale
   *
   * @param Classe $classe Classe dello scrutinio
   * @param string $periodo Periodo dello scrutinio
   *
   * @return Array Dati formattati come array associativo
   */
  public function firmeVerbaleDati(Classe $classe, $periodo) {
    $dati = array();
    if ($periodo == 'P') {
      // dati scrutinio
      $scrutinio = $this->em->getRepository('App:Scrutinio')->findOneBy(['classe' => $classe,
        'periodo' => $periodo, 'stato' => 'C']);
      // legge materie
      $dati_materie = $this->em->getRepository('App:Materia')->createQueryBuilder('m')
        ->select('m.id,m.nome')
        ->where('m.tipo NOT IN (:tipi)')
        ->setParameter('tipi', ['U', 'C'])
        ->orderBy('m.ordinamento,m.nome', 'ASC')
        ->getQuery()
        ->getArrayResult();
      // legge docenti del CdC
      $docenti = $scrutinio->getDato('docenti');
      $dati_docenti = $this->em->getRepository('App:Docente')->createQueryBuilder('d')
        ->select('d.id,d.cognome,d.nome')
        ->where('d.id IN (:lista)')
        ->orderBy('d.cognome,d.nome', 'ASC')
        ->setParameter('lista', array_keys($docenti))
        ->getQuery()
        ->getArrayResult();
      // dati per la visualizzazione della pagina
      foreach ($dati_materie as $mat) {
        foreach ($dati_docenti as $doc) {
          if (isset($docenti[$doc['id']][$mat['id']])) {
            $dati['materie'][$mat['id']][$doc['id']] = array(
              'cognome' => $doc['cognome'],
              'nome' => $doc['nome'],
              'nome_materia' => $mat['nome'],
            );
          }
        }
      }
      // coordinatore
      $dati['coordinatore'] = $classe->getCoordinatore()->getCognome().' '.$classe->getCoordinatore()->getNome();
      $dati['scrutinio']['data'] = $scrutinio->getData()->format('d/m/Y');
      $dati['scrutinio']['presenze'] = $scrutinio->getDato('presenze');
    } elseif ($periodo == 'F') {
      // scrutinio finale
      $dati['scrutinio'] = $this->em->getRepository('App:Scrutinio')->findOneBy(['classe' => $classe,
        'periodo' => $periodo, 'stato' => 'C']);
      $dati['classe'] = $classe;
      // legge docenti del CdC (esclusi potenziamento)
      $docenti = $this->em->getRepository('App:Cattedra')->createQueryBuilder('c')
        ->select('DISTINCT d.id,d.cognome,d.nome,d.sesso,m.nome AS nome_materia,m.tipo,m.id AS id_materia')
        ->join('c.materia', 'm')
        ->join('c.docente', 'd')
        ->where('c.classe=:classe AND c.attiva=:attiva AND c.tipo!=:tipo')
        ->orderBy('m.ordinamento,m.nome', 'ASC')
        ->addOrderBy('c.tipo', 'DESC')
        ->addOrderBy('d.cognome,d.nome', 'ASC')
        ->setParameters(['classe' => $classe, 'attiva' => 1, 'tipo' => 'P'])
        ->getQuery()
        ->getArrayResult();
      foreach ($docenti as $doc) {
        // dati per la visualizzazione della pagina
        $dati['materie'][$doc['id_materia']][$doc['id']] = $doc;
      }
    } elseif ($periodo == 'I' || $periodo == 'X') {
      // scrutinio integrativo
      $dati['scrutinio'] = $this->em->getRepository('App:Scrutinio')->findOneBy(['classe' => $classe,
        'periodo' => $periodo, 'stato' => 'C']);
      $dati['classe'] = $classe;
      // legge docenti del CdC (esclusi potenziamento)
      $docenti = $this->em->getRepository('App:Cattedra')->createQueryBuilder('c')
        ->select('DISTINCT d.id,d.cognome,d.nome,d.sesso,m.nome AS nome_materia,m.tipo,m.id AS id_materia')
        ->join('c.materia', 'm')
        ->join('c.docente', 'd')
        ->where('c.classe=:classe AND c.attiva=:attiva AND c.tipo!=:tipo')
        ->orderBy('m.ordinamento,m.nome', 'ASC')
        ->addOrderBy('c.tipo', 'DESC')
        ->addOrderBy('d.cognome,d.nome', 'ASC')
        ->setParameters(['classe' => $classe, 'attiva' => 1, 'tipo' => 'P'])
        ->getQuery()
        ->getArrayResult();
      foreach ($docenti as $doc) {
        // dati per la visualizzazione della pagina
        $dati['materie'][$doc['id_materia']][$doc['id']] = $doc;
      }
    }
    // restituisce dati
    return $dati;
  }

  /**
   * Crea il foglio firme per il verbale
   *
   * @param Classe $classe Classe dello scrutinio
   * @param string $periodo Periodo dello scrutinio
   *
   * @return Percorso completo del file da inviare
   */
  public function firmeVerbale(Classe $classe, $periodo) {
    // inizializza
    $fs = new Filesystem();
    if ($periodo == 'P') {
      // primo trimestre
      $percorso = $this->root.'/trimestre/'.$classe->getAnno().$classe->getSezione();
      if (!$fs->exists($percorso)) {
        // crea directory
        $fs->mkdir($percorso, 0775);
      }
      // nome documento
      $nomefile = $classe->getAnno().$classe->getSezione().'-primo-trimestre-firme-verbale.pdf';
      if (!$fs->exists($percorso.'/'.$nomefile)) {
        // crea documento
        $this->pdf->configure($this->session->get('/CONFIG/ISTITUTO/intestazione'),
          'Scrutinio Primo Trimestre - Foglio firme Verbale - Classe '.$classe->getAnno().'ª '.$classe->getSezione());
        $dati = $this->firmeVerbaleDati($classe, $periodo);
        // crea il documento
        $nome_classe = $classe->getAnno().'ª '.$classe->getSezione();
        $nome_classe_lungo = $nome_classe.' '.$classe->getCorso()->getNomeBreve().' - '.$classe->getSede()->getCitta();
        $this->creaFirmeVerbale_P($this->pdf->getHandler(), $nome_classe, $nome_classe_lungo, $dati);
        // salva il documento
        $this->pdf->save($percorso.'/'.$nomefile);
      }
      // restituisce nome del file
      return $percorso.'/'.$nomefile;
    } elseif ($periodo == 'F') {
      // scrutinio finale
      $percorso = $this->root.'/finale/'.$classe->getAnno().$classe->getSezione();
      if (!$fs->exists($percorso)) {
        // crea directory
        $fs->mkdir($percorso, 0775);
      }
      // nome documento
      $nomefile = $classe->getAnno().$classe->getSezione().'-scrutinio-finale-firme-verbale.pdf';
      if (!$fs->exists($percorso.'/'.$nomefile)) {
        // crea documento
        $this->pdf->configure($this->session->get('/CONFIG/ISTITUTO/intestazione'),
          'Scrutinio Finale - Foglio firme Verbale - Classe '.$classe->getAnno().'ª '.$classe->getSezione());
        $dati = $this->firmeVerbaleDati($classe, $periodo);
        // crea il documento
        $nome_classe = $classe->getAnno().'ª '.$classe->getSezione();
        $nome_classe_lungo = $nome_classe.' '.$classe->getCorso()->getNomeBreve().' - '.$classe->getSede()->getCitta();
        $this->creaFirmeVerbale_F($this->pdf->getHandler(), $nome_classe, $nome_classe_lungo, $dati);
        // salva il documento
        $this->pdf->save($percorso.'/'.$nomefile);
      }
      // restituisce nome del file
      return $percorso.'/'.$nomefile;
    } elseif ($periodo == 'I') {
      // scrutinio integrativo
      $percorso = $this->root.'/integrativo/'.$classe->getAnno().$classe->getSezione();
      if (!$fs->exists($percorso)) {
        // crea directory
        $fs->mkdir($percorso, 0775);
      }
      // nome documento
      $nomefile = $classe->getAnno().$classe->getSezione().'-scrutinio-integrativo-firme-verbale.pdf';
      if (!$fs->exists($percorso.'/'.$nomefile)) {
        // crea documento
        $this->pdf->configure($this->session->get('/CONFIG/ISTITUTO/intestazione'),
          'Scrutinio Integrativo - Foglio firme Verbale - Classe '.$classe->getAnno().'ª '.$classe->getSezione());
        $dati = $this->firmeVerbaleDati($classe, $periodo);
        // crea il documento
        $nome_classe = $classe->getAnno().'ª '.$classe->getSezione();
        $nome_classe_lungo = $nome_classe.' '.$classe->getCorso()->getNomeBreve().' - '.$classe->getSede()->getCitta();
        $this->creaFirmeVerbale_I($this->pdf->getHandler(), $nome_classe, $nome_classe_lungo, $dati);
        // salva il documento
        $this->pdf->save($percorso.'/'.$nomefile);
      }
      // restituisce nome del file
      return $percorso.'/'.$nomefile;
    } elseif ($periodo == 'X') {
      // scrutinio integrativo
      $percorso = $this->root.'/rinviato/'.$classe->getAnno().$classe->getSezione();
      if (!$fs->exists($percorso)) {
        // crea directory
        $fs->mkdir($percorso, 0775);
      }
      // nome documento
      $nomefile = $classe->getAnno().$classe->getSezione().'-scrutinio-rinviato-firme-verbale.pdf';
      if (!$fs->exists($percorso.'/'.$nomefile)) {
        // crea documento
        $this->pdf->configure($this->session->get('/CONFIG/ISTITUTO/intestazione'),
          'Scrutinio Integrativo - Foglio firme Verbale - Classe '.$classe->getAnno().'ª '.$classe->getSezione());
        $dati = $this->firmeVerbaleDati($classe, $periodo);
        // crea il documento
        $nome_classe = $classe->getAnno().'ª '.$classe->getSezione();
        $nome_classe_lungo = $nome_classe.' '.$classe->getCorso()->getNomeBreve().' - '.$classe->getSede()->getCitta();
        $this->creaFirmeVerbale_I($this->pdf->getHandler(), $nome_classe, $nome_classe_lungo, $dati);
        // salva il documento
        $this->pdf->save($percorso.'/'.$nomefile);
      }
      // restituisce nome del file
      return $percorso.'/'.$nomefile;
    }
    // errore
    return null;
  }

  /**
   * Crea il foglio firme del verbale come documento PDF
   *
   * @param TCPDF $pdf Gestore del documento PDF
   * @param string $classe Nome della classe
   * @param string $classe_completa Nome della classe con corso e sede
   * @param array $dati Dati dello scrutinio
   */
  public function creaFirmeVerbale_P($pdf, $classe, $classe_completa, $dati) {
    // set margins
    $pdf->SetMargins(10, 10, 10, true);
    // set auto page breaks
    $pdf->SetAutoPageBreak(false, 10);
    // set font
    $pdf->SetFont('helvetica', '', 10);
    // inizio pagina
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->AddPage('L');
    // intestazione pagina
    $pdf->SetFont('helvetica', 'B', 8);
    $this->cella($pdf, 100, 4, 0, 0, 'FOGLIO FIRME VERBALE', 0, 'L', 'T');
    $as = $this->session->get('/CONFIG/SCUOLA/anno_scolastico');
    $this->cella($pdf, 0, 4, 0, 0, 'CLASSE '.$classe.' - A.S. '.$as, 0, 'R', 'T');
    $this->acapo($pdf, 5);
    $pdf->SetFont('helvetica', 'B', 16);
    $this->cella($pdf, 70, 10, 0, 0, 'CONSIGLIO DI CLASSE:', 0, 'L', 'B');
    $this->cella($pdf, 0, 10, 0, 0, $classe_completa, 0, 'L', 'B');
    $this->acapo($pdf, 11);
    // intestazione tabella
    $pdf->SetFont('helvetica', 'B', 10);
    $this->cella($pdf, 90, 5, 0, 0, 'MATERIA', 1, 'C', 'B');
    $this->cella($pdf, 60, 5, 0, 0, 'DOCENTI', 1, 'C', 'B');
    $this->cella($pdf, 0, 5, 0, 0, 'FIRME', 1, 'C', 'B');
    $this->acapo($pdf, 5);
    // dati materie
    foreach ($dati['materie'] as $idmateria=>$m) {
      $lista = '';
      foreach ($m as $iddocente=>$mat) {
        $nome_materia = $mat['nome_materia'];
        if ($dati['scrutinio']['presenze'][$iddocente]->getPresenza()) {
          $lista .= ', '.$mat['cognome'].' '.$mat['nome'];
        } else {
          $lista .= ', '.ucwords(strtolower($dati['scrutinio']['presenze'][$iddocente]->getSostituto()));
        }
      }
      $pdf->SetFont('helvetica', 'B', 10);
      $this->cella($pdf, 90, 11, 0, 0, $nome_materia, 1, 'L', 'B');
      $pdf->SetFont('helvetica', '', 10);
      $this->cella($pdf, 60, 11, 0, 0, substr($lista, 2), 1, 'L', 'B');
      $this->cella($pdf, 0, 11, 0, 0, '', 1, 'C', 'B');
      $this->acapo($pdf, 11);
    }
    // fine pagina
    $pdf->SetFont('helvetica', '', 12);
    $this->cella($pdf, 15, 9, 0, 0, 'DATA:', 0, 'R', 'B');
    $this->cella($pdf, 25, 9, 0, 0, $dati['scrutinio']['data'], 'B', 'C', 'B');
  }

  /**
   * Crea il foglio firme per il registro dei voti
   *
   * @param Classe $classe Classe dello scrutinio
   * @param string $periodo Periodo dello scrutinio
   *
   * @return Percorso completo del file da inviare
   */
  public function firmeRegistro(Classe $classe, $periodo) {
    // inizializza
    $fs = new Filesystem();
    if ($periodo == 'P') {
      // primo trimestre
      $percorso = $this->root.'/trimestre/'.$classe->getAnno().$classe->getSezione();
      if (!$fs->exists($percorso)) {
        // crea directory
        $fs->mkdir($percorso, 0775);
      }
      // nome documento
      $nomefile = $classe->getAnno().$classe->getSezione().'-primo-trimestre-firme-registro.pdf';
      if (!$fs->exists($percorso.'/'.$nomefile)) {
        // crea documento
        $this->pdf->configure($this->session->get('/CONFIG/ISTITUTO/intestazione'),
          'Scrutinio Primo Trimestre - Foglio firme Registro - Classe '.$classe->getAnno().'ª '.$classe->getSezione());
        $dati = $this->firmeVerbaleDati($classe, $periodo);
        // crea il documento
        $nome_classe = $classe->getAnno().'ª '.$classe->getSezione();
        $nome_classe_lungo = $nome_classe.' '.$classe->getCorso()->getNomeBreve().' - '.$classe->getSede()->getCitta();
        $this->creaFirmeRegistro_P($this->pdf->getHandler(), $nome_classe, $nome_classe_lungo, $dati);
        // salva il documento
        $this->pdf->save($percorso.'/'.$nomefile);
      }
      // restituisce nome del file
      return $percorso.'/'.$nomefile;
    } elseif ($periodo == 'F') {
      // scrutinio finale
      $percorso = $this->root.'/finale/'.$classe->getAnno().$classe->getSezione();
      if (!$fs->exists($percorso)) {
        // crea directory
        $fs->mkdir($percorso, 0775);
      }
      // nome documento
      $nomefile = $classe->getAnno().$classe->getSezione().'-scrutinio-finale-firme-registro.pdf';
      if (!$fs->exists($percorso.'/'.$nomefile)) {
        // crea documento
        $this->pdf->configure($this->session->get('/CONFIG/ISTITUTO/intestazione'),
          'Scrutinio Finale - Foglio firme Registro - Classe '.$classe->getAnno().'ª '.$classe->getSezione());
        $dati = $this->firmeVerbaleDati($classe, $periodo);
        // crea il documento
        $nome_classe = $classe->getAnno().'ª '.$classe->getSezione();
        $nome_classe_lungo = $nome_classe.' '.$classe->getCorso()->getNomeBreve().' - '.$classe->getSede()->getCitta();
        $this->creaFirmeRegistro_F($this->pdf->getHandler(), $nome_classe, $nome_classe_lungo, $dati);
        // salva il documento
        $this->pdf->save($percorso.'/'.$nomefile);
      }
      // restituisce nome del file
      return $percorso.'/'.$nomefile;
    } elseif ($periodo == 'I') {
      // scrutinio integrativo
      $percorso = $this->root.'/integrativo/'.$classe->getAnno().$classe->getSezione();
      if (!$fs->exists($percorso)) {
        // crea directory
        $fs->mkdir($percorso, 0775);
      }
      // nome documento
      $nomefile = $classe->getAnno().$classe->getSezione().'-scrutinio-integrativo-firme-registro.pdf';
      if (!$fs->exists($percorso.'/'.$nomefile)) {
        // crea documento
        $this->pdf->configure($this->session->get('/CONFIG/ISTITUTO/intestazione'),
          'Scrutinio Integrativo - Foglio firme Registro - Classe '.$classe->getAnno().'ª '.$classe->getSezione());
        $dati = $this->firmeVerbaleDati($classe, $periodo);
        // crea il documento
        $nome_classe = $classe->getAnno().'ª '.$classe->getSezione();
        $nome_classe_lungo = $nome_classe.' '.$classe->getCorso()->getNomeBreve().' - '.$classe->getSede()->getCitta();
        $this->creaFirmeRegistro_I($this->pdf->getHandler(), $nome_classe, $nome_classe_lungo, $dati);
        // salva il documento
        $this->pdf->save($percorso.'/'.$nomefile);
      }
      // restituisce nome del file
      return $percorso.'/'.$nomefile;
    } elseif ($periodo == 'X') {
      // scrutinio integrativo
      $percorso = $this->root.'/rinviato/'.$classe->getAnno().$classe->getSezione();
      if (!$fs->exists($percorso)) {
        // crea directory
        $fs->mkdir($percorso, 0775);
      }
      // nome documento
      $nomefile = $classe->getAnno().$classe->getSezione().'-scrutinio-rinviato-firme-registro.pdf';
      if (!$fs->exists($percorso.'/'.$nomefile)) {
        // crea documento
        $this->pdf->configure($this->session->get('/CONFIG/ISTITUTO/intestazione'),
          'Scrutinio Integrativo - Foglio firme Registro - Classe '.$classe->getAnno().'ª '.$classe->getSezione());
        $dati = $this->firmeVerbaleDati($classe, $periodo);
        // crea il documento
        $nome_classe = $classe->getAnno().'ª '.$classe->getSezione();
        $nome_classe_lungo = $nome_classe.' '.$classe->getCorso()->getNomeBreve().' - '.$classe->getSede()->getCitta();
        $this->creaFirmeRegistro_I($this->pdf->getHandler(), $nome_classe, $nome_classe_lungo, $dati);
        // salva il documento
        $this->pdf->save($percorso.'/'.$nomefile);
      }
      // restituisce nome del file
      return $percorso.'/'.$nomefile;
    }
    // errore
    return null;
  }

  /**
   * Crea il foglio firme del registro dei voti come documento PDF
   *
   * @param TCPDF $pdf Gestore del documento PDF
   * @param string $classe Nome della classe
   * @param string $classe_completa Nome della classe con corso e sede
   * @param array $dati Dati dello scrutinio
   */
  public function creaFirmeRegistro_P($pdf, $classe, $classe_completa, $dati) {
    // set margins
    $pdf->SetMargins(10, 10, 10, true);
    // set auto page breaks
    $pdf->SetAutoPageBreak(false, 10);
    // set font
    $pdf->SetFont('helvetica', '', 10);
    // inizio pagina
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->AddPage('L');
    // intestazione pagina
    $pdf->SetFont('helvetica', 'B', 8);
    $this->cella($pdf, 100, 4, 0, 0, 'FOGLIO FIRME REGISTRO', 0, 'L', 'T');
    $as = $this->session->get('/CONFIG/SCUOLA/anno_scolastico');
    $this->cella($pdf, 0, 4, 0, 0, 'CLASSE '.$classe.' - A.S. '.$as, 0, 'R', 'T');
    $this->acapo($pdf, 5);
    $pdf->SetFont('helvetica', 'B', 16);
    $this->cella($pdf, 70, 10, 0, 0, 'CONSIGLIO DI CLASSE:', 0, 'L', 'B');
    $this->cella($pdf, 145, 10, 0, 0, $classe_completa, 0, 'L', 'B');
    $this->cella($pdf, 0, 10, 0, 0, 'PRIMO TRIMESTRE', 0, 'R', 'B');
    $this->acapo($pdf, 11);
    // intestazione tabella
    $pdf->SetFont('helvetica', 'B', 10);
    $this->cella($pdf, 90, 5, 0, 0, 'MATERIA', 1, 'C', 'B');
    $this->cella($pdf, 60, 5, 0, 0, 'DOCENTI', 1, 'C', 'B');
    $this->cella($pdf, 0, 5, 0, 0, 'FIRME', 1, 'C', 'B');
    $this->acapo($pdf, 5);
    // dati materie
    foreach ($dati['materie'] as $idmateria=>$m) {
      $lista = '';
      foreach ($m as $iddocente=>$mat) {
        $nome_materia = $mat['nome_materia'];
        if ($dati['scrutinio']['presenze'][$iddocente]->getPresenza()) {
          $lista .= ', '.$mat['cognome'].' '.$mat['nome'];
        } else {
          $lista .= ', '.ucwords(strtolower($dati['scrutinio']['presenze'][$iddocente]->getSostituto()));
        }
      }
      $pdf->SetFont('helvetica', 'B', 10);
      $this->cella($pdf, 90, 11, 0, 0, $nome_materia, 1, 'L', 'B');
      $pdf->SetFont('helvetica', '', 10);
      $this->cella($pdf, 60, 11, 0, 0, substr($lista, 2), 1, 'L', 'B');
      $this->cella($pdf, 0, 11, 0, 0, '', 1, 'C', 'B');
      $this->acapo($pdf, 11);
    }
    // fine pagina
    $pdf->SetFont('helvetica', '', 12);
    $this->cella($pdf, 15, 12, 0, 0, 'DATA:', 0, 'R', 'B');
    $this->cella($pdf, 25, 12, 0, 0, $dati['scrutinio']['data'], 'B', 'C', 'B');
    $this->cella($pdf, 50, 12, 0, 0, 'SEGRETARIO:', 0, 'R', 'B');
    $this->cella($pdf, 68, 12, 0, 0, '', 'B', 'C', 'B');
    $this->cella($pdf, 50, 12, 0, 0, 'PRESIDENTE:', 0, 'R', 'B');
    $this->cella($pdf, 68, 12, 0, 0, '', 'B', 'C', 'B');
  }

  /**
   * Restituisce i dati per creare il verbale
   *
   * @param Classe $classe Classe dello scrutinio
   * @param string $periodo Periodo dello scrutinio
   *
   * @return Array Dati formattati come array associativo
   */
  public function verbaleDati(Classe $classe, $periodo) {
    $dati = array();
    if ($periodo == 'P') {
      // dati scrutinio
      $scrutinio = $this->em->getRepository('App:Scrutinio')->findOneBy(['classe' => $classe,
        'periodo' => $periodo, 'stato' => 'C']);
      $dati['scrutinio'] = $scrutinio;
      // definizione scrutinio
      $def = $this->em->getRepository('App:DefinizioneScrutinio')->findOneByPeriodo($periodo);
      $dati['definizione'] = $def;
      // legge classe
      $dati['classe'] = $classe;
      // legge materie
      $dati_materie = $this->em->getRepository('App:Materia')->createQueryBuilder('m')
        ->select('m.id,m.nome')
        ->where('m.tipo NOT IN (:tipi)')
        ->setParameter('tipi', ['U', 'C'])
        ->orderBy('m.ordinamento,m.nome', 'ASC')
        ->getQuery()
        ->getArrayResult();
      // legge docenti del CdC
      $docenti = $scrutinio->getDato('docenti');
      $dati_docenti = $this->em->getRepository('App:Docente')->createQueryBuilder('d')
        ->select('d.id,d.cognome,d.nome,d.sesso')
        ->where('d.id IN (:lista)')
        ->orderBy('d.cognome,d.nome', 'ASC')
        ->setParameter('lista', array_keys($docenti))
        ->getQuery()
        ->getArrayResult();
      // dati per la visualizzazione della pagina
      foreach ($dati_materie as $mat) {
        foreach ($dati_docenti as $doc) {
          if (isset($docenti[$doc['id']][$mat['id']])) {
            $dati['docenti'][$doc['id']]['cognome'] = $doc['cognome'];
            $dati['docenti'][$doc['id']]['nome'] = $doc['nome'];
            $dati['docenti'][$doc['id']]['sesso'] = $doc['sesso'];
            $dati['docenti'][$doc['id']]['materie'][$mat['id']] = array(
              'nome_materia' => $mat['nome'],
              'tipo_cattedra' => $docenti[$doc['id']][$mat['id']]);
          }
        }
      }
      // legge alunni
      $alunni = $this->em->getRepository('App:Alunno')->createQueryBuilder('a')
        ->select('a.id,a.nome,a.cognome,a.dataNascita,a.sesso,a.religione,a.bes,a.note,a.credito3,a.credito4')
        ->where('a.id IN (:lista)')
        ->orderBy('a.cognome,a.nome,a.dataNascita', 'ASC')
        ->setParameters(['lista' => $scrutinio->getDato('alunni')])
        ->getQuery()
        ->getArrayResult();
      $dati['alunni_noreligione'] = array();
      foreach ($alunni as $alu) {
        $dati['alunni'][$alu['id']] = $alu;
      }
      // legge condotta
      $voti = $this->em->getRepository('App:VotoScrutinio')->createQueryBuilder('vs')
        ->join('vs.materia','m')
        ->where('vs.scrutinio=:scrutinio AND vs.alunno IN (:lista) AND m.tipo=:tipo')
        ->setParameters(['scrutinio' => $scrutinio, 'lista' => $scrutinio->getDato('alunni'), 'tipo' => 'C'])
        ->getQuery()
        ->getResult();
      foreach ($voti as $v) {
        // inserisce voti
        $dati['voti'][$v->getAlunno()->getId()] = $v;
      }
    } elseif ($periodo == 'F') {
      // nomi mesi
      $dati['nomi_mesi'] = ['', 'Gennaio', 'Febbraio', 'Marzo', 'Aprile', 'Maggio', 'Giugno', 'Luglio', 'Agosto', 'Settembre', 'Ottobre', 'Novembre', 'Dicembre'];
      // scrutinio finale
      $dati['scrutinio'] = $this->em->getRepository('App:Scrutinio')->findOneBy(['classe' => $classe,
        'periodo' => $periodo, 'stato' => 'C']);
      // definizione scrutinio
      $dati['definizione'] = $this->em->getRepository('App:DefinizioneScrutinio')->findOneByPeriodo($periodo);
      // legge classe
      $dati['classe'] = $classe;
      // legge docenti del CdC (esclusi potenziamento)
      $docenti = $this->em->getRepository('App:Cattedra')->createQueryBuilder('c')
        ->select('DISTINCT d.id,d.cognome,d.nome,d.sesso,m.nome AS nome_materia,m.tipo,m.id AS materia_id,c.supplenza,c.tipo AS doc_tipo')
        ->join('c.materia', 'm')
        ->join('c.docente', 'd')
        ->where('c.classe=:classe AND c.attiva=:attiva AND c.tipo!=:tipo')
        ->orderBy('d.cognome,d.nome,m.ordinamento,m.nomeBreve', 'ASC')
        ->setParameters(['classe' => $classe, 'attiva' => 1, 'tipo' => 'P'])
        ->getQuery()
        ->getArrayResult();
      foreach ($docenti as $doc) {
        // dati per la visualizzazione della pagina
        $dati['docenti'][$doc['id']][] = $doc;
        if ($doc['tipo'] == 'R') {
          $doc_religione = $doc['id'];
        }
      }
      // presidente
      if ($dati['scrutinio']->getDato('presiede_ds')) {
        $dati['presidente_nome'] = $this->session->get('/CONFIG/ISTITUTO/firma_preside');
        $dati['presidente'] = 'il Dirigente Scolastico, '.$dati['presidente_nome'];
      } else {
        $d = $dati['docenti'][$dati['scrutinio']->getDato('presiede_docente')][0];
        if ($dati['scrutinio']->getDato('presenze')[$d['id']]->getPresenza()) {
          $dati['presidente_nome'] = ($d['sesso'] == 'M' ? 'Prof.' : 'Prof.ssa').' '.$d['cognome'].' '.$d['nome'];
          $dati['presidente'] = 'il Coordinatore della classe, '.($d['sesso'] == 'M' ? 'il' : 'la').' '.$dati['presidente_nome'].', '.
            'delegat'.($d['sesso'] == 'M' ? 'o' : 'a').' dal Dirigente Scolastico';
        } else {
          $s = $dati['scrutinio']->getDato('presenze')[$d['id']];
          $dati['presidente_nome'] = ($s->getSessoSostituto() == 'M' ? 'Prof.' : 'Prof.ssa').' '.ucwords(strtolower($s->getSostituto()));
          $dati['presidente'] = ($s->getSessoSostituto() == 'M' ? 'il' : 'la').' '.$dati['presidente_nome'].', '.
            'delegat'.($s->getSessoSostituto() == 'M' ? 'o' : 'a').' dal Dirigente Scolastico';
        }
      }
      // segretario
      $d = $dati['docenti'][$dati['scrutinio']->getDato('segretario')][0];
      if ($dati['scrutinio']->getDato('presenze')[$d['id']]->getPresenza()) {
        $dati['segretario_nome'] = ($d['sesso'] == 'M' ? 'Prof.' : 'Prof.ssa').' '.$d['cognome'].' '.$d['nome'];
        $dati['segretario'] = ($d['sesso'] == 'M' ? 'il' : 'la').' '.$dati['segretario_nome'];
      } else {
        $s = $dati['scrutinio']->getDato('presenze')[$d['id']];
        $dati['segretario_nome'] = ($s->getSessoSostituto() == 'M' ? 'Prof.' : 'Prof.ssa').' '.ucwords(strtolower($s->getSostituto()));
        $dati['segretario'] = ($s->getSessoSostituto() == 'M' ? 'il' : 'la').' '.$dati['segretario_nome'];
      }
      // docente di religione
      $d = $dati['docenti'][$doc_religione][0];
      if ($dati['scrutinio']->getDato('presenze')[$d['id']]->getPresenza()) {
        $dati['religione'] = ($d['sesso'] == 'M' ? 'Il prof.' : 'La prof.ssa').' '.$d['cognome'].' '.$d['nome'];
      } else {
        $s = $dati['scrutinio']->getDato('presenze')[$d['id']];
        $dati['religione'] = ($s->getSessoSostituto() == 'M' ? 'Il prof.' : 'La prof.ssa').' '.ucwords(strtolower($s->getSostituto()));
      }
      // alunni scrutinati
      $dati['scrutinati'] = ($dati['scrutinio']->getDato('scrutinabili') == null ? [] :
        array_keys($dati['scrutinio']->getDato('scrutinabili')));
      // alunni non scrutinati per cessata frequenza
      $dati['cessata_frequenza'] = ($dati['scrutinio']->getDato('cessata_frequenza') == null ? [] :
        $dati['scrutinio']->getDato('cessata_frequenza'));
      // alunni non scrutinabili per limite di assenza e in deroga
      $dati['no_scrutinabili'] = array();
      $dati['deroga'] = array();
      $no_scrut = ($dati['scrutinio']->getDato('no_scrutinabili') == null ? [] :
        $dati['scrutinio']->getDato('no_scrutinabili'));
      foreach ($no_scrut as $alu=>$ns) {
        if (isset($ns['deroga'])) {
          $dati['deroga'][] = $alu;
        } else {
          $dati['no_scrutinabili'][] = $alu;
        }
      }
      // alunni ritirati/estero
      $dati['ritirati'] = array();
      $dati['estero'] = array();
      $ritirati = $this->em->getRepository('App:Alunno')->createQueryBuilder('a')
        ->select('a.id,a.frequenzaEstero,cc.note')
        ->join('App:CambioClasse', 'cc', 'WITH', 'cc.alunno=a.id')
        ->where('a.id IN (:lista) AND cc.classe=:classe')
        ->setParameters(['lista' => ($dati['scrutinio']->getDato('ritirati') == null ? [] : $dati['scrutinio']->getDato('ritirati')),
          'classe' => $classe])
        ->getQuery()
        ->getArrayResult();
      foreach ($ritirati as $a) {
        $dati['ritirati'][$a['id']] = $a['note'];
        if ($a['frequenzaEstero']) {
          $dati['estero'][] = $a['id'];
        }
      }
      // dati degli alunni (scrutinati/cessata frequenza/non scrutinabili/all'estero/ritirati)
      $alunni = $this->em->getRepository('App:Alunno')->createQueryBuilder('a')
        ->select('a.id,a.nome,a.cognome,a.dataNascita,a.sesso,a.religione,a.bes,a.note,a.frequenzaEstero,a.credito3,a.credito4')
        ->where('a.id IN (:lista)')
        ->orderBy('a.cognome,a.nome,a.dataNascita', 'ASC')
        ->setParameters(['lista' =>
          array_merge($dati['scrutinati'], $dati['cessata_frequenza'], $dati['no_scrutinabili'],
            array_keys($dati['ritirati']))])
        ->getQuery()
        ->getResult();
      $dati['alunni_noreligione'] = array();
      foreach ($alunni as $alu) {
        $dati['alunni'][$alu['id']] = $alu;
        if ($alu['religione'] != 'S' && in_array($alu['id'], $dati['scrutinati'])) {
          $dati['alunni_noreligione'][] = $alu['cognome'].' '.$alu['nome'];
        }
      }
      // legge condotta
      $voti = $this->em->getRepository('App:VotoScrutinio')->createQueryBuilder('vs')
        ->join('vs.materia','m')
        ->where('vs.scrutinio=:scrutinio AND m.tipo=:tipo')
        ->setParameters(['scrutinio' => $dati['scrutinio'], 'tipo' => 'C'])
        ->getQuery()
        ->getResult();
      foreach ($voti as $v) {
        // inserisce voti
        $dati['voti'][$v->getAlunno()->getId()] = $v;
      }
      // legge esiti (solo scrutinati)
      $esiti = $this->em->getRepository('App:Esito')->createQueryBuilder('e')
        ->where('e.alunno IN (:lista) AND e.scrutinio=:scrutinio')
        ->setParameters(['lista' => $dati['scrutinati'], 'scrutinio' => $dati['scrutinio']])
        ->getQuery()
        ->getResult();
      $dati['non_ammessi'] = 0;
      foreach ($esiti as $e) {
        $dati['esiti'][$e->getAlunno()->getId()] = $e;
        if ($e->getEsito() == 'N') {
          $dati['non_ammessi']++;
        }
      }
      // legge debiti
      $dati['debiti'] = array();
      $debiti = $this->em->getRepository('App:VotoScrutinio')->createQueryBuilder('vs')
        ->select('(vs.alunno) AS alunno,vs.unico,vs.debito,vs.recupero,m.nome AS materia')
        ->join('App:Esito', 'e', 'WITH', 'e.scrutinio=vs.scrutinio AND e.alunno=vs.alunno')
        ->join('vs.materia', 'm')
        ->where('vs.alunno IN (:lista) AND vs.scrutinio=:scrutinio AND vs.unico<:suff AND e.esito=:esito AND m.tipo=:tipo')
        ->orderBy('m.ordinamento', 'ASC')
        ->setParameters(['lista' => $dati['scrutinati'], 'scrutinio' => $dati['scrutinio'], 'suff' => 6,
          'esito' => 'S', 'tipo' => 'N'])
        ->getQuery()
        ->getArrayResult();
      foreach ($debiti as $d) {
        $dati['debiti'][$d['alunno']][] = $d;
      }
      // PIA
      $materie = $this->em->getRepository('App:Materia')->createQueryBuilder('m')
        ->join('App:Cattedra', 'c', 'WITH', 'c.materia=m.id')
        ->where('c.classe=:classe AND c.attiva=:attiva AND c.tipo=:tipo AND m.tipo!=:sostegno')
        ->orderBy('m.ordinamento', 'ASC')
        ->setParameters(['classe' => $classe, 'attiva' => 1, 'tipo' => 'N', 'sostegno' => 'S'])
        ->getQuery()
        ->getResult();
      foreach ($materie as $mat) {
        $dati['piani'][$mat->getId()] = null;
      }
      // legge piani
      $piani = $this->em->getRepository('App:DocumentoInterno')->createQueryBuilder('di')
        ->join('di.materia', 'm')
        ->where('di.tipo=:tipo AND di.classe=:classe')
        ->setParameters(['tipo' => 'A', 'classe' => $classe])
        ->getQuery()
        ->getResult();
      $dati['no_piano'] = true;
      foreach ($piani as $p) {
        $dati['piani'][$p->getMateria()->getId()] = $p;
        if ($p->getDato('necessario')) {
          $dati['no_piano'] = false;
        }
      }
    } elseif ($periodo == 'I' || $periodo == 'X') {
      // scrutinio integrativo
      $dati['scrutinio'] = $this->em->getRepository('App:Scrutinio')->findOneBy(['classe' => $classe,
        'periodo' => $periodo, 'stato' => 'C']);
      // definizione scrutinio
      $dati['definizione'] = $this->em->getRepository('App:DefinizioneScrutinio')->findOneByPeriodo($periodo);
      // legge classe
      $dati['classe'] = $classe;
      // legge docenti del CdC (esclusi potenziamento)
      $docenti = $this->em->getRepository('App:Cattedra')->createQueryBuilder('c')
        ->select('DISTINCT d.id,d.cognome,d.nome,d.sesso,m.nome AS nome_materia,m.tipo,m.id AS materia_id,c.supplenza,c.tipo AS doc_tipo')
        ->join('c.materia', 'm')
        ->join('c.docente', 'd')
        ->where('c.classe=:classe AND c.attiva=:attiva AND c.tipo!=:tipo')
        ->orderBy('d.cognome,d.nome,m.ordinamento,m.nomeBreve', 'ASC')
        ->setParameters(['classe' => $classe, 'attiva' => 1, 'tipo' => 'P'])
        ->getQuery()
        ->getArrayResult();
      foreach ($docenti as $doc) {
        // dati per la visualizzazione della pagina
        $dati['docenti'][$doc['id']][] = $doc;
      }
      // legge dati di alunni
      $sospesi = ($periodo == 'I' ? $dati['scrutinio']->getDato('sospesi') : $dati['scrutinio']->getDato('rinviati'));
      $alunni = $this->em->getRepository('App:Alunno')->createQueryBuilder('a')
        ->select('a.id,a.nome,a.cognome,a.dataNascita,a.sesso,a.religione,a.bes,a.note')
        ->where('a.id IN (:lista)')
        ->orderBy('a.cognome,a.nome,a.dataNascita', 'ASC')
        ->setParameters(['lista' => $sospesi])
        ->getQuery()
        ->getArrayResult();
      foreach ($alunni as $alu) {
        $dati['alunni'][$alu['id']] = $alu;
      }
      // legge esiti
      $esiti = $this->em->getRepository('App:Esito')->createQueryBuilder('e')
        ->where('e.alunno IN (:lista) AND e.scrutinio=:scrutinio')
        ->setParameters(['lista' => $sospesi, 'scrutinio' => $dati['scrutinio']])
        ->getQuery()
        ->getResult();
      foreach ($esiti as $e) {
        $dati['esiti'][$e->getAlunno()->getId()] = $e;
      }
    }
    // restituisce dati
    return $dati;
  }

  /**
   * Crea il verbale
   *
   * @param Classe $classe Classe dello scrutinio
   * @param string $periodo Periodo dello scrutinio
   *
   * @return Percorso completo del file da inviare
   */
  public function verbale(Classe $classe, $periodo) {
    // inizializza
    $fs = new Filesystem();
    if ($periodo == 'P') {
      // primo trimestre
      $percorso = $this->root.'/trimestre/'.$classe->getAnno().$classe->getSezione();
      if (!$fs->exists($percorso)) {
        // crea directory
        $fs->mkdir($percorso, 0775);
      }
      // nome documento
      $nomefile = $classe->getAnno().$classe->getSezione().'-primo-trimestre-verbale.pdf';
      if (!$fs->exists($percorso.'/'.$nomefile)) {
        // crea il documento
        $nome_classe = $classe->getAnno().'ª '.$classe->getSezione();
        $nome_classe_lungo = $nome_classe.' '.$classe->getCorso()->getNomeBreve().' - '.$classe->getSede()->getCitta();
        $this->pdf->configure($this->session->get('/CONFIG/ISTITUTO/intestazione'),
          'Scrutinio Primo Trimestre - Verbale classe '.$nome_classe);
        $dati = $this->verbaleDati($classe, $periodo);
        // crea il documento
        $this->creaVerbale_P($this->pdf->getHandler(), $nome_classe, $nome_classe_lungo, $dati);
        // salva il documento
        $this->pdf->save($percorso.'/'.$nomefile);
      }
      // restituisce nome del file
      return $percorso.'/'.$nomefile;
    } elseif ($periodo == 'F') {
      // scrutinio finale
      $percorso = $this->root.'/finale/'.$classe->getAnno().$classe->getSezione();
      if (!$fs->exists($percorso)) {
        // crea directory
        $fs->mkdir($percorso, 0775);
      }
      // nome documento
      $nomefile = $classe->getAnno().$classe->getSezione().'-scrutinio-finale-verbale.pdf';
      if (!$fs->exists($percorso.'/'.$nomefile)) {
        // crea il documento
        $nome_classe = $classe->getAnno().'ª '.$classe->getSezione();
        $nome_classe_lungo = $nome_classe.' '.$classe->getCorso()->getNomeBreve().' - '.$classe->getSede()->getCitta();
        $this->pdf->configure($this->session->get('/CONFIG/ISTITUTO/intestazione'),
          'Scrutinio Finale - Verbale classe '.$nome_classe);
        $dati = $this->verbaleDati($classe, $periodo);
        // crea il documento
        $this->creaVerbale_F($this->pdf->getHandler(), $nome_classe, $nome_classe_lungo, $dati);
        // salva il documento
        $this->pdf->save($percorso.'/'.$nomefile);
      }
      // restituisce nome del file
      return $percorso.'/'.$nomefile;
    } elseif ($periodo == 'I') {
      // scrutinio integrativo
      $percorso = $this->root.'/integrativo/'.$classe->getAnno().$classe->getSezione();
      if (!$fs->exists($percorso)) {
        // crea directory
        $fs->mkdir($percorso, 0775);
      }
      // nome documento
      $nomefile = $classe->getAnno().$classe->getSezione().'-scrutinio-integrativo-verbale.pdf';
      if (!$fs->exists($percorso.'/'.$nomefile)) {
        // crea il documento
        $nome_classe = $classe->getAnno().'ª '.$classe->getSezione();
        $nome_classe_lungo = $nome_classe.' '.$classe->getCorso()->getNomeBreve().' - '.$classe->getSede()->getCitta();
        $this->pdf->configure($this->session->get('/CONFIG/ISTITUTO/intestazione'),
          'Scrutinio Integrativo - Verbale classe '.$nome_classe);
        $dati = $this->verbaleDati($classe, $periodo);
        // crea il documento
        $this->creaVerbale_I($this->pdf->getHandler(), $nome_classe, $nome_classe_lungo, $dati);
        // salva il documento
        $this->pdf->save($percorso.'/'.$nomefile);
      }
      // restituisce nome del file
      return $percorso.'/'.$nomefile;
    } elseif ($periodo == 'X') {
      // scrutinio integrativo
      $percorso = $this->root.'/rinviato/'.$classe->getAnno().$classe->getSezione();
      if (!$fs->exists($percorso)) {
        // crea directory
        $fs->mkdir($percorso, 0775);
      }
      // nome documento
      $nomefile = $classe->getAnno().$classe->getSezione().'-scrutinio-rinviato-verbale.pdf';
      if (!$fs->exists($percorso.'/'.$nomefile)) {
        // crea il documento
        $nome_classe = $classe->getAnno().'ª '.$classe->getSezione();
        $nome_classe_lungo = $nome_classe.' '.$classe->getCorso()->getNomeBreve().' - '.$classe->getSede()->getCitta();
        $this->pdf->configure($this->session->get('/CONFIG/ISTITUTO/intestazione'),
          'Scrutinio Integrativo - Verbale classe '.$nome_classe);
        $dati = $this->verbaleDati($classe, $periodo);
        // crea il documento
        $this->creaVerbale_I($this->pdf->getHandler(), $nome_classe, $nome_classe_lungo, $dati);
        // salva il documento
        $this->pdf->save($percorso.'/'.$nomefile);
      }
      // restituisce nome del file
      return $percorso.'/'.$nomefile;
    }
    // errore
    return null;
  }

  /**
   * Crea il verbale come documento PDF
   *
   * @param TCPDF $pdf Gestore del documento PDF
   * @param string $classe Nome della classe
   * @param string $classe_completa Nome della classe con corso e sede
   * @param array $dati Dati dello scrutinio
   */
  public function creaVerbale_P($pdf, $classe, $classe_completa, $dati) {
    // set margins
    $pdf->SetMargins(10, 15, 10, true);
    // set auto page breaks
    $pdf->SetAutoPageBreak(true, 15);
    // set font
    $pdf->SetFont('times', '', 12);
    // inizio pagina
    $pdf->setPrintHeader(false);
    $pdf->SetFooterMargin(15);
    $pdf->setFooterFont(Array('helvetica', '', 9));
    $pdf->setFooterData(array(0,0,0), array(255,255,255));
    $pdf->setPrintFooter(true);
    $pdf->AddPage('P');
    // logo
    $html = '<img src="/img/'.getenv('LOCAL_PATH').'intestazione-documenti.jpg" width="540">';
    $pdf->writeHTML($html, true, false, false, false, 'C');
    // struttura
    foreach ($dati['definizione']->getStruttura() as $step=>$args) {
      $func = 'CreaVerbale_P_'.$args[0];
      $this->$func($pdf, $classe, $classe_completa, $dati, $step, $args);
    }
  }

  /**
   * Crea il verbale come documento PDF: parte iniziale
   *
   * @param TCPDF $pdf Gestore del documento PDF
   * @param string $classe Nome della classe
   * @param string $classe_completa Nome della classe con corso e sede
   * @param array $dati Dati dello scrutinio
   * @param int $step Passo della struttura del verbale
   * @param array $args Argomenti della struttura del verbale
   */
  public function creaVerbale_P_ScrutinioInizio($pdf, $classe, $classe_completa, $dati, $step, $args) {
    // inizializzazione
    $nome_mesi = ['', 'Gennaio', 'Febbraio', 'Marzo', 'Aprile', 'Maggio', 'Giugno', 'Luglio', 'Agosto', 'Settembre', 'Ottobre', 'Novembre', 'Dicembre'];
    $pdf->Ln(5);
    $pdf->SetFont('times', '', 12);
    $html = '<p align="center"><strong>VERBALE DELLO SCRUTINIO DEL PRIMO TRIMESTE<br>'.
      'CLASSE '.$classe_completa.'</strong></p>';
    $pdf->writeHTML($html, true, false, false, true);
    $pdf->Ln(5);
    // inizio seduta
    $pdf->SetFont('times', '', 11);
    $datascrutinio_giorno = intval($dati['scrutinio']->getData()->format('d'));
    $datascrutinio_mese = $nome_mesi[intval($dati['scrutinio']->getData()->format('m'))];
    $datascrutinio_anno = $dati['scrutinio']->getData()->format('Y');
    $orascrutinio_inizio = $dati['scrutinio']->getInizio()->format('H:i');
    $html = '<p align="justify">Il giorno '.$datascrutinio_giorno.' del mese di '.$datascrutinio_mese.', dell\'anno '.
      $datascrutinio_anno.', alle ore '.$orascrutinio_inizio.', nei locali dell\'<em>'.$this->session->get('/CONFIG/ISTITUTO/intestazione').'</em> di Cagliari, con sede staccata in Assemini, si è riunito, a seguito di regolare convocazione, il Consiglio della Classe '.
      $classe.' per discutere il seguente ordine del giorno:</p>';
    $pdf->writeHTML($html, true, false, false, true);
    $html = '<ol>';
    foreach ($dati['definizione']->getArgomenti() as $num=>$arg) {
      $html .='<li align="justify"><strong>'.$arg.(isset($dati['definizione']->getArgomenti()[$num + 1]) ? ';' : '.').'</strong></li>';
    }
    $html .='</ol>';
    $pdf->writeHTML($html, true, false, false, true);
    $pdf->Ln(1);
    if ($dati['scrutinio']->getDato('presiede_ds')) {
      $pres_nome = 'il Dirigente Scolastico';
    } else {
      $d_id = $dati['scrutinio']->getDato('presiede_docente');
      if ($dati['scrutinio']->getDato('presenze')[$d_id]->getPresenza()) {
        $d = $dati['docenti'][$d_id];
        $pres_nome = 'per delega '.($d['sesso'] == 'M' ? 'il Prof.' : 'la Prof.ssa').' '.
          $d['cognome'].' '.$d['nome'];
      } else {
        $pres_nome = 'per delega '.
          ($dati['scrutinio']->getDato('presenze')[$d_id]->getSessoSostituto() == 'M' ? 'il Prof.' : 'la Prof.ssa').
          ucwords(strtolower($dati['scrutinio']->getDato('presenze')[$d_id]->getSostituto()));
      }
    }
    $d_id = $dati['scrutinio']->getDato('segretario');
    $d = $dati['docenti'][$d_id];
    if ($dati['scrutinio']->getDato('presenze')[$d_id]->getPresenza()) {
      $segr_nome = ($d['sesso'] == 'M' ? 'il Prof.' : 'la Prof.ssa').' '.
        $d['cognome'].' '.$d['nome'];
    } else {
      $segr_nome = ($dati['scrutinio']->getDato('presenze')[$d_id]->getSessoSostituto() == 'M' ? 'il Prof.' : 'la Prof.ssa').
        ' '.ucwords(strtolower($dati['scrutinio']->getDato('presenze')[$d_id]->getSostituto()));
    }
    $html = '<p align="justify">Presiede la riunione '.$pres_nome.', funge da segretario verbalizzante '.$segr_nome.'.</p>';
    $pdf->writeHTML($html, true, false, false, true);
    $pdf->Ln(1);
    $html = '<p align="justify">Sono presenti i professori:</p>';
    $pdf->writeHTML($html, true, false, false, true);
    $pdf->Ln(1);
    $pdf->SetFont('helvetica', '', 10);
    $html = '<table border="1" cellpadding="2">
      <tr nobr="true"><td width="40%" align="center"><strong>DOCENTE</strong></td><td width="60%" align="center"><strong>MATERIA</strong></td></tr>';
    $assenti = 0;
    foreach ($dati['scrutinio']->getDato('presenze') as $iddocente=>$doc) {
      if ($doc->getPresenza()) {
        $d = $dati['docenti'][$iddocente];
        $nome = $d['cognome'].' '.$d['nome'];
        $materie = '';
        foreach ($dati['docenti'][$iddocente]['materie'] as $km=>$vm) {
          $materie .= '<br>&bull; '.($vm['tipo_cattedra'] == 'I' ? 'Lab. ' : '').$vm['nome_materia'];
        }
        $html .= '<tr><td>'.$nome.'</td><td>'.substr($materie, 4).'</td></tr>';
      } else {
        $assenti++;
      }
    }
    $html .= '</table>';
    $pdf->writeHTML($html, true, false, false, true);
    $pdf->Ln(1);
    $pdf->SetFont('times', '', 11);
    if ($assenti > 0) {
      $html = '<p align="justify">Sono assenti giustificati i seguenti docenti, surrogati con atto formale del Dirigente Scolastico:</p>';
      $pdf->writeHTML($html, true, false, false, true);
      $html = '<ul>';
      foreach ($dati['scrutinio']->getDato('presenze') as $iddocente=>$doc) {
        if (!$doc->getPresenza()) {
          $assenti--;
          $d = $dati['docenti'][$iddocente];
          $nome = $d['cognome'].' '.$d['nome'];
          $materie = '';
          foreach ($dati['docenti'][$iddocente]['materie'] as $km=>$vm) {
            $materie .= '; '.($vm['tipo_cattedra'] == 'I' ? 'Lab. ' : '').$vm['nome_materia'];
          }
          $text = ($d['sesso'] == 'M' ? 'Prof.' : 'Prof.ssa').' '.$nome.' ('.substr($materie,2).'), '.
            'sostituit'.($d['sesso'] == 'M' ? 'o' : 'a').' dal'.
            ($doc->getSessoSostituto() == 'M' ? ' Prof.' : 'la Prof.ssa').
            ' '.ucwords(strtolower($doc->getSostituto()));
          $html .= '<li align="justify">'.$text.($assenti > 0 ? ';' : '.').'</li>';
        }
      }
      $html .= '</ul>';
      $pdf->writeHTML($html, true, false, false, true);
    } else {
      $html = '<p align="justify">Nessuno è assente.</p>';
      $pdf->writeHTML($html, true, false, false, true);
    }
    $pdf->Ln(1);
    $html = '<p align="justify">Accertata la legalità della seduta, il presidente dà avvio alle operazioni.</p>';
    $pdf->writeHTML($html, true, false, false, true);
    $pdf->Ln(5);
  }

  /**
   * Crea il verbale come documento PDF: argomento all'ordine del giorno
   *
   * @param TCPDF $pdf Gestore del documento PDF
   * @param string $classe Nome della classe
   * @param string $classe_completa Nome della classe con corso e sede
   * @param array $dati Dati dello scrutinio
   * @param int $step Passo della struttura del verbale
   * @param array $args Argomenti della struttura del verbale
   */
  public function CreaVerbale_P_Argomento($pdf, $classe, $classe_completa, $dati, $step, $args) {
    $pdf->SetFont('times', '', 11);
    $num_arg = $args[2]['argomento'];
    $argomento = $dati['definizione']->getArgomenti()[$num_arg];
    $html = '<p align="justify"><strong>'.$args[2]['sezione'].'. '.$argomento.'.</strong></p>';
    $pdf->writeHTML($html, true, false, false, true);
    $pdf->Ln(1);
    $testo = $dati['scrutinio']->getDato('argomento')[$num_arg];
    $html = '<p align="justify">'.nl2br(htmlentities($testo)).'</p>';
    $pdf->writeHTML($html, true, false, false, true);
    $pdf->Ln(5);
  }

  /**
   * Crea il verbale come documento PDF: svolgimento scrutinio
   *
   * @param TCPDF $pdf Gestore del documento PDF
   * @param string $classe Nome della classe
   * @param string $classe_completa Nome della classe con corso e sede
   * @param array $dati Dati dello scrutinio
   * @param int $step Passo della struttura del verbale
   * @param array $args Argomenti della struttura del verbale
   */
  public function CreaVerbale_P_ScrutinioSvolgimento($pdf, $classe, $classe_completa, $dati, $step, $args) {
    $pdf->SetFont('times', '', 11);
    $num_arg = $args[2]['argomento'];
    $argomento = $dati['definizione']->getArgomenti()[$num_arg];
    $html = '<p align="justify"><strong>'.$args[2]['sezione'].'. '.$argomento.'.</strong></p>';
    $pdf->writeHTML($html, true, false, false, true);
    $pdf->Ln(1);
    // indicazioni
    $html = '<p align="justify">Prima di dare inizio alle operazioni di scrutinio, in ottemperanza a quanto previsto dalle norme vigenti e in base ai criteri di valutazione stabiliti dal Collegio dei Docenti e inseriti nel PTOF, il presidente ricorda che:</p>';
    $pdf->writeHTML($html, true, false, false, true);
    $html = '<ul>
      <li align="justify">tutti i presenti sono tenuti all\'obbligo della stretta osservanza del segreto d\'ufficio e che l\'eventuale violazione comporta sanzioni disciplinari;</li>
      <li align="justify">il voto di condotta è proposto dal Coordinatore di classe (o, in sua assenza, dal docente con maggior numero di ore di lezione) ed assegnato dal Consiglio di Classe. Per l\'attribuzione si terrà conto di: interesse e partecipazione attiva e regolare alla vita della scuola, comportamento corretto con i docenti e i compagni, provvedimenti disciplinari;</li>
      <li align="justify">i voti di profitto sono proposti dagli insegnanti delle rispettive materie ed assegnati dal Consiglio di Classe.</li>
      </ul>';
    $pdf->writeHTML($html, true, false, false, true);
    $pdf->Ln(1);
    $html = '<p align="justify">In merito alle proposte di voto che vengono formulate, i singoli docenti dichiarano:</p>';
    $pdf->writeHTML($html, true, false, false, true);
    $html = '<ul>
      <li align="justify">che le proposte di voto ed i giudizi sono stati determinati sulla base delle verifiche sistematiche effettuate nel corso dell\'anno scolastico, sulla base dell\'impegno allo studio, alla partecipazione, all\'interesse al lavoro scolastico, in relazione alle effettive possibilità ed al progresso rispetto alla situazione di partenza di ciascun alunno;</li>
      <li align="justify">che i giudizi proposti tengono conto delle attività di sostegno e di recupero proposte alla classe e delle loro risultanze.</li>
      </ul>';
    $pdf->writeHTML($html, true, false, false, true);
    $pdf->Ln(1);
    // condotta
    $html = '<p align="justify">Il coordinatore propone il voto di condotta, che viene approvato dal Consiglio di Classe secondo quanto segue:</p>';
    $pdf->writeHTML($html, true, false, false, true);
    $pdf->Ln(1);
    $pdf->SetFont('helvetica', '', 10);
    $html = '<table border="1" cellpadding="2">
      <tr nobr="true"><td width="40%" align="center"><strong>ALUNNO</strong></td><td width="6%" align="center"><strong>Voto</strong></td><td width="38%" align="center"><strong>Giudizio</strong></td><td width="16%" align="center"><strong>Votazione</strong></td></tr>';
    foreach ($dati['alunni'] as $idalunno=>$alu) {
      $nome = $alu['cognome'].' '.$alu['nome'];
      $condotta_voto = $dati['voti'][$idalunno]->getUnico() == 4 ? 'NC' : $dati['voti'][$idalunno]->getUnico();
      $condotta_motivazione = htmlentities(str_replace(array("\r", "\n"), ' ',
        $dati['voti'][$idalunno]->getDato('motivazione')));
      $condotta_unanimita = $dati['voti'][$idalunno]->getDato('unanimita');
      $condotta_contrari = intval($dati['voti'][$idalunno]->getDato('contrari'));
      if ($condotta_unanimita) {
        $condotta_approvazione = 'UNANIMITÀ';
      } else {
        $condotta_approvazione = "MAGGIORANZA<br>Contrari: $condotta_contrari";
      }
      $html .= '<tr nobr="true"><td>'.$nome.'</td><td>'.$condotta_voto.'</td><td style="font-size:9pt">'.
        $condotta_motivazione.'</td><td style="font-size:9pt">'.$condotta_approvazione.'</td></tr>';
    }
    $html .= '</table>';
    $pdf->writeHTML($html, true, false, false, true);
    $pdf->Ln(1);
    // valutazione
    $pdf->SetFont('times', '', 11);
    $html = '<p align="justify">Si passa, quindi, seguendo l\'ordine alfabetico, alla valutazione di ogni singolo alunno, tenuto conto degli indicatori precedentemente espressi.</p>';
    $pdf->writeHTML($html, true, false, false, true);
    $pdf->Ln(1);
    $html = '<p align="justify">Per ciascuna disciplina il docente competente esprime il proprio giudizio complessivo sull\'alunno. Ciascun giudizio è tradotto coerentemente in un voto, che viene proposto al Consiglio di Classe.</p>';
    $pdf->writeHTML($html, true, false, false, true);
    $pdf->Ln(1);
    $html = '<p align="justify">Il Consiglio di Classe discute esaurientemente le proposte espresse dai docenti e, tenuti ben presenti i parametri di valutazione deliberati, procede alla definizione e all\'approvazione dei voti per ciascun alunno e per ciascuna disciplina.</p>';
    $pdf->writeHTML($html, true, false, false, true);
    $pdf->Ln(1);
    $html = '<p align="justify">Terminata la fase deliberativa, si procede alla stampa dei tabelloni e alla firma del Registro Generale, nonché alla predisposizione delle comunicazioni per le famiglie degli alunni con debito formativo.</p>'.
      '<p>Il riepilogo dei voti deliberati per ciascun alunno viene allegato al presente verbale, di cui fa parte integrante.</p>';
    $pdf->writeHTML($html, true, false, false, true);
    $pdf->Ln(5);
  }

  /**
   * Crea il verbale come documento PDF: assegnamento nuovi crediti
   *
   * @param TCPDF $pdf Gestore del documento PDF
   * @param string $classe Nome della classe
   * @param string $classe_completa Nome della classe con corso e sede
   * @param array $dati Dati dello scrutinio
   * @param int $step Passo della struttura del verbale
   * @param array $args Argomenti della struttura del verbale
   */
  public function CreaVerbale_P_NuoviCrediti($pdf, $classe, $classe_completa, $dati, $step, $args) {
    $pdf->SetFont('times', '', 11);
    $num_arg = $args[2]['argomento'];
    $argomento = $dati['definizione']->getArgomenti()[$num_arg];
    $html = '<p align="justify"><strong>'.$args[2]['sezione'].'. '.$argomento.'.</strong></p>';
    $pdf->writeHTML($html, true, false, false, true);
    $pdf->Ln(1);
    if ($dati['classe']->getAnno() < 4) {
      // nessun adeguamento
      $html = '<p align="justify">Il punto all\'ordine del giorno non riguarda la classe.</p>';
      $pdf->writeHTML($html, true, false, false, true);
    } else {
      // adeguamento dei crediti
      $nuovicrediti = $dati['scrutinio']->getDato('nuovicrediti');
      $html = '<p align="justify">Il Consiglio di Classe esamina il credito scolastico conseguito dagli alunni '.
        ($dati['classe']->getAnno() == 5 ? 'nelle classi terza e quarta' : 'nella classe terza').
        ' per procedere all\'adeguamento del punteggio secondo quanto previsto per il nuovo Esame di Stato'.
        ' (d.lgs. 62/2017 e circolare MIUR 3050.04-10-2018). Di seguito viene riportato il nuovo credito scolastico risultante:</p>';
      $pdf->writeHTML($html, true, false, false, true);
      $pdf->Ln(1);
      $pdf->SetFont('helvetica', '', 10);
      $html = '<table border="1" cellpadding="2">'.
        '<tr nobr="true"><td width="30%" align="center"><strong>ALUNNO</strong></td><td width="15%" align="center"><strong>Credito<br>Terza</strong></td>'.
        ($dati['classe']->getAnno() == 5 ? '<td width="15%" align="center"><strong>Credito<br>Quarta</strong></td><td width="40%" align="center">' : '<td width="55%" align="center">').
        '<strong>Nuovo Credito</strong></td></tr>';
      foreach ($dati['alunni'] as $idalunno=>$alu) {
        $nome = $alu['cognome'].' '.$alu['nome'];
        if ($alu['credito3'] == 0 || ($dati['classe']->getAnno() == 5 && $alu['credito4'] == 0)) {
          // credito con motivazione
          $motivazione = htmlentities(str_replace(array("\r", "\n"), ' ', $nuovicrediti[$idalunno][1]));
          $html .= '<tr nobr="true"><td rowspan="2">'.$nome.'</td>'.
            '<td>'.($alu['credito3'] > 0 ? $alu['credito3'] : '-').'</td>'.
            ($dati['classe']->getAnno() == 5 ? '<td>'.($alu['credito4'] > 0 ? $alu['credito4'] : '-').'</td>' : '').
            '<td><strong>'.($nuovicrediti[$idalunno][0] > 0 ? $nuovicrediti[$idalunno][0] : 'NON ASSEGNATO').'</strong></td>'.
            '</tr>'.
            '<tr nobr="true"><td style="font-size:9pt" colspan="'.($dati['classe']->getAnno() == 5 ? 3 : 2).'"><em>'.$motivazione.'</em></td></tr>';
        } else {
          // credito modificato in automatico
          $html .= '<tr nobr="true"><td>'.$nome.'</td><td>'.$alu['credito3'].'</td>'.
            ($dati['classe']->getAnno() == 5 ? '<td>'.$alu['credito4'].'</td>' : '').
            '<td><strong>'.$nuovicrediti[$idalunno].'</strong></td></tr>';
        }
      }
      $html .= '</table>';
      $pdf->writeHTML($html, true, false, false, true);
      $pdf->Ln(1);
      $html = '<p align="justify">L\'esito dell\'operazione di conversione del credito sarà riportato nella pagella del trimestre, al fine di rendere consapevole ciascun alunno della nuova situazione.</p>';
      $pdf->writeHTML($html, true, false, false, true);
    }
    $pdf->Ln(5);
  }

  /**
   * Crea il verbale come documento PDF: fine scrutinio
   *
   * @param TCPDF $pdf Gestore del documento PDF
   * @param string $classe Nome della classe
   * @param string $classe_completa Nome della classe con corso e sede
   * @param array $dati Dati dello scrutinio
   * @param int $step Passo della struttura del verbale
   * @param array $args Argomenti della struttura del verbale
   */
  public function CreaVerbale_P_ScrutinioFine($pdf, $classe, $classe_completa, $dati, $step, $args) {
    $pdf->Ln(5);
    $pdf->SetFont('times', '', 11);
    $orascrutinio_fine = $dati['scrutinio']->getFine()->format('H:i');
    $html = '<p align="justify">Alle ore '.$orascrutinio_fine.', terminate tutte le operazioni, la seduta è tolta.</p>';
    $pdf->writeHTML($html, true, false, false, true);
    $pdf->Ln(10);
    // firma
    if ($dati['scrutinio']->getDato('presiede_ds')) {
      $presidente_nome = $this->session->get('/CONFIG/ISTITUTO/firma_preside');
    } else {
      $d_id = $dati['scrutinio']->getDato('presiede_docente');
      if ($dati['scrutinio']->getDato('presenze')[$d_id]->getPresenza()) {
        $d = $dati['docenti'][$d_id];
        $presidente_nome = ($d['sesso'] == 'M' ? 'Prof.' : 'Prof.ssa').' '.
          $d['cognome'].' '.$d['nome'];
      } else {
        $presidente_nome = ($dati['scrutinio']->getDato('presenze')[$d_id]->getSessoSostituto() == 'M' ? 'Prof.' : 'Prof.ssa').
          ucwords(strtolower($dati['scrutinio']->getDato('presenze')[$d_id]->getSostituto()));
      }
    }
    $d_id = $dati['scrutinio']->getDato('segretario');
    $d = $dati['docenti'][$d_id];
    if ($dati['scrutinio']->getDato('presenze')[$d_id]->getPresenza()) {
      $segretario_nome = ($d['sesso'] == 'M' ? 'Prof.' : 'Prof.ssa').' '.
        $d['cognome'].' '.$d['nome'];
    } else {
      $segretario_nome = ($dati['scrutinio']->getDato('presenze')[$d_id]->getSessoSostituto() == 'M' ? 'Prof.' : 'Prof.ssa').
        ' '.ucwords(strtolower($dati['scrutinio']->getDato('presenze')[$d_id]->getSostituto()));
    }
    $html = '<table border="0" cellpadding="3" nobr="true">
      <tr nobr="true"><td width="45%" align="center">Il Segretario</td><td width="10%">&nbsp;</td><td width="45%" align="center">Il Presidente</td></tr>
      <tr nobr="true"><td align="center"><em>'.$segretario_nome.'</em></td><td>&nbsp;</td><td align="center"><em>'.$presidente_nome.'</em></td></tr>
      </table>';
    $pdf->writeHTML($html, true, false, false, true);
  }

  /**
   * Restituisce i dati per creare la pagella
   *
   * @param Classe $classe Classe dello scrutinio
   * @param Alunno $alunno Alunno selezionato
   * @param string $periodo Periodo dello scrutinio
   *
   * @return Array Dati formattati come array associativo
   */
  public function pagellaDati(Classe $classe, Alunno $alunno, $periodo) {
    $dati = array();
    // dati alunno
    $dati['alunno'] = $alunno;
    // dati classe
    $dati['classe'] = $classe;
    // dati scrutinio
    $dati['scrutinio'] = $this->em->getRepository('App:Scrutinio')->findOneBy(['classe' => $classe,
      'periodo' => $periodo, 'stato' => 'C']);
    // legge materie
    $materie = $this->em->getRepository('App:Materia')->createQueryBuilder('m')
      ->select('DISTINCT m.id,m.nome,m.tipo')
      ->join('App:Cattedra', 'c', 'WITH', 'c.materia=m.id')
      ->where('c.classe=:classe AND c.attiva=:attiva AND c.tipo=:tipo AND m.tipo!=:sostegno')
      ->orderBy('m.ordinamento', 'ASC')
      ->setParameters(['classe' => $classe, 'attiva' => 1, 'tipo' => 'N', 'sostegno' => 'S'])
      ->getQuery()
      ->getArrayResult();
    foreach ($materie as $mat) {
      $dati['materie'][$mat['id']] = $mat;
    }
    $condotta = $this->em->getRepository('App:Materia')->findOneByTipo('C');
    $dati['materie'][$condotta->getId()] = array(
      'id' => $condotta->getId(),
      'nome' => $condotta->getNome(),
      'nomeBreve' => $condotta->getNomeBreve(),
      'tipo' => $condotta->getTipo());
    // legge i voti
    $voti = $this->em->getRepository('App:VotoScrutinio')->createQueryBuilder('vs')
      ->join('vs.scrutinio', 's')
      ->where('s.classe=:classe AND s.periodo=:periodo AND vs.alunno=:alunno AND vs.unico IS NOT NULL')
      ->setParameters(['classe' => $classe, 'periodo' => $periodo, 'alunno' => $alunno])
      ->getQuery()
      ->getResult();
    foreach ($voti as $v) {
      // inserisce voti/assenze
      $dati['voti'][$v->getMateria()->getId()] = array(
        'id' => $v->getId(),
        'unico' => $v->getUnico(),
        'assenze' => $v->getAssenze(),
        'recupero' => $v->getRecupero(),
        'debito' => $v->getDebito());
    }
    if ($periodo == 'F' || $periodo == 'I' || $periodo == 'X') {
      // legge esito
      $dati['esito'] = $this->em->getRepository('App:Esito')->createQueryBuilder('e')
        ->where('e.alunno=:alunno AND e.scrutinio=:scrutinio')
        ->setParameters(['alunno' => $alunno, 'scrutinio' => $dati['scrutinio']])
        ->setMaxResults(1)
        ->getQuery()
        ->getOneOrNullResult();
    }
    // restituisce dati
    return $dati;
  }

  /**
   * Crea la pagella
   *
   * @param Classe $classe Classe dello scrutinio
   * @param Alunno $alunno Alunno selezionato
   * @param string $periodo Periodo dello scrutinio
   *
   * @return Percorso completo del file da inviare
   */
  public function pagella(Classe $classe, Alunno $alunno, $periodo) {
    // inizializza
    $fs = new Filesystem();
    if ($periodo == 'P') {
      // primo trimestre
      $percorso = $this->root.'/trimestre/'.$classe->getAnno().$classe->getSezione();
      if (!$fs->exists($percorso)) {
        // crea directory
        $fs->mkdir($percorso, 0775);
      }
      // nome documento
      $nomefile = $classe->getAnno().$classe->getSezione().'-primo-trimestre-pagella-'.$alunno->getId().'.pdf';
      if (!$fs->exists($percorso.'/'.$nomefile)) {
        // crea documento
        $this->pdf->configure($this->session->get('/CONFIG/ISTITUTO/intestazione'),
          'Scrutinio Primo Trimestre - Pagella - Alunno '.$alunno->getCognome().' '.$alunno->getNome());
        $dati = $this->pagellaDati($classe, $alunno, $periodo);
        // crea il documento
        $nome_classe = $classe->getAnno().'ª '.$classe->getSezione();
        $nome_classe_lungo = $nome_classe.' '.$classe->getCorso()->getNomeBreve().' - '.$classe->getSede()->getCitta();
        $this->creaPagella_P($this->pdf->getHandler(), $nome_classe, $nome_classe_lungo, $dati);
        // salva il documento
        $this->pdf->save($percorso.'/'.$nomefile);
      }
      // restituisce nome del file
      return $percorso.'/'.$nomefile;
    } elseif ($periodo == '1') {
      // valutazione intermedia
      $percorso = $this->root.'/val-intermedia/'.$classe->getAnno().$classe->getSezione();
      if (!$fs->exists($percorso)) {
        // crea directory
        $fs->mkdir($percorso, 0775);
      }
      // nome documento
      $nomefile = $classe->getAnno().$classe->getSezione().'-valutazione-intermedia-'.$alunno->getId().'.pdf';
      if (!$fs->exists($percorso.'/'.$nomefile)) {
        // crea pdf
        $this->pdf->configure($this->session->get('/CONFIG/ISTITUTO/intestazione'),
          'Valutazione intermedia - Alunno '.$alunno->getCognome().' '.$alunno->getNome());
        $dati = $this->pagellaDati($classe, $alunno, $periodo);
        // crea il documento
        $nome_classe = $classe->getAnno().'ª '.$classe->getSezione();
        $nome_classe_lungo = $nome_classe.' '.$classe->getCorso()->getNomeBreve().' - '.$classe->getSede()->getCitta();
        $this->creaPagella_1($this->pdf->getHandler(), $nome_classe, $nome_classe_lungo, $dati);
        // salva il documento
        $this->pdf->save($percorso.'/'.$nomefile);
      }
      // restituisce nome del file
      return $percorso.'/'.$nomefile;
    } elseif ($periodo == 'F') {
      // scrutinio finale
      $percorso = $this->root.'/finale/'.$classe->getAnno().$classe->getSezione();
      if (!$fs->exists($percorso)) {
        // crea directory
        $fs->mkdir($percorso, 0775);
      }
      // nome documento
      $nomefile = $classe->getAnno().$classe->getSezione().'-scrutinio-finale-voti-'.$alunno->getId().'.pdf';
      if (!$fs->exists($percorso.'/'.$nomefile)) {
        // crea documento
        $this->pdf->configure($this->session->get('/CONFIG/ISTITUTO/intestazione'),
          'Scrutinio Finale - Comunicazione dei voti - Alunno '.$alunno->getCognome().' '.$alunno->getNome());
        $dati = $this->pagellaDati($classe, $alunno, $periodo);
        $nome_classe = $classe->getAnno().'ª '.$classe->getSezione();
        $nome_classe_lungo = $nome_classe.' '.$classe->getCorso()->getNomeBreve().' - '.$classe->getSede()->getCitta();
        // controllo alunno
        $scrut = ($dati['scrutinio']->getDato('scrutinabili') == null ? [] :
          array_keys($dati['scrutinio']->getDato('scrutinabili')));
        if (in_array($alunno->getId(), $scrut) && $dati['esito']) {
          // alunno scrutinato
          $this->creaPagella_F($this->pdf->getHandler(), $nome_classe, $nome_classe_lungo, $dati);
        } else {
          // errore
          return null;
        }
        // salva il documento
        $this->pdf->save($percorso.'/'.$nomefile);
      }
      // restituisce nome del file
      return $percorso.'/'.$nomefile;
    } elseif ($periodo == 'I') {
      // scrutinio integrativo
      $percorso = $this->root.'/integrativo/'.$classe->getAnno().$classe->getSezione();
      if (!$fs->exists($percorso)) {
        // crea directory
        $fs->mkdir($percorso, 0775);
      }
      // nome documento
      $nomefile = $classe->getAnno().$classe->getSezione().'-scrutinio-integrativo-voti-'.$alunno->getId().'.pdf';
      if (!$fs->exists($percorso.'/'.$nomefile)) {
        // crea documento
        $this->pdf->configure($this->session->get('/CONFIG/ISTITUTO/intestazione'),
          'Scrutinio Integrativo - Comunicazione dei voti - Alunno '.$alunno->getCognome().' '.$alunno->getNome());
        $dati = $this->pagellaDati($classe, $alunno, $periodo);
        $nome_classe = $classe->getAnno().'ª '.$classe->getSezione();
        $nome_classe_lungo = $nome_classe.' '.$classe->getCorso()->getNomeBreve().' - '.$classe->getSede()->getCitta();
        // controllo alunno
        if ($dati['esito'] && $dati['esito']->getEsito() == 'A') {
          // crea il documento
          $this->creaPagella_I($this->pdf->getHandler(), $nome_classe, $nome_classe_lungo, $dati);
        } else {
          // errore
          return null;
        }
        // salva il documento
        $this->pdf->save($percorso.'/'.$nomefile);
      }
      // restituisce nome del file
      return $percorso.'/'.$nomefile;
    } elseif ($periodo == 'X') {
      // scrutinio integrativo
      $percorso = $this->root.'/rinviato/'.$classe->getAnno().$classe->getSezione();
      if (!$fs->exists($percorso)) {
        // crea directory
        $fs->mkdir($percorso, 0775);
      }
      // nome documento
      $nomefile = $classe->getAnno().$classe->getSezione().'-scrutinio-rinviato-voti-'.$alunno->getId().'.pdf';
      if (!$fs->exists($percorso.'/'.$nomefile)) {
        // crea documento
        $this->pdf->configure($this->session->get('/CONFIG/ISTITUTO/intestazione'),
          'Scrutinio Integrativo - Comunicazione dei voti - Alunno '.$alunno->getCognome().' '.$alunno->getNome());
        $dati = $this->pagellaDati($classe, $alunno, $periodo);
        $nome_classe = $classe->getAnno().'ª '.$classe->getSezione();
        $nome_classe_lungo = $nome_classe.' '.$classe->getCorso()->getNomeBreve().' - '.$classe->getSede()->getCitta();
        // controllo alunno
        if ($dati['esito'] && $dati['esito']->getEsito() == 'A') {
          // crea il documento
          $this->creaPagella_I($this->pdf->getHandler(), $nome_classe, $nome_classe_lungo, $dati);
        } else {
          // errore
          return null;
        }
        // salva il documento
        $this->pdf->save($percorso.'/'.$nomefile);
      }
      // restituisce nome del file
      return $percorso.'/'.$nomefile;
    }
    // errore
    return null;
  }

  /**
   * Crea la pagella come documento PDF
   *
   * @param TCPDF $pdf Gestore del documento PDF
   * @param string $classe Nome della classe
   * @param string $classe_completa Nome della classe con corso e sede
   * @param array $dati Dati dello scrutinio
   */
  public function creaPagella_P($pdf, $classe, $classe_completa, $dati) {
    // set margins
    $pdf->SetMargins(10, 15, 10, true);
    // set auto page breaks
    $pdf->SetAutoPageBreak(true, 15);
    // set font
    $pdf->SetFont('times', '', 12);
    // inizio pagina
    $pdf->setPrintHeader(false);
    $pdf->SetFooterMargin(15);
    $pdf->setFooterFont(Array('helvetica', '', 9));
    $pdf->setFooterData(array(0,0,0), array(255,255,255));
    $pdf->setPrintFooter(true);
    $pdf->AddPage('P');
    // logo
    $html = '<img src="/img/'.getenv('LOCAL_PATH').'intestazione-documenti.jpg" width="540">';
    $pdf->writeHTML($html, true, false, false, false, 'C');
    // intestazione
    $alunno = $dati['alunno']->getCognome().' '.$dati['alunno']->getNome();
    $alunno_sesso = $dati['alunno']->getSesso();
    $pdf->Ln(10);
    $pdf->SetFont('times', 'I', 12);
    $text = 'Ai genitori dell\'alunn'.($alunno_sesso == 'M' ? 'o' : 'a');
    $this->cella($pdf, 0, 0, 100, 0, $text, 0, 'L', 'T');
    $pdf->Ln();
    $pdf->SetFont('times', '', 12);
    $text = strtoupper($alunno);
    $this->cella($pdf, 0, 0, 100, 0, $text, 0, 'L', 'T');
    $pdf->Ln();
    $text = 'Classe '.$classe;
    $this->cella($pdf, 0, 0, 100, 0, $text, 0, 'L', 'T');
    $pdf->Ln(15);
    // oggetto
    $pdf->SetFont('times', 'B', 12);
    $as = $this->session->get('/CONFIG/SCUOLA/anno_scolastico');
    $text = 'OGGETTO: Scrutinio del primo trimestre A.S. '.$as.' - Comunicazione dei voti';
    $this->cella($pdf, 0, 0, 0, 0, $text, 0, 'L', 'T');
    $pdf->Ln(10);
    // contenuto
    $pdf->SetFont('times', '', 12);
    $sex = ($alunno_sesso == 'M' ? 'o' : 'a');
    $html = '<p align="justify">Il Consiglio di Classe, nella seduta dello scrutinio del primo trimestre dell’anno scolastico '.$as.
            ', tenutasi il giorno '.$dati['scrutinio']->getData()->format('d/m/Y').', ha attribuito all\'alunn'.$sex.' '.
            'le valutazioni che vengono riportate di seguito:</p>';
    $pdf->writeHTML($html, true, false, false, true);
    $pdf->Ln(2);
    // voti
    $html = '<table border="1" cellpadding="3">';
    $html .= '<tr><td width="60%"><strong>MATERIA</strong></td><td width="20%"><strong>VOTO</strong></td><td width="20%"><strong>ORE DI ASSENZA</strong></td></tr>';
    foreach ($dati['materie'] as $idmateria=>$mat) {
      $html .= '<tr><td align="left"><strong>'.$mat['nome'].'</strong></td><td>';
      if ($mat['tipo'] == 'R' && $dati['alunno']->getReligione() == 'S') {
        // religione
        switch ($dati['voti'][$idmateria]['unico']) {
          case 20:
            $html .= 'Non classificato';
            break;
          case 21:
            $html .= 'Insufficiente';
            break;
          case 22:
            $html .= 'Sufficiente';
            break;
          case 23:
            $html .= 'Buono';
            break;
          case 24:
            $html .= 'Distinto';
            break;
          case 25:
            $html .= 'Ottimo';
            break;
        }
        $html .= '</td><td>'.$dati['voti'][$idmateria]['assenze'].'</td></tr>';
      } elseif ($mat['tipo'] == 'R') {
        // NA
        $html .= '///';
        $html .= '</td><td></td></tr>';
      } elseif ($mat['tipo'] == 'C') {
        $html .= ($dati['voti'][$idmateria]['unico'] == 4 ? 'Non classificato' : $dati['voti'][$idmateria]['unico']);
        $html .= '</td><td></td></tr>';
      } else {
        // altre materie
        $html .= ($dati['voti'][$idmateria]['unico'] == 0 ? 'Non classificato' : $dati['voti'][$idmateria]['unico']);
        $html .= '</td><td>'.$dati['voti'][$idmateria]['assenze'].'</td></tr>';
      }
    }
    $html .= '</table><br>';
    $pdf->SetFont('helvetica', '', 10);
    $pdf->writeHTML($html, true, false, false, true, 'C');
    // firma
    $pdf->SetFont('times', '', 12);
    $html = '<p>Distinti Saluti.<br></p>';
    $pdf->writeHTMLCell(0, 0, $pdf->GetX(), $pdf->GetY(), $html, 0, 1);
    $html = 'Cagliari, '.$dati['scrutinio']->getData()->format('d/m/Y').'.';
    $pdf->writeHTMLCell(100, 0, $pdf->GetX(), $pdf->GetY(), $html, 0, 0);
    $preside = $this->session->get('/CONFIG/ISTITUTO/firma_preside');
    $html = 'Il Dirigente Scolastico<br><em>'.$preside.'</em>';
    $pdf->writeHTMLCell(100, 0, $pdf->GetX(), $pdf->GetY(), $html, 0, 1, false, true, 'C');
  }

  /**
   * Crea la valutazione intermedia come documento PDF
   *
   * @param TCPDF $pdf Gestore del documento PDF
   * @param string $classe Nome della classe
   * @param string $classe_completa Nome della classe con corso e sede
   * @param array $dati Dati dello scrutinio
   */
  public function creaPagella_1($pdf, $classe, $classe_completa, $dati) {
    $info['giudizi'] = [30 => 'Non Classificato', 31 => 'Scarso', 32 => 'Insufficiente', 33 => 'Mediocre', 34 => 'Sufficiente', 35 => 'Discreto', 36 => 'Buono', 37 => 'Ottimo'];
    $info['condotta'] = [40 => 'Non Classificata', 41 => 'Scorretta', 42 => 'Non sempre adeguata', 43 => 'Corretta'];
    $info['recupero'] = [null => '', 'R' => 'Recuperato', 'N' => 'Non recuperato'];
    // set margins
    $pdf->SetMargins(10, 15, 10, true);
    // set auto page breaks
    $pdf->SetAutoPageBreak(true, 15);
    // set font
    $pdf->SetFont('times', '', 12);
    // inizio pagina
    $pdf->setPrintHeader(false);
    $pdf->SetFooterMargin(15);
    $pdf->setFooterFont(Array('helvetica', '', 9));
    $pdf->setFooterData(array(0,0,0), array(255,255,255));
    $pdf->setPrintFooter(true);
    $pdf->AddPage('P');
    // logo
    $html = '<img src="/img/'.getenv('LOCAL_PATH').'intestazione-documenti.jpg" width="540">';
    $pdf->writeHTML($html, true, false, false, false, 'C');
    // intestazione
    $alunno = $dati['alunno']->getCognome().' '.$dati['alunno']->getNome();
    $alunno_sesso = $dati['alunno']->getSesso();
    $pdf->Ln(10);
    $pdf->SetFont('times', 'I', 12);
    $text = 'Ai genitori dell\'alunn'.($alunno_sesso == 'M' ? 'o' : 'a');
    $this->cella($pdf, 0, 0, 100, 0, $text, 0, 'L', 'T');
    $pdf->Ln();
    $pdf->SetFont('times', '', 12);
    $text = strtoupper($alunno);
    $this->cella($pdf, 0, 0, 100, 0, $text, 0, 'L', 'T');
    $pdf->Ln();
    $text = 'Classe '.$classe;
    $this->cella($pdf, 0, 0, 100, 0, $text, 0, 'L', 'T');
    $pdf->Ln(15);
    // oggetto
    $pdf->SetFont('times', 'B', 12);
    $as = $this->session->get('/CONFIG/SCUOLA/anno_scolastico');
    $text = 'OGGETTO: Comunicazione della VALUTAZIONE INTERMEDIA - A.S. '.$as;
    $this->cella($pdf, 0, 0, 0, 0, $text, 0, 'L', 'T');
    $pdf->Ln(10);
    // contenuto
    $pdf->SetFont('times', '', 12);
    $sex = ($alunno_sesso == 'M' ? 'o' : 'a');
    $html = '<p align="justify">Il Consiglio di Classe, riunitosi il giorno '.$dati['scrutinio']->getData()->format('d/m/Y').' '.
      'al fine di valutare l\'andamento didattico disciplinare della classe, esaminata la situazione dell\'alunn'.$sex.', '.
      'sulla base degli elementi finora disponibili per ogni disciplina, informa la famiglia che, allo stato attuale, '.
      'il profitto, la frequenza e il comportamento risultano come indicati di seguito.';
    $pdf->writeHTML($html, true, false, false, true);
    $pdf->Ln(2);
    // voti
    $html = '<table border="1" cellpadding="3">';
    $html .= '<tr><td width="50%"><strong>MATERIA</strong></td><td width="20%"><strong>PROFITTO</strong></td><td width="15%"><strong>DEBITO<br>FORMATIVO</strong></td><td width="15%"><strong>ASSENZE<br>(ore)</strong></td></tr>';
    foreach ($dati['materie'] as $idmateria=>$mat) {
      $html .= '<tr><td align="left"><strong>'.$mat['nome'].'</strong></td>';
      if ($mat['tipo'] == 'R' && $dati['alunno']->getReligione() != 'S') {
        // NA
        $html .= '<td>///</td><td></td><td></td></tr>';
      } elseif ($mat['tipo'] == 'C') {
        // condotta
        $html .= '<td>'.$info['condotta'][$dati['voti'][$idmateria]['unico']].'</td><td></td><td></td></tr>';
      } else {
        // altre materie
        $html .= '<td>'.$info['giudizi'][$dati['voti'][$idmateria]['unico']].'</td><td>'.
          $info['recupero'][$dati['voti'][$idmateria]['recupero']].'</td><td>'.
          $dati['voti'][$idmateria]['assenze'].'</td></tr>';
      }
    }
    $html .= '</table><br>';
    $pdf->SetFont('helvetica', '', 10);
    $pdf->writeHTML($html, true, false, false, true, 'C');
    // firma
    $coord = 'Prof.'.($dati['classe']->getCoordinatore()->getSesso() == 'M' ? ' ' : 'ssa ').
      $dati['classe']->getCoordinatore()->getNome().' '.$dati['classe']->getCoordinatore()->getCognome();
    $pdf->SetFont('times', '', 12);
    $html = '<p>Distinti Saluti.<br></p>';
    $pdf->writeHTMLCell(0, 0, $pdf->GetX(), $pdf->GetY(), $html, 0, 1);
    $html = 'Cagliari, '.$dati['scrutinio']->getData()->format('d/m/Y').'.';
    $pdf->writeHTMLCell(100, 0, $pdf->GetX(), $pdf->GetY(), $html, 0, 0);
    $html = 'Il coordinatore di classe<br><i>'.$coord.'</i>';
    $pdf->writeHTMLCell(100, 0, $pdf->GetX(), $pdf->GetY(), $html, 0, 1, false, true, 'C');
  }

  /**
   * Restituisce i dati per creare il foglio dei debiti
   *
   * @param Classe $classe Classe dello scrutinio
   * @param Alunno $alunno Alunno selezionato
   * @param string $periodo Periodo dello scrutinio
   *
   * @return Array Dati formattati come array associativo
   */
  public function debitiDati(Classe $classe, Alunno $alunno, $periodo) {
    $dati = array();
    if ($periodo == 'P') {
      // dati alunno
      $dati['alunno'] = $alunno;
      // dati scrutinio
      $dati['scrutinio'] = $this->em->getRepository('App:Scrutinio')->findOneBy(['classe' => $classe,
        'periodo' => $periodo, 'stato' => 'C']);
      // legge materie
      $materie = $this->em->getRepository('App:Materia')->createQueryBuilder('m')
        ->select('DISTINCT m.id,m.nome,m.tipo')
        ->join('App:Cattedra', 'c', 'WITH', 'c.materia=m.id')
        ->where('c.classe=:classe AND c.attiva=:attiva AND c.tipo=:tipo AND m.tipo!=:sostegno')
        ->orderBy('m.ordinamento', 'ASC')
        ->setParameters(['classe' => $classe, 'attiva' => 1, 'tipo' => 'N', 'sostegno' => 'S'])
        ->getQuery()
        ->getArrayResult();
      foreach ($materie as $mat) {
        $dati['materie'][$mat['id']] = $mat;
      }
      // legge i debiti
      $debiti = $this->em->getRepository('App:VotoScrutinio')->createQueryBuilder('vs')
        ->join('vs.scrutinio', 's')
        ->join('vs.materia', 'm')
        ->where('s.classe=:classe AND s.periodo=:periodo AND vs.alunno=:alunno '.
          'AND m.tipo=:tipo AND vs.unico IS NOT NULL AND vs.unico < 6')
        ->setParameters(['classe' => $classe, 'periodo' => $periodo, 'alunno' => $alunno, 'tipo' => 'N'])
        ->getQuery()
        ->getResult();
      foreach ($debiti as $d) {
        // inserisce voti/debiti
        $dati['debiti'][$d->getMateria()->getId()] = array(
          'unico' => $d->getUnico(),
          'recupero' => $d->getRecupero(),
          'debito' => $d->getDebito());
      }
    } elseif ($periodo == 'F') {
      // scrutinio finale
      $dati['scrutinio'] = $this->em->getRepository('App:Scrutinio')->findOneBy(['classe' => $classe,
        'periodo' => $periodo, 'stato' => 'C']);
      $dati['classe'] = $classe;
      $dati['alunno'] = $alunno;
      // legge esito
      $dati['esito'] = $this->em->getRepository('App:Esito')->createQueryBuilder('e')
        ->where('e.alunno=:alunno AND e.scrutinio=:scrutinio')
        ->setParameters(['alunno' => $alunno, 'scrutinio' => $dati['scrutinio']])
        ->setMaxResults(1)
        ->getQuery()
        ->getOneOrNullResult();
      // legge materie
      $materie = $this->em->getRepository('App:Materia')->createQueryBuilder('m')
        ->select('DISTINCT m.id,m.nome,m.nomeBreve,m.tipo')
        ->join('App:Cattedra', 'c', 'WITH', 'c.materia=m.id')
        ->where('c.classe=:classe AND c.attiva=:attiva AND c.tipo=:tipo AND m.tipo!=:sostegno')
        ->orderBy('m.ordinamento', 'ASC')
        ->setParameters(['classe' => $classe, 'attiva' => 1, 'tipo' => 'N', 'sostegno' => 'S'])
        ->getQuery()
        ->getArrayResult();
      foreach ($materie as $mat) {
        $dati['materie'][$mat['id']] = $mat;
      }
      // legge i debiti
      $debiti = $this->em->getRepository('App:VotoScrutinio')->createQueryBuilder('vs')
        ->where('vs.scrutinio=:scrutinio AND vs.alunno=:alunno AND vs.unico<:suff')
        ->setParameters(['scrutinio' => $dati['scrutinio'], 'alunno' => $alunno, 'suff' => 6])
        ->getQuery()
        ->getResult();
      foreach ($debiti as $d) {
        // inserisce voti/debiti
        $dati['debiti'][$d->getMateria()->getId()] = array(
          'unico' => $d->getUnico(),
          'recupero' => $d->getRecupero(),
          'debito' => $d->getDebito());
      }
    }
    // restituisce dati
    return $dati;
  }

  /**
   * Crea il foglio dei debiti
   *
   * @param Classe $classe Classe dello scrutinio
   * @param Alunno $alunno Alunno selezionato
   * @param string $periodo Periodo dello scrutinio
   *
   * @return Percorso completo del file da inviare
   */
  public function debiti(Classe $classe, Alunno $alunno, $periodo) {
    // inizializza
    $fs = new Filesystem();
    if ($periodo == 'P') {
      // primo trimestre
      $percorso = $this->root.'/trimestre/'.$classe->getAnno().$classe->getSezione();
      if (!$fs->exists($percorso)) {
        // crea directory
        $fs->mkdir($percorso, 0775);
      }
      // nome documento
      $nomefile = $classe->getAnno().$classe->getSezione().'-primo-trimestre-debiti-'.$alunno->getId().'.pdf';
      if (!$fs->exists($percorso.'/'.$nomefile)) {
        // crea documento
        $this->pdf->configure($this->session->get('/CONFIG/ISTITUTO/intestazione'),
          'Scrutinio Primo Trimestre - Comunicazione debiti formativi - Alunno '.$alunno->getCognome().' '.$alunno->getNome());
        $dati = $this->debitiDati($classe, $alunno, $periodo);
        // crea il documento
        $nome_classe = $classe->getAnno().'ª '.$classe->getSezione();
        $nome_classe_lungo = $nome_classe.' '.$classe->getCorso()->getNomeBreve().' - '.$classe->getSede()->getCitta();
        $this->creaDebiti_P($this->pdf->getHandler(), $nome_classe, $nome_classe_lungo, $dati);
        // salva il documento
        $this->pdf->save($percorso.'/'.$nomefile);
      }
      // restituisce nome del file
      return $percorso.'/'.$nomefile;
    } elseif ($periodo == 'F') {
      // scrutinio finale
      $percorso = $this->root.'/finale/'.$classe->getAnno().$classe->getSezione();
      if (!$fs->exists($percorso)) {
        // crea directory
        $fs->mkdir($percorso, 0775);
      }
      // nome documento
      $nomefile = $classe->getAnno().$classe->getSezione().'-scrutinio-finale-debiti-'.$alunno->getId().'.pdf';
      if (!$fs->exists($percorso.'/'.$nomefile)) {
        // crea documento
        $this->pdf->configure($this->session->get('/CONFIG/ISTITUTO/intestazione'),
          'Scrutinio Finale - Comunicazione debiti formativi - Alunno '.$alunno->getCognome().' '.$alunno->getNome());
        $dati = $this->debitiDati($classe, $alunno, $periodo);
        $nome_classe = $classe->getAnno().'ª '.$classe->getSezione();
        $nome_classe_lungo = $nome_classe.' '.$classe->getCorso()->getNomeBreve().' - '.$classe->getSede()->getCitta();
        // controllo alunno
        $scrut = ($dati['scrutinio']->getDato('scrutinabili') == null ? [] :
          array_keys($dati['scrutinio']->getDato('scrutinabili')));
        if (in_array($alunno->getId(), $scrut) && $dati['esito'] && $dati['esito']->getEsito() == 'S') {
          // alunno sospeso
          $this->creaDebiti_F($this->pdf->getHandler(), $nome_classe, $nome_classe_lungo, $dati);
        } else {
          // errore
          return null;
        }
        // salva il documento
        $this->pdf->save($percorso.'/'.$nomefile);
      }
      // restituisce nome del file
      return $percorso.'/'.$nomefile;
    }
    // errore
    return null;
  }

  /**
   * Crea il foglio dei debiti come documento PDF
   *
   * @param TCPDF $pdf Gestore del documento PDF
   * @param string $classe Nome della classe
   * @param string $classe_completa Nome della classe con corso e sede
   * @param array $dati Dati dello scrutinio
   */
  public function creaDebiti_P($pdf, $classe, $classe_completa, $dati) {
    // set margins
    $pdf->SetMargins(10, 15, 10, true);
    // set auto page breaks
    $pdf->SetAutoPageBreak(true, 15);
    // set font
    $pdf->SetFont('times', '', 12);
    // inizio pagina
    $pdf->setPrintHeader(false);
    $pdf->SetFooterMargin(15);
    $pdf->setFooterFont(Array('helvetica', '', 9));
    $pdf->setFooterData(array(0,0,0), array(255,255,255));
    $pdf->setPrintFooter(true);
    $pdf->AddPage('P');
    // logo
    $html = '<img src="/img/'.getenv('LOCAL_PATH').'intestazione-documenti.jpg" width="540">';
    $pdf->writeHTML($html, true, false, false, false, 'C');
    // intestazione
    $alunno = $dati['alunno']->getCognome().' '.$dati['alunno']->getNome();
    $alunno_sesso = $dati['alunno']->getSesso();
    $pdf->Ln(10);
    $pdf->SetFont('times', 'I', 12);
    $text = 'Ai genitori dell\'alunn'.($alunno_sesso == 'M' ? 'o' : 'a');
    $this->cella($pdf, 0, 0, 100, 0, $text, 0, 'L', 'T');
    $pdf->Ln();
    $pdf->SetFont('times', '', 12);
    $text = strtoupper($alunno);
    $this->cella($pdf, 0, 0, 100, 0, $text, 0, 'L', 'T');
    $pdf->Ln();
    $text = 'Classe '.$classe;
    $this->cella($pdf, 0, 0, 100, 0, $text, 0, 'L', 'T');
    $pdf->Ln(15);
    // oggetto
    $pdf->SetFont('times', 'B', 12);
    $as = $this->session->get('/CONFIG/SCUOLA/anno_scolastico');
    $text = 'OGGETTO: Scrutinio del primo trimestre A.S. '.$as.' - Indicazioni per il recupero';
    $this->cella($pdf, 0, 0, 0, 0, $text, 0, 'L', 'T');
    $pdf->Ln(15);
    // contenuto
    $pdf->SetFont('times', '', 12);
    $sex = ($alunno_sesso == 'M' ? 'o' : 'a');
    $html = '<p align="justify">Il Consiglio di Classe, nella seduta dello scrutinio del primo trimestre dell’anno scolastico '.$as.
            ', tenutasi il giorno '.$dati['scrutinio']->getData()->format('d/m/Y').
            ', ha rilevato la presenza di una o più insufficienze. La tabella seguente illustra le modalità e gli argomenti per il recupero:</p>';
    $pdf->writeHTML($html, true, false, false, true);
    $pdf->Ln(2);
    $html = '<table border="1" cellpadding="3">';
    $html .= '<tr><td width="30%"><strong>MATERIA</strong></td><td width="7%"><strong>VOTO</strong></td><td width="50%"><strong>Argomenti da recuperare</strong></td><td width="13%"><strong>Modalità di recupero</strong></td></tr>';
    foreach ($dati['materie'] as $idmateria=>$mat) {
      if (isset($dati['debiti'][$idmateria]['unico'])) {
        $html .= '<tr><td align="left"><strong>'.$mat['nome'].'</strong></td><td>';
        if ($dati['debiti'][$idmateria]['unico'] == 0) {
          $html .= 'NC';
        } else {
          $html .= $dati['debiti'][$idmateria]['unico'];
        }
        $html .= '</td><td align="left" style="font-size:9pt">'.nl2br(htmlentities($dati['debiti'][$idmateria]['debito'])).'</td><td>';
        if ($dati['debiti'][$idmateria]['recupero'] == 'A') {
          $html .= 'Recupero autonomo';
        } elseif ($dati['debiti'][$idmateria]['recupero'] == 'S') {
          $html .= 'Sportello didattico';
        } elseif ($dati['debiti'][$idmateria]['recupero'] == 'C') {
          $html .= 'Corso di recupero';
        }
        $html .= '</td></tr>';
      }
    }
    $html .= '</table><br>';
    $pdf->SetFont('helvetica', '', 10);
    $pdf->writeHTML($html, true, false, false, true, 'C');
    // altre comunicazioni
    $pdf->SetFont('times', '', 12);
    $html = '<p align="justify">Qualora le famiglie non intendano far frequentare ai propri figli i corsi sopra indicati, dovranno dichiarare che provvederanno personalmente agli interventi di recupero, sollevando l\'Istituto da ogni responsabilità in merito.</p>';
    $pdf->writeHTMLCell(0, 0, $pdf->GetX(), $pdf->GetY(), $html, 0, 1);
    $html = '<p align="justify">In ogni caso gli studenti saranno chiamati a sottoporsi alle prove di verifica del superamento del debito formativo per quanto si riferisce a quelli comunicati con la presente nota.</p>';
    $pdf->writeHTMLCell(0, 0, $pdf->GetX(), $pdf->GetY()+2, $html, 0, 1);
    $html = '<p align="justify">Si ribadisce che, ai sensi della normativa vigente, al termine del corrente anno scolastico non sarà consentita l\'ammissione alla classe successiva nel caso persista il debito formativo sopra evidenziato.<br></p>';
    $pdf->writeHTMLCell(0, 0, $pdf->GetX(), $pdf->GetY()+2, $html, 0, 1);
    // firma
    $pdf->SetFont('times', '', 12);
    $html = '<p>Distinti Saluti.<br></p>';
    $pdf->writeHTMLCell(0, 0, $pdf->GetX(), $pdf->GetY(), $html, 0, 1);
    $html = 'Cagliari, '.$dati['scrutinio']->getData()->format('d/m/Y').'.';
    $pdf->writeHTMLCell(100, 0, $pdf->GetX(), $pdf->GetY(), $html, 0, 0);
    $preside = $this->session->get('/CONFIG/ISTITUTO/firma_preside');
    $html = 'Il Dirigente Scolastico<br><em>'.$preside.'</em>';
    $pdf->writeHTMLCell(100, 0, $pdf->GetX(), $pdf->GetY(), $html, 0, 1, false, true, 'C');
  }

  /**
   * Controlla se l'alunno era nella classe per lo scrutinio indicato
   *
   * @param Classe $classe Classe scolastica
   * @param int $alunno ID alunno da controllare
   * @param string $periodo Periodo relativo allo scrutinio
   *
   * @return Alunno|null Restituisce l'alunno se risulta nello scrutinio del periodo, null altrimenti
   */
  public function alunnoInScrutinio(Classe $classe, $alunno, $periodo) {
    $trovato = null;
    // legge scrutinio
    $scrutinio = $this->em->getRepository('App:Scrutinio')->findOneBy(['periodo' => $periodo, 'classe' => $classe]);
    if ($periodo == 'P' || $periodo == '1') {
      // solo gli alunni al momento dello scrutinio
      if (in_array($alunno, $scrutinio->getDato('alunni'))) {
        // alunno trovato
        $trovato = $this->em->getRepository('App:Alunno')->find($alunno);
      }
    } elseif ($periodo == 'F') {
      // controlla se alunno scrutinato
      $scrut = ($scrutinio->getDato('scrutinabili') == null ? [] :
        array_keys($scrutinio->getDato('scrutinabili')));
      if (in_array($alunno, $scrut)) {
        // alunno scrutinato
        return $this->em->getRepository('App:Alunno')->find($alunno);
      }
      // controlla se alunno all'estero
      $estero = $this->em->getRepository('App:Alunno')->createQueryBuilder('a')
        ->join('App:CambioClasse', 'cc', 'WITH', 'cc.alunno=a.id')
        ->where('a.id IN (:lista) AND a.id=:alunno AND cc.classe=:classe AND a.frequenzaEstero=:estero')
        ->setParameters(['lista' => ($scrutinio->getDato('ritirati') == null ? [] : $scrutinio->getDato('ritirati')),
          'alunno' => $alunno, 'classe' => $classe, 'estero' => 1])
        ->setMaxResults(1)
        ->getQuery()
        ->getOneOrNullResult();
      if ($estero) {
        // alunno all'estero
        return $estero;
      }
      // controlla se non scrutinabile per assenze
      $no_scrut = ($scrutinio->getDato('no_scrutinabili') == null ? [] : $scrutinio->getDato('no_scrutinabili'));
      if (isset($no_scrut[$alunno]) && !isset($no_scrut[$alunno]['deroga'])) {
        // alunno non scrutinabile per assenze
        return $this->em->getRepository('App:Alunno')->find($alunno);
      }
      // controlla se non scrutinato per cessata frequenza
      $freq = ($scrutinio->getDato('cessata_frequenza') == null ? [] : $scrutinio->getDato('cessata_frequenza'));
      if (in_array($alunno, $freq)) {
        // alunno non scrutinato per cessata frequenza
        return $this->em->getRepository('App:Alunno')->find($alunno);
      }
      // alunno non trovato: errore
      return null;
    } elseif ($periodo == 'I') {
      // scrutinio integrativo
      if (in_array($alunno, $scrutinio->getDato('sospesi'))) {
        // alunno trovato
        $trovato = $this->em->getRepository('App:Alunno')->find($alunno);
      }
    } elseif ($periodo == 'X') {
      // scrutinio integrativo
      if (in_array($alunno, $scrutinio->getDato('rinviati'))) {
        // alunno trovato
        $trovato = $this->em->getRepository('App:Alunno')->find($alunno);
      }
    }
    // restituisce alunno
    return $trovato;
  }


  //==================== FUNZIONI PRIVATE  ====================

  // scrive cella
  private function cella($pdf, $width, $height, $relx, $rely, $text, $border, $align, $valign) {
    $pdf->MultiCell($width, $height, $text, $border, $align, false, 0, $pdf->GetX()+$relx, $pdf->GetY()+$rely, true, 0, false, true, $height, $valign, 1);
  }

  // controlla se c'è spazio per la prossima cella/riga dell'altezza data
  // altrimenti crea nuova pagina
  private function acapo($pdf, $height, $nextheight=0, $etichette=array(), $dim=array(6, 35, 12, true)) {
    $pdf->Ln($height);
    if ($nextheight > 0) {
      $margin = $pdf->getMargins();
      $space = $pdf->getPageHeight() - $pdf->GetY() - $margin['bottom'];
      if ($nextheight > $space) {
        $pdf->AddPage('P');
        $pdf->Ln(5);
        // intestazione tabella
        if (count($etichette) > 0) {
          $fn_name = $pdf->getFontFamily();
          $fn_style = $pdf->getFontStyle();
          $fn_size = $pdf->getFontSizePt();
          $pdf->SetFont('helvetica', 'B', 8);
          $this->cella($pdf, $dim[0], 30, 0, 0, 'Pr.', 1, 'C', 'B');
          $this->cella($pdf, $dim[1], 30, 0, 0, 'Alunno', 1, 'C', 'B');
          $pdf->SetX($pdf->GetX() - 6);
          $pdf->StartTransform();
          $pdf->Rotate(90);
          $last_width = 6;
          foreach ($etichette as $et) {
            $this->cella($pdf, 30, $et['dim'], -30, $last_width, $et['nome'], 1, 'L', 'M');
            $last_width = $et['dim'];
          }
          $pdf->StopTransform();
          $this->cella($pdf, $dim[2], 30, (count($etichette)+2)*6, -(count($etichette)+1)*6, 'Media', 1, 'C', 'B');
          if ($dim[3]) {
            $this->cella($pdf, 0, 30, 0, 0, 'Esito', 1, 'C', 'B');
          }
          $pdf->Ln(30);
          $pdf->SetFont($fn_name, $fn_style, $fn_size);
        }
      }
    }
  }

  /**
   * Crea il riepilogo dei voti come documento PDF
   *
   * @param TCPDF $pdf Gestore del documento PDF
   * @param string $classe Nome della classe
   * @param string $classe_completa Nome della classe con corso e sede
   * @param array $dati Dati dello scrutinio
   */
  public function creaRiepilogoVoti_F($pdf, $classe, $classe_completa, $dati) {
    $info_voti['N'] = [0 => 'NC', 1 => '1', 2 => '2', 3 => '3', 4 => '4', 5 => '5', 6 => '6', 7 => '7', 8 => '8', 9 => '9', 10 => '10'];
    $info_voti['C'] = [4 => 'NC', 5 => '5', 6 => '6', 7 => '7', 8 => '8', 9 => '9', 10 => '10'];
    $info_voti['R'] = [20 => 'NC', 21 => 'Insuff.', 22 => 'Suff.', 23 => 'Discr.', 24 => 'Buono', 25 => 'Dist.', 26 => 'Ottimo'];
    // set margins
    $pdf->SetMargins(10, 15, 10, true);
    // set auto page breaks
    $pdf->SetAutoPageBreak(false, 15);
    // set font
    $pdf->SetFont('helvetica', '', 10);
    // inizio pagina
    $pdf->SetHeaderMargin(12);
    $pdf->SetFooterMargin(12);
    $pdf->setHeaderFont(Array('helvetica', 'B', 6));
    $pdf->setFooterFont(Array('helvetica', '', 8));
    $pdf->setHeaderData('', 0, $this->session->get('/CONFIG/ISTITUTO/intestazione')." - CAGLIARI - ASSEMINI     ***     RIEPILOGO VOTI ".$classe, '', array(0,0,0), array(255,255,255));
    $pdf->setFooterData(array(0,0,0), array(255,255,255));
    $pdf->setPrintHeader(true);
    $pdf->setPrintFooter(true);
    $pdf->AddPage('P');
    // intestazione pagina
    $pdf->SetFont('helvetica', 'B', 10);
    $this->cella($pdf, 15, 5, 0, 2, 'Classe:', 0, 'C', 'B');
    $pdf->SetFont('helvetica', '', 10);
    $this->cella($pdf, 85, 5, 0, 0, $classe_completa, 0, 'L', 'B');
    $pdf->SetFont('helvetica', 'B', 10);
    $this->cella($pdf, 31, 5, 0, 0, 'Anno Scolastico:', 0, 'C', 'B');
    $pdf->SetFont('helvetica', '', 10);
    $as = $this->session->get('/CONFIG/SCUOLA/anno_scolastico');
    $this->cella($pdf, 20, 5, 0, 0, $as, 0, 'L', 'B');
    $pdf->SetFont('helvetica', '', 10);
    $this->cella($pdf, 0, 5, 0, 0, 'SCRUTINIO FINALE', 0, 'R', 'B');
    $this->acapo($pdf, 5);
    // intestazione tabella
    $pdf->SetFont('helvetica', 'B', 8);
    $this->cella($pdf, 6, 30, 0, 0, 'Pr.', 1, 'C', 'B');
    $this->cella($pdf, 35, 30, 0, 0, 'Alunno', 1, 'C', 'B');
    $pdf->SetX($pdf->GetX() - 6); // aggiusta prima posizione
    $pdf->StartTransform();
    $pdf->Rotate(90);
    $numrot = 1;
    $etichetterot = array();
    $last_width = 6;
    foreach ($dati['materie'] as $idmateria=>$mat) {
      $text = strtoupper($mat['nomeBreve']);
      if ($mat['tipo'] != 'R') {
        $etichetterot[] = array('nome' => $text, 'dim' => 6);
        $this->cella($pdf, 30, 6, -30, $last_width, $text, 1, 'L', 'M');
        $last_width = 6;
      } else {
        $etichetterot[] = array('nome' => $text, 'dim' => 12);
        $this->cella($pdf, 30, 12, -30, $last_width, $text, 1, 'L', 'M');
        $last_width = 12;
      }
      $numrot++;
    }
    if ($dati['classe']->getAnno() >= 3) {
      // credito
      $etichetterot[] = array('nome' => 'Credito', 'dim' => 6);
      $this->cella($pdf, 30, 6, -30, 6, 'Credito', 1, 'L', 'M');
      $numrot++;
      if ($dati['classe']->getAnno() >= 4) {
        $etichetterot[] = array('nome' => 'Credito Anni Prec.', 'dim' => 6);
        $this->cella($pdf, 30, 6, -30, 6, 'Credito Anni Prec.', 1, 'L', 'M');
        $numrot++;
        $etichetterot[] = array('nome' => 'Totale Credito', 'dim' => 6);
        $this->cella($pdf, 30, 6, -30, 6, 'Totale Credito', 1, 'L', 'M');
        $numrot++;
      }
    }
    $pdf->StopTransform();
    $this->cella($pdf, 12, 30, $numrot*6+6, -$numrot*6, 'Media', 1, 'C', 'B');
    $this->cella($pdf, 0, 30, 0, 0, 'Esito', 1, 'C', 'B');
    $this->acapo($pdf, 30);
    // dati alunni
    $pdf->SetFont('helvetica', '', 8);
    $numalunni = 0;
    $next_height = 26;
    end($dati['alunni']);
    $ultimo_idalu = key($dati['alunni']);
    foreach ($dati['alunni'] as $idalunno=>$alu) {
      if ($idalunno == $ultimo_idalu) {
        // ultima riga
        $next_height = 0;
      }
      // nuovo alunno
      $numalunni++;
      $this->cella($pdf, 6, 11, 0, 0, $numalunni, 1, 'C', 'T');
      $nomealunno = strtoupper($alu['cognome'].' '.$alu['nome']);
      $sessoalunno = ($alu['sesso'] == 'M' ? 'o' : 'a');
      $dataalunno = $alu['dataNascita']->format('d/m/Y');
      $this->cella($pdf, 35, 8, 0, 0, $nomealunno, 0, 'L', 'T');
      $this->cella($pdf, 35, 11, -35, 0, $dataalunno, 1, 'L', 'B');
      $this->cella($pdf, 35, 11, -35, 0, 'Assenze ->', 1, 'R', 'B');
      $pdf->SetXY($pdf->GetX(), $pdf->GetY() + 5.50);
      if (in_array($idalunno, $dati['estero'])) {
        // frequenta all'estero
        $width = (count($dati['materie']) + 1) * 6 + 12;
        if ($dati['classe']->getAnno() == 3) {
          $width += 6;
        } elseif ($dati['classe']->getAnno() >= 4) {
          $width += 3 * 6;
        }
        $this->cella($pdf, $width, 11, 0, -5.50, '', 1, 'C', 'M');
        $esito = 'Anno all\'estero';
        $this->cella($pdf, 0, 11, 0, 0, $esito, 1, 'C', 'M');
        // nuova riga
        $this->acapo($pdf, 11, $next_height, $etichetterot);
      } elseif (in_array($idalunno, $dati['cessata_frequenza'])) {
        // non scrutinato per cessata frequenza
        $width = (count($dati['materie']) + 1) * 6 + 12;
        if ($dati['classe']->getAnno() == 3) {
          $width += 6;
        } elseif ($dati['classe']->getAnno() >= 4) {
          $width += 3 * 6;
        }
        $this->cella($pdf, $width, 11, 0, -5.50, '', 1, 'C', 'M');
        $esito = 'Non Scrutinat'.$sessoalunno;
        $this->cella($pdf, 0, 11, 0, 0, $esito, 1, 'C', 'M');
        // nuova riga
        $this->acapo($pdf, 11, $next_height, $etichetterot);
      } elseif (in_array($idalunno, $dati['no_scrutinabili'])) {
        // non scrutinabile per limite assenze
        $pdf->SetTextColor(0,0,0);
        foreach ($dati['materie'] as $idmateria=>$mat) {
          if ($mat['tipo'] == 'R') {
            if ($alu['religione'] != 'S') {
              // N.A.
              $assenze = '';
            } else {
              // si avvale
              $assenze = $dati['voti'][$idalunno][$idmateria]['assenze'];
            }
            $this->cella($pdf, 12, 5.50, 0, -5.50, '', 1, 'C', 'M');
            $this->cella($pdf, 12, 5.50, -12, 5.50, $assenze, 1, 'C', 'M');
          } elseif ($mat['tipo'] != 'C') {
            // voto numerico (no condotta)
            $this->cella($pdf, 6, 5.50, 0, -5.50, '', 1, 'C', 'M');
            $this->cella($pdf, 6, 5.50, -6, 5.50, $dati['voti'][$idalunno][$idmateria]['assenze'], 1, 'C', 'M');
          }
        }
        // condotta
        $this->cella($pdf, 6, 5.50, 0, -5.50, '', 1, 'C', 'M');
        $this->cella($pdf, 6, 5.50, -6, 5.50, '', 1, 'C', 'M');
        if ($dati['classe']->getAnno() >= 3) {
          // credito
          $this->cella($pdf, 6, 5.50, 0, -5.50, '', 1, 'C', 'M');
          $this->cella($pdf, 6, 5.50, -6, 5.50, '', 1, 'C', 'M');
          if ($dati['classe']->getAnno() >= 4) {
            $this->cella($pdf, 6, 5.50, 0, -5.50, '', 1, 'C', 'M');
            $this->cella($pdf, 6, 5.50, -6, 5.50, '', 1, 'C', 'M');
            $this->cella($pdf, 6, 5.50, 0, -5.50, '', 1, 'C', 'M');
            $this->cella($pdf, 6, 5.50, -6, 5.50, '', 1, 'C', 'M');
          }
        }
        // media
        $this->cella($pdf, 12, 5.50, 0, -5.50, '', 1, 'C', 'M');
        $this->cella($pdf, 12, 5.50, -12, 5.50, '', 1, 'C', 'M');
        // esito
        $esito = "Esclus$sessoalunno dallo scrutinio finale e non ammess$sessoalunno all'".
          ($dati['classe']->getAnno() == 5 ? 'Esame di Stato' : 'anno successivo').
          ' (DPR 122/09 art. 14 comma 7)';
        $this->cella($pdf, 0, 11, 0, -5.50, $esito, 1, 'C', 'M');
        // nuova riga
        $this->acapo($pdf, 11, $next_height, $etichetterot);
      } elseif (in_array($idalunno, $dati['scrutinati'])) {
        // scrutinati
        $voti_somma = 0;
        $voti_num = 0;
        foreach ($dati['materie'] as $idmateria=>$mat) {
          $pdf->SetTextColor(0,0,0);
          $voto = '';
          $assenze = '';
          $width = 6;
          if ($mat['tipo'] == 'R') {
            // religione
            $width = 12;
            if ($alu['religione'] != 'S') {
              // N.A.
              $voto = '///';
            } else {
              $voto = $info_voti['R'][$dati['voti'][$idalunno][$idmateria]['unico']];
              $assenze = $dati['voti'][$idalunno][$idmateria]['assenze'];
              if ($dati['voti'][$idalunno][$idmateria]['unico'] < 22) {
                // insuff.
                $pdf->SetTextColor(255,0,0);
              }
            }
          } elseif ($mat['tipo'] == 'C') {
            // condotta
            $voto = $info_voti['C'][$dati['voti'][$idalunno][$idmateria]['unico']];
            if ($dati['voti'][$idalunno][$idmateria]['unico'] < 6) {
              // insuff.
              $pdf->SetTextColor(255,0,0);
            }
            $voti_somma += ($dati['voti'][$idalunno][$idmateria]['unico'] > 4 ? $dati['voti'][$idalunno][$idmateria]['unico'] : 0);
            $voti_num++;
          } elseif ($mat['tipo'] == 'N') {
            $voto = $info_voti['N'][$dati['voti'][$idalunno][$idmateria]['unico']];
            $assenze = $dati['voti'][$idalunno][$idmateria]['assenze'];
            if ($dati['voti'][$idalunno][$idmateria]['unico'] < 6) {
              // insuff.
              $pdf->SetTextColor(255,0,0);
            }
            $voti_somma += $dati['voti'][$idalunno][$idmateria]['unico'];
            $voti_num++;
          }
          // scrive voto/assenze
          $this->cella($pdf, $width, 5.50, 0, -5.50, $voto, 1, 'C', 'M');
          $pdf->SetTextColor(0,0,0);
          $this->cella($pdf, $width, 5.50, -$width, 5.50, $assenze, 1, 'C', 'M');
        }
        if ($dati['classe']->getAnno() >= 3) {
          // credito
          if ($dati['esiti'][$idalunno]->getEsito() == 'A') {
            // ammessi
            $credito = $dati['esiti'][$idalunno]->getCredito();
            $creditoprec = ($dati['classe']->getAnno() == 5 ?
              ($dati['esiti'][$idalunno]->getDati()['creditoConvertito3'] + $dati['esiti'][$idalunno]->getDati()['creditoConvertito4']) :
              $dati['esiti'][$idalunno]->getCreditoPrecedente());
            $creditotot = $credito + $creditoprec;
          } else {
            // non ammessi o sospesi
            $credito = '';
            $creditoprec = '';
            $creditotot = '';
          }
          $this->cella($pdf, 6, 5.50, 0, -5.50, $credito, 1, 'C', 'M');
          $this->cella($pdf, 6, 5.50, -6, 5.50, '', 1, 'C', 'M');
          if ($dati['classe']->getAnno() >= 4) {
            $this->cella($pdf, 6, 5.50, 0, -5.50, $creditoprec, 1, 'C', 'M');
            $this->cella($pdf, 6, 5.50, -6, 5.50, '', 1, 'C', 'M');
            $this->cella($pdf, 6, 5.50, 0, -5.50, $creditotot, 1, 'C', 'M');
            $this->cella($pdf, 6, 5.50, -6, 5.50, '', 1, 'C', 'M');
          }
        }
        // media
        $media = number_format($voti_somma / $voti_num, 2, ',', '');
        $this->cella($pdf, 12, 5.50, 0, -5.50, $media, 1, 'C', 'M');
        $this->cella($pdf, 12, 5.50, -12, 5.50, '', 1, 'C', 'M');
        // esito
        switch ($dati['esiti'][$idalunno]->getEsito()) {
          case 'A':
            // ammesso
            $esito = 'Ammess'.$sessoalunno;
            break;
          case 'N':
            // non ammesso
            $esito = 'Non Ammess'.$sessoalunno;
            break;
          case 'S':
            // sospeso
            $esito = 'Sospensione del giudizio';
            break;
        }
        $this->cella($pdf, 0, 11, 0, -5.50, $esito, 1, 'C', 'M');
        // nuova riga
        $this->acapo($pdf, 11, $next_height, $etichetterot);
      }
    }
    // data e firma
    $datascrutinio = $dati['scrutinio']->getData()->format('d/m/Y');
    $pdf->SetFont('helvetica', '', 12);
    $this->cella($pdf, 30, 15, 0, 0, 'Data', 0, 'R', 'B');
    $this->cella($pdf, 30, 15, 0, 0, $datascrutinio, 'B', 'C', 'B');
    $pdf->SetXY(-80, $pdf->GetY());
    $preside = $this->session->get('/CONFIG/ISTITUTO/firma_preside');
    $text = '(Il Dirigente Scolastico)'."\n".$preside;
    $this->cella($pdf, 60, 15, 0, 0, $text, 'B', 'C', 'B');
  }

  /**
   * Crea il tabellone dei voti
   *
   * @param Classe $classe Classe dello scrutinio
   * @param string $periodo Periodo dello scrutinio
   *
   * @return Percorso completo del file da inviare
   */
  public function tabelloneVoti(Classe $classe, $periodo) {
    // inizializza
    $fs = new Filesystem();
    if ($periodo == 'F') {
      // scrutinio finale
      $percorso = $this->root.'/finale/'.$classe->getAnno().$classe->getSezione();
      if (!$fs->exists($percorso)) {
        // crea directory
        $fs->mkdir($percorso, 0775);
      }
      // nome documento
      $nomefile = $classe->getAnno().$classe->getSezione().'-scrutinio-finale-tabellone-voti.pdf';
      if (!$fs->exists($percorso.'/'.$nomefile)) {
        // crea pdf
        $this->pdf->configure($this->session->get('/CONFIG/ISTITUTO/intestazione'),
          'Scrutinio Finale - Tabellone voti - Classe '.$classe->getAnno().'ª '.$classe->getSezione());
        $dati = $this->riepilogoVotiDati($classe, $periodo);
        // crea il documento
        $nome_classe = $classe->getAnno().'ª '.$classe->getSezione();
        $nome_classe_lungo = $nome_classe.' '.$classe->getCorso()->getNomeBreve().' - '.$classe->getSede()->getCitta();
        $this->creaTabelloneVoti_F($this->pdf->getHandler(), $nome_classe, $nome_classe_lungo, $dati);
        // salva il documento
        $this->pdf->save($percorso.'/'.$nomefile);
      }
      // restituisce nome del file
      return $percorso.'/'.$nomefile;
    } elseif ($periodo == 'I') {
      // scrutinio integrativo
      $percorso = $this->root.'/integrativo/'.$classe->getAnno().$classe->getSezione();
      if (!$fs->exists($percorso)) {
        // crea directory
        $fs->mkdir($percorso, 0775);
      }
      // nome documento
      $nomefile = $classe->getAnno().$classe->getSezione().'-scrutinio-integrativo-tabellone-voti.pdf';
      if (!$fs->exists($percorso.'/'.$nomefile)) {
        // crea pdf
        $this->pdf->configure($this->session->get('/CONFIG/ISTITUTO/intestazione'),
          'Scrutinio Integrativo - Tabellone voti - Classe '.$classe->getAnno().'ª '.$classe->getSezione());
        $dati = $this->riepilogoVotiDati($classe, $periodo);
        // crea il documento
        $nome_classe = $classe->getAnno().'ª '.$classe->getSezione();
        $nome_classe_lungo = $nome_classe.' '.$classe->getCorso()->getNomeBreve().' - '.$classe->getSede()->getCitta();
        $this->creaTabelloneVoti_I($this->pdf->getHandler(), $nome_classe, $nome_classe_lungo, $dati);
        // salva il documento
        $this->pdf->save($percorso.'/'.$nomefile);
      }
      // restituisce nome del file
      return $percorso.'/'.$nomefile;
    } elseif ($periodo == 'X') {
      // scrutinio integrativo
      $percorso = $this->root.'/rinviato/'.$classe->getAnno().$classe->getSezione();
      if (!$fs->exists($percorso)) {
        // crea directory
        $fs->mkdir($percorso, 0775);
      }
      // nome documento
      $nomefile = $classe->getAnno().$classe->getSezione().'-scrutinio-rinviato-tabellone-voti.pdf';
      if (!$fs->exists($percorso.'/'.$nomefile)) {
        // crea pdf
        $this->pdf->configure($this->session->get('/CONFIG/ISTITUTO/intestazione'),
          'Scrutinio Integrativo - Tabellone voti - Classe '.$classe->getAnno().'ª '.$classe->getSezione());
        $dati = $this->riepilogoVotiDati($classe, $periodo);
        // crea il documento
        $nome_classe = $classe->getAnno().'ª '.$classe->getSezione();
        $nome_classe_lungo = $nome_classe.' '.$classe->getCorso()->getNomeBreve().' - '.$classe->getSede()->getCitta();
        $this->creaTabelloneVoti_I($this->pdf->getHandler(), $nome_classe, $nome_classe_lungo, $dati);
        // salva il documento
        $this->pdf->save($percorso.'/'.$nomefile);
      }
      // restituisce nome del file
      return $percorso.'/'.$nomefile;
    }
    // errore
    return null;
  }

  /**
   * Crea il tabellone dei voti come documento PDF
   *
   * @param TCPDF $pdf Gestore del documento PDF
   * @param string $classe Nome della classe
   * @param string $classe_completa Nome della classe con corso e sede
   * @param array $dati Dati dello scrutinio
   */
  public function creaTabelloneVoti_F($pdf, $classe, $classe_completa, $dati) {
    $info_voti['N'] = [0 => 'NC', 1 => '1', 2 => '2', 3 => '3', 4 => '4', 5 => '5', 6 => '6', 7 => '7', 8 => '8', 9 => '9', 10 => '10'];
    $info_voti['C'] = [4 => 'NC', 5 => '5', 6 => '6', 7 => '7', 8 => '8', 9 => '9', 10 => '10'];
    $info_voti['R'] = [20 => 'NC', 21 => 'Insuff.', 22 => 'Suff.', 23 => 'Discr.', 24 => 'Buono', 25 => 'Dist.', 26 => 'Ottimo'];
    // set margins
    $pdf->SetMargins(10, 15, 10, true);
    // set auto page breaks
    $pdf->SetAutoPageBreak(false, 15);
    // set font
    $pdf->SetFont('helvetica', '', 10);
    // inizio pagina
    $pdf->SetHeaderMargin(12);
    $pdf->SetFooterMargin(12);
    $pdf->setHeaderFont(Array('helvetica', 'B', 6));
    $pdf->setFooterFont(Array('helvetica', '', 8));
    $pdf->setHeaderData('', 0, $this->session->get('/CONFIG/ISTITUTO/intestazione').' - CAGLIARI - ASSEMINI     ***     TABELLONE VOTI '.$classe, '', array(0,0,0), array(255,255,255));
    $pdf->setFooterData(array(0,0,0), array(255,255,255));
    $pdf->setPrintHeader(true);
    $pdf->setPrintFooter(true);
    $pdf->AddPage('P');
    // intestazione pagina
    $pdf->SetFont('helvetica', 'B', 10);
    $this->cella($pdf, 15, 5, 0, 2, 'Classe:', 0, 'C', 'B');
    $pdf->SetFont('helvetica', '', 10);
    $this->cella($pdf, 85, 5, 0, 0, $classe_completa, 0, 'L', 'B');
    $pdf->SetFont('helvetica', 'B', 10);
    $this->cella($pdf, 31, 5, 0, 0, 'Anno Scolastico:', 0, 'C', 'B');
    $pdf->SetFont('helvetica', '', 10);
    $as = $this->session->get('/CONFIG/SCUOLA/anno_scolastico');
    $this->cella($pdf, 20, 5, 0, 0, $as, 0, 'L', 'B');
    $pdf->SetFont('helvetica', '', 10);
    $this->cella($pdf, 0, 5, 0, 0, 'SCRUTINIO FINALE', 0, 'R', 'B');
    $this->acapo($pdf, 5);
    // intestazione tabella
    $pdf->SetFont('helvetica', 'B', 8);
    $this->cella($pdf, 6, 30, 0, 0, 'Pr.', 1, 'C', 'B');
    $this->cella($pdf, 35, 30, 0, 0, 'Alunno', 1, 'C', 'B');
    $pdf->SetX($pdf->GetX() - 6); // aggiusta prima posizione
    $pdf->StartTransform();
    $pdf->Rotate(90);
    $numrot = 1;
    $etichetterot = array();
    $last_width = 6;
    foreach ($dati['materie'] as $idmateria=>$mat) {
      $text = strtoupper($mat['nomeBreve']);
      if ($mat['tipo'] != 'R') {
        $etichetterot[] = array('nome' => $text, 'dim' => 6);
        $this->cella($pdf, 30, 6, -30, $last_width, $text, 1, 'L', 'M');
        $last_width = 6;
      } else {
        $etichetterot[] = array('nome' => $text, 'dim' => 12);
        $this->cella($pdf, 30, 12, -30, $last_width, $text, 1, 'L', 'M');
        $last_width = 12;
      }
      $numrot++;
    }
    if ($dati['classe']->getAnno() >= 3) {
      // credito
      $etichetterot[] = array('nome' => 'Credito', 'dim' => 6);
      $this->cella($pdf, 30, 6, -30, 6, 'Credito', 1, 'L', 'M');
      $numrot++;
      if ($dati['classe']->getAnno() >= 4) {
        $etichetterot[] = array('nome' => 'Credito Anni Prec.', 'dim' => 6);
        $this->cella($pdf, 30, 6, -30, 6, 'Credito Anni Prec.', 1, 'L', 'M');
        $numrot++;
        $etichetterot[] = array('nome' => 'Totale Credito', 'dim' => 6);
        $this->cella($pdf, 30, 6, -30, 6, 'Totale Credito', 1, 'L', 'M');
        $numrot++;
      }
    }
    $pdf->StopTransform();
    $this->cella($pdf, 12, 30, $numrot*6+6, -$numrot*6, 'Media', 1, 'C', 'B');
    $this->cella($pdf, 0, 30, 0, 0, 'Esito', 1, 'C', 'B');
    $this->acapo($pdf, 30);
    // dati alunni
    $pdf->SetFont('helvetica', '', 8);
    $numalunni = 0;
    $next_height = 26;
    end($dati['alunni']);
    $ultimo_idalu = key($dati['alunni']);
    foreach ($dati['alunni'] as $idalunno=>$alu) {
      if ($idalunno == $ultimo_idalu) {
        // ultima riga
        $next_height = 0;
      }
      // nuovo alunno
      $numalunni++;
      $this->cella($pdf, 6, 11, 0, 0, $numalunni, 1, 'C', 'T');
      $nomealunno = strtoupper($alu['cognome'].' '.$alu['nome']);
      $sessoalunno = ($alu['sesso'] == 'M' ? 'o' : 'a');
      $dataalunno = $alu['dataNascita']->format('d/m/Y');
      $this->cella($pdf, 35, 8, 0, 0, $nomealunno, 0, 'L', 'T');
      $this->cella($pdf, 35, 11, -35, 0, $dataalunno, 1, 'L', 'B');
      $this->cella($pdf, 35, 11, -35, 0, 'Assenze ->', 1, 'R', 'B');
      $pdf->SetXY($pdf->GetX(), $pdf->GetY() + 5.50);
      if (in_array($idalunno, $dati['estero'])) {
        // frequenta all'estero
        $width = (count($dati['materie']) + 1) * 6 + 12;
        if ($dati['classe']->getAnno() == 3) {
          $width += 6;
        } elseif ($dati['classe']->getAnno() >= 4) {
          $width += 3 * 6;
        }
        $this->cella($pdf, $width, 11, 0, -5.50, '', 1, 'C', 'M');
        $esito = 'Anno all\'estero';
        $this->cella($pdf, 0, 11, 0, 0, $esito, 1, 'C', 'M');
        // nuova riga
        $this->acapo($pdf, 11, $next_height, $etichetterot);
      } elseif (in_array($idalunno, $dati['cessata_frequenza'])) {
        // non scrutinato per cessata frequenza
        $width = (count($dati['materie']) + 1) * 6 + 12;
        if ($dati['classe']->getAnno() == 3) {
          $width += 6;
        } elseif ($dati['classe']->getAnno() >= 4) {
          $width += 3 * 6;
        }
        $this->cella($pdf, $width, 11, 0, -5.50, '', 1, 'C', 'M');
        $esito = 'Non Scrutinat'.$sessoalunno;
        $this->cella($pdf, 0, 11, 0, 0, $esito, 1, 'C', 'M');
        // nuova riga
        $this->acapo($pdf, 11, $next_height, $etichetterot);
      } elseif (in_array($idalunno, $dati['no_scrutinabili'])) {
        // non scrutinabile per limite assenze
        $width = (count($dati['materie']) + 1) * 6 + 12;
        if ($dati['classe']->getAnno() == 3) {
          $width += 6;
        } elseif ($dati['classe']->getAnno() >= 4) {
          $width += 3 * 6;
        }
        $this->cella($pdf, $width, 11, 0, -5.50, '', 1, 'C', 'M');
        $esito = "Esclus$sessoalunno dallo scrutinio finale e non ammess$sessoalunno all'".
          ($dati['classe']->getAnno() == 5 ? 'Esame di Stato' : 'anno successivo').
          ' (DPR 122/09 art. 14 comma 7)';
        $this->cella($pdf, 0, 11, 0, 0, $esito, 1, 'C', 'M');
        // nuova riga
        $this->acapo($pdf, 11, $next_height, $etichetterot);
      } elseif (in_array($idalunno, $dati['scrutinati'])) {
        // scrutinati
        $voti_somma = 0;
        $voti_num = 0;
        if ($dati['esiti'][$idalunno]->getEsito() == 'N') {
          // non ammesso
          $width = (count($dati['materie']) + 1) * 6 + 12;
          if ($dati['classe']->getAnno() == 3) {
            $width += 6;
          } elseif ($dati['classe']->getAnno() >= 4) {
            $width += 3 * 6;
          }
          $this->cella($pdf, $width, 11, 0, -5.50, '', 1, 'C', 'M');
          $esito = 'Non Ammess'.$sessoalunno;
          $this->cella($pdf, 0, 11, 0, 0, $esito, 1, 'C', 'M');
          // nuova riga
          $this->acapo($pdf, 11, $next_height, $etichetterot);
        } elseif ($dati['esiti'][$idalunno]->getEsito() == 'S') {
          // sospesi
          $width = (count($dati['materie']) + 1) * 6 + 12;
          if ($dati['classe']->getAnno() == 3) {
            $width += 6;
          } elseif ($dati['classe']->getAnno() >= 4) {
            $width += 3 * 6;
          }
          $this->cella($pdf, $width, 11, 0, -5.50, '', 1, 'C', 'M');
          $esito = 'Sospensione del giudizio';
          $this->cella($pdf, 0, 11, 0, 0, $esito, 1, 'C', 'M');
          // nuova riga
          $this->acapo($pdf, 11, $next_height, $etichetterot);
        } elseif ($dati['esiti'][$idalunno]->getEsito() == 'A') {
          // ammessi
          foreach ($dati['materie'] as $idmateria=>$mat) {
            $voto = '';
            $assenze = '';
            $width = 6;
            if ($mat['tipo'] == 'R') {
              // religione
              $width = 12;
              if ($alu['religione'] != 'S') {
                // N.A.
                $voto = '///';
              } else {
                $voto = $info_voti['R'][$dati['voti'][$idalunno][$idmateria]['unico']];
                $assenze = $dati['voti'][$idalunno][$idmateria]['assenze'];
              }
            } elseif ($mat['tipo'] == 'C') {
              // condotta
              $voto = $info_voti['C'][$dati['voti'][$idalunno][$idmateria]['unico']];
              $voti_somma += ($dati['voti'][$idalunno][$idmateria]['unico'] > 4 ? $dati['voti'][$idalunno][$idmateria]['unico'] : 0);
              $voti_num++;
            } elseif ($mat['tipo'] == 'N') {
              $voto = $info_voti['N'][$dati['voti'][$idalunno][$idmateria]['unico']];
              $assenze = $dati['voti'][$idalunno][$idmateria]['assenze'];
              $voti_somma += $dati['voti'][$idalunno][$idmateria]['unico'];
              $voti_num++;
            }
            // scrive voto/assenze
            //-- if ($dati['classe']->getAnno() == 5) {
              // controlla visualizzazione ammessi
              //-- switch ($this->session->get('/CONFIG/SCUOLA/tabelloni_quinta')) {
                //-- case 'N':
                  //-- // non pubblica niente
                  //-- $voto = '';
                  //-- $assenze = '';
                  //-- break;
                //-- case 'V':
                  //-- // pubblica solo voti suff.
                  //-- $voto = (($mat['tipo'] != 'R' && $voto < 6) ||
                    //-- ($mat['tipo'] == 'R' && $alu['religione'] == 'S' && $dati['voti'][$idalunno][$idmateria]['unico'] < 22)) ? ' ' : $voto;
                  //-- break;
                //-- case 'A':
                  //-- // pubblica dati di alunni con tutto suff.
                  //-- foreach ($dati['voti'][$idalunno] as $mm=>$vv) {
                    //-- if (($dati['materie'][$mm]['tipo'] != 'R' && $vv['unico'] < 6) ||
                        //-- ($dati['materie'][$mm]['tipo'] == 'R' && $alu['religione'] == 'S' && $vv['unico'] < 22)) {
                      //-- // insuff. presente
                      //-- $voto = '';
                      //-- $assenze = '';
                      //-- break;
                    //-- }
                  //-- }
                  //-- break;
                //-- default:  // opzione 'T'
                  //-- // pubblica tutto
              //-- }
            //-- }
            $this->cella($pdf, $width, 5.50, 0, -5.50, $voto, 1, 'C', 'M');
            $this->cella($pdf, $width, 5.50, -$width, 5.50, $assenze, 1, 'C', 'M');
          }
          if ($dati['classe']->getAnno() >= 3) {
            // credito
            $credito = $dati['esiti'][$idalunno]->getCredito();
            $creditoprec = ($dati['classe']->getAnno() == 5 ?
              ($dati['esiti'][$idalunno]->getDati()['creditoConvertito3'] + $dati['esiti'][$idalunno]->getDati()['creditoConvertito4']) :
              $dati['esiti'][$idalunno]->getCreditoPrecedente());
            $creditotot = $credito + $creditoprec;
            $this->cella($pdf, 6, 5.50, 0, -5.50, $credito, 1, 'C', 'M');
            $this->cella($pdf, 6, 5.50, -6, 5.50, '', 1, 'C', 'M');
            if ($dati['classe']->getAnno() >= 4) {
              $this->cella($pdf, 6, 5.50, 0, -5.50, $creditoprec, 1, 'C', 'M');
              $this->cella($pdf, 6, 5.50, -6, 5.50, '', 1, 'C', 'M');
              $this->cella($pdf, 6, 5.50, 0, -5.50, $creditotot, 1, 'C', 'M');
              $this->cella($pdf, 6, 5.50, -6, 5.50, '', 1, 'C', 'M');
            }
          }
          // media
          $media = number_format($voti_somma / $voti_num, 2, ',', '');
          $this->cella($pdf, 12, 5.50, 0, -5.50, $media, 1, 'C', 'M');
          $this->cella($pdf, 12, 5.50, -12, 5.50, '', 1, 'C', 'M');
          // esito
          $esito = 'Ammess'.$sessoalunno;
          $this->cella($pdf, 0, 11, 0, -5.50, $esito, 1, 'C', 'M');
          // nuova riga
          $this->acapo($pdf, 11, $next_height, $etichetterot);
        }
      }
    }
    // data e firma
    $datascrutinio = $dati['scrutinio']->getData()->format('d/m/Y');
    $pdf->SetFont('helvetica', '', 12);
    $this->cella($pdf, 30, 15, 0, 0, 'Data', 0, 'R', 'B');
    $this->cella($pdf, 30, 15, 0, 0, $datascrutinio, 'B', 'C', 'B');
    $pdf->SetXY(-80, $pdf->GetY());
    $preside = $this->session->get('/CONFIG/ISTITUTO/firma_preside');
    $text = '(Il Dirigente Scolastico)'."\n".$preside;
    $this->cella($pdf, 60, 15, 0, 0, $text, 'B', 'C', 'B');
  }

  /**
   * Crea il foglio firme del verbale come documento PDF
   *
   * @param TCPDF $pdf Gestore del documento PDF
   * @param string $classe Nome della classe
   * @param string $classe_completa Nome della classe con corso e sede
   * @param array $dati Dati dello scrutinio
   */
  public function creaFirmeVerbale_F($pdf, $classe, $classe_completa, $dati) {
    // set margins
    $pdf->SetMargins(10, 10, 10, true);
    // set auto page breaks
    $pdf->SetAutoPageBreak(false, 10);
    // set font
    $pdf->SetFont('helvetica', '', 10);
    // inizio pagina
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->AddPage('L');
    // intestazione pagina
    $coordinatore = $dati['classe']->getCoordinatore()->getCognome().' '.$dati['classe']->getCoordinatore()->getNome();
    $pdf->SetFont('helvetica', 'B', 8);
    $this->cella($pdf, 100, 4, 0, 0, 'FOGLIO FIRME VERBALE', 0, 'L', 'T');
    $as = $this->session->get('/CONFIG/SCUOLA/anno_scolastico');
    $this->cella($pdf, 0, 4, 0, 0, $classe.' - A.S. '.$as, 0, 'R', 'T');
    $this->acapo($pdf, 5);
    $pdf->SetFont('helvetica', 'B', 16);
    $this->cella($pdf, 70, 10, 0, 0, 'CONSIGLIO DI CLASSE:', 0, 'L', 'B');
    $this->cella($pdf, 0, 10, 0, 0, $classe_completa, 0, 'L', 'B');
    $this->acapo($pdf, 10);
    $pdf->SetFont('helvetica', 'B', 10);
    $this->cella($pdf, 40, 6, 0, 0, 'Docente Coordinatore:', 0, 'L', 'T');
    $this->cella($pdf, 0, 6, 0, 0, $coordinatore, 0, 'L', 'T');
    $this->acapo($pdf, 6);
    // intestazione tabella
    $pdf->SetFont('helvetica', 'B', 10);
    $this->cella($pdf, 90, 5, 0, 0, 'MATERIA', 1, 'C', 'B');
    $this->cella($pdf, 60, 5, 0, 0, 'DOCENTI', 1, 'C', 'B');
    $this->cella($pdf, 0, 5, 0, 0, 'FIRME', 1, 'C', 'B');
    $this->acapo($pdf, 5);
    // dati materie
    foreach ($dati['materie'] as $idmateria=>$mat) {
      $lista = '';
      foreach ($mat as $iddocente=>$doc) {
        $nome_materia = $doc['nome_materia'];
        if ($dati['scrutinio']->getDato('presenze')[$iddocente]->getPresenza()) {
          $lista .= ', '.$doc['cognome'].' '.$doc['nome'];
        } else {
          $lista .= ', '.$dati['scrutinio']->getDato('presenze')[$iddocente]->getSostituto();
        }
      }
      $pdf->SetFont('helvetica', 'B', 10);
      $this->cella($pdf, 90, 11, 0, 0, $nome_materia, 1, 'L', 'B');
      $pdf->SetFont('helvetica', '', 10);
      $this->cella($pdf, 60, 11, 0, 0, substr($lista, 2), 1, 'L', 'B');
      $this->cella($pdf, 0, 11, 0, 0, '', 1, 'C', 'B');
      $this->acapo($pdf, 11);
    }
    // fine pagina
    $datascrutinio = $dati['scrutinio']->getData()->format('d/m/Y');
    $pdf->SetFont('helvetica', '', 12);
    $this->cella($pdf, 15, 9, 0, 0, 'DATA:', 0, 'R', 'B');
    $this->cella($pdf, 25, 9, 0, 0, $datascrutinio, 'B', 'C', 'B');
  }

  /**
   * Crea il foglio firme del registro dei voti come documento PDF
   *
   * @param TCPDF $pdf Gestore del documento PDF
   * @param string $classe Nome della classe
   * @param string $classe_completa Nome della classe con corso e sede
   * @param array $dati Dati dello scrutinio
   */
  public function creaFirmeRegistro_F($pdf, $classe, $classe_completa, $dati) {
    // set margins
    $pdf->SetMargins(10, 10, 10, true);
    // set auto page breaks
    $pdf->SetAutoPageBreak(false, 10);
    // set font
    $pdf->SetFont('helvetica', '', 10);
    // inizio pagina
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->AddPage('L');
    // intestazione pagina
    $pdf->SetFont('helvetica', 'B', 8);
    $this->cella($pdf, 100, 4, 0, 0, 'FOGLIO FIRME REGISTRO', 0, 'L', 'T');
    $as = $this->session->get('/CONFIG/SCUOLA/anno_scolastico');
    $this->cella($pdf, 0, 4, 0, 0, $classe.' - A.S. '.$as, 0, 'R', 'T');
    $this->acapo($pdf, 5);
    $pdf->SetFont('helvetica', 'B', 16);
    $this->cella($pdf, 70, 10, 0, 0, 'CONSIGLIO DI CLASSE:', 0, 'L', 'B');
    $this->cella($pdf, 145, 10, 0, 0, $classe_completa, 0, 'L', 'B');
    $this->cella($pdf, 0, 10, 0, 0, 'SCRUTINIO FINALE', 0, 'R', 'B');
    $this->acapo($pdf, 11);
    // intestazione tabella
    $pdf->SetFont('helvetica', 'B', 10);
    $this->cella($pdf, 90, 5, 0, 0, 'MATERIA', 1, 'C', 'B');
    $this->cella($pdf, 60, 5, 0, 0, 'DOCENTI', 1, 'C', 'B');
    $this->cella($pdf, 0, 5, 0, 0, 'FIRME', 1, 'C', 'B');
    $this->acapo($pdf, 5);
    // dati materie
    foreach ($dati['materie'] as $idmateria=>$mat) {
      $lista = '';
      foreach ($mat as $iddocente=>$doc) {
        $nome_materia = $doc['nome_materia'];
        if ($dati['scrutinio']->getDato('presenze')[$iddocente]->getPresenza()) {
          $lista .= ', '.$doc['cognome'].' '.$doc['nome'];
        } else {
          $lista .= ', '.$dati['scrutinio']->getDato('presenze')[$iddocente]->getSostituto();
        }
      }
      $pdf->SetFont('helvetica', 'B', 10);
      $this->cella($pdf, 90, 11, 0, 0, $nome_materia, 1, 'L', 'B');
      $pdf->SetFont('helvetica', '', 10);
      $this->cella($pdf, 60, 11, 0, 0, substr($lista, 2), 1, 'L', 'B');
      $this->cella($pdf, 0, 11, 0, 0, '', 1, 'C', 'B');
      $this->acapo($pdf, 11);
    }
    // fine pagina
    $datascrutinio = $dati['scrutinio']->getData()->format('d/m/Y');
    $pdf->SetFont('helvetica', '', 12);
    $this->cella($pdf, 15, 12, 0, 0, 'DATA:', 0, 'R', 'B');
    $this->cella($pdf, 25, 12, 0, 0, $datascrutinio, 'B', 'C', 'B');
    $this->cella($pdf, 50, 12, 0, 0, 'SEGRETARIO:', 0, 'R', 'B');
    $this->cella($pdf, 68, 12, 0, 0, '', 'B', 'C', 'B');
    $this->cella($pdf, 50, 12, 0, 0, 'PRESIDENTE:', 0, 'R', 'B');
    $this->cella($pdf, 68, 12, 0, 0, '', 'B', 'C', 'B');
  }

  /**
   * Crea le certificazioni delle competenze
   *
   * @param Classe $classe Classe dello scrutinio
   * @param string $periodo Periodo dello scrutinio
   *
   * @return Percorso completo del file da inviare
   */
  public function certificazioni(Classe $classe, $periodo) {
    // inizializza
    $fs = new Filesystem();
    if ($periodo == 'F') {
      // scrutinio finale
      $percorso = $this->root.'/finale/'.$classe->getAnno().$classe->getSezione();
      if (!$fs->exists($percorso)) {
        // crea directory
        $fs->mkdir($percorso, 0775);
      }
      // nome documento
      $nomefile = $classe->getAnno().$classe->getSezione().'-scrutinio-finale-certificazioni.pdf';
      if (!$fs->exists($percorso.'/'.$nomefile)) {
        // crea pdf
        $this->pdf->configure($this->session->get('/CONFIG/ISTITUTO/intestazione'),
          'Scrutinio Finale - Certificazioni delle competenze - Classe '.$classe->getAnno().'ª '.$classe->getSezione());
        $dati = $this->certificazioniDati($classe, $periodo);
        // crea il documento
        $nome_classe = $classe->getAnno().'ª '.$classe->getSezione();
        $nome_classe_lungo = $nome_classe.' '.$classe->getCorso()->getNomeBreve().' - '.$classe->getSede()->getCitta();
        $this->creaCertificazioni_F($this->pdf->getHandler(), $nome_classe, $nome_classe_lungo, $dati);
        // salva il documento
        $this->pdf->save($percorso.'/'.$nomefile);
      }
      // restituisce nome del file
      return $percorso.'/'.$nomefile;
    } elseif ($periodo == 'I') {
      // scrutinio integrativo
      $percorso = $this->root.'/integrativo/'.$classe->getAnno().$classe->getSezione();
      if (!$fs->exists($percorso)) {
        // crea directory
        $fs->mkdir($percorso, 0775);
      }
      // nome documento
      $nomefile = $classe->getAnno().$classe->getSezione().'-scrutinio-integrativo-certificazioni.pdf';
      if (!$fs->exists($percorso.'/'.$nomefile)) {
        // crea pdf
        $this->pdf->configure($this->session->get('/CONFIG/ISTITUTO/intestazione'),
          'Scrutinio Integrativo - Certificazioni delle competenze - Classe '.$classe->getAnno().'ª '.$classe->getSezione());
        $dati = $this->certificazioniDati($classe, $periodo);
        // crea il documento
        $nome_classe = $classe->getAnno().'ª '.$classe->getSezione();
        $nome_classe_lungo = $nome_classe.' '.$classe->getCorso()->getNomeBreve().' - '.$classe->getSede()->getCitta();
        $this->creaCertificazioni_F($this->pdf->getHandler(), $nome_classe, $nome_classe_lungo, $dati);
        // salva il documento
        $this->pdf->save($percorso.'/'.$nomefile);
      }
      // restituisce nome del file
      return $percorso.'/'.$nomefile;
    } elseif ($periodo == 'X') {
      // scrutinio integrativo
      $percorso = $this->root.'/rinviato/'.$classe->getAnno().$classe->getSezione();
      if (!$fs->exists($percorso)) {
        // crea directory
        $fs->mkdir($percorso, 0775);
      }
      // nome documento
      $nomefile = $classe->getAnno().$classe->getSezione().'-scrutinio-rinviato-certificazioni.pdf';
      if (!$fs->exists($percorso.'/'.$nomefile)) {
        // crea pdf
        $this->pdf->configure($this->session->get('/CONFIG/ISTITUTO/intestazione'),
          'Scrutinio Integrativo - Certificazioni delle competenze - Classe '.$classe->getAnno().'ª '.$classe->getSezione());
        $dati = $this->certificazioniDati($classe, $periodo);
        // crea il documento
        $nome_classe = $classe->getAnno().'ª '.$classe->getSezione();
        $nome_classe_lungo = $nome_classe.' '.$classe->getCorso()->getNomeBreve().' - '.$classe->getSede()->getCitta();
        $this->creaCertificazioni_F($this->pdf->getHandler(), $nome_classe, $nome_classe_lungo, $dati);
        // salva il documento
        $this->pdf->save($percorso.'/'.$nomefile);
      }
      // restituisce nome del file
      return $percorso.'/'.$nomefile;
    }
    // errore
    return null;
  }

  /**
   * Restituisce i dati per creare le certificazioni delle competenze
   *
   * @param Classe $classe Classe dello scrutinio
   * @param string $periodo Periodo dello scrutinio
   *
   * @return Array Dati formattati come array associativo
   */
  public function certificazioniDati(Classe $classe, $periodo) {
    $dati = array();
    if ($periodo == 'F') {
      // scrutinio finale
      $dati['scrutinio'] = $this->em->getRepository('App:Scrutinio')->findOneBy(['classe' => $classe,
        'periodo' => $periodo, 'stato' => 'C']);
      $dati['classe'] = $classe;
      // alunni ammessi
      $alunni = $this->em->getRepository('App:Alunno')->createQueryBuilder('a')
        ->select('a.id,a.nome,a.cognome,a.dataNascita,a.sesso,a.comuneNascita,e.dati')
        ->join('App:Esito', 'e', 'WITH', 'e.alunno=a.id')
        ->where('a.id IN (:lista) AND e.scrutinio=:scrutinio AND e.esito=:esito')
        ->orderBy('a.cognome,a.nome,a.dataNascita', 'ASC')
        ->setParameters(['lista' => array_keys($dati['scrutinio']->getDato('scrutinabili')),
          'scrutinio' => $dati['scrutinio'], 'esito' => 'A'])
        ->getQuery()
        ->getResult();
      foreach ($alunni as $alu) {
        $dati['ammessi'][$alu['id']] = $alu;
      }
    } elseif ($periodo == 'I' || $periodo == 'X') {
      // scrutinio
      $dati['scrutinio'] = $this->em->getRepository('App:Scrutinio')->findOneBy(['classe' => $classe,
        'periodo' => $periodo, 'stato' => 'C']);
      $dati['classe'] = $classe;
      // legge dati di alunni
      $sospesi = ($periodo == 'I' ? $dati['scrutinio']->getDato('sospesi') : $dati['scrutinio']->getDato('rinviati'));
      // alunni ammessi
      $alunni = $this->em->getRepository('App:Alunno')->createQueryBuilder('a')
        ->select('a.id,a.nome,a.cognome,a.dataNascita,a.sesso,a.comuneNascita,e.dati')
        ->join('App:Esito', 'e', 'WITH', 'e.alunno=a.id AND e.scrutinio=:scrutinio')
        ->where('a.id IN (:lista) AND e.esito=:esito')
        ->orderBy('a.cognome,a.nome,a.dataNascita', 'ASC')
        ->setParameters(['scrutinio' => $dati['scrutinio'], 'lista' => $sospesi, 'esito' => 'A'])
        ->getQuery()
        ->getResult();
      foreach ($alunni as $alu) {
        $dati['ammessi'][$alu['id']] = $alu;
      }
    }
    // restituisce dati
    return $dati;
  }

  /**
   * Crea le certificazioni delle competenze come documento PDF
   *
   * @param TCPDF $pdf Gestore del documento PDF
   * @param string $classe Nome della classe
   * @param string $classe_completa Nome della classe con corso e sede
   * @param array $dati Dati dello scrutinio
   */
  public function creaCertificazioni_F($pdf, $classe, $classe_completa, $dati) {
    // set margins
    $pdf->SetMargins(20, 20, 20, true);
    // set auto page breaks
    $pdf->SetAutoPageBreak(false, 15);
    // set font
    $pdf->SetFont('helvetica', '', 10);
    // inizio pagina
    $pdf->SetFooterMargin(15);
    $pdf->setFooterFont(Array('helvetica', '', 8));
    $pdf->setFooterData(array(0,0,0), array(255,255,255));
    $pdf->setPrintFooter(true);
    $pdf->setHeaderTemplateAutoreset(true);
    foreach ($dati['ammessi'] as $idalunno=>$alu) {
      // alunno da certificare
      $valori = $alu['dati'];
      // inizia gruppo pagine
      $pdf->setPrintHeader(false);
      $pdf->startPageGroup();
      $pdf->AddPage('P');
      $alu_cognome = strtoupper($alu['cognome']);
      $alu_nome = strtoupper($alu['nome']);
      $alu_sesso = $alu['sesso'];
      $alu_nascita = $alu['dataNascita']->format('d/m/Y');
      $alu_citta = strtoupper($alu['comuneNascita']);
      // prima pagina
      $html = '<img src="/img/'.getenv('LOCAL_PATH').'intestazione-documenti.jpg" width="540">';
      $pdf->writeHTML($html, true, false, false, false, 'C');
      $pdf->Ln(3);
      $pdf->SetFont('times', 'B', 12);
      $as = $this->session->get('/CONFIG/SCUOLA/anno_scolastico');
      $html = '<p><span style="font-size:14">CERTIFICATO delle COMPETENZE DI BASE</span><br>'.
              '<span style="font-size:11">acquisite nell\'assolvimento dell\' OBBLIGO DI ISTRUZIONE</span></p>'.
              '<p>Anno Scolastico '.$as.'</p>'.
              '<p>&nbsp;</p>';
      $pdf->writeHTML($html, true, false, false, false, 'C');
      $pdf->SetFont('times', '', 11);
      $html = '<p>N° ..............</p>'.
              '<p style="text-align:center;font-weight:bold">IL DIRIGENTE SCOLASTICO</p>'.
              '<p>Visto il regolamento emanato dal Ministro dell\'Istruzione, Università e Ricerca con decreto 22 agosto 2007, n.139;</p>'.
              '<p>Visti gli atti di ufficio;</p>';
      $pdf->writeHTML($html, true, false, false, false, 'L');
      $this->acapo($pdf, 5);
      $text = ($alu_sesso == 'M' ? 'che lo studente' : 'che la studentessa');
      $pdf->SetFont('times', 'B', 14);
      $html = '<p>CERTIFICA<br>'.
              '<span style="font-style:italic">'.$text.'</span></p>';
      $pdf->writeHTML($html, true, false, false, false, 'C');
      // cognome e nome
      $pdf->SetFont('times', '', 11);
      $this->cella($pdf, 18, 8, 0, 0, 'cognome', 0, 'L', 'B');
      $pdf->SetFont('times', 'B', 11);
      $this->cella($pdf, 67, 8, 0, 0, $alu_cognome, 'B', 'L', 'B');
      $pdf->SetFont('times', '', 11);
      $this->cella($pdf, 18, 8, 0, 0, 'nome', 0, 'R', 'B');
      $pdf->SetFont('times', 'B', 11);
      $this->cella($pdf, 67, 8, 0, 0, $alu_nome, 'B', 'L', 'B');
      $this->acapo($pdf, 8);
      // data e città nascita
      $text = ($alu_sesso == 'M' ? 'nato' : 'nata').' il';
      $pdf->SetFont('times', '', 11);
      $this->cella($pdf, 18, 8, 0, 0, $text, 0, 'L', 'B');
      $pdf->SetFont('times', 'B', 11);
      $this->cella($pdf, 67, 8, 0, 0, $alu_nascita, 'B', 'L', 'B');
      $pdf->SetFont('times', '', 11);
      $this->cella($pdf, 18, 8, 0, 0, 'a', 0, 'R', 'B');
      $pdf->SetFont('times', 'B', 11);
      $this->cella($pdf, 67, 8, 0, 0, $alu_citta, 'B', 'L', 'B');
      $this->acapo($pdf, 8);
      // sezione
      $as = $this->session->get('/CONFIG/SCUOLA/anno_scolastico');
      $text = ($alu_sesso == 'M' ? 'iscritto' : 'iscritta').
              ' nell\'anno scolastico '.$as.' presso questo Istituto nella classe II sezione';
      $pdf->SetFont('times', '', 11);
      $this->cella($pdf, 132, 8, 0, 0, $text, 0, 'L', 'B');
      $pdf->SetFont('times', 'B', 11);
      $text = $dati['classe']->getSezione().' - '.$dati['classe']->getSede()->getCitta();
      $this->cella($pdf, 0, 8, 0, 0, $text, 'B', 'L', 'B');
      $this->acapo($pdf, 8);
      // corso
      $pdf->SetFont('times', '', 11);
      $this->cella($pdf, 32, 8, 0, 0, 'indirizzo di studio', 0, 'L', 'B');
      $pdf->SetFont('times', 'B', 11);
      $this->cella($pdf, 0, 8, 0, 0, $dati['classe']->getCorso()->getNome(), 'B', 'L', 'B');
      $this->acapo($pdf, 8);
      // dichiarazione
      $text = 'nell\'assolvimento dell\'obbligo di istruzione, della durata di 10 anni,';
      $pdf->SetFont('times', '', 11);
      $this->cella($pdf, 0, 8, 0, 0, $text, 0, 'L', 'B');
      $this->acapo($pdf, 8);
      $pdf->SetFont('times', 'BI', 14);
      $this->cella($pdf, 0, 10, 0, 0, 'ha acquisito', 0, 'C', 'B');
      $this->acapo($pdf, 10);
      $text = 'le competenze di base di seguito indicate.';
      $pdf->SetFont('times', '', 11);
      $this->cella($pdf, 0, 8, 0, 0, $text, 0, 'L', 'B');
      $this->acapo($pdf, 8);
      // note
      $pdf->SetFont('helvetica', 'B', 9);
      $this->acapo($pdf, 20);
      $this->cella($pdf, 90, 5, 40, 0, 'Note', 'T', 'C', 'B');
      $this->acapo($pdf, 5);
      $html = '<p>1) Il presente certificato ha validità nazionale.</p>'.
              '<p>2) I livelli relativi all’acquisizione delle competenze di ciascun asse sono i seguenti:<br>'.
              'LIVELLO BASE: lo studente svolge compiti semplici in situazioni note, mostrando di possedere conoscenze ed abilità essenziali e di saper applicare regole e procedure fondamentali. Nel caso in cui non sia stato raggiunto il livello base, è riportata l’espressione "Livello base non raggiunto", con l’indicazione della relativa motivazione.<br>'.
              'LIVELLO INTERMEDIO: lo studente svolge compiti e risolve problemi complessi in situazioni note, compie scelte consapevoli, mostrando di saper utilizzare le conoscenze e le abilità acquisite.<br>'.
              'LIVELLO AVANZATO: lo studente svolge compiti e problemi complessi in situazioni anche non note, mostrando padronanza nell’uso delle conoscenze e delle abilità. Es. proporre e sostenere le proprie opinioni e assumere autonomamente decisioni consapevoli.</p>';
      $pdf->writeHTML($html, true, false, false, false, 'L');
      // nuova pagina
      $pdf->SetHeaderMargin(10);
      $pdf->setHeaderFont(Array('helvetica', 'B', 6));
      $pdf->setHeaderData('', 0, $alu_cognome.' '.$alu_nome.' - 2ª '.$dati['classe']->getSezione(), '', array(0,0,0), array(255,255,255));
      $pdf->setPrintHeader(true);
      $pdf->AddPage('P');
      // intestazione
      $pdf->SetFont('helvetica', 'B', 11);
      $this->cella($pdf, 0, 5, 0, 0, 'COMPETENZE DI BASE E RELATIVI LIVELLI RAGGIUNTI', 0, 'C', 'M');
      $this->acapo($pdf, 5);
      $pdf->SetFont('helvetica', 'B', 10);
      $this->cella($pdf, 85, 5, 0, 0, 'ASSE DEI LINGUAGGI', 1, 'C', 'M');
      $this->cella($pdf, 0, 5, 0, 0, 'LIVELLI', 1, 'C', 'M');
      $this->acapo($pdf, 5);
      // asse linguaggi-1
      $pdf->SetFont('helvetica', '', 9);
      $pdf->setListIndentWidth(3);
      $tagvs = array('ul' => array(0 => array('h' => 0.0001, 'n' => 1)));
      $pdf->setHtmlVSpace($tagvs);
      $html = '<i><b>Lingua Italiana:</b></i><ul>'.
              '<li>Padroneggiare gli strumenti espressivi ed argomentativi indispensabili per gestire l\'interazione comunicativa verbale in vari contesti</li>'.
              '<li>Leggere comprendere e interpretare testi scritti di vario tipo</li>'.
              '<li>Produrre testi di vario tipo in relazione ai differenti scopi comunicativi</li></ul>';
      $pdf->writeHTMLCell(85, 32, $pdf->GetX(), $pdf->GetY(), $html, 1, 0, false, true, 'L', true);
      $text = $this->trans->trans('label.certificazione_livello_'.$valori['certificazione_italiano']).
        ($valori['certificazione_italiano'] == 'N' ?
        ' per la seguente motivazione: '.$valori['certificazione_italiano_motivazione'] : '');
      $pdf->SetFont('helvetica', 'B', 10);
      $this->cella($pdf, 0, 32, 0, 0, $text, 1, 'C', 'M');
      $this->acapo($pdf, 32);
      // asse linguaggi-2
      $pdf->SetFont('helvetica', '', 9);
      $pdf->setListIndentWidth(3);
      $tagvs = array('ul' => array(0 => array('h' => 0.0001, 'n' => 1)));
      $pdf->setHtmlVSpace($tagvs);
      $html = '<i><b>Lingua straniera:</b></i><ul>'.
              '<li>Utilizzare la lingua Inglese per i principali scopi comunicativi ed operativi</li></ul>';
      $pdf->writeHTMLCell(85, 18, $pdf->GetX(), $pdf->GetY(), $html, 1, 0, false, true, 'L', true);
      $text = $this->trans->trans('label.certificazione_livello_'.$valori['certificazione_lingua']).
        ($valori['certificazione_lingua'] == 'N' ?
        ' per la seguente motivazione: '.$valori['certificazione_lingua_motivazione'] : '');
      $pdf->SetFont('helvetica', 'B', 10);
      $this->cella($pdf, 0, 18, 0, 0, $text, 1, 'C', 'M');
      $this->acapo($pdf, 18);
      // asse linguaggi-3
      $pdf->SetFont('helvetica', '', 9);
      $pdf->setListIndentWidth(3);
      $tagvs = array('ul' => array(0 => array('h' => 0.0001, 'n' => 1)));
      $pdf->setHtmlVSpace($tagvs);
      $html = '<i><b>Altri linguaggi:</b></i><ul>'.
              '<li>Utilizzare gli strumenti fondamentali per una fruizione consapevole del patrimonio artistico e letterario</li>'.
              '<li>Utilizzare e produrre testi multimediali</li></ul>';
      $pdf->writeHTMLCell(85, 18, $pdf->GetX(), $pdf->GetY(), $html, 1, 0, false, true, 'L', true);
      $text = $this->trans->trans('label.certificazione_livello_'.$valori['certificazione_linguaggio']).
        ($valori['certificazione_linguaggio'] == 'N' ?
        ' per la seguente motivazione: '.$valori['certificazione_linguaggio_motivazione'] : '');
      $pdf->SetFont('helvetica', 'B', 10);
      $this->cella($pdf, 0, 18, 0, 0, $text, 1, 'C', 'M');
      $this->acapo($pdf, 18);
      // asse matematico-4
      $pdf->SetFont('helvetica', 'B', 10);
      $this->cella($pdf, 85, 5, 0, 0, 'ASSE MATEMATICO', 1, 'C', 'M');
      $this->cella($pdf, 0, 5, 0, 0, 'LIVELLI', 1, 'C', 'M');
      $this->acapo($pdf, 5);
      $pdf->SetFont('helvetica', '', 9);
      $pdf->setListIndentWidth(3);
      $tagvs = array('ul' => array(0 => array('h' => 0.0001, 'n' => 1)));
      $pdf->setHtmlVSpace($tagvs);
      $html = '<ul><li>Utilizzare le tecniche e le procedure del calcolo aritmetico ed algebrico, rappresentandole anche sotto forma grafica</li>'.
              '<li>Confrontare ed analizzare figure geometriche, individuando invarianti e relazioni</li>'.
              '<li>Individuare le strategie appropriate per la soluzione dei problemi</li>'.
              '<li>Analizzare dati e interpretarli sviluppando deduzioni e ragionamenti sugli stessi anche con l’ausilio di rappresentazioni grafiche, usando consapevolmente gli strumenti di calcolo e le potenzialità offerte da applicazioni specifiche di tipo informatico</li></ul>';
      $pdf->writeHTMLCell(85, 48, $pdf->GetX(), $pdf->GetY(), $html, 1, 0, false, true, 'L', true);
      $text = $this->trans->trans('label.certificazione_livello_'.$valori['certificazione_matematica']).
        ($valori['certificazione_matematica'] == 'N' ?
        ' per la seguente motivazione: '.$valori['certificazione_matematica_motivazione'] : '');
      $pdf->SetFont('helvetica', 'B', 10);
      $this->cella($pdf, 0, 48, 0, 0, $text, 1, 'C', 'M');
      $this->acapo($pdf, 48);
      // asse scientifico-5
      $pdf->SetFont('helvetica', 'B', 10);
      $this->cella($pdf, 85, 5, 0, 0, 'ASSE SCIENTIFICO-TECNOLOGICO', 1, 'C', 'M');
      $this->cella($pdf, 0, 5, 0, 0, 'LIVELLI', 1, 'C', 'M');
      $this->acapo($pdf, 5);
      $pdf->SetFont('helvetica', '', 9);
      $pdf->setListIndentWidth(3);
      $tagvs = array('ul' => array(0 => array('h' => 0.0001, 'n' => 1)));
      $pdf->setHtmlVSpace($tagvs);
      $html = '<ul><li>Osservare, descrivere ed analizzare fenomeni appartenenti alla realtà naturale e artificiale e riconoscere nelle varie forme i concetti di sistema e di complessità</li>'.
              '<li>Analizzare qualitativamente e quantitativamente fenomeni legati alle trasformazioni di energia a partire dall’esperienza</li>'.
              '<li>Essere consapevoli delle potenzialità e dei limiti delle tecnologie nel contesto culturale e sociale in cui vengono applicate</li></ul>';
      $pdf->writeHTMLCell(85, 40, $pdf->GetX(), $pdf->GetY(), $html, 1, 0, false, true, 'L', true);
      $text = $this->trans->trans('label.certificazione_livello_'.$valori['certificazione_scienze']).
        ($valori['certificazione_scienze'] == 'N' ?
        ' per la seguente motivazione: '.$valori['certificazione_scienze_motivazione'] : '');
      $pdf->SetFont('helvetica', 'B', 10);
      $this->cella($pdf, 0, 40, 0, 0, $text, 1, 'C', 'M');
      $this->acapo($pdf, 40);
      // asse storico-6
      $pdf->SetFont('helvetica', 'B', 10);
      $this->cella($pdf, 85, 5, 0, 0, 'ASSE STORICO-SOCIALE', 1, 'C', 'M');
      $this->cella($pdf, 0, 5, 0, 0, 'LIVELLI', 1, 'C', 'M');
      $this->acapo($pdf, 5);
      $pdf->SetFont('helvetica', '', 9);
      $pdf->setListIndentWidth(3);
      $tagvs = array('ul' => array(0 => array('h' => 0.0001, 'n' => 1)));
      $pdf->setHtmlVSpace($tagvs);
      $html = '<ul><li>Comprendere il cambiamento e la diversità dei tempi storici in una dimensione diacronica attraverso il confronto fra epoche e in una dimensione sincronica attraverso il confronto fra aree geografiche e culturali</li>'.
              '<li>Collocare l’esperienza personale in un sistema di regole fondato sul reciproco riconoscimento dei diritti garantiti dalla Costituzione, a tutela della persona, della collettività e dell’ambiente</li>'.
              '<li>Riconoscere le caratteristiche essenziali del sistema socio economico per orientarsi nel tessuto produttivo del proprio territorio</li></ul>';
      $pdf->writeHTMLCell(85, 44, $pdf->GetX(), $pdf->GetY(), $html, 1, 0, false, true, 'L', true);
      $text = $this->trans->trans('label.certificazione_livello_'.$valori['certificazione_storia']).
        ($valori['certificazione_storia'] == 'N' ?
        ' per la seguente motivazione: '.$valori['certificazione_storia_motivazione'] : '');
      $pdf->SetFont('helvetica', 'B', 10);
      $this->cella($pdf, 0, 44, 0, 0, $text, 1, 'C', 'M');
      $this->acapo($pdf, 44);
      // dichiarazione
      $text = 'Le competenze di base relative agli assi culturali sopra richiamati sono state acquisite dallo studente con riferimento alle competenze chiave di cittadinanza di cui all’allegato 2 del regolamento citato in premessa (1. imparare ad imparare; 2. progettare; 3. comunicare; 4. collaborare e partecipare; 5. agire in modo autonomo e responsabile; 6. risolvere problemi; 7. individuare collegamenti e  relazioni; 8. acquisire e interpretare l’informazione).';
      $pdf->SetFont('helvetica', '', 10);
      $this->cella($pdf, 0, 18, 0, 0, $text, 0, 'L', 'B');
      $this->acapo($pdf, 18);
      // data e firma
      $datascrutinio = $dati['scrutinio']->getData()->format('d/m/Y');
      $pdf->SetFont('helvetica', '', 11);
      $this->cella($pdf, 30, 14, 0, 0, 'Cagliari,', 0, 'R', 'B');
      $this->cella($pdf, 30, 14, 0, 0, $datascrutinio, 'B', 'C', 'B');
      $pdf->SetXY(-80, $pdf->GetY());
      $preside = $this->session->get('/CONFIG/ISTITUTO/firma_preside');
      $text = '(Il Dirigente Scolastico)'."\n".$preside;
      $this->cella($pdf, 60, 15, 0, 0, $text, 'B', 'C', 'B');
    }
  }

  /**
   * Crea la comunicazione per i non ammessi
   *
   * @param Classe $classe Classe dello scrutinio
   * @param Alunno $alunno Alunno selezionato
   * @param string $periodo Periodo dello scrutinio
   *
   * @return Percorso completo del file da inviare
   */
  public function nonAmmesso(Classe $classe, Alunno $alunno, $periodo) {
    // inizializza
    $fs = new Filesystem();
    if ($periodo == 'F') {
      // scrutinio finale
      $percorso = $this->root.'/finale/'.$classe->getAnno().$classe->getSezione();
      if (!$fs->exists($percorso)) {
        // crea directory
        $fs->mkdir($percorso, 0775);
      }
      // nome documento
      $nomefile = $classe->getAnno().$classe->getSezione().'-scrutinio-finale-non-ammesso-'.$alunno->getId().'.pdf';
      if (!$fs->exists($percorso.'/'.$nomefile)) {
        // crea documento
        $this->pdf->configure($this->session->get('/CONFIG/ISTITUTO/intestazione'),
          'Scrutinio Finale - Comunicazione di non ammissione - Alunno '.$alunno->getCognome().' '.$alunno->getNome());
        $dati = $this->nonAmmessoDati($classe, $alunno, $periodo);
        $nome_classe = $classe->getAnno().'ª '.$classe->getSezione();
        $nome_classe_lungo = $nome_classe.' '.$classe->getCorso()->getNomeBreve().' - '.$classe->getSede()->getCitta();
        // controllo alunno
        if ($dati['tipo'] == null) {
          // errore
          return null;
        } else {
          // crea comunicazione non ammissione (per scrutinio o per frequenza)
          $this->creaNonAmmesso_F($this->pdf->getHandler(), $nome_classe, $nome_classe_lungo, $dati);
        }
        // salva il documento
        $this->pdf->save($percorso.'/'.$nomefile);
      }
      // restituisce nome del file
      return $percorso.'/'.$nomefile;
    } elseif ($periodo == 'I') {
      // scrutinio integrativo
      $percorso = $this->root.'/integrativo/'.$classe->getAnno().$classe->getSezione();
      if (!$fs->exists($percorso)) {
        // crea directory
        $fs->mkdir($percorso, 0775);
      }
      // nome documento
      $nomefile = $classe->getAnno().$classe->getSezione().'-scrutinio-integrativo-non-ammesso-'.$alunno->getId().'.pdf';
      if (!$fs->exists($percorso.'/'.$nomefile)) {
        // crea documento
        $this->pdf->configure($this->session->get('/CONFIG/ISTITUTO/intestazione'),
          'Scrutinio Integrativo - Comunicazione di non ammissione - Alunno '.$alunno->getCognome().' '.$alunno->getNome());
        $dati = $this->nonAmmessoDati($classe, $alunno, $periodo);
        $nome_classe = $classe->getAnno().'ª '.$classe->getSezione();
        $nome_classe_lungo = $nome_classe.' '.$classe->getCorso()->getNomeBreve().' - '.$classe->getSede()->getCitta();
        // controllo alunno
        if ($dati['esito'] && $dati['esito']->getEsito() == 'N') {
          // crea il documento
          $this->creaNonAmmesso_I($this->pdf->getHandler(), $nome_classe, $nome_classe_lungo, $dati);
        } else {
          // errore
          return null;
        }
        // salva il documento
        $this->pdf->save($percorso.'/'.$nomefile);
      }
      // restituisce nome del file
      return $percorso.'/'.$nomefile;
    } elseif ($periodo == 'X') {
      // scrutinio integrativo
      $percorso = $this->root.'/rinviato/'.$classe->getAnno().$classe->getSezione();
      if (!$fs->exists($percorso)) {
        // crea directory
        $fs->mkdir($percorso, 0775);
      }
      // nome documento
      $nomefile = $classe->getAnno().$classe->getSezione().'-scrutinio-rinviato-non-ammesso-'.$alunno->getId().'.pdf';
      if (!$fs->exists($percorso.'/'.$nomefile)) {
        // crea documento
        $this->pdf->configure($this->session->get('/CONFIG/ISTITUTO/intestazione'),
          'Scrutinio Integrativo - Comunicazione di non ammissione - Alunno '.$alunno->getCognome().' '.$alunno->getNome());
        $dati = $this->nonAmmessoDati($classe, $alunno, $periodo);
        $nome_classe = $classe->getAnno().'ª '.$classe->getSezione();
        $nome_classe_lungo = $nome_classe.' '.$classe->getCorso()->getNomeBreve().' - '.$classe->getSede()->getCitta();
        // controllo alunno
        if ($dati['esito'] && $dati['esito']->getEsito() == 'N') {
          // crea il documento
          $this->creaNonAmmesso_I($this->pdf->getHandler(), $nome_classe, $nome_classe_lungo, $dati);
        } else {
          // errore
          return null;
        }
        // salva il documento
        $this->pdf->save($percorso.'/'.$nomefile);
      }
      // restituisce nome del file
      return $percorso.'/'.$nomefile;
    }
    // errore
    return null;
  }

  /**
   * Restituisce i dati per creare le comunicazioni per i non ammessi
   *
   * @param Classe $classe Classe dello scrutinio
   * @param Alunno $alunno Alunno selezionato
   * @param string $periodo Periodo dello scrutinio
   *
   * @return Array Dati formattati come array associativo
   */
  public function nonAmmessoDati(Classe $classe, Alunno $alunno, $periodo) {
    $dati = array();
    if ($periodo == 'F') {
      // scrutinio finale
      $dati['scrutinio'] = $this->em->getRepository('App:Scrutinio')->findOneBy(['classe' => $classe,
        'periodo' => $periodo, 'stato' => 'C']);
      $dati['classe'] = $classe;
      $dati['alunno'] = $alunno;
      // legge esito
      $dati['esito'] = $this->em->getRepository('App:Esito')->createQueryBuilder('e')
        ->where('e.alunno=:alunno AND e.scrutinio=:scrutinio')
        ->setParameters(['alunno' => $alunno, 'scrutinio' => $dati['scrutinio']])
        ->setMaxResults(1)
        ->getQuery()
        ->getOneOrNullResult();
      // controllo tipo di non ammissione
      $dati['tipo'] = null;
      $scrut = ($dati['scrutinio']->getDato('scrutinabili') == null ? [] :
        array_keys($dati['scrutinio']->getDato('scrutinabili')));
      $no_scrut = ($dati['scrutinio']->getDato('no_scrutinabili') == null ? [] :
        $dati['scrutinio']->getDato('no_scrutinabili'));
      $freq = ($dati['scrutinio']->getDato('cessata_frequenza') == null ? [] :
        $dati['scrutinio']->getDato('cessata_frequenza'));
      if (in_array($alunno->getId(), $scrut) && $dati['esito'] && $dati['esito']->getEsito() == 'N') {
        // non ammesso durante lo scrutinio
        $dati['tipo'] = 'N';
      } elseif (isset($no_scrut[$alunno->getId()]) && !isset($no_scrut[$alunno->getId()]['deroga'])) {
        // non scrutinabile per assenze e non ammesso
        $dati['tipo'] = 'A';
      } elseif (in_array($alunno->getId(), $freq)) {
        // non scrutinato per cessata frequenza
        $dati['tipo'] = 'C';
      }
      // legge materie
      $materie = $this->em->getRepository('App:Materia')->createQueryBuilder('m')
        ->select('DISTINCT m.id,m.nome,m.nomeBreve,m.tipo')
        ->join('App:Cattedra', 'c', 'WITH', 'c.materia=m.id')
        ->where('c.classe=:classe AND c.attiva=:attiva AND c.tipo=:tipo AND m.tipo!=:sostegno')
        ->orderBy('m.ordinamento,m.nome', 'ASC')
        ->setParameters(['classe' => $classe, 'attiva' => 1, 'tipo' => 'N', 'sostegno' => 'S'])
        ->getQuery()
        ->getArrayResult();
      foreach ($materie as $mat) {
        $dati['materie'][$mat['id']] = $mat;
      }
      $condotta = $this->em->getRepository('App:Materia')->findOneByTipo('C');
      $dati['materie'][$condotta->getId()] = array(
        'id' => $condotta->getId(),
        'nome' => $condotta->getNome(),
        'nomeBreve' => $condotta->getNomeBreve(),
        'tipo' => $condotta->getTipo());
      // legge i voti
      $voti = $this->em->getRepository('App:VotoScrutinio')->createQueryBuilder('vs')
        ->where('vs.scrutinio=:scrutinio AND vs.alunno=:alunno')
        ->setParameters(['scrutinio' => $dati['scrutinio'], 'alunno' => $alunno])
        ->getQuery()
        ->getResult();
      foreach ($voti as $v) {
        // inserisce voti/assenze
        $dati['voti'][$v->getMateria()->getId()] = array(
          'id' => $v->getId(),
          'unico' => $v->getUnico(),
          'assenze' => $v->getAssenze());
      }
    } elseif ($periodo == 'I' || $periodo == 'X') {
      // scrutinio integrativo
      $dati['scrutinio'] = $this->em->getRepository('App:Scrutinio')->findOneBy(['classe' => $classe,
        'periodo' => $periodo, 'stato' => 'C']);
      $dati['classe'] = $classe;
      $dati['alunno'] = $alunno;
      // legge esito
      $dati['esito'] = $this->em->getRepository('App:Esito')->createQueryBuilder('e')
        ->where('e.alunno=:alunno AND e.scrutinio=:scrutinio')
        ->setParameters(['alunno' => $alunno, 'scrutinio' => $dati['scrutinio']])
        ->setMaxResults(1)
        ->getQuery()
        ->getOneOrNullResult();
      // legge materie
      $materie = $this->em->getRepository('App:Materia')->createQueryBuilder('m')
        ->select('DISTINCT m.id,m.nome,m.nomeBreve,m.tipo')
        ->join('App:Cattedra', 'c', 'WITH', 'c.materia=m.id')
        ->where('c.classe=:classe AND c.attiva=:attiva AND c.tipo=:tipo AND m.tipo!=:sostegno')
        ->orderBy('m.ordinamento', 'ASC')
        ->setParameters(['classe' => $classe, 'attiva' => 1, 'tipo' => 'N', 'sostegno' => 'S'])
        ->getQuery()
        ->getArrayResult();
      foreach ($materie as $mat) {
        $dati['materie'][$mat['id']] = $mat;
      }
      $condotta = $this->em->getRepository('App:Materia')->findOneByTipo('C');
      $dati['materie'][$condotta->getId()] = array(
        'id' => $condotta->getId(),
        'nome' => $condotta->getNome(),
        'nomeBreve' => $condotta->getNomeBreve(),
        'tipo' => $condotta->getTipo());
      // legge i voti
      $voti = $this->em->getRepository('App:VotoScrutinio')->createQueryBuilder('vs')
        ->where('vs.scrutinio=:scrutinio AND vs.alunno=:alunno')
        ->setParameters(['scrutinio' => $dati['scrutinio'], 'alunno' => $alunno])
        ->getQuery()
        ->getResult();
      foreach ($voti as $v) {
        // inserisce voti/assenze
        $dati['voti'][$v->getMateria()->getId()] = array(
          'id' => $v->getId(),
          'unico' => $v->getUnico(),
          'assenze' => $v->getAssenze());
      }
    }
    // restituisce dati
    return $dati;
  }

  /**
   * Crea la comunicazione per i non ammessi come documento PDF
   *
   * @param TCPDF $pdf Gestore del documento PDF
   * @param string $classe Nome della classe
   * @param string $classe_completa Nome della classe con corso e sede
   * @param array $dati Dati dello scrutinio
   */
  public function creaNonAmmesso_F($pdf, $classe, $classe_completa, $dati) {
    $info_voti['N'] = [0 => 'Non Classificato', 1 => '1', 2 => '2', 3 => '3', 4 => '4', 5 => '5', 6 => '6', 7 => '7', 8 => '8', 9 => '9', 10 => '10'];
    $info_voti['C'] = [4 => 'Non Classificato', 5 => '5', 6 => '6', 7 => '7', 8 => '8', 9 => '9', 10 => '10'];
    $info_voti['R'] = [20 => 'Non Classificato', 21 => 'Insufficiente', 22 => 'Sufficiente', 23 => 'Discreto', 24 => 'Buono', 25 => 'Distinto', 26 => 'Ottimo'];
    $datascrutinio = $dati['scrutinio']->getData()->format('d/m/Y');
    // set margins
    $pdf->SetMargins(10, 15, 10, true);
    // set auto page breaks
    $pdf->SetAutoPageBreak(true, 5);
    // set font
    $pdf->SetFont('times', '', 12);
    // inizio pagina
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->AddPage('P');
    // intestazione pagina
    $html = '<img src="/img/'.getenv('LOCAL_PATH').'intestazione-documenti.jpg" width="540">';
    $pdf->writeHTML($html, true, false, false, false, 'C');
    $alunno_nome = $dati['alunno']->getCognome().' '.$dati['alunno']->getNome();
    $alunno_sesso = $dati['alunno']->getSesso();
    $sex = ($alunno_sesso == 'M' ? 'o' : 'a');
    $pdf->Ln(10);
    $pdf->SetFont('times', 'I', 12);
    $text = 'Ai genitori dell\'alunn'.($alunno_sesso == 'M' ? 'o' : 'a');
    $this->cella($pdf, 0, 0, 100, 0, $text, 0, 'L', 'T');
    $pdf->Ln();
    $pdf->SetFont('times', '', 12);
    $text = strtoupper($alunno_nome);
    $this->cella($pdf, 0, 0, 100, 0, $text, 0, 'L', 'T');
    $pdf->Ln();
    $text = 'Classe '.$classe;
    $this->cella($pdf, 0, 0, 100, 0, $text, 0, 'L', 'T');
    $pdf->Ln(15);
    // oggetto
    $pdf->SetFont('times', 'B', 12);
    $as = $this->session->get('/CONFIG/SCUOLA/anno_scolastico');
    $text = 'OGGETTO: Comunicazione esito dello scrutinio finale - Anno Scolastico '.$as.'.';
    $this->cella($pdf, 0, 0, 0, 0, $text, 0, 'L', 'T');
    $pdf->Ln(10);
    $pdf->SetFont('times', '', 12);
    if ($dati['tipo'] == 'C') {
      // non scrutinato per cessata frequenza
      $html = '<p align="justify">Si comunica che il Consiglio di Classe, nella fase preliminare delle operazioni dello scrutinio del '.$datascrutinio.','.
              ' avendo constatato che l’alunn'.$sex.' '.$alunno_nome.' ha cessato di frequentare entro il 15 marzo,'.
              ' l'.$sex.' ha dichiarat'.$sex.', ai sensi del R.D. 653/25, ritirat'.$sex.' d\'ufficio e di conseguenza <b>non ha proceduto al suo scrutinio</b>.'.
              '</p>';
      $pdf->writeHTML($html, true, false, false, true);
      $pdf->Ln(20);
    } elseif ($dati['tipo'] == 'A') {
      // non scrutinabile per assenze
      $html = '<p align="justify">Si comunica che il Consiglio di Classe, nella fase preliminare delle operazioni dello scrutinio del '.$datascrutinio.','.
              ' avendo constatato che l’alunn'.$sex.' '.$alunno_nome.
              ' ha superato il numero massimo di assenze previsto dalla normativa in vigore,'.
              ' ha deliberato, ai sensi dell’ art. 14 comma 7 del D.P.R. 122 del 22 giugno 2009,'.
              ' <b>l’esclusione dell\'alunn'.$sex.' dallo scrutinio e la sua NON AMMISSIONE '.
              ($dati['classe']->getAnno() == 5 ? 'all\'Esame di Stato' : 'alla classe successiva').
              '</b>.</p>';
      $pdf->writeHTML($html, true, false, false, true);
      $pdf->Ln(20);
    } elseif ($dati['tipo'] == 'N')  {
      // non ammesso per scrutinio
      $html = '<p align="justify">Si comunica che il Consiglio di Classe, nello scrutinio del '.$datascrutinio.','.
              ' ha deliberato la <b>NON AMMISSIONE '.($dati['classe']->getAnno() == 5 ? 'all\'Esame di Stato' : 'alla classe successiva').'</b>'.
              ' dell\'alunn'.$sex.' '.$alunno_nome.', con la seguente motivazione:</p>';
      $pdf->writeHTML($html, true, false, false, true);
      $html = '<p align="justify"><i>'.htmlentities($dati['esito']->getDati()['giudizio']).'</i></p>';
      $pdf->writeHTMLCell(186, 0, $pdf->GetX()+2, $pdf->GetY(), $html, 0, 1);
      //-- $html = '<p align="justify">Il Coordinatore di Classe sarà disponibile a fornire ulteriori chiarimenti previo appuntamento telefonico.</p>';
      //-- $pdf->writeHTMLCell(0, 0, $pdf->GetX(), $pdf->GetY()+2, $html, 0, 1);
      $html = '<p align="justify">Di seguito viene riportato il riepilogo dei voti attribuiti durante lo scrutinio:</p>';
      $pdf->writeHTMLCell(0, 0, $pdf->GetX(), $pdf->GetY()+2, $html, 0, 1);
      // voti
      $html = '<table border="1" cellpadding="3">';
      $html .= '<tr><td width="60%"><strong>MATERIA</strong></td><td width="20%"><strong>VOTO</strong></td><td width="20%"><strong>ORE DI ASSENZA</strong></td></tr>';
      foreach ($dati['materie'] as $idmateria=>$mat) {
        $html .= '<tr><td align="left"><strong>'.$mat['nome'].'</strong></td>';
        $voto = '';
        $assenze = '';
        if ($mat['tipo'] == 'R') {
          if ($dati['alunno']->getReligione() == 'S') {
            // si avvale
            $voto = $info_voti['R'][$dati['voti'][$idmateria]['unico']];
            $assenze = $dati['voti'][$idmateria]['assenze'];
          } else {
            // N.A.
            $voto = '///';
          }
        } elseif ($mat['tipo'] == 'C') {
          // condotta
          $voto = $info_voti['C'][$dati['voti'][$idmateria]['unico']];
        } elseif ($mat['tipo'] == 'N') {
          // altre
          $voto = $info_voti['N'][$dati['voti'][$idmateria]['unico']];
          $assenze = $dati['voti'][$idmateria]['assenze'];
        }
        $html .= "<td>$voto</td><td>$assenze</td></tr>";
      }
      $html .= '</table>';
      $pdf->SetFont('helvetica', '', 10);
      $pdf->writeHTML($html, true, false, false, true, 'C');
      $pdf->Ln(10);
    }
    // firma
    $pdf->SetFont('times', '', 12);
    $html = 'Cagliari, '.$datascrutinio.'.';
    $pdf->writeHTMLCell(100, 0, $pdf->GetX(), $pdf->GetY(), $html, 0, 0);
    $preside = $this->session->get('/CONFIG/ISTITUTO/firma_preside');
    $html = 'Il Dirigente Scolastico<br><i>'.$preside.'</i>';
    $pdf->writeHTMLCell(100, 0, $pdf->GetX(), $pdf->GetY(), $html, 0, 1, false, true, 'C');
  }

  /**
   * Crea il foglio dei debiti come documento PDF
   *
   * @param TCPDF $pdf Gestore del documento PDF
   * @param string $classe Nome della classe
   * @param string $classe_completa Nome della classe con corso e sede
   * @param array $dati Dati dello scrutinio
   */
  public function creaDebiti_F($pdf, $classe, $classe_completa, $dati) {
    // set margins
    $pdf->SetMargins(10, 15, 10, true);
    // set auto page breaks
    $pdf->SetAutoPageBreak(true, 5);
    // set font
    $pdf->SetFont('times', '', 12);
    // inizio pagina
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->AddPage('P');
    // logo
    $html = '<img src="/img/'.getenv('LOCAL_PATH').'intestazione-documenti.jpg" width="540">';
    $pdf->writeHTML($html, true, false, false, false, 'C');
    // intestazione
    $datascrutinio = $dati['scrutinio']->getData()->format('d/m/Y');
    $alunno_nome = $dati['alunno']->getCognome().' '.$dati['alunno']->getNome();
    $alunno_sesso = $dati['alunno']->getSesso();
    $sex = ($alunno_sesso == 'M' ? 'o' : 'a');
    $pdf->Ln(10);
    $pdf->SetFont('times', 'I', 12);
    $text = 'Ai genitori dell\'alunn'.($alunno_sesso == 'M' ? 'o' : 'a');
    $this->cella($pdf, 0, 0, 100, 0, $text, 0, 'L', 'T');
    $pdf->Ln();
    $pdf->SetFont('times', '', 12);
    $text = strtoupper($alunno_nome);
    $this->cella($pdf, 0, 0, 100, 0, $text, 0, 'L', 'T');
    $pdf->Ln();
    $text = 'Classe '.$classe;
    $this->cella($pdf, 0, 0, 100, 0, $text, 0, 'L', 'T');
    $pdf->Ln(15);
    // oggetto
    $pdf->SetFont('times', 'B', 12);
    $text = 'OGGETTO:';
    $this->cella($pdf, 26, 0, 0, 0, $text, 0, 'L', 'T');
    $as = $this->session->get('/CONFIG/SCUOLA/anno_scolastico');
    $text = 'Comunicazione debito formativo allo scrutinio finale - Anno Scolastico '.$as.'.';
    $this->cella($pdf, 0, 0, 0, 0, $text, 0, 'L', 'T');
    $pdf->Ln(10);
    // contenuto
    $pdf->SetFont('times', '', 12);
    $sex = ($alunno_sesso == 'M' ? 'o' : 'a');
    $html = '<p align="justify">Si comunica che il Consiglio di Classe, avendo accertato nel corso dello scrutinio del '.$datascrutinio.' la presenza di un debito formativo per l\'alunn'.$sex.' '.$alunno_nome.', ne ha deliberato la <b>SOSPENSIONE DEL GIUDIZIO</b>, ai sensi dell\'art. 4 comma 6 del D.P.R. 122 del 2009.<br>'.
            'Si riporta nel prospetto seguente il dettaglio del debito formativo dell\'alunn'.$sex.' e la modalità consigliata per il recupero.</p>';
    $pdf->writeHTMLCell(0, 0, $pdf->GetX(), $pdf->GetY(), $html, 0 ,1);
    $html = '<table border="1" cellpadding="3">';
    $html .= '<tr><td width="30%"><strong>MATERIA</strong></td><td width="7%"><strong>VOTO</strong></td><td width="50%"><strong>Argomenti da recuperare</strong></td><td width="13%"><strong>Modalità di recupero</strong></td></tr>';
    foreach ($dati['materie'] as $idmateria=>$mat) {
      if (isset($dati['debiti'][$idmateria])) {
        // materia con debito
        $html .= '<tr><td align="left"><strong>'.$mat['nome'].'</strong></td>';
        $voto = ($dati['debiti'][$idmateria]['unico'] == 0 ? 'NC' : $dati['debiti'][$idmateria]['unico']);
        $recupero = $this->trans->trans('label.recupero_'.$dati['debiti'][$idmateria]['recupero']);
        $debito = str_replace(array("\r", "\n"), ' ', $dati['debiti'][$idmateria]['debito']);
        $html .= '<td>'.$voto.'</td><td align="left" style="font-size:9pt">'.$debito.'</td><td>'.$recupero.'</td></tr>';
      }
    }
    $html .= '</table><br>';
    $pdf->SetFont('helvetica', '', 10);
    $pdf->writeHTML($html, true, false, false, true, 'C');
    // altre comunicazioni
    $pdf->SetFont('times', '', 12);
    $html = '<p align="justify">Qualora le famiglie non intendano far frequentare ai propri figli i corsi sopra indicati, dovranno dichiarare che provvederanno personalmente agli interventi di recupero, sollevando l\'Istituto da ogni responsabilità in merito.'.
            ' In ogni caso gli studenti saranno chiamati a sottoporsi alle prove di verifica del superamento del debito formativo indicato in questa comunicazione.</p>';
    $pdf->writeHTMLCell(0, 0, $pdf->GetX(), $pdf->GetY(), $html, 0, 1);
    $html = '<p align="justify">Si ricorda che, ai sensi della normativa vigente, non sarà consentita l\'ammissione alla classe successiva nel caso persista il debito formativo sopra evidenziato.<br></p>';
    $pdf->writeHTMLCell(0, 0, $pdf->GetX(), $pdf->GetY()+2, $html, 0, 1);
    // firma
    $pdf->SetFont('times', '', 12);
    $html = 'Cagliari, '.$dati['scrutinio']->getData()->format('d/m/Y').'.';
    $pdf->writeHTMLCell(100, 0, $pdf->GetX(), $pdf->GetY(), $html, 0, 0);
    $preside = $this->session->get('/CONFIG/ISTITUTO/firma_preside');
    $html = 'Il Dirigente Scolastico<br><i>'.$preside.'</i>';
    $pdf->writeHTMLCell(100, 0, $pdf->GetX(), $pdf->GetY(), $html, 0, 1, false, true, 'C');
  }

  /**
   * Crea la comunicazione delle carenze
   *
   * @param Classe $classe Classe dello scrutinio
   * @param Alunno $alunno Alunno selezionato
   * @param string $periodo Periodo dello scrutinio
   *
   * @return Percorso completo del file da inviare
   */
  public function carenze(Classe $classe, Alunno $alunno, $periodo) {
    // inizializza
    $fs = new Filesystem();
    if ($periodo == 'F') {
      // scrutinio finale
      $percorso = $this->root.'/finale/'.$classe->getAnno().$classe->getSezione();
      if (!$fs->exists($percorso)) {
        // crea directory
        $fs->mkdir($percorso, 0775);
      }
      // nome documento
      $nomefile = $classe->getAnno().$classe->getSezione().'-scrutinio-finale-carenze-'.$alunno->getId().'.pdf';
      if (!$fs->exists($percorso.'/'.$nomefile)) {
        // crea documento
        $this->pdf->configure($this->session->get('/CONFIG/ISTITUTO/intestazione'),
          'Scrutinio Finale - Comunicazione per il recupero autonomo - Alunno '.$alunno->getCognome().' '.$alunno->getNome());
        $dati = $this->carenzeDati($classe, $alunno, $periodo);
        $nome_classe = $classe->getAnno().'ª '.$classe->getSezione();
        $nome_classe_lungo = $nome_classe.' '.$classe->getCorso()->getNomeBreve().' - '.$classe->getSede()->getCitta();
        // controllo alunno
        $scrut = ($dati['scrutinio']->getDato('scrutinabili') == null ? [] :
          array_keys($dati['scrutinio']->getDato('scrutinabili')));
        if (in_array($alunno->getId(), $scrut) && $dati['esito'] && in_array($dati['esito']->getEsito(), ['A', 'S'])) {
          // alunno sospeso/ammesso
          $this->creaCarenze_F($this->pdf->getHandler(), $nome_classe, $nome_classe_lungo, $dati);
        } else {
          // errore
          return null;
        }
        // salva il documento
        $this->pdf->save($percorso.'/'.$nomefile);
      }
      // restituisce nome del file
      return $percorso.'/'.$nomefile;
    }
    // errore
    return null;
  }

  /**
   * Restituisce i dati per creare la comunicazione delle carenze
   *
   * @param Classe $classe Classe dello scrutinio
   * @param Alunno $alunno Alunno selezionato
   * @param string $periodo Periodo dello scrutinio
   *
   * @return Array Dati formattati come array associativo
   */
  public function carenzeDati(Classe $classe, Alunno $alunno, $periodo) {
    $dati = array();
    if ($periodo == 'F') {
      // scrutinio finale
      $dati['scrutinio'] = $this->em->getRepository('App:Scrutinio')->findOneBy(['classe' => $classe,
        'periodo' => $periodo, 'stato' => 'C']);
      $dati['classe'] = $classe;
      $dati['alunno'] = $alunno;
      // legge esito
      $dati['esito'] = $this->em->getRepository('App:Esito')->createQueryBuilder('e')
        ->where('e.alunno=:alunno AND e.scrutinio=:scrutinio')
        ->setParameters(['alunno' => $alunno, 'scrutinio' => $dati['scrutinio']])
        ->setMaxResults(1)
        ->getQuery()
        ->getOneOrNullResult();
      // legge materie
      $materie = $this->em->getRepository('App:Materia')->createQueryBuilder('m')
        ->select('DISTINCT m.id,m.nome,m.nomeBreve,m.tipo')
        ->join('App:Cattedra', 'c', 'WITH', 'c.materia=m.id')
        ->where('c.classe=:classe AND c.attiva=:attiva AND c.tipo=:tipo AND m.tipo!=:sostegno')
        ->orderBy('m.ordinamento', 'ASC')
        ->setParameters(['classe' => $classe, 'attiva' => 1, 'tipo' => 'N', 'sostegno' => 'S'])
        ->getQuery()
        ->getArrayResult();
      foreach ($materie as $mat) {
        $dati['materie'][$mat['id']] = $mat;
      }
      // legge carenze
      $carenze = $this->em->getRepository('App:VotoScrutinio')->createQueryBuilder('vs')
        ->join('App:Esito', 'e', 'WITH', 'e.alunno=vs.alunno AND e.scrutinio=vs.scrutinio')
        ->join('App:PropostaVoto', 'pv', 'WITH', 'pv.alunno=vs.alunno AND pv.materia=vs.materia')
        ->where('vs.alunno=:alunno AND vs.scrutinio=:scrutinio AND e.esito IN (:esiti) AND pv.classe=:classe AND pv.periodo=:periodo AND pv.unico<:suff AND vs.unico>=:suff')
        ->setParameters(['alunno' => $alunno, 'scrutinio' => $dati['scrutinio'], 'esiti' => ['A','S'],
          'classe' => $classe, 'periodo' => $periodo, 'suff' => 6])
        ->getQuery()
        ->getResult();
      foreach ($carenze as $voto) {
        if ($voto->getDebito()) {
          // comunicazione da inviare
          $dati['carenze'][$voto->getMateria()->getId()] = $voto;
        }
      }
    }
    // restituisce dati
    return $dati;
  }

  /**
   * Crea la comunicazione delle carenze come documento PDF
   *
   * @param TCPDF $pdf Gestore del documento PDF
   * @param string $classe Nome della classe
   * @param string $classe_completa Nome della classe con corso e sede
   * @param array $dati Dati dello scrutinio
   */
  public function creaCarenze_F($pdf, $classe, $classe_completa, $dati) {
    $datascrutinio = $dati['scrutinio']->getData()->format('d/m/Y');
    // set margins
    $pdf->SetMargins(10, 15, 10, true);
    // set auto page breaks
    $pdf->SetAutoPageBreak(true, 5);
    // set font
    $pdf->SetFont('times', '', 12);
    // inizio pagina
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->AddPage('P');
    // logo
    $html = '<img src="/img/'.getenv('LOCAL_PATH').'intestazione-documenti.jpg" width="540">';
    $pdf->writeHTML($html, true, false, false, false, 'C');
    // intestazione
    $alunno_nome = $dati['alunno']->getCognome().' '.$dati['alunno']->getNome();
    $alunno_sesso = $dati['alunno']->getSesso();
    $sex = ($alunno_sesso == 'M' ? 'o' : 'a');
    $pdf->Ln(10);
    $pdf->SetFont('times', 'I', 12);
    $text = 'Ai genitori dell\'alunn'.($alunno_sesso == 'M' ? 'o' : 'a');
    $this->cella($pdf, 0, 0, 100, 0, $text, 0, 'L', 'T');
    $pdf->Ln();
    $pdf->SetFont('times', '', 12);
    $text = strtoupper($alunno_nome);
    $this->cella($pdf, 0, 0, 100, 0, $text, 0, 'L', 'T');
    $pdf->Ln();
    $text = 'Classe '.$classe;
    $this->cella($pdf, 0, 0, 100, 0, $text, 0, 'L', 'T');
    $pdf->Ln(15);
    // oggetto
    $pdf->SetFont('times', 'B', 12);
    $text = 'OGGETTO:';
    $this->cella($pdf, 26, 0, 0, 0, $text, 0, 'L', 'T');
    $as = $this->session->get('/CONFIG/SCUOLA/anno_scolastico');
    $text = 'Comunicazione per il recupero autonomo - Anno Scolastico '.$as.'.';
    $this->cella($pdf, 0, 0, 0, 0, $text, 0, 'L', 'T');
    $pdf->Ln(15);
    // contenuto
    $pdf->SetFont('times', '', 12);
    $sex = ($alunno_sesso == 'M' ? 'o' : 'a');
    if ($dati['esito']->getEsito() == 'A') {
      // ammesso
      $html = '<p align="justify">Si comunica che il Consiglio di Classe, nello scrutinio del '.$datascrutinio.', ha deliberato l\'ammissione all\'anno successivo dell\'alunn'.$sex.' '.$alunno_nome.','.
              ' nonostante siano state rilevate alcune carenze nella formazione dell'.$sex.' student'.($sex == 'o' ? 'e' : 'essa').'.<br>'.
              'Il Consiglio ritiene che <b>le lacune</b> evidenziate potranno essere colmate attraverso un autonomo ed adeguato studio individuale durante l’estate, sulla base delle indicazioni fornite nella presente scheda.</p>';
    } elseif ($dati['esito']->getEsito() == 'S') {
      // sospeso
      $html = '<p align="justify">Si comunica che il Consiglio di Classe, nello scrutinio del '.$datascrutinio.', ha deliberato la sospensione del giudizio dell\'alunn'.$sex.' '.$alunno_nome.'.<br>'.
              'Il Consiglio ritiene che vi siano anche <b>delle ulteriori lacune</b> nella preparazione dell'.$sex.' student'.($sex == 'o' ? 'e' : 'essa').' e che queste potranno essere colmate attraverso un autonomo ed adeguato studio individuale durante l’estate, sulla base delle indicazioni fornite nella presente scheda.</p>';
    }
    $pdf->writeHTMLCell(0, 0, $pdf->GetX(), $pdf->GetY(), $html, 0 ,1);
    $html = '<table border="1" cellpadding="3">';
    $html .= '<tr><td width="40%"><strong>MATERIA</strong></td><td width="60%"><strong>Carenze</strong></td></tr>';
    foreach ($dati['materie'] as $idmateria=>$mat) {
      if (isset($dati['carenze'][$idmateria])) {
        // materia con carenze
        $html .= '<tr><td align="left"><strong>'.$mat['nome'].'</strong></td>';
        $debito = str_replace(array("\r", "\n"), ' ', $dati['carenze'][$idmateria]->getDebito());
        $html .= '<td align="left" style="font-size:9pt">'.$debito.'</td></tr>';
      }
    }
    $html .= '</table><br><br>';
    $pdf->SetFont('helvetica', '', 10);
    $pdf->writeHTML($html, true, false, false, true, 'C');
    // firma
    $pdf->SetFont('times', '', 12);
    $html = 'Cagliari, '.$datascrutinio.'.';
    $pdf->writeHTMLCell(100, 0, $pdf->GetX(), $pdf->GetY(), $html, 0, 0);
    $preside = $this->session->get('/CONFIG/ISTITUTO/firma_preside');
    $html = 'Il Dirigente Scolastico<br><i>'.$preside.'</i>';
    $pdf->writeHTMLCell(100, 0, $pdf->GetX(), $pdf->GetY(), $html, 0, 1, false, true, 'C');
  }

  /**
   * Crea la pagella come documento PDF
   *
   * @param TCPDF $pdf Gestore del documento PDF
   * @param string $classe Nome della classe
   * @param string $classe_completa Nome della classe con corso e sede
   * @param array $dati Dati dello scrutinio
   */
  public function creaPagella_F($pdf, $classe, $classe_completa, $dati) {
    $info_voti['N'] = [0 => 'Non Classificato', 1 => '1', 2 => '2', 3 => '3', 4 => '4', 5 => '5', 6 => '6', 7 => '7', 8 => '8', 9 => '9', 10 => '10'];
    $info_voti['C'] = [4 => 'Non Classificato', 5 => '5', 6 => '6', 7 => '7', 8 => '8', 9 => '9', 10 => '10'];
    $info_voti['R'] = [20 => 'Non Classificato', 21 => 'Insufficiente', 22 => 'Sufficiente', 23 => 'Discreto', 24 => 'Buono', 25 => 'Distinto', 26 => 'Ottimo'];
    // set margins
    $pdf->SetMargins(10, 15, 10, true);
    // set auto page breaks
    $pdf->SetAutoPageBreak(true, 5);
    // set font
    $pdf->SetFont('times', '', 12);
    // inizio pagina
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->AddPage('P');
    // logo
    $html = '<img src="/img/'.getenv('LOCAL_PATH').'intestazione-documenti.jpg" width="540">';
    $pdf->writeHTML($html, true, false, false, false, 'C');
    // intestazione
    $alunno = $dati['alunno']->getCognome().' '.$dati['alunno']->getNome();
    $alunno_sesso = $dati['alunno']->getSesso();
    $pdf->Ln(10);
    $pdf->SetFont('times', 'I', 12);
    $text = 'Ai genitori dell\'alunn'.($alunno_sesso == 'M' ? 'o' : 'a');
    $this->cella($pdf, 0, 0, 100, 0, $text, 0, 'L', 'T');
    $pdf->Ln();
    $pdf->SetFont('times', '', 12);
    $text = strtoupper($alunno);
    $this->cella($pdf, 0, 0, 100, 0, $text, 0, 'L', 'T');
    $pdf->Ln();
    $text = 'Classe '.$classe;
    $this->cella($pdf, 0, 0, 100, 0, $text, 0, 'L', 'T');
    $pdf->Ln(15);
    // oggetto
    $pdf->SetFont('times', 'B', 12);
    $as = $this->session->get('/CONFIG/SCUOLA/anno_scolastico');
    $text = 'OGGETTO: Comunicazione dei voti dello scrutinio finale - Anno Scolastico '.$as.'.';
    $this->cella($pdf, 0, 0, 0, 0, $text, 0, 'L', 'T');
    $pdf->Ln(10);
    // contenuto
    $pdf->SetFont('times', '', 12);
    $sex = ($alunno_sesso == 'M' ? 'o' : 'a');
    $html = '<p align="justify">Si comunica che il Consiglio di Classe, nella seduta dello scrutinio finale dell’anno scolastico '.$as.', tenutasi il giorno '.$dati['scrutinio']->getData()->format('d/m/Y').', ha attribuito all\'alunn'.$sex.' '.$alunno.' '.
            'le valutazioni che vengono riportate di seguito:</p>';
    $pdf->writeHTML($html, true, false, false, true);
    $pdf->Ln(5);
    // voti
    $html = '<table border="1" cellpadding="3">';
    $html .= '<tr><td width="60%"><strong>MATERIA</strong></td><td width="20%"><strong>VOTO</strong></td><td width="20%"><strong>ORE DI ASSENZA</strong></td></tr>';
    $num_insuff = 0;
    foreach ($dati['materie'] as $idmateria=>$mat) {
      $html .= '<tr><td align="left"><strong>'.$mat['nome'].'</strong></td>';
      $voto = '';
      $assenze = '';
      if ($mat['tipo'] == 'R') {
        if ($dati['alunno']->getReligione() == 'S') {
          // si avvale
          $voto = $info_voti['R'][$dati['voti'][$idmateria]['unico']];
          $assenze = $dati['voti'][$idmateria]['assenze'];
          if ($dati['voti'][$idmateria]['unico'] < 22) {
            $num_insuff++;
          }
        } else {
          // N.A.
          $voto = '///';
        }
      } elseif ($mat['tipo'] == 'C') {
        // condotta
        $voto = $info_voti['C'][$dati['voti'][$idmateria]['unico']];
      } elseif ($mat['tipo'] == 'N') {
        // altre
        $voto = $info_voti['N'][$dati['voti'][$idmateria]['unico']];
        $assenze = $dati['voti'][$idmateria]['assenze'];
        if ($dati['voti'][$idmateria]['unico'] < 6) {
          $num_insuff++;
        }
      }
      $html .= "<td>$voto</td><td>$assenze</td></tr>";
    }
    $html .= '</table><br>';
    $pdf->SetFont('helvetica', '', 10);
    $pdf->writeHTML($html, true, false, false, true, 'C');
    // credito
    if ($dati['classe']->getAnno() >= 3) {
      $pdf->SetFont('helvetica', '', 10);
      if ($dati['classe']->getAnno() == 3) {
        $html = '<table border="1" cellpadding="3">'.
          '<tr nobr="true"><td width="40%" align="center"><strong>ALUNNO</strong></td><td width="10%" align="center"><strong>Media voti</strong></td><td width="40%" align="center"><strong>Criteri</strong></td><td width="10%" align="center"><strong>Credito</strong></td></tr>';
      } else {
        $html = '<table border="1" cellpadding="3">'.
          '<tr nobr="true"><td width="40%" align="center"><strong>ALUNNO</strong></td><td width="10%" align="center"><strong>Media voti</strong></td><td width="20%" align="center"><strong>Criteri</strong></td><td width="10%" align="center"><strong>Credito</strong></td><td width="10%" align="center"><strong>Credito anni prec.</strong></td><td width="10%" align="center"><strong>Credito totale</strong></td></tr>';
      }
      $html = '<div style="text-align:center"><strong>CREDITO SCOLASTICO</strong></div>'.$html;
      $nome = $dati['alunno']->getCognome().' '.$dati['alunno']->getNome().' ('.$dati['alunno']->getDataNascita()->format('d/m/Y').')';
      $media = number_format($dati['esito']->getMedia(), 2, ',', '');
      $valori = $dati['esito']->getDati();
      // criteri
      $criteri = '';
      foreach ($valori['creditoScolastico'] as $c) {
        $criteri .= '; '.$this->trans->trans('label.criterio_credito_desc_'.$c);
      }
      if (strlen($criteri) <= 2) {
        $criteri = '-----';
      } else {
        $criteri = substr($criteri, 2).'.';
      }
      if ($dati['classe']->getAnno() == 3) {
        $html .= '<tr nobr="true"><td>'.$nome.'</td><td align="center">'.$media.'</td><td style="font-size:9pt">'.$criteri.'</td><td align="center">'. $dati['esito']->getCredito().'</td></tr>';
      } elseif ($dati['classe']->getAnno() == 4) {
        $cred_tot = $dati['esito']->getCredito() + $dati['esito']->getCreditoPrecedente();
        $html .= '<tr nobr="true"><td>'.$nome.'</td><td align="center">'.$media.'</td><td style="font-size:9pt">'.$criteri.'</td><td align="center">'. $dati['esito']->getCredito().'</td><td align="center">'. $dati['esito']->getCreditoPrecedente().'</td><td align="center">'. $cred_tot.'</td></tr>';
      } else {
        // quinta: dati convertiti
        $cred3 = $dati['esito']->getDati()['creditoConvertito3'];
        $cred4 = $dati['esito']->getDati()['creditoConvertito4'];
        $cred_prec = $cred3 + $cred4;
        $cred_tot = $dati['esito']->getCredito() + $cred_prec;
        $html .= '<tr nobr="true"><td>'.$nome.'</td><td align="center">'.$media.'</td><td style="font-size:9pt">'.$criteri.'</td><td align="center">'. $dati['esito']->getCredito().'</td><td align="center">'.$cred_prec.'</td><td align="center">'. $cred_tot.'</td></tr>';
      }
      $html .= '</table><br>';
      $pdf->writeHTML($html, true, false, false, true);
    }
    // PAI
    if ($num_insuff > 0 && $dati['alunno']->getClasse()->getAnno() != 5) {
      $pdf->Ln(2);
      $pdf->SetFont('times', '', 12);
      $html = '<p align="justify">Il Consiglio di Classe, avendo ammesso alla classe successiva l\'alunn'.$sex.' '.$alunno.' con votazioni inferiori a sei decimi, '.
              'predispone il piano di apprendimento individualizzato nel quale sono indicati, per ciascuna disciplina, gli obiettivi di apprendimento '.
              'da conseguire nonché le specifiche strategie per il raggiungimento dei relativi livelli di apprendimento (OM n. 11 del 16/05/2020).</p><br>';
      $pdf->writeHTML($html, true, false, false, true);
    }
    $pdf->Ln(2);
    // firma
    $pdf->SetFont('times', '', 12);
    $html = 'Cagliari, '.$dati['scrutinio']->getData()->format('d/m/Y').'.';
    $pdf->writeHTMLCell(100, 0, $pdf->GetX(), $pdf->GetY(), $html, 0, 0);
    $preside = $this->session->get('/CONFIG/ISTITUTO/firma_preside');
    $html = 'Il Dirigente Scolastico<br><i>'.$preside.'</i>';
    $pdf->writeHTMLCell(100, 0, $pdf->GetX(), $pdf->GetY(), $html, 0, 1, false, true, 'C');
  }

  /**
   * Crea il verbale come documento PDF
   *
   * @param TCPDF $pdf Gestore del documento PDF
   * @param string $classe Nome della classe
   * @param string $classe_completa Nome della classe con corso e sede
   * @param array $dati Dati dello scrutinio
   */
  public function creaVerbale_I($pdf, $classe, $classe_completa, $dati) {
    // set margins
    $pdf->SetMargins(10, 15, 10, true);
    // set auto page breaks
    $pdf->SetAutoPageBreak(true, 15);
    // set font
    $pdf->SetFont('times', '', 12);
    // inizio pagina
    $pdf->setPrintHeader(false);
    $pdf->SetFooterMargin(15);
    $pdf->setFooterFont(array('helvetica', '', 9));
    $pdf->setFooterData(array(0,0,0), array(255,255,255));
    $pdf->setPrintFooter(true);
    $pdf->AddPage('P');
    // logo
    $html = '<img src="/img/'.getenv('LOCAL_PATH').'intestazione-documenti.jpg" width="540">';
    $pdf->writeHTML($html, true, false, false, false, 'C');
    // struttura
    foreach ($dati['definizione']->getStruttura() as $step=>$args) {
      $func = 'CreaVerbale_I_'.$args[0];
      $this->$func($pdf, $classe, $classe_completa, $dati, $step, $args);
    }
  }

  /**
   * Crea il verbale come documento PDF: parte iniziale
   *
   * @param TCPDF $pdf Gestore del documento PDF
   * @param string $classe Nome della classe
   * @param string $classe_completa Nome della classe con corso e sede
   * @param array $dati Dati dello scrutinio
   * @param int $step Passo della struttura del verbale
   * @param array $args Argomenti della struttura del verbale
   */
  public function creaVerbale_I_ScrutinioInizio($pdf, $classe, $classe_completa, $dati, $step, $args) {
    // inizializzazione
    $nome_mesi = ['', 'Gennaio', 'Febbraio', 'Marzo', 'Aprile', 'Maggio', 'Giugno', 'Luglio', 'Agosto', 'Settembre', 'Ottobre', 'Novembre', 'Dicembre'];
    $pdf->Ln(5);
    $pdf->SetFont('times', '', 12);
    $html = '<p align="center"><strong>VERBALE DELLO SCRUTINIO INTEGRATIVO<br>'.
      'CLASSE '.$classe_completa.'</strong></p>';
    $pdf->writeHTML($html, true, false, false, true);
    $pdf->Ln(5);
    // inizio seduta
    $pdf->SetFont('times', '', 11);
    $datascrutinio_giorno = intval($dati['scrutinio']->getData()->format('d'));
    $datascrutinio_mese = $nome_mesi[intval($dati['scrutinio']->getData()->format('m'))];
    $datascrutinio_anno = $dati['scrutinio']->getData()->format('Y');
    $orascrutinio_inizio = $dati['scrutinio']->getInizio()->format('H:i');
    $html = '<p align="justify">Il giorno '.$datascrutinio_giorno.' del mese di '.$datascrutinio_mese.' dell\'anno '.
      $datascrutinio_anno.', alle ore '.$orascrutinio_inizio.', nei locali dell\'<em>'.$this->session->get('/CONFIG/ISTITUTO/intestazione').'</em> di Cagliari, con sede associata in Assemini, si riunisce, a seguito di regolare convocazione, il Consiglio della Classe '.
      $classe.' per discutere il seguente ordine del giorno:</p>';
    $pdf->writeHTML($html, true, false, false, true);
    $html = '<ol>';
    foreach ($dati['definizione']->getArgomenti() as $num=>$arg) {
      $html .='<li align="justify"><strong>'.$arg.(isset($dati['definizione']->getArgomenti()[$num + 1]) ? ';' : '.').'</strong></li>';
    }
    $html .='</ol>';
    $pdf->writeHTML($html, true, false, false, true);
    $pdf->Ln(1);
    if ($dati['scrutinio']->getDato('presiede_ds')) {
      $pres_nome = 'il Dirigente Scolastico';
    } else {
      $d = $dati['docenti'][$dati['scrutinio']->getDato('presiede_docente')][0];
      if ($dati['scrutinio']->getDato('presenze')[$d['id']]->getPresenza()) {
        $pres_nome = 'per delega '.($d['sesso'] == 'M' ? 'il Prof.' : 'la Prof.ssa').' '.
          $d['cognome'].' '.$d['nome'];
      } else {
        $pres_nome = 'per delega '.($dati['scrutinio']->getDato('presenze')[$d['id']]->getSessoSostituto() == 'M' ? 'il Prof.' : 'la Prof.ssa').
          ucwords(strtolower($dati['scrutinio']->getDato('presenze')[$d['id']]->getSostituto()));
      }
    }
    $d = $dati['docenti'][$dati['scrutinio']->getDato('segretario')][0];
    if ($dati['scrutinio']->getDato('presenze')[$d['id']]->getPresenza()) {
      $segr_nome = ($d['sesso'] == 'M' ? 'il Prof.' : 'la Prof.ssa').' '.
        $d['cognome'].' '.$d['nome'];
    } else {
      $segr_nome = ($dati['scrutinio']->getDato('presenze')[$d['id']]->getSessoSostituto() == 'M' ? 'il Prof.' : 'la Prof.ssa').
        ' '.ucwords(strtolower($dati['scrutinio']->getDato('presenze')[$d['id']]->getSostituto()));
    }
    $html = '<p align="justify">Presiede la riunione '.$pres_nome.', funge da segretario verbalizzante '.$segr_nome.'.</p>';
    $pdf->writeHTML($html, true, false, false, true);
    $pdf->Ln(1);
    $html = '<p align="justify">Sono presenti i professori:</p>';
    $pdf->writeHTML($html, true, false, false, true);
    $pdf->Ln(1);
    $pdf->SetFont('helvetica', '', 10);
    $html = '<table border="1" cellpadding="3">
      <tr nobr="true"><td width="40%" align="center"><strong>DOCENTE</strong></td><td width="60%" align="center"><strong>MATERIA</strong></td></tr>';
    $assenti = 0;
    foreach ($dati['scrutinio']->getDato('presenze') as $iddocente=>$doc) {
      if ($doc->getPresenza()) {
        $d = $dati['docenti'][$doc->getDocente()][0];
        $nome = $d['cognome'].' '.$d['nome'];
        $materie = '';
        foreach ($dati['docenti'][$doc->getDocente()] as $km=>$vm) {
          $materie .= '<br>&bull; '.($vm['doc_tipo'] == 'I' ? 'Lab. ' : '').$vm['nome_materia'];
        }
        $html .= '<tr><td>'.$nome.'</td><td>'.substr($materie, 4).'</td></tr>';
      } else {
        $assenti++;
      }
    }
    $html .= '</table>';
    $pdf->writeHTML($html, true, false, false, true);
    $pdf->Ln(2);
    $pdf->SetFont('times', '', 11);
    if ($assenti > 0) {
      $html = '<p align="justify">Sono assenti giustificati i seguenti docenti, surrogati con atto formale del Dirigente Scolastico:</p>';
      $pdf->writeHTML($html, true, false, false, true);
      $html = '<ul>';
      foreach ($dati['scrutinio']->getDato('presenze') as $iddocente=>$doc) {
        if (!$doc->getPresenza()) {
          $assenti--;
          $d = $dati['docenti'][$doc->getDocente()][0];
          $nome = $d['cognome'].' '.$d['nome'];
          $materie = '';
              foreach ($dati['docenti'][$doc->getDocente()] as $km=>$vm) {
                $materie .= ', '.$vm['nome_materia'];
              }
          $text = ($d['sesso'] == 'M' ? 'Prof.' : 'Prof.ssa').' '.$nome.' ('.substr($materie,2).'), '.
            'sostituit'.($d['sesso'] == 'M' ? 'o' : 'a').' dal'.
            ($dati['scrutinio']->getDato('presenze')[$d['id']]->getSessoSostituto() == 'M' ? ' Prof.' : 'la Prof.ssa').
            ' '.ucwords(strtolower($doc->getSostituto()));
          $html .= '<li align="justify">'.$text.($assenti > 0 ? ';' : '.').'</li>';
        }
      }
      $html .= '</ul>';
      $pdf->writeHTML($html, true, false, false, true);
    } else {
      $html = '<p align="justify">Nessuno è assente.</p>';
      $pdf->writeHTML($html, true, false, false, true);
    }
    $pdf->Ln(1);
    $html = '<p align="justify">Accertata la legalità della seduta, il presidente dà l\'avvio alle operazioni.</p>';
    $pdf->writeHTML($html, true, false, false, true);
    $pdf->Ln(5);
  }

  /**
   * Crea il verbale come documento PDF: svolgimento scrutinio
   *
   * @param TCPDF $pdf Gestore del documento PDF
   * @param string $classe Nome della classe
   * @param string $classe_completa Nome della classe con corso e sede
   * @param array $dati Dati dello scrutinio
   * @param int $step Passo della struttura del verbale
   * @param array $args Argomenti della struttura del verbale
   */
  public function CreaVerbale_I_ScrutinioSvolgimento($pdf, $classe, $classe_completa, $dati, $step, $args) {
    $pdf->SetFont('times', '', 11);
    $num_arg = $args[2]['argomento'];
    $argomento = $dati['definizione']->getArgomenti()[$num_arg];
    $html = '<p align="justify"><strong>'.$args[2]['sezione'].'. '.$argomento.'.</strong></p>';
    $pdf->writeHTML($html, true, false, false, true);
    $pdf->Ln(1);
    // indicazioni
    $html = '<p align="justify">Prima di dare inizio alle operazioni di scrutinio, in ottemperanza a quanto previsto dalle norme vigenti e in base ai criteri di valutazione stabiliti dal Collegio dei Docenti e inseriti nel PTOF, il presidente ricorda che:</p>';
    $pdf->writeHTML($html, true, false, false, true);
    $html = '<ul>'.
      '<li align="justify">tutti i presenti sono tenuti all\'obbligo della stretta osservanza del segreto d\'ufficio e che l\'eventuale violazione comporta sanzioni disciplinari;</li>'.
      '<li align="justify">l’O.M. 92/2007, in merito all’integrazione dello scrutinio finale, indica di considerare la “valutazione complessiva dello studente” tenendo conto dei risultati conseguiti “non soltanto in sede di accertamento finale, ma anche nelle varie fasi dell’intero percorso delle attività di recupero”.</li>'.
      '</ul>';
    $pdf->writeHTML($html, true, false, false, true);
    $pdf->Ln(1);
    // valutazione
    $pdf->SetFont('times', '', 11);
    $html = '<p align="justify">Si passa, quindi, seguendo l\'ordine alfabetico, alla valutazione di ogni singolo alunno, tenuto conto degli indicatori precedentemente espressi. '.
      'Per ciascuna disciplina il docente competente esprime il proprio giudizio complessivo sull\'alunno. Ciascun giudizio è tradotto coerentemente in un voto, che viene proposto al Consiglio di Classe. '.
      'Il Consiglio di Classe discute esaurientemente le proposte espresse dai docenti e, tenuti ben presenti i parametri di valutazione deliberati, procede alla definizione e all\'approvazione dei voti per ciascun alunno e per ciascuna disciplina.</p>';
    $pdf->writeHTML($html, true, false, false, true);
    // ammessi
    $num_bocciati = 0;
    $num_ammessi = 0;
    $num_rinviati = 0;
    foreach ($dati['alunni'] as $idalunno=>$alu) {
      if ($dati['esiti'][$idalunno]->getEsito() == 'N') {
        // bocciati
        $num_bocciati++;
      } elseif ($dati['esiti'][$idalunno]->getEsito() == 'A') {
        // ammessi
        $num_ammessi++;
      } elseif ($dati['esiti'][$idalunno]->getEsito() == 'X') {
        // ammessi
        $num_rinviati++;
      } else {
        // errore
        throw new NotFoundHttpException('exception.invalid_params');
      }
    }
    if ($num_ammessi > 0) {
      $pdf->Ln(5);
      $html = '<p align="justify"><b><i>Il Consiglio di Classe dichiara ammessi</i></b>'.
        ' alla classe successiva, per avere riportato almeno sei decimi in ciascuna disciplina, i seguenti alunni:</p>';
      $pdf->writeHTML($html, true, false, false, true);
      $pdf->Ln(1);
      $pdf->SetFont('helvetica', '', 10);
      $html = '<table border="1" cellpadding="3">
        <tr nobr="true"><td width="40%" align="center"><strong>ALUNNO</strong></td><td width="60%" align="center"><strong>Delibera</strong></td></tr>';
      foreach ($dati['alunni'] as $idalunno=>$alu) {
        if ($dati['esiti'][$idalunno]->getEsito() == 'A') {
          // ammessi
          $valori = $dati['esiti'][$idalunno]->getDati();
          $nome = $alu['cognome'].' '.$alu['nome'].' ('.$alu['dataNascita']->format('d/m/Y').')';
          if ($valori['unanimita']) {
            $esito_approvazione = 'UNANIMITÀ';
          } else {
            $esito_approvazione = "MAGGIORANZA<br>Contrari: ".$valori['contrari'];
          }
          $esito_giudizio = trim(str_replace(array("\r","\n"), ' ', $valori['giudizio']));
          if ($esito_giudizio) {
            // aggiunge motivazione
            $esito_approvazione .= '<div align="left" style="font-size:9pt">Motivazione: <i>'.$esito_giudizio.'</i></div>';
          }
          $html .= '<tr nobr="true"><td><b>'.$nome.'</b></td><td align="center">'.$esito_approvazione.'</td></tr>';
        }
      }
      $html .= '</table>';
      $pdf->writeHTML($html, true, false, false, true);
      $pdf->Ln(2);
      // solo triennio: crediti
      if ($dati['classe']->getAnno() >= 3) {
        $pdf->SetFont('times', '', 11);
        $html = '<p align="justify">Contestualmente alla definizione dei voti, il Consiglio di Classe determina per ciascun alunno il relativo credito scolastico, secondo la nuova tabella di attribuzione del punteggio (ai sensi dell\'art. 15 del d.lgs. 62/2017).</p>';
        $pdf->writeHTML($html, true, false, false, true);
        $pdf->Ln(3);
        $pdf->SetFont('helvetica', '', 10);
        $html = '<div align="center"><b>Tabella Crediti Scolastici</b></div>'.
          '<table border="1" cellpadding="3">'.
          '<tr nobr="true"><td width="25%" align="center"><strong>Media dei voti (M)</strong></td><td width="25%" align="center"><strong>Punti di credito scolastico per la classe terza</strong></td><td width="25%" align="center"><strong>Punti di credito scolastico per la classe quarta</strong></td><td width="25%" align="center"><strong>Punti di credito scolastico per la classe quinta</strong></td></tr>'.
          '<tr nobr="true"><td align="center">M &lt; 6</td><td align="center">-</td><td align="center">-</td><td align="center">7 - 8</td></tr>'.
          '<tr nobr="true"><td align="center">M = 6</td><td align="center">7 - 8</td><td align="center">8 - 9</td><td align="center">9 - 10</td></tr>'.
          '<tr nobr="true"><td align="center">6 &lt; M &lt;= 7</td><td align="center">8 - 9</td><td align="center">9 - 10</td><td align="center">10 - 11</td></tr>'.
          '<tr nobr="true"><td align="center">7 &lt; M &lt;= 8</td><td align="center">9 - 10</td><td align="center">10 - 11</td><td align="center">11 - 12</td></tr>'.
          '<tr nobr="true"><td align="center">8 &lt; M &lt;= 9</td><td align="center">10 - 11</td><td align="center">11 - 12</td><td align="center">13 - 14</td></tr>'.
          '<tr nobr="true"><td align="center">9 &lt; M &lt;= 10</td><td align="center">11 - 12</td><td align="center">12 - 13</td><td align="center">14 - 15</td></tr>'.
          '</table>';
        $pdf->writeHTML($html, true, false, false, true);
        $pdf->Ln(3);
        $pdf->SetFont('times', '', 11);
        $html = '<p align="justify">Il Consiglio di Classe attribuisce il seguente credito scolastico agli alunni:</p>';
        $pdf->writeHTML($html, true, false, false, true);
        $pdf->Ln(1);
        $pdf->SetFont('helvetica', '', 10);
        if ($dati['classe']->getAnno() == 3) {
          $html = '<table border="1" cellpadding="3">'.
            '<tr nobr="true"><td width="40%" align="center"><strong>ALUNNO</strong></td><td width="10%" align="center"><strong>Media voti</strong></td><td width="40%" align="center"><strong>Criteri</strong></td><td width="10%" align="center"><strong>Credito</strong></td></tr>';
        } else {
          $html = '<table border="1" cellpadding="3">'.
            '<tr nobr="true"><td width="40%" align="center"><strong>ALUNNO</strong></td><td width="10%" align="center"><strong>Media voti</strong></td><td width="20%" align="center"><strong>Criteri</strong></td><td width="10%" align="center"><strong>Credito</strong></td><td width="10%" align="center"><strong>Credito anni prec.</strong></td><td width="10%" align="center"><strong>Credito totale</strong></td></tr>';
        }
        foreach ($dati['alunni'] as $idalunno=>$alu) {
          if ($dati['esiti'][$idalunno]->getEsito() == 'A') {
            // solo alunni ammessi
            $nome = $alu['cognome'].' '.$alu['nome'].' ('.$alu['dataNascita']->format('d/m/Y').')';
            $esito = $dati['esiti'][$idalunno];
            $media = number_format($esito->getMedia(), 2, ',', '');
            $valori = $esito->getDati();
            // criteri
            $criteri = '';
            foreach ($valori['creditoScolastico'] as $c) {
              $criteri .= '; '.$this->trans->trans('label.criterio_credito_desc_'.$c);
            }
            if (strlen($criteri) <= 2) {
              $criteri = '-----';
            } else {
              $criteri = substr($criteri, 2).'.';
            }
            if ($dati['classe']->getAnno() == 3) {
              $html .= '<tr nobr="true"><td>'.$nome.'</td><td align="center">'.$media.'</td><td style="font-size:9pt">'.$criteri.'</td><td align="center">'. $esito->getCredito().'</td></tr>';
            } else {
              $cred_tot = $esito->getCredito() + $esito->getCreditoPrecedente();
              $html .= '<tr nobr="true"><td>'.$nome.'</td><td align="center">'.$media.'</td><td style="font-size:9pt">'.$criteri.'</td><td align="center">'. $esito->getCredito().'</td><td align="center">'. $esito->getCreditoPrecedente().'</td><td align="center">'. $cred_tot.'</td></tr>';
            }
          }
        }
        $html .= '</table>';
        $pdf->writeHTML($html, true, false, false, true);
        $pdf->Ln(2);
      } elseif ($dati['classe']->getAnno() == 2) {
        // certificazione competenze
        $pdf->SetFont('times', '', 11);
        $html = '<p align="justify">Contestualmente alla definizione dei voti, il Consiglio di Classe certifica le competenze di base acquisite dagli studenti (ai sensi del D.M. n.139 del 22 agosto 2007).</p>';
        $pdf->writeHTML($html, true, false, false, true);
        $pdf->Ln(1);
      }
    }
    // non ammessi
    if ($num_bocciati > 0) {
      $pdf->Ln(5);
      $pdf->SetFont('times', '', 11);
      $html = '<p align="justify"><b><i>Il Consiglio di Classe</i></b></p>';
      $pdf->writeHTML($html, true, false, false, true);
      $pdf->Ln(1);
      $html = '<ul>'.
        '<li>tenuto conto degli obiettivi generali e specifici previsti nella programmazione iniziale;</li>'.
        '<li>considerati tutti gli elementi che concorrono alla valutazione finale: interesse, partecipazione, metodo di studio, impegno;</li>'.
        '<li>valutati gli obiettivi minimi previsti per le singole discipline: conoscenze degli argomenti, proprietà espressiva, capacità di analisi, applicazione, capacità di giudizio autonomo;</li>'.
        '<li>preso atto della gravità delle carenze accertate nelle diverse discipline;</li>'.
        '</ul>';
      $pdf->writeHTML($html, true, false, false, true);
      $pdf->Ln(1);
      $html = '<p align="justify"><b><i>dichiara non ammessi</i></b> alla classe successiva i seguenti alunni:</p>';
      $pdf->writeHTML($html, true, false, false, true);
      $pdf->Ln(1);
      $pdf->SetFont('helvetica', '', 10);
      $html = '<table border="1" cellpadding="3">'.
        '<tr nobr="true"><td width="40%" align="center"><strong>ALUNNO</strong></td><td width="16%" align="center"><strong>Delibera</strong></td><td width="44%" align="center"><strong>Motivazione della non ammissione</strong></td></tr>';
      foreach ($dati['alunni'] as $idalunno=>$alu) {
        if ($dati['esiti'][$idalunno]->getEsito() == 'N') {
          // solo alunni non ammessi
          $nome = $alu['cognome'].' '.$alu['nome'].' ('.$alu['dataNascita']->format('d/m/Y').')';
          $valori = $dati['esiti'][$idalunno]->getDati();
          if ($valori['unanimita']) {
            $esito_approvazione = 'UNANIMITÀ';
          } else {
            $esito_approvazione = "MAGGIORANZA\nContrari: ".$valori['contrari'];
          }
          $esito_giudizio = str_replace(array("\r","\n"), ' ', $valori['giudizio']);
          $html .= '<tr nobr="true"><td><strong>'.$nome.'</strong></td><td align="center" style="font-size:9pt">'.$esito_approvazione.'</td><td style="font-size:9pt">'.$esito_giudizio.'</td></tr>';
        }
      }
      $html .= '</table>';
      $pdf->writeHTML($html, true, false, false, true);
      $pdf->Ln(2);
    }
    // scrutini rinviati
    if ($num_rinviati > 0) {
      $pdf->Ln(5);
      $pdf->SetFont('times', '', 11);
      $html = '<p align="justify"><b><i>Il Consiglio di Classe rinvia a data da definirsi</i></b> lo scrutinio dei seguenti alunni:</p>';
      $pdf->writeHTML($html, true, false, false, true);
      $pdf->Ln(1);
      $pdf->SetFont('helvetica', '', 10);
      $html = '<table border="1" cellpadding="3">'.
        '<tr nobr="true"><td width="40%" align="center"><strong>ALUNNO</strong></td><td width="60%" align="center"><strong>Motivazione</strong></td></tr>';
      foreach ($dati['alunni'] as $idalunno=>$alu) {
        if ($dati['esiti'][$idalunno]->getEsito() == 'X') {
          // solo alunni con scrutinio rinviato
          $nome = $alu['cognome'].' '.$alu['nome'].' ('.$alu['dataNascita']->format('d/m/Y').')';
          $valori = $dati['esiti'][$idalunno]->getDati();
          $esito_giudizio = str_replace(array("\r","\n"), ' ', $valori['giudizio']);
          $html .= '<tr nobr="true"><td><strong>'.$nome.'</strong></td><td style="font-size:9pt">'.$esito_giudizio.'</td></tr>';
        }
      }
      $html .= '</table>';
      $pdf->writeHTML($html, true, false, false, true);
      $pdf->Ln(2);
    }
    // riepilogo
    $pdf->Ln(5);
    $pdf->SetFont('times', '', 11);
    $html = '<p align="justify">Terminata la fase deliberativa, si procede, a cura del coordinatore, alla stampa dei tabelloni e alla firma del Registro Generale dei voti.</p>';
    $pdf->writeHTML($html, true, false, false, true);
    $pdf->Ln(1);
    $html = '<p>Il riepilogo dei voti deliberati per ciascun alunno e ciascuna disciplina viene allegato al presente verbale, di cui fa parte integrante.</p>';
    $pdf->writeHTML($html, true, false, false, true);
    $pdf->Ln(1);
    $html = '<p align="justify">I risultati complessivi dello scrutinio integrativo della classe '.$classe.' vengono così riassunti:</p>';
    $pdf->writeHTML($html, true, false, false, true);
    $pdf->SetFont('helvetica', '', 10);
    $num_tot = count($dati['alunni']);
    $html = '<table border="0" cellpadding="1" width="90%">'.
      '<tr nobr="true"><td width="70%" align="right"><b>Alunni con giudizio sospeso:</b></td><td width="30%">'.$num_tot.'</td></tr>'.
      '<tr nobr="true"><td width="70%" align="right"><b>AMMESSI:</b></td><td width="30%">'.$num_ammessi.'</td></tr>'.
      '<tr nobr="true"><td width="70%" align="right"><b>NON AMMESSI:</b></td><td width="30%">'.$num_bocciati.'</td></tr>'.
      ($num_rinviati > 0 ? '<tr nobr="true"><td width="70%" align="right"><b>SCRUTINIO RINVIATO AD ALTRA DATA:</b></td><td width="30%">'.$num_rinviati.'</td></tr>' : '').
      '</table>';
    $pdf->writeHTML($html, true, false, false, true);
    $pdf->Ln(5);
  }

  /**
   * Crea il verbale come documento PDF: comunicazione esiti
   *
   * @param TCPDF $pdf Gestore del documento PDF
   * @param string $classe Nome della classe
   * @param string $classe_completa Nome della classe con corso e sede
   * @param array $dati Dati dello scrutinio
   * @param int $step Passo della struttura del verbale
   * @param array $args Argomenti della struttura del verbale
   */
  public function CreaVerbale_I_ScrutinioComunicazioni($pdf, $classe, $classe_completa, $dati, $step, $args) {
    $pdf->SetFont('times', '', 11);
    $num_arg = $args[2]['argomento'];
    $argomento = $dati['definizione']->getArgomenti()[$num_arg];
    $html = '<p align="justify"><strong>'.$args[2]['sezione'].'. '.$argomento.'.</strong></p>';
    $pdf->writeHTML($html, true, false, false, true);
    $pdf->Ln(1);
    $html = '<p align="justify">Il Dirigente Scolastico fa presente che il Consiglio di Classe, prima della pubblicazione dei risultati, deve dare comunicazione dell’esito di non ammissione alle famiglie degli alunni minorenni, mediante fonogramma registrato.</p>';
    $pdf->writeHTML($html, true, false, false, true);
    $pdf->Ln(1);
    $html = '<p align="justify">Il Consiglio di Classe predispone quindi le comunicazioni da inviare alle famiglie a riguardo dell\'esito dello scrutinio. Le famiglie potranno visualizzare queste comunicazioni direttamente sul registro elettronico.</p>';
    $pdf->writeHTML($html, true, false, false, true);
    $pdf->Ln(1);
  }

  /**
   * Crea il verbale come documento PDF: fine scrutinio
   *
   * @param TCPDF $pdf Gestore del documento PDF
   * @param string $classe Nome della classe
   * @param string $classe_completa Nome della classe con corso e sede
   * @param array $dati Dati dello scrutinio
   * @param int $step Passo della struttura del verbale
   * @param array $args Argomenti della struttura del verbale
   */
  public function CreaVerbale_I_ScrutinioFine($pdf, $classe, $classe_completa, $dati, $step, $args) {
    $pdf->Ln(5);
    $pdf->SetFont('times', '', 11);
    $orascrutinio_fine = $dati['scrutinio']->getFine()->format('H:i');
    $html = '<p align="justify">Alle ore '.$orascrutinio_fine.', terminate tutte le operazioni, la seduta è tolta.</p>';
    $pdf->writeHTML($html, true, false, false, true);
    $pdf->Ln(10);
    // firma
    if ($dati['scrutinio']->getDato('presiede_ds')) {
      $presidente_nome = $this->session->get('/CONFIG/ISTITUTO/firma_preside');
    } else {
      $d = $dati['docenti'][$dati['scrutinio']->getDato('presiede_docente')][0];
      if ($dati['scrutinio']->getDato('presenze')[$d['id']]->getPresenza()) {
        $presidente_nome = ($d['sesso'] == 'M' ? 'Prof.' : 'Prof.ssa').' '.
          $d['cognome'].' '.$d['nome'];
      } else {
        $presidente_nome = ($dati['scrutinio']->getDato('presenze')[$d['id']]->getSessoSostituto() == 'M' ? 'Prof.' : 'Prof.ssa').' '.
          ucwords(strtolower($dati['scrutinio']->getDato('presenze')[$d['id']]->getSostituto()));
      }
    }
    $d = $dati['docenti'][$dati['scrutinio']->getDato('segretario')][0];
    if ($dati['scrutinio']->getDato('presenze')[$d['id']]->getPresenza()) {
      $segretario_nome = ($d['sesso'] == 'M' ? 'Prof.' : 'Prof.ssa').' '.
        $d['cognome'].' '.$d['nome'];
    } else {
      $segretario_nome = ($dati['scrutinio']->getDato('presenze')[$d['id']]->getSessoSostituto() == 'M' ? 'Prof.' : 'Prof.ssa').' '.
        ucwords(strtolower($dati['scrutinio']->getDato('presenze')[$d['id']]->getSostituto()));
    }
    $html = '<table border="0" cellpadding="3" nobr="true">
      <tr nobr="true"><td width="45%" align="center">Il Segretario</td><td width="10%">&nbsp;</td><td width="45%" align="center">Il Presidente</td></tr>
      <tr nobr="true"><td align="center"><em>'.$segretario_nome.'</em></td><td>&nbsp;</td><td align="center"><em>'.$presidente_nome.'</em></td></tr>
      </table>';
    $pdf->writeHTML($html, true, false, false, true);
  }

  /**
   * Crea il riepilogo dei voti come documento PDF
   *
   * @param TCPDF $pdf Gestore del documento PDF
   * @param string $classe Nome della classe
   * @param string $classe_completa Nome della classe con corso e sede
   * @param array $dati Dati dello scrutinio
   */
  public function creaRiepilogoVoti_I($pdf, $classe, $classe_completa, $dati) {
    $info_voti['N'] = [0 => 'NC', 1 => '1', 2 => '2', 3 => '3', 4 => '4', 5 => '5', 6 => '6', 7 => '7', 8 => '8', 9 => '9', 10 => '10'];
    $info_voti['C'] = [4 => 'NC', 5 => '5', 6 => '6', 7 => '7', 8 => '8', 9 => '9', 10 => '10'];
    $info_voti['R'] = [20 => 'NC', 21 => 'Insuff.', 22 => 'Suff.', 23 => 'Buono', 24 => 'Dist.', 25 => 'Ottimo'];
    // set margins
    $pdf->SetMargins(10, 15, 10, true);
    // set auto page breaks
    $pdf->SetAutoPageBreak(false, 15);
    // set font
    $pdf->SetFont('helvetica', '', 10);
    // inizio pagina
    $pdf->SetHeaderMargin(12);
    $pdf->SetFooterMargin(12);
    $pdf->setHeaderFont(Array('helvetica', 'B', 6));
    $pdf->setFooterFont(Array('helvetica', '', 8));
    $pdf->setHeaderData('', 0, $this->session->get('/CONFIG/ISTITUTO/intestazione').' - CAGLIARI - ASSEMINI     ***     RIEPILOGO VOTI '.$classe, '', array(0,0,0), array(255,255,255));
    $pdf->setFooterData(array(0,0,0), array(255,255,255));
    $pdf->setPrintHeader(true);
    $pdf->setPrintFooter(true);
    $pdf->AddPage('P');
    // intestazione pagina
    $pdf->SetFont('helvetica', 'B', 10);
    $this->cella($pdf, 15, 5, 0, 2, 'Classe:', 0, 'C', 'B');
    $pdf->SetFont('helvetica', '', 10);
    $this->cella($pdf, 85, 5, 0, 0, $classe_completa, 0, 'L', 'B');
    $pdf->SetFont('helvetica', 'B', 10);
    $this->cella($pdf, 31, 5, 0, 0, 'Anno Scolastico:', 0, 'C', 'B');
    $pdf->SetFont('helvetica', '', 10);
    $as = $this->session->get('/CONFIG/SCUOLA/anno_scolastico');
    $this->cella($pdf, 20, 5, 0, 0, $as, 0, 'L', 'B');
    $pdf->SetFont('helvetica', '', 10);
    $this->cella($pdf, 0, 5, 0, 0, 'SCRUTINIO INTEGRATIVO', 0, 'R', 'B');
    $this->acapo($pdf, 5);
    // intestazione tabella
    $pdf->SetFont('helvetica', 'B', 8);
    $this->cella($pdf, 6, 30, 0, 0, 'Pr.', 1, 'C', 'B');
    $this->cella($pdf, 35, 30, 0, 0, 'Alunno', 1, 'C', 'B');
    $pdf->SetX($pdf->GetX() - 6); // aggiusta prima posizione
    $pdf->StartTransform();
    $pdf->Rotate(90);
    $numrot = 1;
    $etichetterot = array();
    $last_width = 6;
    foreach ($dati['materie'] as $idmateria=>$mat) {
      $text = strtoupper($mat['nomeBreve']);
      if ($mat['tipo'] != 'R') {
        $etichetterot[] = array('nome' => $text, 'dim' => 6);
        $this->cella($pdf, 30, 6, -30, $last_width, $text, 1, 'L', 'M');
        $last_width = 6;
      } else {
        $etichetterot[] = array('nome' => $text, 'dim' => 12);
        $this->cella($pdf, 30, 12, -30, $last_width, $text, 1, 'L', 'M');
        $last_width = 12;
      }
      $numrot++;
    }
    if ($dati['classe']->getAnno() >= 3) {
      // credito
      $etichetterot[] = array('nome' => 'Credito', 'dim' => 6);
      $this->cella($pdf, 30, 6, -30, 6, 'Credito', 1, 'L', 'M');
      $numrot++;
      if ($dati['classe']->getAnno() >= 4) {
        $etichetterot[] = array('nome' => 'Credito Anni Prec.', 'dim' => 6);
        $this->cella($pdf, 30, 6, -30, 6, 'Credito Anni Prec.', 1, 'L', 'M');
        $numrot++;
        $etichetterot[] = array('nome' => 'Totale Credito', 'dim' => 6);
        $this->cella($pdf, 30, 6, -30, 6, 'Totale Credito', 1, 'L', 'M');
        $numrot++;
      }
    }
    $pdf->StopTransform();
    $this->cella($pdf, 12, 30, $numrot*6+6, -$numrot*6, 'Media', 1, 'C', 'B');
    $this->cella($pdf, 0, 30, 0, 0, 'Esito', 1, 'C', 'B');
    $this->acapo($pdf, 30);
    // dati alunni
    $pdf->SetFont('helvetica', '', 8);
    $numalunni = 0;
    $next_height = 26;
    end($dati['alunni']);
    $ultimo_idalu = key($dati['alunni']);
    foreach ($dati['alunni'] as $idalunno=>$alu) {
      if ($idalunno == $ultimo_idalu) {
        // ultima riga
        $next_height = 0;
      }
      // nuovo alunno
      $numalunni++;
      $this->cella($pdf, 6, 11, 0, 0, $numalunni, 1, 'C', 'T');
      $nomealunno = strtoupper($alu['cognome'].' '.$alu['nome']);
      $sessoalunno = ($alu['sesso'] == 'M' ? 'o' : 'a');
      $dataalunno = $alu['dataNascita']->format('d/m/Y');
      $this->cella($pdf, 35, 8, 0, 0, $nomealunno, 0, 'L', 'T');
      $this->cella($pdf, 35, 11, -35, 0, $dataalunno, 1, 'L', 'B');
      $this->cella($pdf, 35, 11, -35, 0, 'Assenze ->', 1, 'R', 'B');
      $pdf->SetXY($pdf->GetX(), $pdf->GetY() + 5.50);
      if ($dati['esiti'][$idalunno]->getEsito() == 'X') {
        // scrutinio rinviato
        $width = (count($dati['materie']) + 1) * 6 + 12;
        if ($dati['classe']->getAnno() == 3) {
          $width += 6;
        } elseif ($dati['classe']->getAnno() >= 4) {
          $width += 3 * 6;
        }
        $this->cella($pdf, $width, 11, 0, -5.50, '', 1, 'C', 'M');
        $esito = 'Scrutinio rinviato';
        $this->cella($pdf, 0, 11, 0, 0, $esito, 1, 'C', 'M');
        // nuova riga
        $this->acapo($pdf, 11, $next_height, $etichetterot);
      } else {
        // scrutinati
        $voti_somma = 0;
        $voti_num = 0;
        foreach ($dati['materie'] as $idmateria=>$mat) {
          $pdf->SetTextColor(0,0,0);
          $voto = '';
          $assenze = '';
          $width = 6;
          if ($mat['tipo'] == 'R') {
            // religione
            $width = 12;
            if ($alu['religione'] != 'S') {
              // N.A.
              $voto = '///';
            } else {
              $voto = $info_voti['R'][$dati['voti'][$idalunno][$idmateria]['unico']];
              $assenze = $dati['voti'][$idalunno][$idmateria]['assenze'];
              if ($dati['voti'][$idalunno][$idmateria]['unico'] < 22) {
                // insuff.
                $pdf->SetTextColor(255,0,0);
              }
            }
          } elseif ($mat['tipo'] == 'C') {
            // condotta
            $voto = $info_voti['C'][$dati['voti'][$idalunno][$idmateria]['unico']];
            if ($dati['voti'][$idalunno][$idmateria]['unico'] < 6) {
              // insuff.
              $pdf->SetTextColor(255,0,0);
            }
            $voti_somma += ($dati['voti'][$idalunno][$idmateria]['unico'] > 4 ? $dati['voti'][$idalunno][$idmateria]['unico'] : 0);
            $voti_num++;
          } elseif ($mat['tipo'] == 'N') {
            $voto = $info_voti['N'][$dati['voti'][$idalunno][$idmateria]['unico']];
            $assenze = $dati['voti'][$idalunno][$idmateria]['assenze'];
            if ($dati['voti'][$idalunno][$idmateria]['unico'] < 6) {
              // insuff.
              $pdf->SetTextColor(255,0,0);
            }
            $voti_somma += $dati['voti'][$idalunno][$idmateria]['unico'];
            $voti_num++;
          }
          // scrive voto/assenze
          $this->cella($pdf, $width, 5.50, 0, -5.50, $voto, 1, 'C', 'M');
          $pdf->SetTextColor(0,0,0);
          $this->cella($pdf, $width, 5.50, -$width, 5.50, $assenze, 1, 'C', 'M');
        }
        if ($dati['classe']->getAnno() >= 3) {
          // credito
          if ($dati['esiti'][$idalunno]->getEsito() == 'A') {
            // ammessi
            $credito = $dati['esiti'][$idalunno]->getCredito();
            $creditoprec = $dati['esiti'][$idalunno]->getCreditoPrecedente();
            $creditotot = $credito + $creditoprec;
          } else {
            // non ammessi o sospesi
            $credito = '';
            $creditoprec = '';
            $creditotot = '';
          }
          $this->cella($pdf, 6, 5.50, 0, -5.50, $credito, 1, 'C', 'M');
          $this->cella($pdf, 6, 5.50, -6, 5.50, '', 1, 'C', 'M');
          if ($dati['classe']->getAnno() >= 4) {
            $this->cella($pdf, 6, 5.50, 0, -5.50, $creditoprec, 1, 'C', 'M');
            $this->cella($pdf, 6, 5.50, -6, 5.50, '', 1, 'C', 'M');
            $this->cella($pdf, 6, 5.50, 0, -5.50, $creditotot, 1, 'C', 'M');
            $this->cella($pdf, 6, 5.50, -6, 5.50, '', 1, 'C', 'M');
          }
        }
        // media
        $media = number_format($voti_somma / $voti_num, 2, ',', '');
        $this->cella($pdf, 12, 5.50, 0, -5.50, $media, 1, 'C', 'M');
        $this->cella($pdf, 12, 5.50, -12, 5.50, '', 1, 'C', 'M');
        // esito
        switch ($dati['esiti'][$idalunno]->getEsito()) {
          case 'A':
            // ammesso
            $esito = 'Ammess'.$sessoalunno;
            break;
          case 'N':
            // non ammesso
            $esito = 'Non Ammess'.$sessoalunno;
            break;
        }
        $this->cella($pdf, 0, 11, 0, -5.50, $esito, 1, 'C', 'M');
        // nuova riga
        $this->acapo($pdf, 11, $next_height, $etichetterot);
      }
    }
    // data e firma
    $datascrutinio = $dati['scrutinio']->getData()->format('d/m/Y');
    $pdf->SetFont('helvetica', '', 12);
    $this->cella($pdf, 30, 15, 0, 0, 'Data', 0, 'R', 'B');
    $this->cella($pdf, 30, 15, 0, 0, $datascrutinio, 'B', 'C', 'B');
    $pdf->SetXY(-80, $pdf->GetY());
    $preside = $this->session->get('/CONFIG/ISTITUTO/firma_preside');
    $text = '(Il Dirigente Scolastico)'."\n".$preside;
    $this->cella($pdf, 60, 15, 0, 0, $text, 'B', 'C', 'B');
  }

  /**
   * Crea il tabellone dei voti come documento PDF
   *
   * @param TCPDF $pdf Gestore del documento PDF
   * @param string $classe Nome della classe
   * @param string $classe_completa Nome della classe con corso e sede
   * @param array $dati Dati dello scrutinio
   */
  public function creaTabelloneVoti_I($pdf, $classe, $classe_completa, $dati) {
    $info_voti['N'] = [0 => 'NC', 1 => '1', 2 => '2', 3 => '3', 4 => '4', 5 => '5', 6 => '6', 7 => '7', 8 => '8', 9 => '9', 10 => '10'];
    $info_voti['C'] = [4 => 'NC', 5 => '5', 6 => '6', 7 => '7', 8 => '8', 9 => '9', 10 => '10'];
    $info_voti['R'] = [20 => 'NC', 21 => 'Insuff.', 22 => 'Suff.', 23 => 'Buono', 24 => 'Dist.', 25 => 'Ottimo'];
    // set margins
    $pdf->SetMargins(10, 15, 10, true);
    // set auto page breaks
    $pdf->SetAutoPageBreak(false, 15);
    // set font
    $pdf->SetFont('helvetica', '', 10);
    // inizio pagina
    $pdf->SetHeaderMargin(12);
    $pdf->SetFooterMargin(12);
    $pdf->setHeaderFont(Array('helvetica', 'B', 6));
    $pdf->setFooterFont(Array('helvetica', '', 8));
    $pdf->setHeaderData('', 0, $this->session->get('/CONFIG/ISTITUTO/intestazione').' - CAGLIARI - ASSEMINI     ***     TABELLONE VOTI '.$classe, '', array(0,0,0), array(255,255,255));
    $pdf->setFooterData(array(0,0,0), array(255,255,255));
    $pdf->setPrintHeader(true);
    $pdf->setPrintFooter(true);
    $pdf->AddPage('P');
    // intestazione pagina
    $pdf->SetFont('helvetica', 'B', 10);
    $this->cella($pdf, 15, 5, 0, 2, 'Classe:', 0, 'C', 'B');
    $pdf->SetFont('helvetica', '', 10);
    $this->cella($pdf, 85, 5, 0, 0, $classe_completa, 0, 'L', 'B');
    $pdf->SetFont('helvetica', 'B', 10);
    $this->cella($pdf, 31, 5, 0, 0, 'Anno Scolastico:', 0, 'C', 'B');
    $pdf->SetFont('helvetica', '', 10);
    $as = $this->session->get('/CONFIG/SCUOLA/anno_scolastico');
    $this->cella($pdf, 20, 5, 0, 0, $as, 0, 'L', 'B');
    $pdf->SetFont('helvetica', '', 10);
    $this->cella($pdf, 0, 5, 0, 0, 'SCRUTINIO INTEGRATIVO', 0, 'R', 'B');
    $this->acapo($pdf, 5);
    // intestazione tabella
    $pdf->SetFont('helvetica', 'B', 8);
    $this->cella($pdf, 6, 30, 0, 0, 'Pr.', 1, 'C', 'B');
    $this->cella($pdf, 35, 30, 0, 0, 'Alunno', 1, 'C', 'B');
    $pdf->SetX($pdf->GetX() - 6); // aggiusta prima posizione
    $pdf->StartTransform();
    $pdf->Rotate(90);
    $numrot = 1;
    $etichetterot = array();
    $last_width = 6;
    foreach ($dati['materie'] as $idmateria=>$mat) {
      $text = strtoupper($mat['nomeBreve']);
      if ($mat['tipo'] != 'R') {
        $etichetterot[] = array('nome' => $text, 'dim' => 6);
        $this->cella($pdf, 30, 6, -30, $last_width, $text, 1, 'L', 'M');
        $last_width = 6;
      } else {
        $etichetterot[] = array('nome' => $text, 'dim' => 12);
        $this->cella($pdf, 30, 12, -30, $last_width, $text, 1, 'L', 'M');
        $last_width = 12;
      }
      $numrot++;
    }
    if ($dati['classe']->getAnno() >= 3) {
      // credito
      $etichetterot[] = array('nome' => 'Credito', 'dim' => 6);
      $this->cella($pdf, 30, 6, -30, 6, 'Credito', 1, 'L', 'M');
      $numrot++;
      if ($dati['classe']->getAnno() >= 4) {
        $etichetterot[] = array('nome' => 'Credito Anni Prec.', 'dim' => 6);
        $this->cella($pdf, 30, 6, -30, 6, 'Credito Anni Prec.', 1, 'L', 'M');
        $numrot++;
        $etichetterot[] = array('nome' => 'Totale Credito', 'dim' => 6);
        $this->cella($pdf, 30, 6, -30, 6, 'Totale Credito', 1, 'L', 'M');
        $numrot++;
      }
    }
    $pdf->StopTransform();
    $this->cella($pdf, 12, 30, $numrot*6+6, -$numrot*6, 'Media', 1, 'C', 'B');
    $this->cella($pdf, 0, 30, 0, 0, 'Esito', 1, 'C', 'B');
    $this->acapo($pdf, 30);
    // dati alunni
    $pdf->SetFont('helvetica', '', 8);
    $numalunni = 0;
    $next_height = 26;
    end($dati['alunni']);
    $ultimo_idalu = key($dati['alunni']);
    foreach ($dati['alunni'] as $idalunno=>$alu) {
      if ($idalunno == $ultimo_idalu) {
        // ultima riga
        $next_height = 0;
      }
      // nuovo alunno
      $numalunni++;
      $this->cella($pdf, 6, 11, 0, 0, $numalunni, 1, 'C', 'T');
      $nomealunno = strtoupper($alu['cognome'].' '.$alu['nome']);
      $sessoalunno = ($alu['sesso'] == 'M' ? 'o' : 'a');
      $dataalunno = $alu['dataNascita']->format('d/m/Y');
      $this->cella($pdf, 35, 8, 0, 0, $nomealunno, 0, 'L', 'T');
      $this->cella($pdf, 35, 11, -35, 0, $dataalunno, 1, 'L', 'B');
      $this->cella($pdf, 35, 11, -35, 0, 'Assenze ->', 1, 'R', 'B');
      $pdf->SetXY($pdf->GetX(), $pdf->GetY() + 5.50);
      // scrutinati
      $voti_somma = 0;
      $voti_num = 0;
      if ($dati['esiti'][$idalunno]->getEsito() == 'X') {
        // scrutinio rinviato
        $width = (count($dati['materie']) + 1) * 6 + 12;
        if ($dati['classe']->getAnno() == 3) {
          $width += 6;
        } elseif ($dati['classe']->getAnno() >= 4) {
          $width += 3 * 6;
        }
        $this->cella($pdf, $width, 11, 0, -5.50, '', 1, 'C', 'M');
        $esito = 'Scrutinio rinviato';
        $this->cella($pdf, 0, 11, 0, 0, $esito, 1, 'C', 'M');
        // nuova riga
        $this->acapo($pdf, 11, $next_height, $etichetterot);
      } elseif ($dati['esiti'][$idalunno]->getEsito() == 'N') {
        // non ammesso
        $width = (count($dati['materie']) + 1) * 6 + 12;
        if ($dati['classe']->getAnno() == 3) {
          $width += 6;
        } elseif ($dati['classe']->getAnno() >= 4) {
          $width += 3 * 6;
        }
        $this->cella($pdf, $width, 11, 0, -5.50, '', 1, 'C', 'M');
        $esito = 'Non Ammess'.$sessoalunno;
        $this->cella($pdf, 0, 11, 0, 0, $esito, 1, 'C', 'M');
        // nuova riga
        $this->acapo($pdf, 11, $next_height, $etichetterot);
      } elseif ($dati['esiti'][$idalunno]->getEsito() == 'A') {
        // ammessi
        foreach ($dati['materie'] as $idmateria=>$mat) {
          $voto = '';
          $assenze = '';
          $width = 6;
          if ($mat['tipo'] == 'R') {
            // religione
            $width = 12;
            if ($alu['religione'] != 'S') {
              // N.A.
              $voto = '///';
            } else {
              $voto = $info_voti['R'][$dati['voti'][$idalunno][$idmateria]['unico']];
              $assenze = $dati['voti'][$idalunno][$idmateria]['assenze'];
            }
          } elseif ($mat['tipo'] == 'C') {
            // condotta
            $voto = $info_voti['C'][$dati['voti'][$idalunno][$idmateria]['unico']];
            $voti_somma += ($dati['voti'][$idalunno][$idmateria]['unico'] > 4 ? $dati['voti'][$idalunno][$idmateria]['unico'] : 0);
            $voti_num++;
          } elseif ($mat['tipo'] == 'N') {
            $voto = $info_voti['N'][$dati['voti'][$idalunno][$idmateria]['unico']];
            $assenze = $dati['voti'][$idalunno][$idmateria]['assenze'];
            $voti_somma += $dati['voti'][$idalunno][$idmateria]['unico'];
            $voti_num++;
          }
          // scrive voto/assenze
          $this->cella($pdf, $width, 5.50, 0, -5.50, $voto, 1, 'C', 'M');
          $this->cella($pdf, $width, 5.50, -$width, 5.50, $assenze, 1, 'C', 'M');
        }
        if ($dati['classe']->getAnno() >= 3) {
          // credito
          $credito = $dati['esiti'][$idalunno]->getCredito();
          $creditoprec = $dati['esiti'][$idalunno]->getCreditoPrecedente();
          $creditotot = $credito + $creditoprec;
          $this->cella($pdf, 6, 5.50, 0, -5.50, $credito, 1, 'C', 'M');
          $this->cella($pdf, 6, 5.50, -6, 5.50, '', 1, 'C', 'M');
          if ($dati['classe']->getAnno() >= 4) {
            $this->cella($pdf, 6, 5.50, 0, -5.50, $creditoprec, 1, 'C', 'M');
            $this->cella($pdf, 6, 5.50, -6, 5.50, '', 1, 'C', 'M');
            $this->cella($pdf, 6, 5.50, 0, -5.50, $creditotot, 1, 'C', 'M');
            $this->cella($pdf, 6, 5.50, -6, 5.50, '', 1, 'C', 'M');
          }
        }
        // media
        $media = number_format($voti_somma / $voti_num, 2, ',', '');
        $this->cella($pdf, 12, 5.50, 0, -5.50, $media, 1, 'C', 'M');
        $this->cella($pdf, 12, 5.50, -12, 5.50, '', 1, 'C', 'M');
        // esito
        $esito = 'Ammess'.$sessoalunno;
        $this->cella($pdf, 0, 11, 0, -5.50, $esito, 1, 'C', 'M');
        // nuova riga
        $this->acapo($pdf, 11, $next_height, $etichetterot);
      }
    }
    // data e firma
    $datascrutinio = $dati['scrutinio']->getData()->format('d/m/Y');
    $pdf->SetFont('helvetica', '', 12);
    $this->cella($pdf, 30, 15, 0, 0, 'Data', 0, 'R', 'B');
    $this->cella($pdf, 30, 15, 0, 0, $datascrutinio, 'B', 'C', 'B');
    $pdf->SetXY(-80, $pdf->GetY());
    $preside = $this->session->get('/CONFIG/ISTITUTO/firma_preside');
    $text = '(Il Dirigente Scolastico)'."\n".$preside;
    $this->cella($pdf, 60, 15, 0, 0, $text, 'B', 'C', 'B');
  }

  /**
   * Crea il foglio firme del verbale come documento PDF
   *
   * @param TCPDF $pdf Gestore del documento PDF
   * @param string $classe Nome della classe
   * @param string $classe_completa Nome della classe con corso e sede
   * @param array $dati Dati dello scrutinio
   */
  public function creaFirmeVerbale_I($pdf, $classe, $classe_completa, $dati) {
    // set margins
    $pdf->SetMargins(10, 10, 10, true);
    // set auto page breaks
    $pdf->SetAutoPageBreak(false, 10);
    // set font
    $pdf->SetFont('helvetica', '', 10);
    // inizio pagina
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->AddPage('L');
    // intestazione pagina
    $coordinatore = $dati['classe']->getCoordinatore()->getCognome().' '.$dati['classe']->getCoordinatore()->getNome();
    $pdf->SetFont('helvetica', 'B', 8);
    $this->cella($pdf, 100, 4, 0, 0, 'FOGLIO FIRME VERBALE', 0, 'L', 'T');
    $as = $this->session->get('/CONFIG/SCUOLA/anno_scolastico');
    $this->cella($pdf, 0, 4, 0, 0, $classe.' - A.S '.$as, 0, 'R', 'T');
    $this->acapo($pdf, 5);
    $pdf->SetFont('helvetica', 'B', 16);
    $this->cella($pdf, 70, 10, 0, 0, 'CONSIGLIO DI CLASSE:', 0, 'L', 'B');
    $this->cella($pdf, 0, 10, 0, 0, $classe_completa, 0, 'L', 'B');
    $this->acapo($pdf, 10);
    $pdf->SetFont('helvetica', 'B', 10);
    $this->cella($pdf, 40, 6, 0, 0, 'Docente Coordinatore:', 0, 'L', 'T');
    $this->cella($pdf, 0, 6, 0, 0, $coordinatore, 0, 'L', 'T');
    $this->acapo($pdf, 6);
    // intestazione tabella
    $pdf->SetFont('helvetica', 'B', 10);
    $this->cella($pdf, 90, 5, 0, 0, 'MATERIA', 1, 'C', 'B');
    $this->cella($pdf, 60, 5, 0, 0, 'DOCENTI', 1, 'C', 'B');
    $this->cella($pdf, 0, 5, 0, 0, 'FIRME', 1, 'C', 'B');
    $this->acapo($pdf, 5);
    // dati materie
    foreach ($dati['materie'] as $idmateria=>$mat) {
      $lista = '';
      foreach ($mat as $iddocente=>$doc) {
        $nome_materia = $doc['nome_materia'];
        if ($dati['scrutinio']->getDato('presenze')[$iddocente]->getPresenza()) {
          $lista .= ', '.$doc['cognome'].' '.$doc['nome'];
        } else {
          $lista .= ', '.$dati['scrutinio']->getDato('presenze')[$iddocente]->getSostituto();
        }
      }
      $pdf->SetFont('helvetica', 'B', 10);
      $this->cella($pdf, 90, 11, 0, 0, $nome_materia, 1, 'L', 'B');
      $pdf->SetFont('helvetica', '', 10);
      $this->cella($pdf, 60, 11, 0, 0, substr($lista, 2), 1, 'L', 'B');
      $this->cella($pdf, 0, 11, 0, 0, '', 1, 'C', 'B');
      $this->acapo($pdf, 11);
    }
    // fine pagina
    $datascrutinio = $dati['scrutinio']->getData()->format('d/m/Y');
    $pdf->SetFont('helvetica', '', 12);
    $this->cella($pdf, 15, 9, 0, 0, 'DATA:', 0, 'R', 'B');
    $this->cella($pdf, 25, 9, 0, 0, $datascrutinio, 'B', 'C', 'B');
  }

  /**
   * Crea il foglio firme del registro dei voti come documento PDF
   *
   * @param TCPDF $pdf Gestore del documento PDF
   * @param string $classe Nome della classe
   * @param string $classe_completa Nome della classe con corso e sede
   * @param array $dati Dati dello scrutinio
   */
  public function creaFirmeRegistro_I($pdf, $classe, $classe_completa, $dati) {
    // set margins
    $pdf->SetMargins(10, 10, 10, true);
    // set auto page breaks
    $pdf->SetAutoPageBreak(false, 10);
    // set font
    $pdf->SetFont('helvetica', '', 10);
    // inizio pagina
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->AddPage('L');
    // intestazione pagina
    $pdf->SetFont('helvetica', 'B', 8);
    $this->cella($pdf, 100, 4, 0, 0, 'FOGLIO FIRME REGISTRO', 0, 'L', 'T');
    $as = $this->session->get('/CONFIG/SCUOLA/anno_scolastico');
    $this->cella($pdf, 0, 4, 0, 0, $classe.' - A.S '.$as, 0, 'R', 'T');
    $this->acapo($pdf, 5);
    $pdf->SetFont('helvetica', 'B', 16);
    $this->cella($pdf, 70, 10, 0, 0, 'CONSIGLIO DI CLASSE:', 0, 'L', 'B');
    $this->cella($pdf, 145, 10, 0, 0, $classe_completa, 0, 'L', 'B');
    $this->cella($pdf, 0, 10, 0, 0, 'SCRUTINIO INTEGRATIVO', 0, 'R', 'B');
    $this->acapo($pdf, 11);
    // intestazione tabella
    $pdf->SetFont('helvetica', 'B', 10);
    $this->cella($pdf, 90, 5, 0, 0, 'MATERIA', 1, 'C', 'B');
    $this->cella($pdf, 60, 5, 0, 0, 'DOCENTI', 1, 'C', 'B');
    $this->cella($pdf, 0, 5, 0, 0, 'FIRME', 1, 'C', 'B');
    $this->acapo($pdf, 5);
    // dati materie
    foreach ($dati['materie'] as $idmateria=>$mat) {
      $lista = '';
      foreach ($mat as $iddocente=>$doc) {
        $nome_materia = $doc['nome_materia'];
        if ($dati['scrutinio']->getDato('presenze')[$iddocente]->getPresenza()) {
          $lista .= ', '.$doc['cognome'].' '.$doc['nome'];
        } else {
          $lista .= ', '.$dati['scrutinio']->getDato('presenze')[$iddocente]->getSostituto();
        }
      }
      $pdf->SetFont('helvetica', 'B', 10);
      $this->cella($pdf, 90, 11, 0, 0, $nome_materia, 1, 'L', 'B');
      $pdf->SetFont('helvetica', '', 10);
      $this->cella($pdf, 60, 11, 0, 0, substr($lista, 2), 1, 'L', 'B');
      $this->cella($pdf, 0, 11, 0, 0, '', 1, 'C', 'B');
      $this->acapo($pdf, 11);
    }
    // fine pagina
    $datascrutinio = $dati['scrutinio']->getData()->format('d/m/Y');
    $pdf->SetFont('helvetica', '', 12);
    $this->cella($pdf, 15, 12, 0, 0, 'DATA:', 0, 'R', 'B');
    $this->cella($pdf, 25, 12, 0, 0, $datascrutinio, 'B', 'C', 'B');
    $this->cella($pdf, 50, 12, 0, 0, 'SEGRETARIO:', 0, 'R', 'B');
    $this->cella($pdf, 68, 12, 0, 0, '', 'B', 'C', 'B');
    $this->cella($pdf, 50, 12, 0, 0, 'PRESIDENTE:', 0, 'R', 'B');
    $this->cella($pdf, 68, 12, 0, 0, '', 'B', 'C', 'B');
  }

  /**
   * Crea la comunicazione per i non ammessi come documento PDF
   *
   * @param TCPDF $pdf Gestore del documento PDF
   * @param string $classe Nome della classe
   * @param string $classe_completa Nome della classe con corso e sede
   * @param array $dati Dati dello scrutinio
   */
  public function creaNonAmmesso_I($pdf, $classe, $classe_completa, $dati) {
    $info_voti['N'] = [0 => 'Non Classificato', 1 => '1', 2 => '2', 3 => '3', 4 => '4', 5 => '5', 6 => '6', 7 => '7', 8 => '8', 9 => '9', 10 => '10'];
    $info_voti['C'] = [4 => 'Non Classificato', 5 => '5', 6 => '6', 7 => '7', 8 => '8', 9 => '9', 10 => '10'];
    $info_voti['R'] = [20 => 'Non Classificato', 21 => 'Insufficiente', 22 => 'Sufficiente', 23 => 'Buono', 24 => 'Distinto', 25 => 'Ottimo'];
    $datascrutinio = $dati['scrutinio']->getData()->format('d/m/Y');
    // set margins
    $pdf->SetMargins(10, 15, 10, true);
    // set auto page breaks
    $pdf->SetAutoPageBreak(true, 5);
    // set font
    $pdf->SetFont('times', '', 12);
    // inizio pagina
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->AddPage('P');
    // intestazione pagina
    $html = '<img src="/img/'.getenv('LOCAL_PATH').'intestazione-documenti.jpg" width="540">';
    $pdf->writeHTML($html, true, false, false, false, 'C');
    $alunno_nome = $dati['alunno']->getCognome().' '.$dati['alunno']->getNome();
    $alunno_sesso = $dati['alunno']->getSesso();
    $pdf->Ln(10);
    $pdf->SetFont('times', 'I', 12);
    $text = 'Ai genitori dell\'alunn'.($alunno_sesso == 'M' ? 'o' : 'a');
    $this->cella($pdf, 0, 0, 100, 0, $text, 0, 'L', 'T');
    $pdf->Ln();
    $pdf->SetFont('times', '', 12);
    $text = strtoupper($alunno_nome);
    $this->cella($pdf, 0, 0, 100, 0, $text, 0, 'L', 'T');
    $pdf->Ln();
    $text = 'Classe '.$classe;
    $this->cella($pdf, 0, 0, 100, 0, $text, 0, 'L', 'T');
    $pdf->Ln(15);
    // oggetto
    $pdf->SetFont('times', 'B', 12);
    $as = $this->session->get('/CONFIG/SCUOLA/anno_scolastico');
    $text = 'OGGETTO: Comunicazione esito dello scrutinio integrativo - Anno Scolastico '.$as.'.';
    $this->cella($pdf, 0, 0, 0, 0, $text, 0, 'L', 'T');
    $pdf->Ln(10);
    // non ammesso
    $pdf->SetFont('times', '', 12);
    $sex = ($alunno_sesso == 'M' ? 'o' : 'a');
    $html = '<p align="justify">Si comunica che il Consiglio di Classe, nello scrutinio del '.$datascrutinio.','.
            ' ha deliberato la <b>NON AMMISSIONE alla classe successiva</b>'.
            ' dell\'alunn'.$sex.' '.$alunno_nome.', con la seguente motivazione:</p>';
    $pdf->writeHTML($html, true, false, false, true);
    $html = '<p align="justify"><i>'.htmlentities($dati['esito']->getDati()['giudizio']).'</i></p>';
    $pdf->writeHTMLCell(186, 0, $pdf->GetX()+2, $pdf->GetY(), $html, 0, 1);
    $html = '<p align="justify">Il Coordinatore di Classe sarà disponibile a fornire ulteriori chiarimenti previo appuntamento telefonico.</p>';
    $pdf->writeHTMLCell(0, 0, $pdf->GetX(), $pdf->GetY()+2, $html, 0, 1);
    $html = '<p align="justify">Di seguito viene riportato il riepilogo dei voti attribuiti durante lo scrutinio:</p>';
    $pdf->writeHTMLCell(0, 0, $pdf->GetX(), $pdf->GetY()+2, $html, 0, 1);
    // voti
    $html = '<table border="1" cellpadding="3">';
    $html .= '<tr><td width="60%"><strong>MATERIA</strong></td><td width="20%"><strong>VOTO</strong></td><td width="20%"><strong>ORE DI ASSENZA</strong></td></tr>';
    foreach ($dati['materie'] as $idmateria=>$mat) {
      $html .= '<tr><td align="left"><strong>'.$mat['nome'].'</strong></td>';
      $voto = '';
      $assenze = '';
      if ($mat['tipo'] == 'R') {
        if ($dati['alunno']->getReligione() == 'S') {
          // si avvale
          $voto = $info_voti['R'][$dati['voti'][$idmateria]['unico']];
          $assenze = $dati['voti'][$idmateria]['assenze'];
        } else {
          // N.A.
          $voto = '///';
        }
      } elseif ($mat['tipo'] == 'C') {
        // condotta
        $voto = $info_voti['C'][$dati['voti'][$idmateria]['unico']];
      } elseif ($mat['tipo'] == 'N') {
        // altre
        $voto = $info_voti['N'][$dati['voti'][$idmateria]['unico']];
        $assenze = $dati['voti'][$idmateria]['assenze'];
      }
      $html .= "<td>$voto</td><td>$assenze</td></tr>";
    }
    $html .= '</table>';
    $pdf->SetFont('helvetica', '', 10);
    $pdf->writeHTML($html, true, false, false, true, 'C');
    $pdf->Ln(10);
    // firma
    $pdf->SetFont('times', '', 12);
    $html = 'Cagliari, '.$datascrutinio.'.';
    $pdf->writeHTMLCell(100, 0, $pdf->GetX(), $pdf->GetY(), $html, 0, 0);
    $preside = $this->session->get('/CONFIG/ISTITUTO/firma_preside');
    $html = 'Il Dirigente Scolastico<br><i>'.$preside.'</i>';
    $pdf->writeHTMLCell(100, 0, $pdf->GetX(), $pdf->GetY(), $html, 0, 1, false, true, 'C');
  }

  /**
   * Crea la pagella come documento PDF
   *
   * @param TCPDF $pdf Gestore del documento PDF
   * @param string $classe Nome della classe
   * @param string $classe_completa Nome della classe con corso e sede
   * @param array $dati Dati dello scrutinio
   */
  public function creaPagella_I($pdf, $classe, $classe_completa, $dati) {
    $info_voti['N'] = [0 => 'Non Classificato', 1 => '1', 2 => '2', 3 => '3', 4 => '4', 5 => '5', 6 => '6', 7 => '7', 8 => '8', 9 => '9', 10 => '10'];
    $info_voti['C'] = [4 => 'Non Classificato', 5 => '5', 6 => '6', 7 => '7', 8 => '8', 9 => '9', 10 => '10'];
    $info_voti['R'] = [20 => 'Non Classificato', 21 => 'Insufficiente', 22 => 'Sufficiente', 23 => 'Buono', 24 => 'Distinto', 25 => 'Ottimo'];
    // set margins
    $pdf->SetMargins(10, 15, 10, true);
    // set auto page breaks
    $pdf->SetAutoPageBreak(true, 5);
    // set font
    $pdf->SetFont('times', '', 12);
    // inizio pagina
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->AddPage('P');
    // logo
    $html = '<img src="/img/'.getenv('LOCAL_PATH').'intestazione-documenti.jpg" width="540">';
    $pdf->writeHTML($html, true, false, false, false, 'C');
    // intestazione
    $alunno = $dati['alunno']->getCognome().' '.$dati['alunno']->getNome();
    $alunno_sesso = $dati['alunno']->getSesso();
    $pdf->Ln(10);
    $pdf->SetFont('times', 'I', 12);
    $text = 'Ai genitori dell\'alunn'.($alunno_sesso == 'M' ? 'o' : 'a');
    $this->cella($pdf, 0, 0, 100, 0, $text, 0, 'L', 'T');
    $pdf->Ln();
    $pdf->SetFont('times', '', 12);
    $text = strtoupper($alunno);
    $this->cella($pdf, 0, 0, 100, 0, $text, 0, 'L', 'T');
    $pdf->Ln();
    $text = 'Classe '.$classe;
    $this->cella($pdf, 0, 0, 100, 0, $text, 0, 'L', 'T');
    $pdf->Ln(15);
    // oggetto
    $pdf->SetFont('times', 'B', 12);
    $as = $this->session->get('/CONFIG/SCUOLA/anno_scolastico');
    $text = 'OGGETTO: Comunicazione dei voti dello scrutinio integrativo - Anno Scolastico '.$as.'.';
    $this->cella($pdf, 0, 0, 0, 0, $text, 0, 'L', 'T');
    $pdf->Ln(10);
    // contenuto
    $pdf->SetFont('times', '', 12);
    $sex = ($alunno_sesso == 'M' ? 'o' : 'a');
    $html = '<p align="justify">Si comunica che il Consiglio di Classe, nella seduta dello scrutinio integrativo dell’anno scolastico '.$as.', tenutasi il giorno '.$dati['scrutinio']->getData()->format('d/m/Y').', ha attribuito all\'alunn'.$sex.' '.
            'le valutazioni che vengono riportate di seguito:</p>';
    $pdf->writeHTML($html, true, false, false, true);
    $pdf->Ln(5);
    // voti
    $html = '<table border="1" cellpadding="3">';
    $html .= '<tr><td width="60%"><strong>MATERIA</strong></td><td width="20%"><strong>VOTO</strong></td><td width="20%"><strong>ORE DI ASSENZA</strong></td></tr>';
    foreach ($dati['materie'] as $idmateria=>$mat) {
      $html .= '<tr><td align="left"><strong>'.$mat['nome'].'</strong></td>';
      $voto = '';
      $assenze = '';
      if ($mat['tipo'] == 'R') {
        if ($dati['alunno']->getReligione() == 'S') {
          // si avvale
          $voto = $info_voti['R'][$dati['voti'][$idmateria]['unico']];
          $assenze = $dati['voti'][$idmateria]['assenze'];
        } else {
          // N.A.
          $voto = '///';
        }
      } elseif ($mat['tipo'] == 'C') {
        // condotta
        $voto = $info_voti['C'][$dati['voti'][$idmateria]['unico']];
      } elseif ($mat['tipo'] == 'N') {
        // altre
        $voto = $info_voti['N'][$dati['voti'][$idmateria]['unico']];
        $assenze = $dati['voti'][$idmateria]['assenze'];
      }
      $html .= "<td>$voto</td><td>$assenze</td></tr>";
    }
    $html .= '</table><br><br>';
    $pdf->SetFont('helvetica', '', 10);
    $pdf->writeHTML($html, true, false, false, true, 'C');
    // firma
    $pdf->SetFont('times', '', 12);
    $html = 'Cagliari, '.$dati['scrutinio']->getData()->format('d/m/Y').'.';
    $pdf->writeHTMLCell(100, 0, $pdf->GetX(), $pdf->GetY(), $html, 0, 0);
    $preside = $this->session->get('/CONFIG/ISTITUTO/firma_preside');
    $html = 'Il Dirigente Scolastico<br><i>'.$preside.'</i>';
    $pdf->writeHTMLCell(100, 0, $pdf->GetX(), $pdf->GetY(), $html, 0, 1, false, true, 'C');
  }

  /**
   * Crea il verbale come documento PDF
   *
   * @param TCPDF $pdf Gestore del documento PDF
   * @param string $classe Nome della classe
   * @param string $classe_completa Nome della classe con corso e sede
   * @param array $dati Dati dello scrutinio
   */
  public function creaVerbale_F($pdf, $classe, $classe_completa, $dati) {
    // set margins
    $pdf->SetMargins(10, 15, 10, true);
    // set auto page breaks
    $pdf->SetAutoPageBreak(true, 15);
    // set font
    $pdf->SetFont('times', '', 12);
    // inizio pagina
    $pdf->setPrintHeader(false);
    $pdf->SetFooterMargin(15);
    $pdf->setFooterFont(array('helvetica', '', 9));
    $pdf->setFooterData(array(0,0,0), array(255,255,255));
    $pdf->setPrintFooter(true);
    $pdf->AddPage('P');
    // logo
    $html = '<img src="/img/'.getenv('LOCAL_PATH').'intestazione-documenti.jpg" width="540">';
    $pdf->writeHTML($html, true, false, false, false, 'C');
    // struttura
    foreach ($dati['definizione']->getStruttura() as $step=>$args) {
      $func = 'CreaVerbale_F_'.$args[0];
      $this->$func($pdf, $classe, $classe_completa, $dati, $step, $args);
    }
  }

  /**
   * Crea il verbale come documento PDF: parte iniziale
   *
   * @param TCPDF $pdf Gestore del documento PDF
   * @param string $classe Nome della classe
   * @param string $classe_completa Nome della classe con corso e sede
   * @param array $dati Dati dello scrutinio
   * @param int $step Passo della struttura del verbale
   * @param array $args Argomenti della struttura del verbale
   */
  public function creaVerbale_F_ScrutinioInizio($pdf, $classe, $classe_completa, $dati, $step, $args) {
    // inizializzazione
    $nome_mesi = ['', 'Gennaio', 'Febbraio', 'Marzo', 'Aprile', 'Maggio', 'Giugno', 'Luglio', 'Agosto', 'Settembre', 'Ottobre', 'Novembre', 'Dicembre'];
    $pdf->Ln(5);
    $pdf->SetFont('times', '', 12);
    $html = '<p align="center"><strong>VERBALE DELLO SCRUTINIO FINALE<br>'.
      'CLASSE '.$classe_completa.'</strong></p>';
    $pdf->writeHTML($html, true, false, false, true);
    $pdf->Ln(5);
    // inizio seduta
    $pdf->SetFont('times', '', 11);
    $datascrutinio_giorno = intval($dati['scrutinio']->getData()->format('d'));
    $datascrutinio_mese = $nome_mesi[intval($dati['scrutinio']->getData()->format('m'))];
    $datascrutinio_anno = $dati['scrutinio']->getData()->format('Y');
    $orascrutinio_inizio = $dati['scrutinio']->getInizio()->format('H:i');
    $html = '<p align="justify">Il giorno '.$datascrutinio_giorno.' del mese di '.$datascrutinio_mese.' dell\'anno '.
      $datascrutinio_anno.', alle ore '.$orascrutinio_inizio.', nei locali dell\'<em>'.$this->session->get('/CONFIG/ISTITUTO/intestazione').'</em> di Cagliari, con sede associata in Assemini, si riunisce, a seguito di regolare convocazione, il Consiglio della Classe '.
      $classe.' per discutere il seguente ordine del giorno:</p>';
    $pdf->writeHTML($html, true, false, false, true);
    $html = '<ol>';
    foreach ($dati['definizione']->getArgomenti() as $num=>$arg) {
      $html .='<li align="justify"><strong>'.$arg.(isset($dati['definizione']->getArgomenti()[$num + 1]) ? ';' : '.').'</strong></li>';
    }
    $html .='</ol>';
    $pdf->writeHTML($html, true, false, false, true);
    $pdf->Ln(1);
    if ($dati['scrutinio']->getDato('presiede_ds')) {
      $pres_nome = 'il Dirigente Scolastico';
    } else {
      $d = $dati['docenti'][$dati['scrutinio']->getDato('presiede_docente')][0];
      if ($dati['scrutinio']->getDato('presenze')[$d['id']]->getPresenza()) {
        $pres_nome = 'per delega '.($d['sesso'] == 'M' ? 'il Prof.' : 'la Prof.ssa').' '.
          $d['cognome'].' '.$d['nome'];
      } else {
        $pres_nome = 'per delega '.($dati['scrutinio']->getDato('presenze')[$d['id']]->getSessoSostituto() == 'M' ? 'il Prof.' : 'la Prof.ssa').
          ucwords(strtolower($dati['scrutinio']->getDato('presenze')[$d['id']]->getSostituto()));
      }
    }
    $d = $dati['docenti'][$dati['scrutinio']->getDato('segretario')][0];
    if ($dati['scrutinio']->getDato('presenze')[$d['id']]->getPresenza()) {
      $segr_nome = ($d['sesso'] == 'M' ? 'il Prof.' : 'la Prof.ssa').' '.
        $d['cognome'].' '.$d['nome'];
    } else {
      $segr_nome = ($dati['scrutinio']->getDato('presenze')[$d['id']]->getSessoSostituto() == 'M' ? 'il Prof.' : 'la Prof.ssa').
        ' '.ucwords(strtolower($dati['scrutinio']->getDato('presenze')[$d['id']]->getSostituto()));
    }
    $html = '<p align="justify">Presiede la riunione '.$pres_nome.', funge da segretario verbalizzante '.$segr_nome.'.</p>';
    $pdf->writeHTML($html, true, false, false, true);
    $pdf->Ln(1);
    $html = '<p align="justify">Sono presenti i professori:</p>';
    $pdf->writeHTML($html, true, false, false, true);
    $pdf->Ln(1);
    $pdf->SetFont('helvetica', '', 10);
    $html = '<table border="1" cellpadding="3">
      <tr nobr="true"><td width="40%" align="center"><strong>DOCENTE</strong></td><td width="60%" align="center"><strong>MATERIA</strong></td></tr>';
    $assenti = 0;
    foreach ($dati['scrutinio']->getDato('presenze') as $iddocente=>$doc) {
      if ($doc->getPresenza()) {
        $d = $dati['docenti'][$doc->getDocente()][0];
        $nome = $d['cognome'].' '.$d['nome'];
        $materie = '';
        foreach ($dati['docenti'][$doc->getDocente()] as $km=>$vm) {
          $materie .= '<br>&bull; '.($vm['doc_tipo'] == 'I' ? 'Lab. ' : '').$vm['nome_materia'];
        }
        $html .= '<tr><td>'.$nome.'</td><td>'.substr($materie, 4).'</td></tr>';
      } else {
        $assenti++;
      }
    }
    $html .= '</table>';
    $pdf->writeHTML($html, true, false, false, true);
    $pdf->Ln(2);
    $pdf->SetFont('times', '', 11);
    if ($assenti > 0) {
      $html = '<p align="justify">Sono assenti giustificati i seguenti docenti, surrogati con atto formale del Dirigente Scolastico:</p>';
      $pdf->writeHTML($html, true, false, false, true);
      $html = '<ul>';
      foreach ($dati['scrutinio']->getDato('presenze') as $iddocente=>$doc) {
        if (!$doc->getPresenza()) {
          $assenti--;
          $d = $dati['docenti'][$doc->getDocente()][0];
          $nome = $d['cognome'].' '.$d['nome'];
          $materie = '';
              foreach ($dati['docenti'][$doc->getDocente()] as $km=>$vm) {
                $materie .= ', '.$vm['nome_materia'];
              }
          $text = ($d['sesso'] == 'M' ? 'Prof.' : 'Prof.ssa').' '.$nome.' ('.substr($materie,2).'), '.
            'sostituit'.($d['sesso'] == 'M' ? 'o' : 'a').' dal'.
            ($dati['scrutinio']->getDato('presenze')[$d['id']]->getSessoSostituto() == 'M' ? ' Prof.' : 'la Prof.ssa').
            ' '.ucwords(strtolower($doc->getSostituto()));
          $html .= '<li align="justify">'.$text.($assenti > 0 ? ';' : '.').'</li>';
        }
      }
      $html .= '</ul>';
      $pdf->writeHTML($html, true, false, false, true);
    } else {
      $html = '<p align="justify">Nessuno è assente.</p>';
      $pdf->writeHTML($html, true, false, false, true);
    }
    $pdf->Ln(1);
    $html = '<p align="justify">Accertata la legalità della seduta, il presidente dà l\'avvio alle operazioni.</p>';
    $pdf->writeHTML($html, true, false, false, true);
    $pdf->Ln(5);
  }

  /**
   * Crea il verbale come documento PDF: argomento all'ordine del giorno
   *
   * @param TCPDF $pdf Gestore del documento PDF
   * @param string $classe Nome della classe
   * @param string $classe_completa Nome della classe con corso e sede
   * @param array $dati Dati dello scrutinio
   * @param int $step Passo della struttura del verbale
   * @param array $args Argomenti della struttura del verbale
   */
  public function CreaVerbale_F_Argomento($pdf, $classe, $classe_completa, $dati, $step, $args) {
    $pdf->SetFont('times', '', 11);
    $num_arg = $args[2]['argomento'];
    $argomento = $dati['definizione']->getArgomenti()[$num_arg];
    $html = '<p align="justify"><strong>'.$args[2]['sezione'].'. '.$argomento.'.</strong></p>';
    $pdf->writeHTML($html, true, false, false, true);
    $pdf->Ln(1);
    $testo = $dati['scrutinio']->getDato('argomento')[$num_arg];
    $html = '<p align="justify">'.nl2br(htmlentities($testo)).'</p>';
    $pdf->writeHTML($html, true, false, false, true);
    $pdf->Ln(5);
  }

  /**
   * Crea il verbale come documento PDF: rilevazione assenze alunni
   *
   * @param TCPDF $pdf Gestore del documento PDF
   * @param string $classe Nome della classe
   * @param string $classe_completa Nome della classe con corso e sede
   * @param array $dati Dati dello scrutinio
   * @param int $step Passo della struttura del verbale
   * @param array $args Argomenti della struttura del verbale
   */
  public function CreaVerbale_F_ScrutinioAssenze($pdf, $classe, $classe_completa, $dati, $step, $args) {
    $pdf->SetFont('times', '', 11);
    $num_arg = $args[2]['argomento'];
    $argomento = $dati['definizione']->getArgomenti()[$num_arg];
    $html = '<p align="justify"><strong>'.$args[2]['sezione'].'. '.$argomento.'.</strong></p>';
    $pdf->writeHTML($html, true, false, false, true);
    $pdf->Ln(1);
    $html = '<p align="justify">Il Consiglio di Classe verifica preliminarmente, per ciascun alunno, la frequenza delle lezioni.</p>';
    $pdf->writeHTML($html, true, false, false, true);
    $pdf->Ln(1);
    // cessata frequenza
    if (count($dati['cessata_frequenza']) > 0) {
      $html = '<p align="justify">I seguenti alunni risultano aver cessato la frequenza delle lezioni entro il 15 marzo, pertanto il Consiglio di Classe li dichiara ritirati d’ufficio e non procede al loro scrutinio (R.D. 653/25):</p>';
      $pdf->writeHTML($html, true, false, false, true);
      $html = '<ul>';
      foreach ($dati['alunni'] as $idalunno=>$alu) {
        if (in_array($idalunno, $dati['cessata_frequenza'])) {
          $html .= '<li align="justify"><b>'.$alu['cognome'].' '.$alu['nome'].' ('.$alu['dataNascita']->format('d/m/Y').')</b></li>';
        }
      }
      $html .= '</ul>';
      $pdf->writeHTML($html, true, false, false, true);
      $pdf->Ln(1);
    }
    // limite di assenze superato
    if (count($dati['no_scrutinabili']) + count($dati['deroga']) > 0) {
      $html = '<p align="justify">Si esaminano ora gli alunni che presentano un numero di assenze superiore al 25% dell’orario annuale personalizzato:</p>';
      $pdf->writeHTML($html, true, false, false, true);
      $pdf->Ln(1);
      $pdf->SetFont('helvetica', '', 10);
      $html = '<table border="1" cellpadding="3">'.
        '<tr nobr="true"><td width="40%" align="center"><b>ALUNNO</b></td><td width="20%" align="center"><b>Monte ore annuo complessivo personalizzato della classe</b></td><td width="20%" align="center"><b>Numero massimo di ore di assenza consentite per la validità dell\'A.S. (25% monte ore)</b></td><td width="20%" align="center"><b>Ore di assenza dell\'alunno</b></td></tr>';
      $assenze_monteore = $dati['scrutinio']->getDato('monteore');
      $assenze_max = $dati['scrutinio']->getDato('maxassenze');
      foreach ($dati['alunni'] as $idalunno=>$alu) {
        if (in_array($idalunno, $dati['no_scrutinabili']) || in_array($idalunno, $dati['deroga'])) {
          $nome = $alu['cognome'].' '.$alu['nome'].' ('.$alu['dataNascita']->format('d/m/Y').')';
          $ore = $dati['scrutinio']->getDato('no_scrutinabili')[$idalunno]['ore'];
          $html .= '<tr><td>'.$nome.'</td><td align="center">'.$assenze_monteore.'</td><td align="center">'.$assenze_max.'</td><td align="center">'.$ore.'</td></tr>';
        }
      }
      $html .= '</table>';
      $pdf->writeHTML($html, true, false, false, true);
      $pdf->Ln(3);
      // deroghe
      if (count($dati['deroga']) > 0) {
        $pdf->SetFont('times', '', 11);
        $html = '<p align="justify">Il Consiglio di Classe ammette allo scrutinio, nonostante il superamento del limite di assenze, vista la documentazione prodotta e tenuto conto dei criteri generali di deroga stabiliti dal Collegio dei Docenti, i seguenti alunni (art. 14 comma 7 del D.P.R. 122 del 22 giugno 2009):</p>';
        $pdf->writeHTML($html, true, false, false, true);
        $pdf->Ln(1);
        $pdf->SetFont('helvetica', '', 10);
        $html = '<table border="1" cellpadding="3">'.
          '<tr nobr="true"><td width="40%" align="center"><b>ALUNNO</b></td><td width="60%" align="center"><b>Motivazioni della deroga</b></td></tr>';
        foreach ($dati['alunni'] as $idalunno=>$alu) {
          if (in_array($idalunno, $dati['deroga'])) {
            $nome = $alu['cognome'].' '.$alu['nome'].' ('.$alu['dataNascita']->format('d/m/Y').')';
            $motivo = $dati['scrutinio']->getDato('no_scrutinabili')[$idalunno]['deroga'];
            $html .= '<tr><td>'.$nome.'</td><td>'.$motivo.'</td></tr>';
          }
        }
        $html .= '</table>';
        $pdf->writeHTML($html, true, false, false, true);
        $pdf->Ln(3);
      }
      // non scrutinabili
      if (count($dati['no_scrutinabili']) > 0) {
        $pdf->SetFont('times', '', 11);
        $html = '<p align="justify">Il Consiglio di Classe, avendo constatato il superamento del numero massimo di assenze previsto dalla normativa in vigore (art. 14 comma 7 del D.P.R. 122 del 22 giugno 2009), non accompagnato da una adeguata e documentata motivazione, delibera l\'esclusione dallo scrutinio e la non ammissione '.
          ($dati['classe']->getAnno() == 5 ? 'all\'Esame di Stato' : 'alla classe successiva').' degli alunni seguenti:</p>';
        $pdf->writeHTML($html, true, false, false, true);
        $pdf->Ln(1);
        $html = '<ul>';
        foreach ($dati['alunni'] as $idalunno=>$alu) {
          if (in_array($idalunno, $dati['no_scrutinabili'])) {
            $nome = $alu['cognome'].' '.$alu['nome'].' ('.$alu['dataNascita']->format('d/m/Y').')';
            $html .= '<li align="justify"><b>'.$nome.'</b></li>';
          }
        }
        $html .= '</ul>';
        $pdf->writeHTML($html, true, false, false, true);
        $pdf->Ln(1);
      }
    } else {
      // tutti scrutinati
      $pdf->SetFont('times', '', 11);
      $html = '<p align="justify">Tutti gli alunni rientrano nei limiti di assenze previsti dalla normativa, pari al 25% dell’orario annuale personalizzato.</p>';
      $pdf->writeHTML($html, true, false, false, true);
      $pdf->Ln(1);
    }
    // alunni ritirati
    if (count($dati['ritirati']) > 0) {
      $pdf->SetFont('times', '', 11);
      $html = '<p align="justify">Gli alunni seguenti risultano essersi ritirati o trasferiti, oppure frequentano l\'anno all\'estero:</p>';
      $pdf->writeHTML($html, true, false, false, true);
      $pdf->Ln(1);
      $pdf->SetFont('helvetica', '', 10);
      $html = '<table border="1" cellpadding="3">'.
        '<tr nobr="true"><td width="40%" align="center"><b>ALUNNO</b></td><td width="60%" align="center"><b>Note</b></td></tr>';
      foreach ($dati['alunni'] as $idalunno=>$alu) {
        if (in_array($idalunno, array_keys($dati['ritirati']))) {
          $nome = $alu['cognome'].' '.$alu['nome'].' ('.$alu['dataNascita']->format('d/m/Y').')';
          $note = $dati['ritirati'][$idalunno];
          $html .= '<tr><td>'.$nome.'</td><td>'.$note.'</td></tr>';
        }
      }
      $html .= '</table>';
      $pdf->writeHTML($html, true, false, false, true);
      $pdf->Ln(3);
    }
    // riassunto scrutinati
    $pdf->SetFont('times', '', 11);
    $num_ritirati = count($dati['ritirati']);
    $num_no_scrutinati = count($dati['no_scrutinabili']);
    $num_cessata_frequenza = count($dati['cessata_frequenza']);
    $num_scrutinati = count($dati['scrutinati']);
    $num_tot = $num_scrutinati + $num_no_scrutinati + $num_cessata_frequenza + $num_ritirati;
    $html = '<p align="justify">Dall\'esposizione risulta quanto segue: '.
      "di n. $num_tot alunni iscritti alla classe sono da scrutinare n. $num_scrutinati alunni, ".
      "poiché n. $num_ritirati alunni si sono ritirati o trasferiti o frequentano l'anno all'estero, ".
      "n. $num_cessata_frequenza alunni hanno cessato la frequenza entro il 15 marzo ".
      "e n. $num_no_scrutinati alunni non possiedono la frequenza per almeno i tre quarti dell’orario annuale personalizzato senza documentata giustificazione.</p>";
    $pdf->writeHTML($html, true, false, false, true);
    $pdf->Ln(5);
  }

  /**
   * Crea il verbale come documento PDF: svolgimento scrutinio
   *
   * @param TCPDF $pdf Gestore del documento PDF
   * @param string $classe Nome della classe
   * @param string $classe_completa Nome della classe con corso e sede
   * @param array $dati Dati dello scrutinio
   * @param int $step Passo della struttura del verbale
   * @param array $args Argomenti della struttura del verbale
   */
  public function CreaVerbale_F_ScrutinioSvolgimento($pdf, $classe, $classe_completa, $dati, $step, $args) {
    $pdf->SetFont('times', '', 11);
    $num_arg = $args[2]['argomento'];
    $argomento = $dati['definizione']->getArgomenti()[$num_arg];
    $html = '<p align="justify"><strong>'.$args[2]['sezione'].'. '.$argomento.'.</strong></p>';
    $pdf->writeHTML($html, true, false, false, true);
    $pdf->Ln(1);
    // indicazioni
    $html = '<p align="justify">Prima di dare inizio alle operazioni di scrutinio, in ottemperanza a quanto previsto dalle norme vigenti e in base ai criteri di valutazione stabiliti dal Collegio dei Docenti e inseriti nel PTOF, il presidente ricorda che:</p>';
    $pdf->writeHTML($html, true, false, false, true);
    $html = '<ul>'.
      '<li align="justify">tutti i presenti sono tenuti all\'obbligo della stretta osservanza del segreto d\'ufficio e che l\'eventuale violazione comporta sanzioni disciplinari;</li>'.
      '<li align="justify">il voto di condotta è proposto dal Coordinatore di classe ed assegnato dal Consiglio di Classe;</li>'.
      '<li align="justify">i voti di profitto sono proposti dagli insegnanti delle rispettive materie ed assegnati dal Consiglio di Classe.</li>'.
      '</ul>';
    $pdf->writeHTML($html, true, false, false, true);
    $pdf->Ln(1);
    $html = '<p align="justify">In merito alle proposte di voto che vengono formulate, i singoli docenti dichiarano:</p>';
    $pdf->writeHTML($html, true, false, false, true);
    $html = '<ul>
      <li align="justify">che le proposte di voto ed i giudizi sono stati determinati sulla base delle verifiche sistematiche effettuate nel corso dell\'anno scolastico, tenuto conto dell\'impegno allo studio, alla partecipazione, all\'interesse al lavoro scolastico, in relazione alle effettive possibilità ed al progresso rispetto alla situazione di partenza di ciascun alunno;</li>
      <li align="justify">che i giudizi proposti tengono conto delle attività di sostegno e di recupero proposte alla classe, dei percorsi per le competenze trasversali e per l’orientamento, delle attività curricolari e delle loro risultanze.</li>
      </ul>';
    $pdf->writeHTML($html, true, false, false, true);
    $pdf->Ln(1);
    // condotta
    $html = '<p align="justify">Il coordinatore propone il voto di condotta, che viene approvato dal Consiglio di Classe secondo quanto segue:</p>';
    $pdf->writeHTML($html, true, false, false, true);
    $pdf->Ln(1);
    $pdf->SetFont('helvetica', '', 10);
    $html = '<table border="1" cellpadding="3">
      <tr nobr="true"><td width="30%" align="center"><strong>ALUNNO</strong></td><td width="6%" align="center"><strong>Voto</strong></td><td width="48%" align="center"><strong>Giudizio</strong></td><td width="16%" align="center"><strong>Delibera</strong></td></tr>';
    foreach ($dati['alunni'] as $idalunno=>$alu) {
      if (in_array($idalunno, $dati['scrutinati'])) {
        $nome = $alu['cognome'].' '.$alu['nome'].' ('.$alu['dataNascita']->format('d/m/Y').')';
        $condotta_voto = $dati['voti'][$idalunno]->getUnico() == 4 ? 'NC' : $dati['voti'][$idalunno]->getUnico();
        $condotta_motivazione = htmlentities(str_replace(array("\r", "\n"), ' ',
          $dati['voti'][$idalunno]->getDato('motivazione')));
        $condotta_unanimita = $dati['voti'][$idalunno]->getDato('unanimita');
        $condotta_contrari = intval($dati['voti'][$idalunno]->getDato('contrari'));
        if ($condotta_unanimita) {
          $condotta_approvazione = 'UNANIMITÀ';
        } else {
          $condotta_approvazione = "MAGGIORANZA<br>Contrari: $condotta_contrari";
        }
        $html .= '<tr nobr="true"><td>'.$nome.'</td><td align="center">'.$condotta_voto.'</td><td style="font-size:9pt">'.
          $condotta_motivazione.'</td><td style="font-size:9pt" align="center">'.$condotta_approvazione.'</td></tr>';
      }
    }
    $html .= '</table>';
    $pdf->writeHTML($html, true, false, false, true);
    $pdf->Ln(2);
    // valutazione
    $pdf->SetFont('times', '', 11);
    $html = '<p align="justify">Si passa, quindi, seguendo l\'ordine alfabetico, alla valutazione di ogni singolo alunno, tenuto conto degli indicatori precedentemente espressi. '.
      'Per ciascuna disciplina il docente competente esprime il proprio giudizio complessivo sull\'alunno. Ciascun giudizio è tradotto coerentemente in un voto, che viene proposto al Consiglio di Classe. '.
      'Il Consiglio di Classe discute esaurientemente le proposte espresse dai docenti e, tenuti ben presenti i parametri di valutazione deliberati, procede alla definizione e all\'approvazione dei voti per ciascun alunno e per ciascuna disciplina.</p>';
    $pdf->writeHTML($html, true, false, false, true);
    // ammessi
    $pdf->Ln(5);
    $html = '<p align="justify"><b><i>Il Consiglio di Classe dichiara ammessi</i></b>'.
      ($dati['classe']->getAnno() == 5 ? ' all\'Esame di Stato' : ' alla classe successiva').
      ', per avere riportato almeno sei decimi in ciascuna disciplina, i seguenti alunni:</p>';
    $pdf->writeHTML($html, true, false, false, true);
    $pdf->Ln(1);
    $pdf->SetFont('helvetica', '', 10);
    $html = '<table border="1" cellpadding="3">
      <tr nobr="true"><td width="40%" align="center"><strong>ALUNNO</strong></td><td width="60%" align="center"><strong>Delibera</strong></td></tr>';
    $num_bocciati = 0;
    $num_ammessi = 0;
    $num_sospesi = 0;
    $ammessi_insuff = array();
    foreach ($dati['alunni'] as $idalunno=>$alu) {
      if (in_array($idalunno, $dati['scrutinati'])) {
        if ($dati['esiti'][$idalunno]->getEsito() == 'N') {
          // non ammessi
          $num_bocciati++;
        } elseif ($dati['esiti'][$idalunno]->getEsito() == 'S') {
          // sospesi
          $num_sospesi++;
        } elseif ($dati['esiti'][$idalunno]->getEsito() == 'A') {
          // ammessi
          $num_ammessi++;
          if ($dati['classe']->getAnno() == 5) {
            // controlla insuff
            $insuff = $this->em->getRepository('App:VotoScrutinio')->createQueryBuilder('vs')
              ->select('COUNT(vs.id)')
              ->join('vs.materia','m')
              ->where('vs.scrutinio=:scrutinio AND vs.alunno=:alunno AND ((m.tipo=:normale AND vs.unico<:suff) OR (m.tipo=:religione AND vs.unico<:suffrel))')
              ->setParameters(['scrutinio' => $dati['scrutinio'], 'alunno' => $idalunno,
                'normale' => 'N', 'suff' => 6, 'religione' => 'R', 'suffrel' => 22])
              ->getQuery()
              ->getSingleScalarResult();
            if ($insuff > 0) {
              $ammessi_insuff[$idalunno] = 1;
            }
          }
          if (!isset($ammessi_insuff[$idalunno])) {
            // solo ammessi con suff.
            $valori = $dati['esiti'][$idalunno]->getDati();
            $nome = $alu['cognome'].' '.$alu['nome'].' ('.$alu['dataNascita']->format('d/m/Y').')';
            if ($valori['unanimita']) {
              $esito_approvazione = 'UNANIMITÀ';
            } else {
              $esito_approvazione = "MAGGIORANZA\nContrari: ".$valori['contrari'];
            }
            $html .= '<tr nobr="true"><td><b>'.$nome.'</b></td><td align="center">'.$esito_approvazione.'</td></tr>';
          }
        }
      }
    }
    $html .= '</table>';
    if (count($ammessi_insuff) < $num_ammessi) {
      // stampa tabella solo se ci sono ammessi SENZA insuff
      $pdf->writeHTML($html, true, false, false, true);
      $pdf->Ln(2);
    }
    if (count($ammessi_insuff) > 0) {
      // QUINTE: ammessi con insuff
      $pdf->Ln(5);
      $pdf->SetFont('times', '', 11);
      $html = '<p align="justify"><b><i>Il Consiglio di Classe dichiara ammessi</i></b>'.
        ($dati['classe']->getAnno() == 5 ? ' all\'Esame di Stato' : ' alla classe successiva').
        ', pur in presenza di una votazione inferiore a sei decimi in una sola disciplina, i seguenti alunni (C.M. 3050 del 4 ottobre 2018):</p>';
      $pdf->writeHTML($html, true, false, false, true);
      $pdf->Ln(1);
      $pdf->SetFont('helvetica', '', 10);
      $html = '<table border="1" cellpadding="3">'.
        '<tr nobr="true"><td width="40%" align="center"><strong>ALUNNO</strong></td><td width="16%" align="center"><strong>Delibera</strong></td><td width="44%" align="center"><strong>Motivazione</strong></td></tr>';
      foreach ($dati['alunni'] as $idalunno=>$alu) {
        if (isset($ammessi_insuff[$idalunno])) {
          // solo alunni ammessi con insuff.
          $nome = $alu['cognome'].' '.$alu['nome'].' ('.$alu['dataNascita']->format('d/m/Y').')';
          $valori = $dati['esiti'][$idalunno]->getDati();
          if ($valori['unanimita']) {
            $esito_approvazione = 'UNANIMITÀ';
          } else {
            $esito_approvazione = "MAGGIORANZA\nContrari: ".$valori['contrari'];
          }
          $motivazione = str_replace(array("\r","\n"), ' ', $valori['giudizio']);
          $html .= '<tr nobr="true"><td><strong>'.$nome.'</strong></td><td align="center" style="font-size:9pt">'.$esito_approvazione.'</td><td style="font-size:9pt">'.$motivazione.'</td></tr>';
        }
      }
      $html .= '</table>';
      $pdf->writeHTML($html, true, false, false, true);
      $pdf->Ln(2);
    }
    // solo triennio: crediti
    if ($dati['classe']->getAnno() >= 3) {
      $pdf->SetFont('times', '', 11);
      $html = '<p align="justify">Contestualmente alla definizione dei voti, il Consiglio di Classe determina per ciascun alunno il relativo credito scolastico, secondo la nuova tabella di attribuzione del punteggio (ai sensi dell\'art. 15 del d.lgs. 62/2017).</p>';
      $pdf->writeHTML($html, true, false, false, true);
      $pdf->Ln(3);
      $pdf->SetFont('helvetica', '', 10);
      $html = '<div align="center"><b>Tabella Crediti Scolastici</b></div>'.
        '<table border="1" cellpadding="3">'.
        '<tr nobr="true"><td width="25%" align="center"><strong>Media dei voti (M)</strong></td><td width="25%" align="center"><strong>Punti di credito scolastico per la classe terza</strong></td><td width="25%" align="center"><strong>Punti di credito scolastico per la classe quarta</strong></td><td width="25%" align="center"><strong>Punti di credito scolastico per la classe quinta</strong></td></tr>'.
        '<tr nobr="true"><td align="center">M &lt; 6</td><td align="center">-</td><td align="center">-</td><td align="center">7 - 8</td></tr>'.
        '<tr nobr="true"><td align="center">M = 6</td><td align="center">7 - 8</td><td align="center">8 - 9</td><td align="center">9 - 10</td></tr>'.
        '<tr nobr="true"><td align="center">6 &lt; M &lt;= 7</td><td align="center">8 - 9</td><td align="center">9 - 10</td><td align="center">10 - 11</td></tr>'.
        '<tr nobr="true"><td align="center">7 &lt; M &lt;= 8</td><td align="center">9 - 10</td><td align="center">10 - 11</td><td align="center">11 - 12</td></tr>'.
        '<tr nobr="true"><td align="center">8 &lt; M &lt;= 9</td><td align="center">10 - 11</td><td align="center">11 - 12</td><td align="center">13 - 14</td></tr>'.
        '<tr nobr="true"><td align="center">9 &lt; M &lt;= 10</td><td align="center">11 - 12</td><td align="center">12 - 13</td><td align="center">14 - 15</td></tr>'.
        '</table>';
      $pdf->writeHTML($html, true, false, false, true);
      $pdf->Ln(3);
      $pdf->SetFont('times', '', 11);
      $html = '<p align="justify">Il Consiglio di Classe attribuisce il seguente credito scolastico agli alunni:</p>';
      $pdf->writeHTML($html, true, false, false, true);
      $pdf->Ln(1);
      $pdf->SetFont('helvetica', '', 10);
      if ($dati['classe']->getAnno() == 3) {
        $html = '<table border="1" cellpadding="3">'.
          '<tr nobr="true"><td width="40%" align="center"><strong>ALUNNO</strong></td><td width="10%" align="center"><strong>Media voti</strong></td><td width="40%" align="center"><strong>Criteri</strong></td><td width="10%" align="center"><strong>Credito</strong></td></tr>';
      } else {
        $html = '<table border="1" cellpadding="3">'.
          '<tr nobr="true"><td width="40%" align="center"><strong>ALUNNO</strong></td><td width="10%" align="center"><strong>Media voti</strong></td><td width="20%" align="center"><strong>Criteri</strong></td><td width="10%" align="center"><strong>Credito</strong></td><td width="10%" align="center"><strong>Credito anni prec.</strong></td><td width="10%" align="center"><strong>Credito totale</strong></td></tr>';
      }
      foreach ($dati['alunni'] as $idalunno=>$alu) {
        if (in_array($idalunno, $dati['scrutinati']) && $dati['esiti'][$idalunno]->getEsito() == 'A') {
          // solo alunni ammessi
          $nome = $alu['cognome'].' '.$alu['nome'].' ('.$alu['dataNascita']->format('d/m/Y').')';
          $esito = $dati['esiti'][$idalunno];
          $media = number_format($esito->getMedia(), 2, ',', '');
          $valori = $esito->getDati();
          // criteri
          $criteri = '';
          foreach ($valori['creditoScolastico'] as $c) {
            $criteri .= '; '.$this->trans->trans('label.criterio_credito_desc_'.$c);
          }
          if (strlen($criteri) <= 2) {
            $criteri = '-----';
          } else {
            $criteri = substr($criteri, 2).'.';
          }
          if ($dati['classe']->getAnno() == 3) {
            $html .= '<tr nobr="true"><td>'.$nome.'</td><td align="center">'.$media.'</td><td style="font-size:9pt">'.$criteri.'</td><td align="center">'. $esito->getCredito().'</td></tr>';
          } else {
            $cred_tot = $esito->getCredito() + $esito->getCreditoPrecedente();
            $html .= '<tr nobr="true"><td>'.$nome.'</td><td align="center">'.$media.'</td><td style="font-size:9pt">'.$criteri.'</td><td align="center">'. $esito->getCredito().'</td><td align="center">'. $esito->getCreditoPrecedente().'</td><td align="center">'. $cred_tot.'</td></tr>';
          }
        }
      }
      $html .= '</table>';
      $pdf->writeHTML($html, true, false, false, true);
      $pdf->Ln(2);
    } elseif ($dati['classe']->getAnno() == 2) {
      // certificazione competenze
      $pdf->SetFont('times', '', 11);
      $html = '<p align="justify">Contestualmente alla definizione dei voti, il Consiglio di Classe certifica le competenze di base acquisite dagli studenti (ai sensi del D.M. n.139 del 22 agosto 2007).</p>';
      $pdf->writeHTML($html, true, false, false, true);
      $pdf->Ln(1);
    }
    // sospensione giudizio
    if ($num_sospesi > 0) {
      // sospesi
      $pdf->Ln(5);
      $pdf->SetFont('times', '', 11);
      $html = '<p align="justify"><b><i>Il Consiglio di Classe sospende la formulazione del giudizio finale</i></b>'.
        ', sulla base della normativa vigente (art. 4 comma 6 del D.P.R. 122 del 2009), per gli alunni che presentano dei debiti formativi. Questi dovranno essere colmati attraverso interventi educativi organizzati dalla Scuola o mediante lo studio autonomo, con l\'obbligo per gli alunni di sottoporsi alle prove di verifica del superamento delle carenze riscontrate.</p>';
      $pdf->writeHTML($html, true, false, false, true);
      $pdf->Ln(1);
      $html = '<p align="justify">Vengono di seguito riportati gli alunni con giudizio sospeso con il dettaglio dei debiti formativi:</p>';
      $pdf->writeHTML($html, true, false, false, true);
      $pdf->Ln(1);
      $table = '<table border="1" cellpadding="3">
        <tr nobr="true"><td width="30%" align="center"><strong>MATERIA</strong></td><td width="7%" align="center"><strong>Voto</strong></td><td width="50%" align="center"><strong>Debito formativo</strong></td><td width="13%" align="center"><strong>Modalità di recupero</strong></td></tr>';
      foreach ($dati['alunni'] as $idalunno=>$alu) {
        if (in_array($idalunno, $dati['scrutinati']) && $dati['esiti'][$idalunno]->getEsito() == 'S') {
          // solo sospesi
          $nome = $alu['cognome'].' '.$alu['nome'].' ('.$alu['dataNascita']->format('d/m/Y').')';
          $valori = $dati['esiti'][$idalunno]->getDati();
          if ($valori['unanimita']) {
            $esito_approvazione = "all'unanimità";
          } else {
            $esito_approvazione = 'a maggioranza - contrari '.$valori['contrari'];
          }
          $pdf->SetFont('times', '', 11);
          $html = '<ul><li><b>'.$nome.'</b><br>Delibera '.$esito_approvazione.'</li></ul>';
          $pdf->writeHTML($html, true, false, false, true);
          $pdf->SetFont('helvetica', '', 10);
          $html = $table;
          foreach ($dati['debiti'][$idalunno] as $deb) {
            $voto = ($deb['unico'] == 0 ? 'NC' : $deb['unico']);
            $debito = str_replace(array("\r","\n"), ' ', $deb['debito']);
            $recupero = $this->trans->trans('label.recupero_'.$deb['recupero']);
            $html .= '<tr nobr="true"><td>'.$deb['materia'].'</td><td align="center">'.$voto.'</td><td style="font-size:9pt">'.$debito.'</td><td align="center" style="font-size:9pt">'.$recupero.'</td></tr>';
          }
          $html .= '</table>';
          $pdf->writeHTML($html, true, false, false, true);
          $pdf->Ln(2);
        }
      }
    }
    // non ammessi
    if ($num_bocciati > 0) {
      $pdf->Ln(5);
      $pdf->SetFont('times', '', 11);
      $html = '<p align="justify"><b><i>Il Consiglio di Classe</i></b></p>';
      $pdf->writeHTML($html, true, false, false, true);
      $pdf->Ln(1);
      $html = '<ul>'.
        '<li>tenuto conto degli obiettivi generali e specifici previsti nella programmazione iniziale;</li>'.
        '<li>considerati tutti gli elementi che concorrono alla valutazione finale: interesse, partecipazione, metodo di studio, impegno;</li>'.
        '<li>valutati gli obiettivi minimi previsti per le singole discipline: conoscenze degli argomenti, proprietà espressiva, capacità di analisi, applicazione, capacità di giudizio autonomo;</li>'.
        '<li>preso atto della gravità delle carenze accertate nelle diverse discipline;</li>'.
        '</ul>';
      $pdf->writeHTML($html, true, false, false, true);
      $pdf->Ln(1);
      $html = '<p align="justify"><b><i>dichiara non ammessi</i></b>'.
        ($dati['classe']->getAnno() == 5 ? ' all\'Esame di Stato' : ' alla classe successiva').' i seguenti alunni:</p>';
      $pdf->writeHTML($html, true, false, false, true);
      $pdf->Ln(1);
      $pdf->SetFont('helvetica', '', 10);
      $html = '<table border="1" cellpadding="3">'.
        '<tr nobr="true"><td width="40%" align="center"><strong>ALUNNO</strong></td><td width="16%" align="center"><strong>Delibera</strong></td><td width="44%" align="center"><strong>Motivazione della non ammissione</strong></td></tr>';
      foreach ($dati['alunni'] as $idalunno=>$alu) {
        if (in_array($idalunno, $dati['scrutinati']) && $dati['esiti'][$idalunno]->getEsito() == 'N') {
          // solo alunni non ammessi
          $nome = $alu['cognome'].' '.$alu['nome'].' ('.$alu['dataNascita']->format('d/m/Y').')';
          $valori = $dati['esiti'][$idalunno]->getDati();
          if ($valori['unanimita']) {
            $esito_approvazione = 'UNANIMITÀ';
          } else {
            $esito_approvazione = "MAGGIORANZA\nContrari: ".$valori['contrari'];
          }
          $esito_giudizio = str_replace(array("\r","\n"), ' ', $valori['giudizio']);
          $html .= '<tr nobr="true"><td><strong>'.$nome.'</strong></td><td align="center" style="font-size:9pt">'.$esito_approvazione.'</td><td style="font-size:9pt">'.$esito_giudizio.'</td></tr>';
        }
      }
      $html .= '</table>';
      $pdf->writeHTML($html, true, false, false, true);
      $pdf->Ln(2);
    }
    // riepilogo
    $pdf->Ln(5);
    $pdf->SetFont('times', '', 11);
    $html = '<p align="justify">Terminata la fase deliberativa, si procede, a cura del coordinatore, alla stampa dei tabelloni e alla firma del Registro Generale dei voti.</p>';
    $pdf->writeHTML($html, true, false, false, true);
    $pdf->Ln(1);
    $html = '<p>Il riepilogo dei voti deliberati per ciascun alunno e ciascuna disciplina viene allegato al presente verbale, di cui fa parte integrante.</p>';
    $pdf->writeHTML($html, true, false, false, true);
    $pdf->Ln(1);
    $html = '<p align="justify">I risultati complessivi dello scrutinio della classe '.$classe.' vengono così riassunti:</p>';
    $pdf->writeHTML($html, true, false, false, true);
    $pdf->SetFont('helvetica', '', 10);
    $num_ritirati = count($dati['ritirati']);
    $num_no_scrutinati = count($dati['no_scrutinabili']) + count($dati['cessata_frequenza']);
    $num_scrutinati = count($dati['scrutinati']);
    $num_tot = $num_scrutinati + $num_no_scrutinati + $num_ritirati;
    $html = '<table border="0" cellpadding="1" width="90%">'.
      '<tr nobr="true"><td width="70%" align="right"><b>Iscritti:</b></td><td width="30%">'.$num_tot.'</td></tr>'.
        '<tr nobr="true"><td width="70%" align="right"><b>Ritirati, trasferiti, o che frequentano l\'anno all\'estero:</b></td><td width="30%">'.$num_ritirati.'</td></tr>'.
      '<tr nobr="true"><td width="70%" align="right"><b>Non scrutinati:</b></td><td width="30%">'.$num_no_scrutinati.'</td></tr>'.
      '<tr nobr="true"><td width="70%" align="right"><b>Regolarmente scrutinati:</b></td><td width="30%">'.$num_scrutinati.'</td></tr>'.
      '<tr nobr="true"><td width="70%" align="right"><b>AMMESSI:</b></td><td width="30%">'.$num_ammessi.'</td></tr>'.
      '<tr nobr="true"><td width="70%" align="right"><b>GIUDIZIO SOSPESO:</b></td><td width="30%">'.$num_sospesi.'</td></tr>'.
      '<tr nobr="true"><td width="70%" align="right"><b>NON AMMESSI:</b></td><td width="30%">'.$num_bocciati.'</td></tr>'.
      '</table>';
    $pdf->writeHTML($html, true, false, false, true);
    $pdf->Ln(5);
  }

  /**
   * Crea il verbale come documento PDF: assegnamento nuovi crediti
   *
   * @param TCPDF $pdf Gestore del documento PDF
   * @param string $classe Nome della classe
   * @param string $classe_completa Nome della classe con corso e sede
   * @param array $dati Dati dello scrutinio
   * @param int $step Passo della struttura del verbale
   * @param array $args Argomenti della struttura del verbale
   */
  public function CreaVerbale_F_NuoviCrediti($pdf, $classe, $classe_completa, $dati, $step, $args) {
    if ($dati['classe']->getAnno() >= 4) {
      // solo per quarte e quinte
      $alunni_credito = ($dati['definizione']->getDati()['nuovi_crediti'] == null ? [] : $dati['definizione']->getDati()['nuovi_crediti']);
      $lista_alunni = array_intersect($dati['scrutinati'], $alunni_credito);
      if (count($lista_alunni) > 0) {
        // sono presenti alunni con nuovo credito
        $nuovicrediti = $dati['scrutinio']->getDato('nuovicrediti');
        $pdf->SetFont('times', '', 11);
        $html = '<p align="justify">Il Consiglio di Classe esamina il credito scolastico conseguito dagli alunni '.
          ($dati['classe']->getAnno() == 5 ? 'nelle classi terza e quarta' : 'nella classe terza').
          ' per procedere all\'adeguamento del punteggio secondo quanto previsto per il nuovo Esame di Stato'.
          ' (d.lgs. 62/2017 e circolare MIUR 3050.04-10-2018), relativamente ai soli alunni per i quali non è stato possibile farlo nello scrutinio del primo trimestre.<br>Di seguito viene riportato il nuovo credito scolastico risultante:</p>';
        $pdf->writeHTML($html, true, false, false, true);
        $pdf->Ln(1);
        $pdf->SetFont('helvetica', '', 10);
        $html = '<table border="1" cellpadding="3">'.
          '<tr nobr="true"><td width="30%" align="center"><strong>ALUNNO</strong></td><td width="15%" align="center"><strong>Credito<br>Terza</strong></td>'.
          ($dati['classe']->getAnno() == 5 ? '<td width="15%" align="center"><strong>Credito<br>Quarta</strong></td><td width="40%" align="center">' : '<td width="55%" align="center">').
          '<strong>Nuovo Credito</strong></td></tr>';
        foreach ($dati['alunni'] as $idalunno=>$alu) {
          if (in_array($idalunno, $lista_alunni)) {
            // alunno con nuovo credito
            $nome = $alu['cognome'].' '.$alu['nome'].' ('.$alu['dataNascita']->format('d/m/Y').')';
            $motivazione = htmlentities(str_replace(array("\r", "\n"), ' ', $nuovicrediti[$idalunno][1]));
            $html .= '<tr nobr="true"><td rowspan="2">'.$nome.'</td>'.
              '<td>'.($alu['credito3'] > 0 ? $alu['credito3'] : '-').'</td>'.
              ($dati['classe']->getAnno() == 5 ? '<td>'.($alu['credito4'] > 0 ? $alu['credito4'] : '-').'</td>' : '').
              '<td><strong>'.($nuovicrediti[$idalunno][0] > 0 ? $nuovicrediti[$idalunno][0] : 'NON ASSEGNATO').'</strong></td>'.
              '</tr>'.
              '<tr nobr="true"><td style="font-size:9pt" colspan="'.($dati['classe']->getAnno() == 5 ? 3 : 2).'"><em>'.$motivazione.'</em></td></tr>';
          }
        }
        $html .= '</table>';
        $pdf->writeHTML($html, true, false, false, true);
        $pdf->Ln(5);
      }
    }
  }

  /**
   * Crea il verbale come documento PDF: fine scrutinio
   *
   * @param TCPDF $pdf Gestore del documento PDF
   * @param string $classe Nome della classe
   * @param string $classe_completa Nome della classe con corso e sede
   * @param array $dati Dati dello scrutinio
   * @param int $step Passo della struttura del verbale
   * @param array $args Argomenti della struttura del verbale
   */
  public function CreaVerbale_F_ScrutinioFine($pdf, $classe, $classe_completa, $dati, $step, $args) {
    $pdf->Ln(5);
    $pdf->SetFont('times', '', 11);
    $orascrutinio_fine = $dati['scrutinio']->getFine()->format('H:i');
    $html = '<p align="justify">Alle ore '.$orascrutinio_fine.', terminate tutte le operazioni, la seduta è tolta.</p>';
    $pdf->writeHTML($html, true, false, false, true);
    $pdf->Ln(10);
    // firma
    if ($dati['scrutinio']->getDato('presiede_ds')) {
      $presidente_nome = $this->session->get('/CONFIG/ISTITUTO/firma_preside');
    } else {
      $d = $dati['docenti'][$dati['scrutinio']->getDato('presiede_docente')][0];
      if ($dati['scrutinio']->getDato('presenze')[$d['id']]->getPresenza()) {
        $presidente_nome = ($d['sesso'] == 'M' ? 'Prof.' : 'Prof.ssa').' '.
          $d['cognome'].' '.$d['nome'];
      } else {
        $presidente_nome = ($dati['scrutinio']->getDato('presenze')[$d['id']]->getSessoSostituto() == 'M' ? 'Prof.' : 'Prof.ssa').' '.
          ucwords(strtolower($dati['scrutinio']->getDato('presenze')[$d['id']]->getSostituto()));
      }
    }
    $d = $dati['docenti'][$dati['scrutinio']->getDato('segretario')][0];
    if ($dati['scrutinio']->getDato('presenze')[$d['id']]->getPresenza()) {
      $segretario_nome = ($d['sesso'] == 'M' ? 'Prof.' : 'Prof.ssa').' '.
        $d['cognome'].' '.$d['nome'];
    } else {
      $segretario_nome = ($dati['scrutinio']->getDato('presenze')[$d['id']]->getSessoSostituto() == 'M' ? 'Prof.' : 'Prof.ssa').' '.
        ucwords(strtolower($dati['scrutinio']->getDato('presenze')[$d['id']]->getSostituto()));
    }
    $html = '<table border="0" cellpadding="3" nobr="true">
      <tr nobr="true"><td width="45%" align="center">Il Segretario</td><td width="10%">&nbsp;</td><td width="45%" align="center">Il Presidente</td></tr>
      <tr nobr="true"><td align="center"><em>'.$segretario_nome.'</em></td><td>&nbsp;</td><td align="center"><em>'.$presidente_nome.'</em></td></tr>
      </table>';
    $pdf->writeHTML($html, true, false, false, true);
  }

  /**
   * Crea il verbale come documento PDF: comunicazione esiti
   *
   * @param TCPDF $pdf Gestore del documento PDF
   * @param string $classe Nome della classe
   * @param string $classe_completa Nome della classe con corso e sede
   * @param array $dati Dati dello scrutinio
   * @param int $step Passo della struttura del verbale
   * @param array $args Argomenti della struttura del verbale
   */
  public function CreaVerbale_F_ScrutinioComunicazioni($pdf, $classe, $classe_completa, $dati, $step, $args) {
    $pdf->SetFont('times', '', 11);
    $num_arg = $args[2]['argomento'];
    $argomento = $dati['definizione']->getArgomenti()[$num_arg];
    $html = '<p align="justify"><strong>'.$args[2]['sezione'].'. '.$argomento.'.</strong></p>';
    $pdf->writeHTML($html, true, false, false, true);
    $pdf->Ln(1);
    $html = '<p align="justify">Il Dirigente Scolastico fa presente che il Consiglio di Classe, prima della pubblicazione dei risultati, deve dare comunicazione dell’esito di non ammissione alle famiglie degli alunni minorenni, mediante fonogramma registrato.</p>';
    $pdf->writeHTML($html, true, false, false, true);
    $pdf->Ln(1);
    $html = '<p align="justify">Il Consiglio di Classe predispone quindi le comunicazioni da inviare alle famiglie a riguardo dell\'esito dello scrutinio e degli eventuali debiti formativi. Le famiglie potranno visualizzare queste comunicazioni direttamente sul registro elettronico.</p>';
    $pdf->writeHTML($html, true, false, false, true);
    $pdf->Ln(1);
  }

  /**
   * Crea la tabella di conversione dei crediti per la classe quinta
   *
   * @param TCPDF $pdf Gestore del documento PDF
   * @param string $classe Nome della classe
   * @param string $classe_completa Nome della classe con corso e sede
   * @param array $dati Dati dello scrutinio
   * @param int $step Passo della struttura del verbale
   * @param array $args Argomenti della struttura del verbale
   */
  public function CreaVerbale_F_ScrutinioConversioneCrediti($pdf, $classe, $classe_completa, $dati, $step, $args) {
  }

  /**
   * Gestione PAI
   *
   * @param TCPDF $pdf Gestore del documento PDF
   * @param string $classe Nome della classe
   * @param string $classe_completa Nome della classe con corso e sede
   * @param array $dati Dati dello scrutinio
   * @param int $step Passo della struttura del verbale
   * @param array $args Argomenti della struttura del verbale
   */
  public function CreaVerbale_F_ScrutinioPAI($pdf, $classe, $classe_completa, $dati, $step, $args) {
  }

  /**
   * Gestione PIA
   *
   * @param TCPDF $pdf Gestore del documento PDF
   * @param string $classe Nome della classe
   * @param string $classe_completa Nome della classe con corso e sede
   * @param array $dati Dati dello scrutinio
   * @param int $step Passo della struttura del verbale
   * @param array $args Argomenti della struttura del verbale
   */
  public function CreaVerbale_F_ScrutinioPIA($pdf, $classe, $classe_completa, $dati, $step, $args) {
  }

  /**
   * Crea il documento PIA
   *
   * @param Classe $classe Classe dello scrutinio
   * @param string $periodo Periodo dello scrutinio
   *
   * @return Percorso completo del file da inviare
   */
  public function PIA(Classe $classe, $periodo) {
    // inizializza
    $fs = new Filesystem();
    if ($periodo == 'F' && $classe->getAnno() != 5) {
      // scrutinio finale
      $percorso = $this->root.'/finale/'.$classe->getAnno().$classe->getSezione();
      if (!$fs->exists($percorso)) {
        // crea directory
        $fs->mkdir($percorso, 0775);
      }
      // nome documento
      $nomefile = $classe->getAnno().$classe->getSezione().'-piano-di-integrazione-degli-apprendimenti.pdf';
      if (!$fs->exists($percorso.'/'.$nomefile)) {
        // crea pdf
        $this->pdf->configure($this->session->get('/CONFIG/ISTITUTO/intestazione'),
          'Piano di Integrazione degli Apprendimenti - Classe '.$classe->getAnno().'ª '.$classe->getSezione());
        $this->pdf->getHandler()->SetAutoPageBreak(true, 20);
        $this->pdf->getHandler()->SetFooterMargin(10);
        $this->pdf->getHandler()->setFooterFont(Array('helvetica', '', 9));
        $this->pdf->getHandler()->setFooterData(array(0,0,0), array(255,255,255));
        $this->pdf->getHandler()->setPrintFooter(true);
        // legge materie
        $materie = $this->em->getRepository('App:Materia')->createQueryBuilder('m')
          ->join('App:Cattedra', 'c', 'WITH', 'c.materia=m.id')
          ->where('c.classe=:classe AND c.attiva=:attiva AND c.tipo=:tipo AND m.tipo!=:sostegno')
          ->orderBy('m.ordinamento', 'ASC')
          ->setParameters(['classe' => $classe, 'attiva' => 1, 'tipo' => 'N', 'sostegno' => 'S'])
          ->getQuery()
          ->getResult();
        foreach ($materie as $mat) {
          $dati['piani'][$mat->getId()] = null;
        }
        // legge piani
        $piani = $this->em->getRepository('App:DocumentoInterno')->createQueryBuilder('di')
          ->join('di.materia', 'm')
          ->where('di.tipo=:tipo AND di.classe=:classe')
          ->setParameters(['tipo' => 'A', 'classe' => $classe])
          ->getQuery()
          ->getResult();
        $dati['no_piano'] = true;
        foreach ($piani as $p) {
          $dati['piani'][$p->getMateria()->getId()] = $p;
          if ($p->getDato('necessario')) {
            $dati['no_piano'] = false;
          }
        }
        // legge scrutinio
        $scrutinio = $this->em->getRepository('App:Scrutinio')->findOneBy(['classe' => $classe, 'periodo' => 'F']);
        // crea documento
        $dati['classe'] = $classe;
        $dati['data_scrutinio'] = $scrutinio->getData();
        $html = $this->tpl->render('coordinatore/documenti/scrutinio_PIA.html.twig', array('dati' => $dati));
        $this->pdf->createFromHtml($html);
        // salva il documento
        $this->pdf->save($percorso.'/'.$nomefile);
      }
      // restituisce nome del file
      return $percorso.'/'.$nomefile;
    }
    // errore
    return null;
  }

  /**
   * Crea il documento PAI
   *
   * @param Classe $classe Classe dello scrutinio
   * @param Alunno $alunno Alunno selezionato
   * @param string $periodo Periodo dello scrutinio
   *
   * @return Percorso completo del file da inviare
   */
  public function PAI(Classe $classe, Alunno $alunno, $periodo) {
    // inizializza
    $fs = new Filesystem();
    if ($periodo == 'F' && $classe->getAnno() != 5) {
      // scrutinio finale
      $percorso = $this->root.'/finale/'.$classe->getAnno().$classe->getSezione();
      if (!$fs->exists($percorso)) {
        // crea directory
        $fs->mkdir($percorso, 0775);
      }
      // nome documento
      $nomefile = $classe->getAnno().$classe->getSezione().'-piano-di-apprendimento-individualizzato-'.
        $alunno->getId().'.pdf';
      if (!$fs->exists($percorso.'/'.$nomefile)) {
        // crea pdf
        $this->pdf->configure($this->session->get('/CONFIG/ISTITUTO/intestazione'),
          'Piano di Apprendimento Individualizzato - Alunno '.$alunno->getCognome().' '.$alunno->getNome());
        $this->pdf->getHandler()->SetAutoPageBreak(true, 20);
        $this->pdf->getHandler()->SetFooterMargin(10);
        $this->pdf->getHandler()->setFooterFont(Array('helvetica', '', 9));
        $this->pdf->getHandler()->setFooterData(array(0,0,0), array(255,255,255));
        $this->pdf->getHandler()->setPrintFooter(true);
        // legge materie
        $materie = $this->em->getRepository('App:Materia')->createQueryBuilder('m')
          ->join('App:Cattedra', 'c', 'WITH', 'c.materia=m.id')
          ->where('c.classe=:classe AND c.attiva=:attiva AND c.tipo=:tipo AND m.tipo!=:sostegno')
          ->orderBy('m.ordinamento', 'ASC')
          ->setParameters(['classe' => $classe, 'attiva' => 1, 'tipo' => 'N', 'sostegno' => 'S'])
          ->getQuery()
          ->getResult();
        foreach ($materie as $mat) {
          $dati['piani'][$mat->getId()] = null;
        }
        // legge i voti e PAI
        $voti = $this->em->getRepository('App:VotoScrutinio')->createQueryBuilder('vs')
          ->join('vs.scrutinio', 's')
          ->join('vs.materia', 'm')
          ->join('App:Esito', 'e', 'WITH', 'e.scrutinio=vs.scrutinio AND e.alunno=vs.alunno')
          ->where('s.classe=:classe AND s.periodo=:periodo AND vs.unico IS NOT NULL AND vs.alunno=:alunno AND m.tipo!=:condotta AND e.esito=:ammesso')
          ->setParameters(['classe' => $classe, 'periodo' => 'F', 'alunno' => $alunno, 'condotta' => 'C', 'ammesso' => 'A'])
          ->getQuery()
          ->getResult();
        foreach ($voti as $v) {
          // inserisce voti
          if (($v->getMateria()->getTipo() == 'R' && $v->getUnico() < 22) ||
              ($v->getMateria()->getTipo() != 'R' && $v->getUnico() < 6)) {
            // solo materie insufficienti
            $dati['piani'][$v->getMateria()->getId()] = $v;
          }
        }
        // legge scrutinio
        $scrutinio = $this->em->getRepository('App:Scrutinio')->findOneBy(['classe' => $classe, 'periodo' => 'F']);
        // crea documento
        $dati['classe'] = $classe;
        $dati['alunno'] = $alunno;
        $dati['data_scrutinio'] = $scrutinio->getData();
        $html = $this->tpl->render('coordinatore/documenti/scrutinio_PAI.html.twig', array('dati' => $dati));
        $this->pdf->createFromHtml($html);
        // salva il documento
        $this->pdf->save($percorso.'/'.$nomefile);
      }
      // restituisce nome del file
      return $percorso.'/'.$nomefile;
    }
    // errore
    return null;
  }

  /**
   * Crea il documento del verbale
   *
   * @param Classe $classe Classe dello scrutinio
   * @param Alunno $alunno Alunno selezionato
   * @param string $periodo Periodo dello scrutinio
   *
   * @return Percorso completo del file da inviare
   */
  public function verbale2(Classe $classe, $periodo) {
    // inizializza
    $fs = new Filesystem();
    if ($periodo == 'F') {
      // scrutinio finale
      $percorso = $this->root.'/finale/'.$classe->getAnno().$classe->getSezione();
      if (!$fs->exists($percorso)) {
        // crea directory
        $fs->mkdir($percorso, 0775);
      }
      // nome documento
      $nomefile = $classe->getAnno().$classe->getSezione().'-scrutinio-finale-verbale.pdf';
      if (!$fs->exists($percorso.'/'.$nomefile)) {
        // crea documento
        $nome_classe = $classe->getAnno().'ª '.$classe->getSezione();
        $this->pdf->configure($this->session->get('/CONFIG/ISTITUTO/intestazione'),
          'Scrutinio Finale - Verbale classe '.$nome_classe);
        $this->pdf->getHandler()->SetAutoPageBreak(true, 20);
        $this->pdf->getHandler()->SetFooterMargin(10);
        $this->pdf->getHandler()->setFooterFont(Array('helvetica', '', 9));
        $this->pdf->getHandler()->setFooterData(array(0,0,0), array(255,255,255));
        $this->pdf->getHandler()->setPrintFooter(true);
        // legge dati
        $dati = $this->verbaleDati($classe, $periodo);
        // crea documento
        $html = $this->tpl->render('coordinatore/documenti/scrutinio_verbale.html.twig', array('dati' => $dati));
        $this->pdf->createFromHtml($html);
        // salva il documento
        $this->pdf->save($percorso.'/'.$nomefile);
      }
      // restituisce nome del file
      return $percorso.'/'.$nomefile;
    }
    // errore
    return null;
  }

  /**
   * Crea tutti i documenti PAI della classe
   *
   * @param Classe $classe Classe dello scrutinio
   * @param string $periodo Periodo dello scrutinio
   *
   * @return Percorso completo del file da inviare
   */
  public function tuttiPAI(Classe $classe, $periodo) {
    // inizializza
    $fs = new Filesystem();
    if ($periodo == 'F' && $classe->getAnno() != 5) {
      // scrutinio finale
      $percorso = $this->root.'/finale/'.$classe->getAnno().$classe->getSezione();
      if (!$fs->exists($percorso)) {
        // crea directory
        $fs->mkdir($percorso, 0775);
      }

      // nome documento
      $nomefile = $classe->getAnno().$classe->getSezione().'-piani-di-apprendimento-individualizzato.pdf';
      if (!$fs->exists($percorso.'/'.$nomefile)) {
        // crea pdf
        $this->pdf->configure($this->session->get('/CONFIG/ISTITUTO/intestazione'),
          'Piani di Apprendimento Individualizzato - Classe '.$classe->getAnno().'ª '.$classe->getSezione());
        $this->pdf->getHandler()->SetAutoPageBreak(true, 20);
        $this->pdf->getHandler()->SetFooterMargin(10);
        $this->pdf->getHandler()->setFooterFont(Array('helvetica', '', 9));
        $this->pdf->getHandler()->setFooterData(array(0,0,0), array(255,255,255));
        $this->pdf->getHandler()->setPrintFooter(true);
        // legge scrutinio
        $scrutinio = $this->em->getRepository('App:Scrutinio')->findOneBy(['classe' => $classe, 'periodo' => 'F']);
        // legge materie
        $materie = $this->em->getRepository('App:Materia')->createQueryBuilder('m')
          ->join('App:Cattedra', 'c', 'WITH', 'c.materia=m.id')
          ->where('c.classe=:classe AND c.attiva=:attiva AND c.tipo=:tipo AND m.tipo!=:sostegno')
          ->orderBy('m.ordinamento', 'ASC')
          ->setParameters(['classe' => $classe, 'attiva' => 1, 'tipo' => 'N', 'sostegno' => 'S'])
          ->getQuery()
          ->getResult();
        // legge alunni ammessi
        $alunni = $this->em->getRepository('App:Alunno')->createQueryBuilder('a')
          ->join('App:Esito', 'e', 'WITH', 'e.alunno=a.id')
          ->where('a.id IN (:lista) AND e.scrutinio=:scrutinio AND e.esito=:esito')
          ->orderBy('a.cognome,a.nome,a.dataNascita', 'ASC')
          ->setParameters(['lista' => array_keys($scrutinio->getDato('scrutinabili')),
            'scrutinio' => $scrutinio, 'esito' => 'A'])
          ->getQuery()
          ->getResult();
        foreach ($alunni as $alu) {
          // dati di alunno
          $dati = array();
          foreach ($materie as $mat) {
            $dati['piani'][$mat->getId()] = null;
          }
          // legge i voti e PAI
          $voti = $this->em->getRepository('App:VotoScrutinio')->createQueryBuilder('vs')
            ->join('vs.materia', 'm')
            ->where('vs.alunno=:alunno AND vs.scrutinio=:scrutinio AND vs.unico IS NOT NULL AND m.tipo!=:condotta')
            ->setParameters(['alunno' => $alu, 'scrutinio' => $scrutinio, 'condotta' => 'C'])
            ->getQuery()
            ->getResult();
          $da_fare = false;
          foreach ($voti as $v) {
            // inserisce voti
            if (($v->getMateria()->getTipo() == 'R' && $v->getUnico() < 22) ||
                ($v->getMateria()->getTipo() != 'R' && $v->getUnico() < 6)) {
              // solo materie insufficienti
              $dati['piani'][$v->getMateria()->getId()] = $v;
              $da_fare = true;
            }
          }
          if ($da_fare) {
            // crea documento
            $dati['classe'] = $classe;
            $dati['alunno'] = $alu;
            $dati['data_scrutinio'] = $scrutinio->getData();
            $this->pdf->getHandler()->startPageGroup();
            $html = $this->tpl->render('coordinatore/documenti/scrutinio_PAI.html.twig', array('dati' => $dati));
            $this->pdf->createFromHtml($html);
          }
        }
        // salva il documento
        $this->pdf->save($percorso.'/'.$nomefile);
      }
      // restituisce nome del file
      return $percorso.'/'.$nomefile;
    }
    // errore
    return null;
  }

  /**
   * Crea la certificazione delle competenze
   *
   * @param Classe $classe Classe dello scrutinio
   * @param Alunno $alunno Alunno dello scrutinio
   * @param string $periodo Periodo dello scrutinio
   *
   * @return Percorso completo del file da inviare
   */
  public function certificazione(Classe $classe, Alunno $alunno, $periodo) {
    // inizializza
    $fs = new Filesystem();
    if ($periodo == 'F') {
      // scrutinio finale
      $percorso = $this->root.'/finale/'.$classe->getAnno().$classe->getSezione();
      if (!$fs->exists($percorso)) {
        // crea directory
        $fs->mkdir($percorso, 0775);
      }
      // nome documento
      $nomefile = $classe->getAnno().$classe->getSezione().'-scrutinio-finale-certificazione-'.$alunno->getId().'.pdf';
      if (!$fs->exists($percorso.'/'.$nomefile)) {
        // crea pdf
        $this->pdf->configure($this->session->get('/CONFIG/ISTITUTO/intestazione'),
          'Scrutinio Finale - Certificazione delle competenze - Alunno '.$alunno->getCognome().' '.$alunno->getNome());
        $dati = $this->certificazioniDati($classe, $periodo);
        if (!in_array($alunno->getId(), array_keys($dati['ammessi']))) {
          // errore: alunno non ammesso
        }
        // crea il documento
        $nome_classe = $classe->getAnno().'ª '.$classe->getSezione();
        $nome_classe_lungo = $nome_classe.' '.$classe->getCorso()->getNomeBreve().' - '.$classe->getSede()->getCitta();
        $this->creaCertificazione_F($this->pdf->getHandler(), $nome_classe, $nome_classe_lungo, $dati, $alunno);
        // salva il documento
        $this->pdf->save($percorso.'/'.$nomefile);
      }
      // restituisce nome del file
      return $percorso.'/'.$nomefile;
    }
    // errore
    return null;
  }

  /**
   * Crea le certificazioni delle competenze come documento PDF
   *
   * @param TCPDF $pdf Gestore del documento PDF
   * @param string $classe Nome della classe
   * @param string $classe_completa Nome della classe con corso e sede
   * @param array $dati Dati dello scrutinio
   * @param Alunno $alunno Alunno dello scrutinio
   */
  public function creaCertificazione_F($pdf, $classe, $classe_completa, $dati, Alunno $alunno) {
    // set margins
    $pdf->SetMargins(20, 20, 20, true);
    // set auto page breaks
    $pdf->SetAutoPageBreak(false, 15);
    // set font
    $pdf->SetFont('helvetica', '', 10);
    // inizio pagina
    $pdf->SetFooterMargin(15);
    $pdf->setFooterFont(Array('helvetica', '', 8));
    $pdf->setFooterData(array(0,0,0), array(255,255,255));
    $pdf->setPrintFooter(true);
    $pdf->setHeaderTemplateAutoreset(true);
    foreach ($dati['ammessi'] as $idalunno=>$alu) {
      if ($idalunno != $alunno->getId()) {
        // salta
        continue;
      }
      // alunno da certificare
      $valori = $alu['dati'];
      // inizia gruppo pagine
      $pdf->setPrintHeader(false);
      $pdf->startPageGroup();
      $pdf->AddPage('P');
      $alu_cognome = strtoupper($alu['cognome']);
      $alu_nome = strtoupper($alu['nome']);
      $alu_sesso = $alu['sesso'];
      $alu_nascita = $alu['dataNascita']->format('d/m/Y');
      $alu_citta = strtoupper($alu['comuneNascita']);
      // prima pagina
      $html = '<img src="/img/'.getenv('LOCAL_PATH').'intestazione-documenti.jpg" width="540">';
      $pdf->writeHTML($html, true, false, false, false, 'C');
      $pdf->Ln(3);
      $pdf->SetFont('times', 'B', 12);
      $as = $this->session->get('/CONFIG/SCUOLA/anno_scolastico');
      $html = '<p><span style="font-size:14">CERTIFICATO delle COMPETENZE DI BASE</span><br>'.
              '<span style="font-size:11">acquisite nell\'assolvimento dell\' OBBLIGO DI ISTRUZIONE</span></p>'.
              '<p>Anno Scolastico '.$as.'</p>'.
              '<p>&nbsp;</p>';
      $pdf->writeHTML($html, true, false, false, false, 'C');
      $pdf->SetFont('times', '', 11);
      $html = '<p>N° ..............</p>'.
              '<p style="text-align:center;font-weight:bold">IL DIRIGENTE SCOLASTICO</p>'.
              '<p>Visto il regolamento emanato dal Ministro dell\'Istruzione, Università e Ricerca con decreto 22 agosto 2007, n.139;</p>'.
              '<p>Visti gli atti di ufficio;</p>';
      $pdf->writeHTML($html, true, false, false, false, 'L');
      $this->acapo($pdf, 5);
      $text = ($alu_sesso == 'M' ? 'che lo studente' : 'che la studentessa');
      $pdf->SetFont('times', 'B', 14);
      $html = '<p>CERTIFICA<br>'.
              '<span style="font-style:italic">'.$text.'</span></p>';
      $pdf->writeHTML($html, true, false, false, false, 'C');
      // cognome e nome
      $pdf->SetFont('times', '', 11);
      $this->cella($pdf, 18, 8, 0, 0, 'cognome', 0, 'L', 'B');
      $pdf->SetFont('times', 'B', 11);
      $this->cella($pdf, 67, 8, 0, 0, $alu_cognome, 'B', 'L', 'B');
      $pdf->SetFont('times', '', 11);
      $this->cella($pdf, 18, 8, 0, 0, 'nome', 0, 'R', 'B');
      $pdf->SetFont('times', 'B', 11);
      $this->cella($pdf, 67, 8, 0, 0, $alu_nome, 'B', 'L', 'B');
      $this->acapo($pdf, 8);
      // data e città nascita
      $text = ($alu_sesso == 'M' ? 'nato' : 'nata').' il';
      $pdf->SetFont('times', '', 11);
      $this->cella($pdf, 18, 8, 0, 0, $text, 0, 'L', 'B');
      $pdf->SetFont('times', 'B', 11);
      $this->cella($pdf, 67, 8, 0, 0, $alu_nascita, 'B', 'L', 'B');
      $pdf->SetFont('times', '', 11);
      $this->cella($pdf, 18, 8, 0, 0, 'a', 0, 'R', 'B');
      $pdf->SetFont('times', 'B', 11);
      $this->cella($pdf, 67, 8, 0, 0, $alu_citta, 'B', 'L', 'B');
      $this->acapo($pdf, 8);
      // sezione
      $as = $this->session->get('/CONFIG/SCUOLA/anno_scolastico');
      $text = ($alu_sesso == 'M' ? 'iscritto' : 'iscritta').
              ' nell\'anno scolastico '.$as.' presso questo Istituto nella classe II sezione';
      $pdf->SetFont('times', '', 11);
      $this->cella($pdf, 132, 8, 0, 0, $text, 0, 'L', 'B');
      $pdf->SetFont('times', 'B', 11);
      $text = $dati['classe']->getSezione().' - '.$dati['classe']->getSede()->getCitta();
      $this->cella($pdf, 0, 8, 0, 0, $text, 'B', 'L', 'B');
      $this->acapo($pdf, 8);
      // corso
      $pdf->SetFont('times', '', 11);
      $this->cella($pdf, 32, 8, 0, 0, 'indirizzo di studio', 0, 'L', 'B');
      $pdf->SetFont('times', 'B', 11);
      $this->cella($pdf, 0, 8, 0, 0, $dati['classe']->getCorso()->getNome(), 'B', 'L', 'B');
      $this->acapo($pdf, 8);
      // dichiarazione
      $text = 'nell\'assolvimento dell\'obbligo di istruzione, della durata di 10 anni,';
      $pdf->SetFont('times', '', 11);
      $this->cella($pdf, 0, 8, 0, 0, $text, 0, 'L', 'B');
      $this->acapo($pdf, 8);
      $pdf->SetFont('times', 'BI', 14);
      $this->cella($pdf, 0, 10, 0, 0, 'ha acquisito', 0, 'C', 'B');
      $this->acapo($pdf, 10);
      $text = 'le competenze di base di seguito indicate.';
      $pdf->SetFont('times', '', 11);
      $this->cella($pdf, 0, 8, 0, 0, $text, 0, 'L', 'B');
      $this->acapo($pdf, 8);
      // note
      $pdf->SetFont('helvetica', 'B', 9);
      $this->acapo($pdf, 20);
      $this->cella($pdf, 90, 5, 40, 0, 'Note', 'T', 'C', 'B');
      $this->acapo($pdf, 5);
      $html = '<p>1) Il presente certificato ha validità nazionale.</p>'.
              '<p>2) I livelli relativi all’acquisizione delle competenze di ciascun asse sono i seguenti:<br>'.
              'LIVELLO BASE: lo studente svolge compiti semplici in situazioni note, mostrando di possedere conoscenze ed abilità essenziali e di saper applicare regole e procedure fondamentali. Nel caso in cui non sia stato raggiunto il livello base, è riportata l’espressione "Livello base non raggiunto", con l’indicazione della relativa motivazione.<br>'.
              'LIVELLO INTERMEDIO: lo studente svolge compiti e risolve problemi complessi in situazioni note, compie scelte consapevoli, mostrando di saper utilizzare le conoscenze e le abilità acquisite.<br>'.
              'LIVELLO AVANZATO: lo studente svolge compiti e problemi complessi in situazioni anche non note, mostrando padronanza nell’uso delle conoscenze e delle abilità. Es. proporre e sostenere le proprie opinioni e assumere autonomamente decisioni consapevoli.</p>';
      $pdf->writeHTML($html, true, false, false, false, 'L');
      // nuova pagina
      $pdf->SetHeaderMargin(10);
      $pdf->setHeaderFont(Array('helvetica', 'B', 6));
      $pdf->setHeaderData('', 0, $alu_cognome.' '.$alu_nome.' - 2ª '.$dati['classe']->getSezione(), '', array(0,0,0), array(255,255,255));
      $pdf->setPrintHeader(true);
      $pdf->AddPage('P');
      // intestazione
      $pdf->SetFont('helvetica', 'B', 11);
      $this->cella($pdf, 0, 5, 0, 0, 'COMPETENZE DI BASE E RELATIVI LIVELLI RAGGIUNTI', 0, 'C', 'M');
      $this->acapo($pdf, 5);
      $pdf->SetFont('helvetica', 'B', 10);
      $this->cella($pdf, 85, 5, 0, 0, 'ASSE DEI LINGUAGGI', 1, 'C', 'M');
      $this->cella($pdf, 0, 5, 0, 0, 'LIVELLI', 1, 'C', 'M');
      $this->acapo($pdf, 5);
      // asse linguaggi-1
      $pdf->SetFont('helvetica', '', 9);
      $pdf->setListIndentWidth(3);
      $tagvs = array('ul' => array(0 => array('h' => 0.0001, 'n' => 1)));
      $pdf->setHtmlVSpace($tagvs);
      $html = '<i><b>Lingua Italiana:</b></i><ul>'.
              '<li>Padroneggiare gli strumenti espressivi ed argomentativi indispensabili per gestire l\'interazione comunicativa verbale in vari contesti</li>'.
              '<li>Leggere comprendere e interpretare testi scritti di vario tipo</li>'.
              '<li>Produrre testi di vario tipo in relazione ai differenti scopi comunicativi</li></ul>';
      $pdf->writeHTMLCell(85, 32, $pdf->GetX(), $pdf->GetY(), $html, 1, 0, false, true, 'L', true);
      $text = $this->trans->trans('label.certificazione_livello_'.$valori['certificazione_italiano']).
        ($valori['certificazione_italiano'] == 'N' ?
        ' per la seguente motivazione: '.$valori['certificazione_italiano_motivazione'] : '');
      $pdf->SetFont('helvetica', 'B', 10);
      $this->cella($pdf, 0, 32, 0, 0, $text, 1, 'C', 'M');
      $this->acapo($pdf, 32);
      // asse linguaggi-2
      $pdf->SetFont('helvetica', '', 9);
      $pdf->setListIndentWidth(3);
      $tagvs = array('ul' => array(0 => array('h' => 0.0001, 'n' => 1)));
      $pdf->setHtmlVSpace($tagvs);
      $html = '<i><b>Lingua straniera:</b></i><ul>'.
              '<li>Utilizzare la lingua Inglese per i principali scopi comunicativi ed operativi</li></ul>';
      $pdf->writeHTMLCell(85, 18, $pdf->GetX(), $pdf->GetY(), $html, 1, 0, false, true, 'L', true);
      $text = $this->trans->trans('label.certificazione_livello_'.$valori['certificazione_lingua']).
        ($valori['certificazione_lingua'] == 'N' ?
        ' per la seguente motivazione: '.$valori['certificazione_lingua_motivazione'] : '');
      $pdf->SetFont('helvetica', 'B', 10);
      $this->cella($pdf, 0, 18, 0, 0, $text, 1, 'C', 'M');
      $this->acapo($pdf, 18);
      // asse linguaggi-3
      $pdf->SetFont('helvetica', '', 9);
      $pdf->setListIndentWidth(3);
      $tagvs = array('ul' => array(0 => array('h' => 0.0001, 'n' => 1)));
      $pdf->setHtmlVSpace($tagvs);
      $html = '<i><b>Altri linguaggi:</b></i><ul>'.
              '<li>Utilizzare gli strumenti fondamentali per una fruizione consapevole del patrimonio artistico e letterario</li>'.
              '<li>Utilizzare e produrre testi multimediali</li></ul>';
      $pdf->writeHTMLCell(85, 18, $pdf->GetX(), $pdf->GetY(), $html, 1, 0, false, true, 'L', true);
      $text = $this->trans->trans('label.certificazione_livello_'.$valori['certificazione_linguaggio']).
        ($valori['certificazione_linguaggio'] == 'N' ?
        ' per la seguente motivazione: '.$valori['certificazione_linguaggio_motivazione'] : '');
      $pdf->SetFont('helvetica', 'B', 10);
      $this->cella($pdf, 0, 18, 0, 0, $text, 1, 'C', 'M');
      $this->acapo($pdf, 18);
      // asse matematico-4
      $pdf->SetFont('helvetica', 'B', 10);
      $this->cella($pdf, 85, 5, 0, 0, 'ASSE MATEMATICO', 1, 'C', 'M');
      $this->cella($pdf, 0, 5, 0, 0, 'LIVELLI', 1, 'C', 'M');
      $this->acapo($pdf, 5);
      $pdf->SetFont('helvetica', '', 9);
      $pdf->setListIndentWidth(3);
      $tagvs = array('ul' => array(0 => array('h' => 0.0001, 'n' => 1)));
      $pdf->setHtmlVSpace($tagvs);
      $html = '<ul><li>Utilizzare le tecniche e le procedure del calcolo aritmetico ed algebrico, rappresentandole anche sotto forma grafica</li>'.
              '<li>Confrontare ed analizzare figure geometriche, individuando invarianti e relazioni</li>'.
              '<li>Individuare le strategie appropriate per la soluzione dei problemi</li>'.
              '<li>Analizzare dati e interpretarli sviluppando deduzioni e ragionamenti sugli stessi anche con l’ausilio di rappresentazioni grafiche, usando consapevolmente gli strumenti di calcolo e le potenzialità offerte da applicazioni specifiche di tipo informatico</li></ul>';
      $pdf->writeHTMLCell(85, 48, $pdf->GetX(), $pdf->GetY(), $html, 1, 0, false, true, 'L', true);
      $text = $this->trans->trans('label.certificazione_livello_'.$valori['certificazione_matematica']).
        ($valori['certificazione_matematica'] == 'N' ?
        ' per la seguente motivazione: '.$valori['certificazione_matematica_motivazione'] : '');
      $pdf->SetFont('helvetica', 'B', 10);
      $this->cella($pdf, 0, 48, 0, 0, $text, 1, 'C', 'M');
      $this->acapo($pdf, 48);
      // asse scientifico-5
      $pdf->SetFont('helvetica', 'B', 10);
      $this->cella($pdf, 85, 5, 0, 0, 'ASSE SCIENTIFICO-TECNOLOGICO', 1, 'C', 'M');
      $this->cella($pdf, 0, 5, 0, 0, 'LIVELLI', 1, 'C', 'M');
      $this->acapo($pdf, 5);
      $pdf->SetFont('helvetica', '', 9);
      $pdf->setListIndentWidth(3);
      $tagvs = array('ul' => array(0 => array('h' => 0.0001, 'n' => 1)));
      $pdf->setHtmlVSpace($tagvs);
      $html = '<ul><li>Osservare, descrivere ed analizzare fenomeni appartenenti alla realtà naturale e artificiale e riconoscere nelle varie forme i concetti di sistema e di complessità</li>'.
              '<li>Analizzare qualitativamente e quantitativamente fenomeni legati alle trasformazioni di energia a partire dall’esperienza</li>'.
              '<li>Essere consapevoli delle potenzialità e dei limiti delle tecnologie nel contesto culturale e sociale in cui vengono applicate</li></ul>';
      $pdf->writeHTMLCell(85, 40, $pdf->GetX(), $pdf->GetY(), $html, 1, 0, false, true, 'L', true);
      $text = $this->trans->trans('label.certificazione_livello_'.$valori['certificazione_scienze']).
        ($valori['certificazione_scienze'] == 'N' ?
        ' per la seguente motivazione: '.$valori['certificazione_scienze_motivazione'] : '');
      $pdf->SetFont('helvetica', 'B', 10);
      $this->cella($pdf, 0, 40, 0, 0, $text, 1, 'C', 'M');
      $this->acapo($pdf, 40);
      // asse storico-6
      $pdf->SetFont('helvetica', 'B', 10);
      $this->cella($pdf, 85, 5, 0, 0, 'ASSE STORICO-SOCIALE', 1, 'C', 'M');
      $this->cella($pdf, 0, 5, 0, 0, 'LIVELLI', 1, 'C', 'M');
      $this->acapo($pdf, 5);
      $pdf->SetFont('helvetica', '', 9);
      $pdf->setListIndentWidth(3);
      $tagvs = array('ul' => array(0 => array('h' => 0.0001, 'n' => 1)));
      $pdf->setHtmlVSpace($tagvs);
      $html = '<ul><li>Comprendere il cambiamento e la diversità dei tempi storici in una dimensione diacronica attraverso il confronto fra epoche e in una dimensione sincronica attraverso il confronto fra aree geografiche e culturali</li>'.
              '<li>Collocare l’esperienza personale in un sistema di regole fondato sul reciproco riconoscimento dei diritti garantiti dalla Costituzione, a tutela della persona, della collettività e dell’ambiente</li>'.
              '<li>Riconoscere le caratteristiche essenziali del sistema socio economico per orientarsi nel tessuto produttivo del proprio territorio</li></ul>';
      $pdf->writeHTMLCell(85, 44, $pdf->GetX(), $pdf->GetY(), $html, 1, 0, false, true, 'L', true);
      $text = $this->trans->trans('label.certificazione_livello_'.$valori['certificazione_storia']).
        ($valori['certificazione_storia'] == 'N' ?
        ' per la seguente motivazione: '.$valori['certificazione_storia_motivazione'] : '');
      $pdf->SetFont('helvetica', 'B', 10);
      $this->cella($pdf, 0, 44, 0, 0, $text, 1, 'C', 'M');
      $this->acapo($pdf, 44);
      // dichiarazione
      $text = 'Le competenze di base relative agli assi culturali sopra richiamati sono state acquisite dallo studente con riferimento alle competenze chiave di cittadinanza di cui all’allegato 2 del regolamento citato in premessa (1. imparare ad imparare; 2. progettare; 3. comunicare; 4. collaborare e partecipare; 5. agire in modo autonomo e responsabile; 6. risolvere problemi; 7. individuare collegamenti e  relazioni; 8. acquisire e interpretare l’informazione).';
      $pdf->SetFont('helvetica', '', 10);
      $this->cella($pdf, 0, 18, 0, 0, $text, 0, 'L', 'B');
      $this->acapo($pdf, 18);
      // data e firma
      $datascrutinio = $dati['scrutinio']->getData()->format('d/m/Y');
      $pdf->SetFont('helvetica', '', 11);
      $this->cella($pdf, 30, 14, 0, 0, 'Cagliari,', 0, 'R', 'B');
      $this->cella($pdf, 30, 14, 0, 0, $datascrutinio, 'B', 'C', 'B');
      $pdf->SetXY(-80, $pdf->GetY());
      $preside = $this->session->get('/CONFIG/ISTITUTO/firma_preside');
      $text = '(Il Dirigente Scolastico)'."\n".$preside;
      $this->cella($pdf, 60, 15, 0, 0, $text, 'B', 'C', 'B');
    }
  }

}

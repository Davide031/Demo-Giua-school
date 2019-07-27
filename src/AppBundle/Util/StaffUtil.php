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


namespace AppBundle\Util;

use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Translation\TranslatorInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use AppBundle\Util\RegistroUtil;
use AppBundle\Util\GenitoriUtil;
use AppBundle\Entity\Classe;
use AppBundle\Entity\Alunno;
use AppBundle\Entity\Docente;


/**
 * StaffUtil - classe di utilità per le funzioni disponibili allo staff
 */
class StaffUtil {


  //==================== ATTRIBUTI DELLA CLASSE  ====================

  /**
   * @var RouterInterface $router Gestore delle URL
   */
  private $router;

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
   * @var RegistroUtil $regUtil Funzioni di utilità per il registro
   */
  private $regUtil;

  /**
   * @var GenitoriUtil $genUtil Funzioni di utilità per i genitori
   */
  private $genUtil;


  //==================== METODI DELLA CLASSE ====================

  /**
   * Construttore
   *
   * @param RouterInterface $router Gestore delle URL
   * @param EntityManagerInterface $em Gestore delle entità
   * @param TranslatorInterface $trans Gestore delle traduzioni
   * @param SessionInterface $session Gestore delle sessioni
   * @param RegistroUtil $regUtil Funzioni di utilità per il registro
   * @param GenitoriUtil $genUtil Funzioni di utilità per i genitori
   */
  public function __construct(RouterInterface $router, EntityManagerInterface $em, TranslatorInterface $trans,
                               SessionInterface $session, RegistroUtil $regUtil, GenitoriUtil $genUtil) {
    $this->router = $router;
    $this->em = $em;
    $this->trans = $trans;
    $this->session = $session;
    $this->regUtil = $regUtil;
    $this->genUtil = $genUtil;
  }

  /**
   * Restituisce dati degli alunni per la gestione dei ritardi e delle uscite
   *
   * @param \DateTime $inizio Data di inizio del periodo da considerare
   * @param \DateTime $fine Data di fine del periodo da considerare
   * @param Paginator $lista Lista degli alunni da considerare
   *
   * @return array Informazioni sui ritard/uscite come valori di array associativo
   */
  public function entrateUscite(\DateTime $inizio, \DateTime $fine, Paginator $lista) {
    $dati = array();
    // scansione della lista
    foreach ($lista as $a) {
      $alunno = array();
      $alunno['alunno_id'] = $a->getId();
      $alunno['nome'] = $a->getCognome().' '.$a->getNome().' ('.$a->getDataNascita()->format('d/m/Y').')';
      $alunno['classe_id'] = $a->getClasse()->getId();
      $alunno['classe'] = $a->getClasse()->getAnno().'ª '.$a->getClasse()->getSezione();
      // dati ritardi
      $entrate = $this->em->getRepository('AppBundle:Entrata')->createQueryBuilder('e')
        ->select('e.data,e.ora,e.note')
        ->where('e.valido=:valido AND e.alunno=:alunno AND e.data BETWEEN :inizio AND :fine')
        ->setParameters(['valido' => 1, 'alunno' => $a, 'inizio' => $inizio->format('Y-m-d'),
          'fine' => $fine->format('Y-m-d')])
        ->orderBy('e.data', 'DESC')
        ->getQuery()
        ->getArrayResult();
      $alunno['entrate'] = $entrate;
      // dati uscite
      $uscite = $this->em->getRepository('AppBundle:Uscita')->createQueryBuilder('u')
        ->select('u.data,u.ora,u.note')
        ->where('u.valido=:valido AND u.alunno=:alunno AND u.data BETWEEN :inizio AND :fine')
        ->setParameters(['valido' => 1, 'alunno' => $a, 'inizio' => $inizio->format('Y-m-d'),
          'fine' => $fine->format('Y-m-d')])
        ->orderBy('u.data', 'DESC')
        ->getQuery()
        ->getArrayResult();
      $alunno['uscite'] = $uscite;
      // aggiunge alunno
      $dati[] = $alunno;
    }
    // restituisce dati
    return $dati;
  }

  /**
   * Restituisce le note della classe indicata.
   *
   * @param Classe $classe Classe selezionata
   *
   * @return array Dati restituiti come array associativo
   */
  public function note(Classe $classe) {
    $mesi = ['', 'Gennaio', 'Febbraio', 'Marzo', 'Aprile', 'Maggio', 'Giugno', 'Luglio', 'Agosto', 'Settembre', 'Ottobre', 'Novembre', 'Dicembre'];
    $periodi = $this->regUtil->infoPeriodi();
    $dati = array();
    // legge note di classe
    $note = $this->em->getRepository('AppBundle:Nota')->createQueryBuilder('n')
      ->select("n.data,n.testo,CONCAT(d.nome,' ',d.cognome) AS docente,n.provvedimento,CONCAT(dp.nome,' ',dp.cognome) AS docente_prov")
      ->join('n.docente', 'd')
      ->leftJoin('n.docenteProvvedimento', 'dp')
      ->where('n.tipo=:tipo AND n.classe=:classe')
      ->setParameters(['tipo' => 'C', 'classe' => $classe])
      ->getQuery()
      ->getArrayResult();
    // imposta array associativo per note di classe
    $dati_periodo = array();
    foreach ($note as $n) {
      $data = $n['data']->format('Y-m-d');
      $numperiodo = ($data <= $periodi[1]['fine'] ? 1 : ($data <= $periodi[2]['fine'] ? 2 : 3));
      $data_str = intval(substr($data, 8)).' '.$mesi[intval(substr($data, 5, 2))];
      $d = array();
      $dati_periodo[$numperiodo][$data]['classe'][] = array(
        'data' => $data_str,
        'nota' => $n['testo'],
        'nota_doc' => $n['docente'],
        'provvedimento' => $n['provvedimento'],
        'provvedimento_doc' => $n['docente_prov']);
    }
    // legge note individuali
    $individuali = $this->em->getRepository('AppBundle:Nota')->createQueryBuilder('n')
      ->join('n.alunni', 'a')
      ->join('n.docente', 'd')
      ->leftJoin('n.docenteProvvedimento', 'dp')
      ->where('n.tipo=:tipo AND n.classe=:classe')
      ->setParameters(['tipo' => 'I', 'classe' => $classe])
      ->getQuery()
      ->getResult();
    // imposta array associativo per note individuali
    foreach ($individuali as $n) {
      $data = $n->getData()->format('Y-m-d');
      $numperiodo = ($data <= $periodi[1]['fine'] ? 1 : ($data <= $periodi[2]['fine'] ? 2 : 3));
      $data_str = intval(substr($data, 8)).' '.$mesi[intval(substr($data, 5, 2))];
      $alunni = array();
      foreach ($n->getAlunni() as $alu) {
        $alunni[] = $alu->getCognome().' '.$alu->getNome();
      }
      $dati_periodo[$numperiodo][$data]['individuale'][] = array(
        'data' => $data_str,
        'nota' => $n->getTesto(),
        'nota_doc' => $n->getDocente()->getNome().' '.$n->getDocente()->getCognome(),
        'provvedimento' => $n->getProvvedimento(),
        'provvedimento_doc' => $n->getDocenteProvvedimento() ?
          ($n->getDocenteProvvedimento()->getNome().' '.$n->getDocenteProvvedimento()->getCognome()) : '',
        'alunni' => $alunni);
    }
    // ordina periodi
    for ($k = 3; $k >= 1; $k--) {
      if (isset($dati_periodo[$k])) {
        krsort($dati_periodo[$k]);
        $dati[$periodi[$k]['nome']] = $dati_periodo[$k];
      }
    }
    // restituisce dati come array associativo
    return $dati;
  }

  /**
   * Restituisce le assenze della classe indicata.
   *
   * @param Classe $classe Classe dell'alunno
   *
   * @return array Dati restituiti come array associativo
   */
  public function assenze(Classe $classe) {
    $dati = array();
    // legge alunni
    $lista_alunni = $this->regUtil->alunniInData(new \DateTime(), $classe);
    // legge assenze
    $assenze = $this->em->getRepository('AppBundle:Assenza')->createQueryBuilder('a')
      ->select('(a.alunno) AS id,a.giustificato')
      ->where('a.alunno IN (:lista)')
      ->setParameters(['lista' => $lista_alunni])
      ->getQuery()
      ->getArrayResult();
    // imposta array associativo per le assenze
    foreach ($assenze as $a) {
      if (!isset($dati['statistiche'][$a['id']]['assenze'])) {
        $dati['statistiche'][$a['id']]['assenze'] = 0;
        $dati['statistiche'][$a['id']]['giustifica-ass'] = 0;
      }
      $dati['statistiche'][$a['id']]['assenze']++;
      if (!$a['giustificato']) {
        $dati['statistiche'][$a['id']]['giustifica-ass']++;
      }
    }
    // legge ritardi
    $entrate = $this->em->getRepository('AppBundle:Entrata')->createQueryBuilder('e')
      ->select('(e.alunno) AS id,e.data,e.ora,e.ritardoBreve,e.giustificato,e.valido')
      ->where('e.alunno IN (:lista)')
      ->setParameters(['lista' => $lista_alunni])
      ->getQuery()
      ->getArrayResult();
    // imposta array associativo per i ritardi
    foreach ($entrate as $e) {
      if (!isset($dati['statistiche'][$e['id']]['ritardi'])) {
        $dati['statistiche'][$e['id']]['ritardi'] = 0;
        $dati['statistiche'][$e['id']]['brevi'] = 0;
        $dati['statistiche'][$e['id']]['giustifica-rit'] = 0;
        $dati['statistiche'][$e['id']]['conta-ritardi'] = 0;
      }
      $dati['statistiche'][$e['id']]['ritardi']++;
      if ($e['ritardoBreve']) {
        $dati['statistiche'][$e['id']]['brevi']++;
      }
      if (!$e['giustificato']) {
        $dati['statistiche'][$e['id']]['giustifica-rit']++;
      }
      if ($e['valido']) {
        $dati['statistiche'][$e['id']]['conta-ritardi']++;
      }
    }
    // legge uscite anticipate
    $uscite = $this->em->getRepository('AppBundle:Uscita')->createQueryBuilder('u')
      ->select('(u.alunno) AS id,u.data,u.ora,u.valido')
      ->where('u.alunno IN (:lista)')
      ->setParameters(['lista' => $lista_alunni])
      ->getQuery()
      ->getArrayResult();
    // imposta array associativo per le uscite
    foreach ($uscite as $u) {
      if (!isset($dati['statistiche'][$u['id']]['uscite'])) {
        $dati['statistiche'][$u['id']]['uscite'] = 0;
        $dati['statistiche'][$u['id']]['conta-uscite'] = 0;
      }
      $dati['statistiche'][$u['id']]['uscite']++;
      if ($u['valido']) {
        $dati['statistiche'][$u['id']]['conta-uscite']++;
      }
    }
    // ore di assenza (escluso sostegno/supplenza/religione)
    $ore_N = $this->em->getRepository('AppBundle:AssenzaLezione')->createQueryBuilder('al')
      ->select('(al.alunno) AS id,SUM(al.ore) AS ore')
      ->join('al.lezione', 'l')
      ->join('l.materia', 'm')
      ->leftJoin('AppBundle:CambioClasse', 'cc', 'WHERE', 'cc.alunno=al.alunno AND l.data BETWEEN cc.inizio AND cc.fine')
      ->where('al.alunno IN (:lista) AND m.tipo=:tipo AND (l.classe=:classe OR l.classe=cc.classe)')
      ->groupBy('al.alunno')
      ->setParameters(['lista' => $lista_alunni, 'classe' => $classe, 'tipo' => 'N'])
      ->getQuery()
      ->getArrayResult();
    // ore di assenza di religione (per chi si avvale)
    $ore_R = $this->em->getRepository('AppBundle:AssenzaLezione')->createQueryBuilder('al')
      ->select('(al.alunno) AS id,SUM(al.ore) AS ore')
      ->join('al.lezione', 'l')
      ->join('al.alunno', 'a')
      ->join('l.materia', 'm')
      ->leftJoin('AppBundle:CambioClasse', 'cc', 'WHERE', 'cc.alunno=al.alunno AND l.data BETWEEN cc.inizio AND cc.fine')
      ->where('al.alunno IN (:lista) AND a.religione=:religione AND m.tipo=:tipo AND (l.classe=:classe OR l.classe=cc.classe)')
      ->groupBy('al.alunno')
      ->setParameters(['lista' => $lista_alunni, 'classe' => $classe, 'religione' => 'S', 'tipo' => 'R'])
      ->getQuery()
      ->getArrayResult();
    // ore di assenza totali
    $ore = array();
    foreach ($ore_N as $o) {
      $ore[$o['id']] = $o['ore'];
    }
    foreach ($ore_R as $o) {
      if (isset($ore[$o['id']])) {
        $ore[$o['id']] += $o['ore'];
      } else {
        $ore[$o['id']] = $o['ore'];
      }
    }
    // imposta array associativo per le ore di assenza
    $dati['monte'] = $classe->getOreSettimanali() * 33;
    foreach ($ore as $id=>$o) {
      $dati['statistiche'][$id]['ore'] = number_format($o, 1, ',', null);
      $dati['statistiche'][$id]['perc'] = number_format($o / $dati['monte'] * 100, 2, ',', null);
    }
    // dati alunni
    $alunni = $this->em->getRepository('AppBundle:Alunno')->createQueryBuilder('a')
      ->select('a.id,a.cognome,a.nome,a.dataNascita,a.bes,a.note')
      ->where('a.id IN (:lista)')
      ->orderBy('a.cognome,a.nome,a.dataNascita', 'ASC')
      ->setParameters(['lista' => $lista_alunni])
      ->getQuery()
      ->getArrayResult();
    // imposta array associativo per gli alunni
    foreach ($alunni as $a) {
      $dati['alunni'][$a['id']]['nome'] = $a['cognome'].' '.$a['nome'].' ('.$a['dataNascita']->format('d/m/Y').')';
      $dati['alunni'][$a['id']]['bes'] = $a['bes'];
      $dati['alunni'][$a['id']]['note'] = $a['note'];
    }
    // restituisce dati come array associativo
    return $dati;
  }

  /**
   * Restituisce i voti medi della classe indicata.
   *
   * @param Classe $classe Classe dell'alunno
   *
   * @return array Dati restituiti come array associativo
   */
  public function voti(Classe $classe) {
    $dati = array();
    $periodo = $this->regUtil->periodo(new \DateTime());
    $dati['periodo'] = $periodo['nome'];
    // lista materie
    $materie = $this->em->getRepository('AppBundle:Materia')->createQueryBuilder('m')
      ->select('DISTINCT m.id,m.nome,m.nomeBreve')
      ->join('AppBundle:Cattedra', 'c', 'WHERE', 'c.materia=m.id')
      ->where('c.classe=:classe AND c.attiva=:attiva AND m.valutazione=:valutazione AND m.media=:media')
      ->orderBy('m.ordinamento', 'ASC')
      ->setParameters(['classe' => $classe, 'attiva' => 1, 'valutazione' => 'N', 'media' => 1])
      ->getQuery()
      ->getArrayResult();
    // imposta array associativo per le materie
    foreach ($materie as $m) {
      $dati['materie'][$m['id']] = $m;
    }
    // legge alunni
    $lista_alunni = $this->regUtil->alunniInData(new \DateTime(), $classe);
    // legge medie
    $voti = $this->em->getRepository('AppBundle:Valutazione')->createQueryBuilder('v')
      ->select('(v.alunno) AS alunno,(l.materia) AS materia,v.tipo,AVG(v.voto) AS media')
      ->join('v.lezione', 'l')
      ->join('l.materia', 'm')
      ->where('v.alunno IN (:lista) AND v.media=:media AND v.voto>0 AND l.classe=:classe AND l.data BETWEEN :inizio AND :fine AND m.media=:media')
      ->groupBy('v.alunno,l.materia,v.tipo')
      ->setParameters(['lista' => $lista_alunni, 'media' => 1, 'classe' => $classe,
        'inizio' => $periodo['inizio'], 'fine' => $periodo['fine']])
      ->getQuery()
      ->getArrayResult();
    // imposta array associativo per gli alunni
    $medie = array();
    foreach ($voti as $v) {
      if (!isset($medie[$v['alunno']][$v['materia']])) {
        $medie[$v['alunno']][$v['materia']]['somma'] = $v['media'];
        $medie[$v['alunno']][$v['materia']]['num'] = 1;
      } else {
        $medie[$v['alunno']][$v['materia']]['somma'] += $v['media'];
        $medie[$v['alunno']][$v['materia']]['num']++;
      }
    }
    $somma = array();
    $numero = array();
    foreach ($medie as $alu=>$v) {
      $somma[$alu] = 0;
      $numero[$alu] = 0;
      foreach ($v as $mat=>$m) {
        $dati['medie'][$alu][$mat] = number_format($m['somma'] / $m['num'], 1, ',', null);
        $somma[$alu] += $m['somma'] / $m['num'];
        $numero[$alu]++;
      }
      $dati['medie'][$alu][0] = number_format($somma[$alu] / $numero[$alu], 1, ',', null);
    }
    // dati alunni
    $alunni = $this->em->getRepository('AppBundle:Alunno')->createQueryBuilder('a')
      ->select('a.id,a.cognome,a.nome,a.dataNascita,a.bes,a.note')
      ->where('a.id IN (:lista)')
      ->orderBy('a.cognome,a.nome,a.dataNascita', 'ASC')
      ->setParameters(['lista' => $lista_alunni])
      ->getQuery()
      ->getArrayResult();
    // imposta array associativo per gli alunni
    foreach ($alunni as $a) {
      $dati['alunni'][$a['id']]['nome'] = $a['cognome'].' '.$a['nome'];
      $dati['alunni'][$a['id']]['nascita'] = $a['dataNascita']->format('d/m/Y');
      $dati['alunni'][$a['id']]['bes'] = $a['bes'];
      $dati['alunni'][$a['id']]['note'] = $a['note'];
    }
    // restituisce dati come array associativo
    return $dati;
  }

  /**
   * Restituisce la scansione oraria per le sedi della scuola.
   *
   * @return array Dati restituiti come array associativo
   */
  public function orarioPerSede() {
    $dati = array();
    // legge orario
    $ore = $this->em->getRepository('AppBundle:ScansioneOraria')->createQueryBuilder('so')
      ->select('s.citta,o.id,so.giorno,so.ora,so.inizio,so.fine,so.durata')
      ->join('so.orario', 'o')
      ->join('o.sede', 's')
      ->where(':data BETWEEN o.inizio AND o.fine')
      ->orderBy('s.id,so.giorno,so.ora', 'ASC')
      ->setParameters(['data' => (new \DateTime())->format('Y-m-d')])
      ->getQuery()
      ->getArrayResult();
    foreach ($ore as $o) {
      $dati[$o['citta']][$o['giorno']][$o['ora']] = [$o['inizio']->format('H:i'), $o['fine']->format('H:i'),
        $o['durata'], $o['id']];
    }
    return $dati;
  }

  /**
   * Restituisce i docenti per ognuna delle sedi della scuola.
   *
   * @return array Dati restituiti come array associativo
   */
  public function docentiPerSede() {
    $dati = array();
    // legge docenti
    $docenti = $this->em->getRepository('AppBundle:Cattedra')->createQueryBuilder('c')
      ->select('DISTINCT s.citta,d.id,d.cognome,d.nome')
      ->join('c.docente', 'd')
      ->join('c.classe', 'cl')
      ->join('cl.sede', 's')
      ->where('c.attiva=:attiva AND d.abilitato=:abilitato')
      ->orderBy('d.cognome,d.nome', 'ASC')
      ->setParameters(['attiva' => 1, 'abilitato' => 1])
      ->getQuery()
      ->getArrayResult();
    foreach ($docenti as $d) {
      $dati[$d['citta']][$d['id']] = $d['cognome'].' '.$d['nome'];
    }
    return $dati;
  }

  /**
   * Restituisce i dati degli alunni della classe indicata.
   *
   * @param Classe $classe Classe degli alunni
   *
   * @return array Dati restituiti come array associativo
   */
  public function alunni(Classe $classe) {
    $dati = array();
    // legge alunni
    $lista_alunni = $this->regUtil->alunniInData(new \DateTime(), $classe);
    $alunni = $this->em->getRepository('AppBundle:Alunno')->createQueryBuilder('a')
      ->select('a.id,a.cognome,a.nome,a.dataNascita,a.bes,a.note,a.religione,g.ultimoAccesso')
      ->join('AppBundle:Genitore', 'g', 'WHERE', 'g.alunno=a.id')
      ->where('a.id IN (:lista)')
      ->orderBy('a.cognome,a.nome,a.dataNascita', 'ASC')
      ->setParameters(['lista' => $lista_alunni])
      ->getQuery()
      ->getArrayResult();
    // imposta array associativo per gli alunni
    foreach ($alunni as $a) {
      $dati['alunni'][$a['id']]['nome'] = $a['cognome'].' '.$a['nome'];
      $dati['alunni'][$a['id']]['nascita'] = $a['dataNascita']->format('d/m/Y');
      $dati['alunni'][$a['id']]['bes'] = $a['bes'];
      $dati['alunni'][$a['id']]['note'] = $a['note'];
      $dati['alunni'][$a['id']]['religione'] = $a['religione'];
      $dati['alunni'][$a['id']]['accesso'] = $a['ultimoAccesso'];
    }
    // restituisce dati come array associativo
    return $dati;
  }

  /**
   * Restituisce la situazione dell'alunno indicato.
   *
   * @param Alunno $alunno Alunno selezionato
   * @param string $tipo Tipo di informazioni da mostrare [V=voti,S=scrutini,A=assenze,N=note,O=osservazioni,T=tutto]
   *
   * @return array Dati restituiti come array associativo
   */
  public function situazione(Alunno $alunno, $tipo) {
    $dati = array();
    // voti
    if ($tipo == 'V' || $tipo == 'T') {
      $d = $this->genUtil->voti($alunno->getClasse(), null, $alunno);
      foreach ($d as $periodo=>$p) {
        foreach ($p as $materia=>$m) {
          $dati['voti'][$materia][$periodo] = $m;
        }
      }
    }
    // scrutini
    if ($tipo == 'S' || $tipo == 'T') {
      // tutti gli scrutini svolti
      $lista = $this->genUtil->pagelleAlunno($alunno);
      foreach ($lista as $d) {
        $dati['scrutini'][$d[1]->getPeriodo()] =
          $this->genUtil->pagelle($d[1]->getClasse(), $alunno, $d[1]->getPeriodo());
      }
    }
    // assenze
    if ($tipo == 'A' || $tipo == 'T') {
      $dati['assenze'] = $this->genUtil->assenze($alunno->getClasse(), $alunno);
    }
    // note
    if ($tipo == 'N' || $tipo == 'T') {
      $dati['note'] = $this->genUtil->note($alunno->getClasse(), $alunno);
    }
    // osservazioni
    if ($tipo == 'O' || $tipo == 'T') {
      $dati['osservazioni'] = $this->genUtil->osservazioni($alunno);
    }
    // restituisce dati come array associativo
    return $dati;
  }

  /**
   * Restituisce le statistiche sulle ore di lezione dei docenti.
   *
   * @param mixed $docente Docente selezionato
   * @param \DateTime $inizio Data iniziale delle lezioni
   * @param \DateTime $fine Data finale delle lezioni
   * @param int $page Pagina corrente
   * @param int $limit Numero di elementi per pagina
   *
   * @return Paginator Oggetto Paginator
   */
  public function statistiche($docente, $inizio, $fine, $page=1, $limit=10) {

    if ($docente instanceOf Docente) {
      // statistiche di singolo docente
      $stat = $this->em->getRepository('AppBundle:Docente')->createQueryBuilder('d')
        ->select('d AS docente,SUM(so.durata/60) AS ore')
        ->join('AppBundle:Firma', 'f', 'WHERE', 'd.id=f.docente')
        ->join('f.lezione', 'l')
        ->join('l.classe', 'cl')
        ->join('AppBundle:ScansioneOraria', 'so', 'WHERE', 'l.ora=so.ora AND (WEEKDAY(l.data)+1)=so.giorno')
        ->join('so.orario', 'o')
        ->where('d.abilitato=:abilitato AND l.data BETWEEN :inizio AND :fine AND l.data BETWEEN o.inizio AND o.fine AND o.sede=cl.sede')
        ->andWhere('f.docente=:docente')
        ->orderBy('d.cognome,d.nome', 'ASC')
        ->setParameters(['abilitato' => 1, 'inizio' => $inizio->format('Y-m-d'),
          'fine' => $fine->format('Y-m-d'), 'docente' => $docente])
        ->groupBy('d.id')
        ->getQuery();
    } elseif ($docente == -1) {
      // statistiche di tutti i docenti
      $stat = $this->em->getRepository('AppBundle:Docente')->createQueryBuilder('d')
        ->select('d AS docente,SUM(so.durata/60) AS ore')
        ->join('AppBundle:Firma', 'f', 'WHERE', 'd.id=f.docente')
        ->join('f.lezione', 'l')
        ->join('l.classe', 'cl')
        ->join('AppBundle:ScansioneOraria', 'so', 'WHERE', 'l.ora=so.ora AND (WEEKDAY(l.data)+1)=so.giorno')
        ->join('so.orario', 'o')
        ->where('d.abilitato=:abilitato AND l.data BETWEEN :inizio AND :fine AND l.data BETWEEN o.inizio AND o.fine AND o.sede=cl.sede')
        ->orderBy('d.cognome,d.nome', 'ASC')
        ->setParameters(['abilitato' => 1, 'inizio' => $inizio->format('Y-m-d'),
          'fine' => $fine->format('Y-m-d')])
        ->groupBy('d.id')
        ->getQuery();
    } else {
      // query vuota
      $stat = $this->em->getRepository('AppBundle:Docente')->createQueryBuilder('d')
        ->where('d.id=:valore')
        ->setParameters(['valore' => -999])
        ->getQuery();
    }
    // paginazione
    $paginator = new Paginator($stat);
    $paginator->getQuery()
      ->setFirstResult($limit * ($page - 1))
      ->setMaxResults($limit);
    return $paginator;
  }

  /**
   * Recupera i programmi svolti secondo i criteri di ricerca indicati
   *
   * @param array $search Criteri di ricerca
   * @param int $pagina Pagina corrente
   * @param int $limite Numero di elementi per pagina
   *
   * @return Array Dati formattati come array associativo
   */
  public function programmi($search, $pagina, $limite) {
    // lista cattedre e programmi
    $param = ['attiva' => 1, 'potenziamento' => 'P', 'sostegno' => 'S', 'quinta' => 5];
    $cattedre = $this->em->getRepository('AppBundle:Cattedra')->createQueryBuilder('c')
      ->join('c.materia', 'm')
      ->join('c.classe', 'cl')
      ->where('c.attiva=:attiva AND c.tipo!=:potenziamento AND m.tipo!=:sostegno AND cl.anno!=:quinta');
    if ($search['docente']) {
      $cattedre = $cattedre
        ->andWhere('c.docente=:docente');
      $param['docente'] = $search['docente'];
    }
    if ($search['classe']) {
      $cattedre = $cattedre
        ->andWhere('c.classe=:classe');
      $param['classe'] = $search['classe'];
    }
    $cattedre = $cattedre
      ->groupBy('cl.anno,cl.sezione,m.nomeBreve')
      ->orderBy('cl.anno,cl.sezione,m.nomeBreve', 'ASC')
      ->setParameters($param)
      ->getQuery();
    // paginazione
    $paginator = new Paginator($cattedre);
    $paginator->getQuery()
      ->setFirstResult($limite * ($pagina - 1))
      ->setMaxResults($limite);
    $dati['lista'] = $paginator;
    // aggiunge info
    foreach ($paginator as $k=>$c) {
      // legge docenti
      $dati['docenti'][$k] = $this->em->getRepository('AppBundle:Docente')->createQueryBuilder('d')
        ->join('AppBundle:Cattedra', 'c', 'WHERE', 'c.docente=d.id AND c.attiva=:attiva AND c.tipo!=:potenziamento')
        ->where('d.abilitato=:abilitato AND c.materia=:materia AND c.classe=:classe')
        ->orderBy('d.cognome,d.nome', 'ASC')
        ->setParameters(['attiva' => 1, 'potenziamento' => 'P', 'abilitato' => 1,
          'materia' => $c->getMateria(), 'classe' => $c->getClasse()])
        ->getQuery()
        ->getArrayResult();
      // legge documenti
      $dati['documenti'][$k] = $this->em->getRepository('AppBundle:Documento')->createQueryBuilder('d')
        ->where('d.tipo=:tipo AND d.materia=:materia AND d.classe=:classe')
        ->setParameters(['tipo' => 'P', 'materia' => $c->getMateria(), 'classe' => $c->getClasse()])
        ->getQuery()
        ->getOneOrNullResult();
    }
    // restituisce dati
    return $dati;
  }

  /**
   * Recupera le relazioni finali secondo i criteri di ricerca indicati
   *
   * @param array $search Criteri di ricerca
   * @param int $pagina Pagina corrente
   * @param int $limite Numero di elementi per pagina
   *
   * @return Array Dati formattati come array associativo
   */
  public function relazioni($search, $pagina, $limite) {
    // lista cattedre e programmi
    $param = ['attiva' => 1, 'potenziamento' => 'P', 'sostegno' => 'S', 'quinta' => 5];
    $cattedre = $this->em->getRepository('AppBundle:Cattedra')->createQueryBuilder('c')
      ->join('c.materia', 'm')
      ->join('c.classe', 'cl')
      ->where('c.attiva=:attiva AND c.tipo!=:potenziamento AND m.tipo!=:sostegno AND cl.anno!=:quinta');
    if ($search['docente']) {
      $cattedre = $cattedre
        ->andWhere('c.docente=:docente');
      $param['docente'] = $search['docente'];
    }
    if ($search['classe']) {
      $cattedre = $cattedre
        ->andWhere('c.classe=:classe');
      $param['classe'] = $search['classe'];
    }
    $cattedre = $cattedre
      ->groupBy('cl.anno,cl.sezione,m.nomeBreve')
      ->orderBy('cl.anno,cl.sezione,m.nomeBreve', 'ASC')
      ->setParameters($param)
      ->getQuery();
    // paginazione
    $paginator = new Paginator($cattedre);
    $paginator->getQuery()
      ->setFirstResult($limite * ($pagina - 1))
      ->setMaxResults($limite);
    $dati['lista'] = $paginator;
    // aggiunge info
    foreach ($paginator as $k=>$c) {
      // legge docenti
      $dati['docenti'][$k] = $this->em->getRepository('AppBundle:Docente')->createQueryBuilder('d')
        ->join('AppBundle:Cattedra', 'c', 'WHERE', 'c.docente=d.id AND c.attiva=:attiva AND c.tipo!=:potenziamento')
        ->where('d.abilitato=:abilitato AND c.materia=:materia AND c.classe=:classe')
        ->orderBy('d.cognome,d.nome', 'ASC')
        ->setParameters(['attiva' => 1, 'potenziamento' => 'P', 'abilitato' => 1,
          'materia' => $c->getMateria(), 'classe' => $c->getClasse()])
        ->getQuery()
        ->getArrayResult();
      // legge documenti
      $dati['documenti'][$k] = $this->em->getRepository('AppBundle:Documento')->createQueryBuilder('d')
        ->where('d.tipo=:tipo AND d.materia=:materia AND d.classe=:classe')
        ->setParameters(['tipo' => 'R', 'materia' => $c->getMateria(), 'classe' => $c->getClasse()])
        ->getQuery()
        ->getOneOrNullResult();
    }
    // restituisce dati
    return $dati;
  }

  /**
   * Recupera i piani di lavoro secondo i criteri di ricerca indicati
   *
   * @param array $search Criteri di ricerca
   * @param int $pagina Pagina corrente
   * @param int $limite Numero di elementi per pagina
   *
   * @return Array Dati formattati come array associativo
   */
  public function piani($search, $pagina, $limite) {
    // lista cattedre e programmi
    $param = ['attiva' => 1, 'potenziamento' => 'P', 'sostegno' => 'S'];
    $cattedre = $this->em->getRepository('AppBundle:Cattedra')->createQueryBuilder('c')
      ->join('c.materia', 'm')
      ->join('c.classe', 'cl')
      ->where('c.attiva=:attiva AND c.tipo!=:potenziamento AND m.tipo!=:sostegno');
    if ($search['docente']) {
      $cattedre = $cattedre
        ->andWhere('c.docente=:docente');
      $param['docente'] = $search['docente'];
    }
    if ($search['classe']) {
      $cattedre = $cattedre
        ->andWhere('c.classe=:classe');
      $param['classe'] = $search['classe'];
    }
    $cattedre = $cattedre
      ->groupBy('cl.anno,cl.sezione,m.nomeBreve')
      ->orderBy('cl.anno,cl.sezione,m.nomeBreve', 'ASC')
      ->setParameters($param)
      ->getQuery();
    // paginazione
    $paginator = new Paginator($cattedre);
    $paginator->getQuery()
      ->setFirstResult($limite * ($pagina - 1))
      ->setMaxResults($limite);
    $dati['lista'] = $paginator;
    // aggiunge info
    foreach ($paginator as $k=>$c) {
      // legge docenti
      $dati['docenti'][$k] = $this->em->getRepository('AppBundle:Docente')->createQueryBuilder('d')
        ->join('AppBundle:Cattedra', 'c', 'WHERE', 'c.docente=d.id AND c.attiva=:attiva AND c.tipo!=:potenziamento')
        ->where('d.abilitato=:abilitato AND c.materia=:materia AND c.classe=:classe')
        ->orderBy('d.cognome,d.nome', 'ASC')
        ->setParameters(['attiva' => 1, 'potenziamento' => 'P', 'abilitato' => 1,
          'materia' => $c->getMateria(), 'classe' => $c->getClasse()])
        ->getQuery()
        ->getArrayResult();
      // legge documenti
      $dati['documenti'][$k] = $this->em->getRepository('AppBundle:Documento')->createQueryBuilder('d')
        ->where('d.tipo=:tipo AND d.materia=:materia AND d.classe=:classe')
        ->setParameters(['tipo' => 'L', 'materia' => $c->getMateria(), 'classe' => $c->getClasse()])
        ->getQuery()
        ->getOneOrNullResult();
    }
    // restituisce dati
    return $dati;
  }

  /**
   * Recupera i documenti del 15 maggio secondo i criteri di ricerca indicati
   *
   * @param array $search Criteri di ricerca
   * @param int $pagina Pagina corrente
   * @param int $limite Numero di elementi per pagina
   *
   * @return Array Dati formattati come array associativo
   */
  public function doc15($search, $pagina, $limite) {
    // lista cattedre e programmi
    $param = ['quinta' => 5, 'documento' => 'M'];
    $cattedre = $this->em->getRepository('AppBundle:Classe')->createQueryBuilder('cl')
      ->addSelect('cl.id AS classe_id,cl.anno,cl.sezione,co.nomeBreve AS corso,s.citta AS sede,d.id AS documento_id,d.file,d.dimensione')
      ->join('cl.corso', 'co')
      ->join('cl.sede', 's')
      ->leftJoin('AppBundle:Documento', 'd', 'WHERE', 'd.tipo=:documento AND d.classe=cl.id')
      ->where('cl.anno=:quinta');
    if ($search['classe']) {
      $cattedre = $cattedre
        ->andWhere('cl.id=:classe');
      $param['classe'] = $search['classe'];
    }
    $cattedre = $cattedre
      ->orderBy('cl.anno,cl.sezione', 'ASC')
      ->setParameters($param)
      ->getQuery();
    // paginazione
    $paginator = new Paginator($cattedre);
    $paginator->getQuery()
      ->setFirstResult($limite * ($pagina - 1))
      ->setMaxResults($limite);
    $dati['lista'] = $paginator;
    // restituisce dati
    return $dati;
  }

  /**
   * Recupera le statistiche sulle presenze secondo i criteri di ricerca indicati
   *
   * @param DateTime $data Data per la generazione delle statistiche
   * @param array $search Criteri di ricerca
   *
   * @return array Dati formattati come array associativo
   */
  public function statisticheAlunni(\DateTime $data, $search) {
    $dati = [];
    $param = [];
    // lista classi
    $classi = $this->em->getRepository('AppBundle:Classe')->createQueryBuilder('c')
      ->join('c.corso', 'co')
      ->join('c.sede', 's');
    if ($search['sede']) {
      $classi = $classi
        ->andWhere('c.sede=:sede');
      $param['sede'] = $search['sede'];
    }
    if ($search['classe']) {
      $classi = $classi
        ->andWhere('c.id=:classe');
      $param['classe'] = $search['classe'];
    }
    $classi = $classi
      ->orderBy('c.anno,c.sezione', 'ASC')
      ->setParameters($param)
      ->getQuery()
      ->getResult();
    foreach ($classi as $c) {
      // alunni in classe
      $lista = $this->regUtil->alunniInData($data, $c);
      $totale = count($lista);
      // assenti e presenti
      $assenti = $this->em->getRepository('AppBundle:Assenza')->createQueryBuilder('a')
        ->select('COUNT(a.id)')
        ->where('a.data=:data AND a.alunno IN (:lista)')
        ->setParameters(['data' => $data->format('Y-m-d'), 'lista' => $lista])
        ->getQuery()
        ->getSingleScalarResult();
      $presenti = $totale - $assenti;
      // formatta i dati
      $dati[$c->getId()] = array(
        'classe' => $c->getAnno().'ª '.$c->getSezione().' - '.$c->getCorso()->getNomeBreve(),
        'sede' => $c->getSede()->getCitta(),
        'totale' => $totale,
        'assenti' => $assenti,
        'presenti' => $presenti,
        'percentuale' => number_format($presenti / $totale * 100, 2, ',', ''));
    }
    // restituisce dati
    return $dati;
  }

  /**
   * Restituisce le statistiche per la stampa in PDF delle ore di lezione dei docenti
   *
   * @param mixed $docente Docente selezionato
   * @param \DateTime $inizio Data iniziale delle lezioni
   * @param \DateTime $fine Data finale delle lezioni
   *
   * @return array Dati formattati come array associativo
   */
  public function statisticheStampa($docente, $inizio, $fine) {

    if ($docente instanceOf Docente) {
      // statistiche di singolo docente
      $stat = $this->em->getRepository('AppBundle:Docente')->createQueryBuilder('d')
        ->select('d.cognome,d.nome,SUM(so.durata/60) AS ore')
        ->join('AppBundle:Firma', 'f', 'WHERE', 'd.id=f.docente')
        ->join('f.lezione', 'l')
        ->join('l.classe', 'cl')
        ->join('AppBundle:ScansioneOraria', 'so', 'WHERE', 'l.ora=so.ora AND (WEEKDAY(l.data)+1)=so.giorno')
        ->join('so.orario', 'o')
        ->where('d.abilitato=:abilitato AND l.data BETWEEN :inizio AND :fine AND l.data BETWEEN o.inizio AND o.fine AND o.sede=cl.sede')
        ->andWhere('f.docente=:docente')
        ->setParameters(['abilitato' => 1, 'inizio' => $inizio->format('Y-m-d'),
          'fine' => $fine->format('Y-m-d'), 'docente' => $docente])
        ->groupBy('d.id')
        ->getQuery()
        ->getArrayResult();
    } elseif ($docente == -1) {
      // statistiche di tutti i docenti
      $stat = $this->em->getRepository('AppBundle:Docente')->createQueryBuilder('d')
        ->select('d.cognome,d.nome,SUM(so.durata/60) AS ore')
        ->join('AppBundle:Firma', 'f', 'WHERE', 'd.id=f.docente')
        ->join('f.lezione', 'l')
        ->join('l.classe', 'cl')
        ->join('AppBundle:ScansioneOraria', 'so', 'WHERE', 'l.ora=so.ora AND (WEEKDAY(l.data)+1)=so.giorno')
        ->join('so.orario', 'o')
        ->where('d.abilitato=:abilitato AND l.data BETWEEN :inizio AND :fine AND l.data BETWEEN o.inizio AND o.fine AND o.sede=cl.sede')
        ->orderBy('d.cognome,d.nome', 'ASC')
        ->setParameters(['abilitato' => 1, 'inizio' => $inizio->format('Y-m-d'),
          'fine' => $fine->format('Y-m-d')])
        ->groupBy('d.id')
        ->getQuery()
        ->getArrayResult();
    } else {
      // query vuota
      $stat = $this->em->getRepository('AppBundle:Docente')->createQueryBuilder('d')
        ->where('d.id=:valore')
        ->setParameters(['valore' => -999])
        ->getQuery();
    }
    // restituisce dati
    return $stat;
  }

}


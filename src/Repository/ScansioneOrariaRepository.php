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


namespace App\Repository;

use Doctrine\ORM\EntityRepository;
use App\Entity\Sede;
use App\Entity\Docente;
use App\Entity\Lezione;


/**
 * ScansioneOraria - repository
 */
class ScansioneOrariaRepository extends EntityRepository {

  /**
   * Restituisce l'ora di inizio delle lezioni per il giorno indicato (non festivo)
   *
   * @param \DateTime $data Data di riferimento
   * @param Sede $sede Sede scolastica
   *
   * @return string Ora nel formato hh:mm
   */
  public function inizioLezioni(\DateTime $data, Sede $sede) {
    // legge la prima ora
    $ora = $this->createQueryBuilder('s')
      ->select('s.inizio')
      ->join('s.orario', 'o')
      ->where(':data BETWEEN o.inizio AND o.fine AND o.sede=:sede AND s.giorno=:giorno AND s.ora=:ora')
      ->setParameters(['data' => $data->format('Y-m-d'), 'sede' => $sede, 'giorno' => $data->format('w'), 'ora' => 1])
      ->setMaxResults(1)
      ->getQuery()
      ->getScalarResult();
    return (!empty($ora) ? substr($ora[0]['inizio'], 0, 5) : '00:00');
  }

  /**
   * Restituisce l'ora di fine delle lezioni per il giorno indicato (non festivo)
   *
   * @param \DateTime $data Data di riferimento
   * @param Sede $sede Sede scolastica
   *
   * @return string Ora nel formato hh:mm
   */
  public function fineLezioni(\DateTime $data, Sede $sede) {
    // legge la ultima ora
    $ora = $this->createQueryBuilder('s')
      ->select('s.fine')
      ->join('s.orario', 'o')
      ->where(':data BETWEEN o.inizio AND o.fine AND o.sede=:sede AND s.giorno=:giorno')
      ->setParameters(['data' => $data->format('Y-m-d'), 'sede' => $sede, 'giorno' => $data->format('w')])
      ->orderBy('s.ora', 'DESC')
      ->setMaxResults(1)
      ->getQuery()
      ->getScalarResult();
    return (!empty($ora) ? substr($ora[0]['fine'], 0, 5) : '23:59');
  }

  /**
   * Restituisce l'ora di fine delle lezioni per il docente nel giorno indicato (non festivo)
   *
   * @param \DateTime $data Data di riferimento
   * @param Docente $docente Docente
   *
   * @return string Ora nel formato hh:mm
   */
  public function fineLezioniDocente(\DateTime $data, Docente $docente) {
    // legge la ultima ora
    $ora = $this->createQueryBuilder('s')
      ->select('s.fine')
      ->join('s.orario', 'o')
      ->join('App:Cattedra', 'c', 'WITH', 'c.docente=:docente')
      ->join('c.classe', 'cl')
      ->where(':data BETWEEN o.inizio AND o.fine AND o.sede=cl.sede AND s.giorno=:giorno')
      ->setParameters(['docente' => $docente, 'data' => $data->format('Y-m-d'), 'giorno' => $data->format('w')])
      ->orderBy('s.fine', 'DESC')
      ->setMaxResults(1)
      ->getQuery()
      ->getScalarResult();
    return (!empty($ora) ? substr($ora[0]['fine'], 0, 5) : '23:59');
  }

  /**
   * Restituisce i dati della scansione oraria di una lezione
   *
   * @param Lezione $lezione Lezione di cui leggere la scansione oraria
   *
   * @return ScansioneOraria Oggetto che rappresenta la scansione oraria
   */
  public function oraLezione(Lezione $lezione) {
    // legge l'ora della lezione
    $ora = $this->createQueryBuilder('s')
      ->join('s.orario', 'o')
      ->where(':data BETWEEN o.inizio AND o.fine AND o.sede=:sede AND s.giorno=:giorno AND s.ora=:ora')
      ->setParameters(['data' => $lezione->getData()->format('Y-m-d'),
        'sede' => $lezione->getClasse()->getSede(), 'giorno' => $lezione->getData()->format('w'),
        'ora' => $lezione->getOra()])
      ->setMaxResults(1)
      ->getQuery()
      ->getOneOrNullResult();
    return $ora;
  }

  /**
   * Restituisce i dati della scansione oraria di una un giorno e un orario specificato
   *
   * @param int $giorno Giorno settimnale (0=domenica, 1=lunedì, ...)
   * @param Orario $orario Orario a cui fare riferimento; se nullo si prende quello attuale della sede principale
   *
   * @return ScansioneOraria Oggetto che rappresenta la scansione oraria
   */
  public function orarioGiorno($giorno, Orario $orario=null) {
    if (!$orario) {
      $orario = $this->_em->getRepository('App:Orario')->orarioSede(null);
    }
    // legge le ore del giorno
    $ore = $this->createQueryBuilder('s')
      ->select('s.ora,s.inizio,s.fine')
      ->where('s.orario=:orario AND s.giorno=:giorno')
      ->orderBy('s.ora', 'ASC')
      ->setParameters(['orario' => $orario, 'giorno' => $giorno])
      ->getQuery()
      ->getArrayResult();
    return $ore;
  }

}

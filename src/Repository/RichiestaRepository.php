<?php
/*
 * SPDX-FileCopyrightText: 2017 I.I.S. Michele Giua - Cagliari - Assemini
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */


namespace App\Repository;

use App\Entity\Richiesta;
use App\Entity\Utente;


/**
 * Richiesta - repository
 *
 * @author Antonello Dessì
 */
class RichiestaRepository extends BaseRepository {

  /**
   * Restituisce una nuova richiesta (multipla) del tipo indicato relativa all'alunno e alla data specificata
   *
   * @param string $tipo Codifica del tipo di richiesta
   * @param int $idAlunno Identificativo alunno che ha fatto richiesta
   * @param DateTime $data Data di riferimento della richiesta
   *
   * @return Richiesta|null Richiesta, se esiste
   */
  public function richiestaAlunno(string $tipo, int $idAlunno, \DateTime $data): ?Richiesta {
    $richiesta = $this->createQueryBuilder('r')
      ->join('r.definizioneRichiesta', 'dr')
      ->where('dr.abilitata=:si AND dr.unica=:no AND dr.tipo=:tipo AND r.utente=:utente AND r.stato IN (:stati) AND r.data=:data')
      ->setParameters(['si' => 1, 'no' => 0, 'tipo' => $tipo, 'utente' => $idAlunno, 'stati' => ['I', 'G'],
        'data' => $data->format('Y-m-d')])
      ->getQuery()
      ->getOneOrNullResult();
    // restituisce risultato
    return $richiesta;
  }

  /**
   * Restituisce la lista dei moduli di richiesta per la gestione da parte del destinatario
   *
   * @param Utente $utente Utente che gestisce i moduli di richiesta
   * @param array $criteri Criteri di rricerca dei moduli di richiesta
   * @param int $pagina Numero di pagina da visualizzare
   *
   * @return array Lista associativa con i risultati
   */
  public function lista(Utente $utente, array $criteri, int $pagina): array {
    // controllo destinatario
    $ruolo = $utente->getCodiceRuolo();
    $funzioni = array_map(fn($f) => "FIND_IN_SET('".$ruolo.$f."', dr.destinatari) > 0",
      $utente->getCodiceFunzioni());
    $sql = implode(' OR ', $funzioni);
    // query base
    $richieste = $this->createQueryBuilder('r')
      ->join('r.definizioneRichiesta', 'dr')
      ->join('App\Entity\Alunno', 'a', 'WITH', 'a.id=r.utente')
      ->join('a.classe', 'c')
      ->where('dr.abilitata=:abilitata AND c.sede=:sede')
      ->andWhere($sql)
      ->setParameters(['abilitata' => 1, 'sede' => $criteri['sede']])
      ->orderBy('dr.nome,r.data,r.inviata', 'ASC');
    // controllo tipo
    if ($criteri['tipo'] == 'E' || $criteri['tipo'] == 'D') {
      // tipo indicato
      $richieste
        ->andWhere('dr.tipo=:tipo')
        ->setParameter('tipo', $criteri['tipo']);
    } elseif ($criteri['tipo'] == '*') {
      // altri tipi non definiti
      $richieste
        ->andWhere('dr.tipo NOT IN (:tipi)')
        ->setParameter('tipi', ['E', 'D', 'U']);
    } else {
      // tutte (escluso quelli gestiti altrove)
      $richieste
        ->andWhere('dr.tipo!=:tipo')
        ->setParameter('tipo', 'U');
    }
    // controllo stato
    if ($criteri['stato'] == 'IA') {
      // nuove (inviate o annullate)
      $richieste
        ->andWhere('r.stato IN (:stati)')
        ->setParameter('stati', ['I', 'A']);
    } elseif ($criteri['stato']) {
      // stato definito (gestite o rimosse)
      $richieste
        ->andWhere('r.stato=:stato')
        ->setParameter('stato', $criteri['stato']);
    }
    // controllo classe
    if ($criteri['classe']) {
      // classe definita
      $richieste
        ->andWhere('c.id=:classe')
        ->setParameter('classe', $criteri['classe']);
    }
    // controllo cognome
    if ($criteri['cognome']) {
      // classe definita
      $richieste
        ->andWhere('a.cognome LIKE :cognome')
        ->setParameter('cognome', $criteri['cognome'].'%');
    }
    // controllo nome
    if ($criteri['nome']) {
      // classe definita
      $richieste
        ->andWhere('a.nome LIKE :nome')
        ->setParameter('nome', $criteri['nome'].'%');
    }
    // paginazione
    $dati = $this->paginazione($richieste->getQuery(), $pagina);
    // per evitare errori di paginazione
    $dati['lista']->setUseOutputWalkers(false);
    // restituisce dati
    return $dati;
  }

}

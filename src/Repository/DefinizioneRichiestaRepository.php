<?php
/*
 * SPDX-FileCopyrightText: 2017 I.I.S. Michele Giua - Cagliari - Assemini
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */


namespace App\Repository;

use App\Entity\Genitore;
use App\Entity\Utente;


/**
 * DefinizioneRichiesta - repository
 *
 * @author Antonello Dessì
 */
class DefinizioneRichiestaRepository extends BaseRepository {

  /**
   * Restituisce la lista dei moduli di richiesta accessibili all'utente indicato
   *
   * @param Utente $utente Utente che ha accesso ai moduli di richiesta
   *
   * @return array Lista associativa con i risultati
   */
  public function lista(Utente $utente): array {
    $ruolo = $utente->getCodiceRuolo();
    $funzioni = array_map(fn($f) => "FIND_IN_SET('".$ruolo.$f."', dr.richiedenti) > 0",
      $utente->getCodiceFunzioni());
    $sql = implode(' OR ', $funzioni);
    // legge richieste
    $richieste = $this->createQueryBuilder('dr')
      ->select('dr.id,dr.nome,dr.unica,r.id as richiesta_id,r.inviata,r.gestita,r.data,r.documento,r.allegati,r.stato,r.messaggio')
      ->leftJoin('App\Entity\Richiesta', 'r', 'WITH', 'r.definizioneRichiesta=dr.id AND r.utente=:utente AND r.stato IN (:stati)')
      ->where('dr.abilitata=:si')
      ->andWhere($sql)
      ->setParameters(['si' => 1, 'utente' => $utente instanceOf Genitore ? $utente->getAlunno() : $utente,
        'stati' => ['I', 'G']])
      ->orderBy('dr.nome', 'ASC')
      ->addOrderBy('r.data', 'DESC')
      ->addOrderBy('r.inviata', 'DESC')
      ->getQuery()
      ->getArrayResult();
    // formatta dati
    $dati['uniche'] = [];
    $dati['multiple'] = [];
    $dati['richieste'] = [];
    $dati['richieste'] = [];
    $moduloPrec = null;
    $oggi = new \DateTime('today');
    foreach ($richieste as $richiesta) {
      $modulo = $richiesta['id'];
      if (!$moduloPrec || $moduloPrec != $modulo) {
        // aggiunge a lista moduli
        $dati[$richiesta['unica'] ? 'uniche' : 'multiple'][$modulo] = [
          'nome' => $richiesta['nome']];
        $moduloPrec = $modulo;
      }
      if ($richiesta['richiesta_id']) {
        // aggiunge a lista richieste
        $tipo = ($richiesta['unica'] || $richiesta['data'] >= $oggi) ? 'nuove' : 'vecchie';
        $dati['richieste'][$modulo][$tipo][] = [
          'id' => $richiesta['richiesta_id'],
          'inviata' => $richiesta['inviata'],
          'gestita' => $richiesta['gestita'],
          'data' => $richiesta['data'],
          'documento' => $richiesta['documento'],
          'allegati' => $richiesta['allegati'],
          'stato' => $richiesta['stato'],
          'messaggio' => $richiesta['messaggio']];
      }
    }
    // restituisce dati
    return $dati;
  }

}

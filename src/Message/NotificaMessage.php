<?php
/*
 * SPDX-FileCopyrightText: 2017 I.I.S. Michele Giua - Cagliari - Assemini
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */


namespace App\Message;


/**
 * NotificaMessage - dati per l'invio delle notifiche
 *
 * @author Antonello Dessì
 */
class NotificaMessage {

  //==================== ATTRIBUTI DELLA CLASSE  ====================

  /**
   * @var int $utenteId Identificativo dell'utente destinatario della notifica
   */
  private int $utenteId;

  /**
   * @var string $tipo Tipo di notifica
   */
  private string $tipo;

  /**
   * @var array $dati Dati necessari per creare la notifica
   */
  private array $dati;


  //==================== METODI DELLA CLASSE ====================

  /**
   * Costruttore
   *
   * @param int $utenteId Identificativo dell'utente destinatario della notifica
   * @param string $tipo Tipo di notifica
   * @param array $dati Dati necessari per creare la notifica
   */
  public function __construct(int $utenteId, string $tipo, array $dati) {
    $this->utenteId = $utenteId;
    $this->tipo = $tipo;
    $this->dati = $dati;
  }

  /**
   * Restituisce l'identificativo dell'utente destinatario della notifica
   *
   * @return int Identificativo dell'utente destinatario della notifica
   */
  public function getUtenteId(): int {
    return $this->utenteId;
  }

  /**
   * Restituisce il tipo di notifica
   *
   * @return string Tipo di notifica
   */
  public function getTipo(): string {
    return $this->tipo;
  }

  /**
   * Restituisce i dati necessari per creare la notifica
   *
   * @return array Dati necessari per creare la notifica
   */
  public function getDati(): array {
    return $this->dati;
  }

}

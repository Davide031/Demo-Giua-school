<?php
/**
 * giua@school
 *
 * Copyright (c) 2017-2021 Antonello Dessì
 *
 * @author    Antonello Dessì
 * @license   http://www.gnu.org/licenses/agpl.html AGPL
 * @copyright Antonello Dessì 2017-2021
 */


namespace App\Tests\UnitTest\Entity;

use App\DataFixtures\AmministratoreFixtures;
use App\Tests\DatabaseTestCase;
use App\Entity\Amministratore;


/**
 * Unit test della classe
 */
class AmministratoreTest extends DatabaseTestCase {

  /**
   * Costruttore
   * Definisce dati per i test.
   *
   */
  public function __construct() {
    parent::__construct();
    // nome dell'entità
    $this->entity = '\App\Entity\Amministratore';
    // campi da testare
    $this->fields = ['username', 'password', 'email', 'token', 'tokenCreato', 'prelogin', 'preloginCreato',
      'abilitato', 'ultimoAccesso', 'nome', 'cognome', 'sesso', 'dataNascita', 'comuneNascita',
      'codiceFiscale', 'citta', 'indirizzo', 'numeriTelefono', 'notifica'];
    // fixture da caricare
    $this->fixtures = [[AmministratoreFixtures::class, 'encoder']];
    // SQL read
    $this->canRead = [
      'gs_utente' => ['id', 'creato', 'modificato', 'username', 'password', 'email', 'token', 'token_creato',
        'prelogin', 'prelogin_creato', 'abilitato', 'ultimo_accesso', 'nome', 'cognome', 'sesso',
        'data_nascita', 'comune_nascita', 'codice_fiscale', 'citta', 'indirizzo', 'numeri_telefono',
        'notifica', 'ruolo', 'tipo', 'segreteria', 'chiave1', 'chiave2', 'chiave3', 'otp', 'ultimo_otp',
        'bes', 'note_bes', 'autorizza_entrata', 'autorizza_uscita', 'note', 'frequenza_estero',
        'religione', 'credito3', 'credito4', 'giustifica_online', 'richiesta_certificato', 'foto', 'sede_id',
        'classe_id', 'alunno_id', 'responsabile_bes', 'responsabile_bes_sede_id']];
    // SQL write
    $this->canWrite = [
      'gs_utente' => ['id', 'creato', 'modificato', 'username', 'password', 'email', 'token', 'token_creato',
        'prelogin', 'prelogin_creato', 'abilitato', 'ultimo_accesso', 'nome', 'cognome', 'sesso',
        'data_nascita', 'comune_nascita', 'codice_fiscale', 'citta', 'indirizzo', 'numeri_telefono',
        'notifica', 'ruolo', 'tipo', 'segreteria', 'chiave1', 'chiave2', 'chiave3', 'otp', 'ultimo_otp',
        'bes', 'note_bes', 'autorizza_entrata', 'autorizza_uscita', 'note', 'frequenza_estero',
        'religione', 'credito3', 'credito4', 'giustifica_online', 'richiesta_certificato', 'foto', 'sede_id',
        'classe_id', 'alunno_id', 'responsabile_bes', 'responsabile_bes_sede_id']];
    // SQL exec
    $this->canExecute = ['START TRANSACTION', 'COMMIT'];
  }

  /**
   * Test getter/setter degli attributi, con memorizzazione su database.
   * Sono esclusi gli attributi ereditati.
   */
  public function testAttributi() {
    // carica oggetto esistente
    $existent = $this->em->getRepository($this->entity)->find(1);
    $this->assertEquals(1, $existent->getId(), 'Oggetto esistente');
    // crea nuovi oggetti
    for ($i = 0; $i < 3; $i++) {
      $o[$i] = new $this->entity();
      $sesso = $this->faker->randomElement(['M', 'F']);
      list($nome, $cognome, $username) = $this->faker->unique()->utente($sesso);
      $email = $username.'.a@lovelace.edu.it';
      foreach ($this->fields as $field) {
        $data[$i][$field] =
          $field == 'username' ? $username.'.a' :
          ($field == 'password' ? $this->encoder->encodePassword($o[$i], $username.'.a') :
          ($field == 'email' ? $email :
          ($field == 'token' ? $this->faker->optional(0.5, null)->md5() :
          ($field == 'tokenCreato' ? $this->faker->optional(0.5, null)->dateTimeBetween('-1 month', 'now') :
          ($field == 'prelogin' ? $this->faker->optional(0.5, null)->md5() :
          ($field == 'preloginCreato' ? $this->faker->optional(0.5, null)->dateTimeBetween('-1 month', 'now') :
          ($field == 'abilitato' ? $this->faker->randomElement([true, true, true, true, false]) :
          ($field == 'ultimoAccesso' ? $this->faker->optional(0.5, null)->dateTimeBetween('-1 month', 'now') :
          ($field == 'nome' ? $nome :
          ($field == 'cognome' ? $cognome :
          ($field == 'sesso' ? $sesso :
          ($field == 'dataNascita' ? $this->faker->dateTimeBetween('-60 years', '-14 years') :
          ($field == 'comuneNascita' ? $this->faker->city() :
          ($field == 'codiceFiscale' ? $this->faker->unique()->taxId() :
          ($field == 'citta' ?  $this->faker->city() :
          ($field == 'indirizzo' ? $this->faker->streetAddress() :
          ($field == 'numeriTelefono' ? $this->faker->telefono($this->faker->numberBetween(0, 3)) :
          null)))))))))))))))));
        $o[$i]->{'set'.ucfirst($field)}($data[$i][$field]);
      }
      $this->assertEmpty($o[$i]->getId(), $this->entity.'::getId Pre-inserimento');
      $this->assertEmpty($o[$i]->getCreato(), $this->entity.'::getCreato Pre-inserimento');
      $this->assertEmpty($o[$i]->getModificato(), $this->entity.'::getModificato Pre-inserimento');
      // memorizza su db
      $this->em->persist($o[$i]);
      $this->em->flush();
      $this->assertNotEmpty($o[$i]->getId(), $this->entity.'::getId Post-inserimento');
      $this->assertNotEmpty($o[$i]->getCreato(), $this->entity.'::getCreato Post-inserimento');
      $this->assertNotEmpty($o[$i]->getModificato(), $this->entity.'::getModificato Post-inserimento');
      $data[$i]['id'] = $o[$i]->getId();
      $data[$i]['creato'] = $o[$i]->getCreato();
      // controlla creato < modificato
      sleep(1);
      $o[$i]->{'set'.ucfirst($this->fields[0])}(!$data[$i][$this->fields[0]]);
      $this->em->flush();
      $o[$i]->{'set'.ucfirst($this->fields[0])}($data[$i][$this->fields[0]]);
      $this->em->flush();
      $this->assertTrue($o[$i]->getCreato() < $o[$i]->getModificato(), $this->entity.'::getCreato < getModificato');
      $data[$i]['modificato'] = $o[$i]->getModificato();
    }
    // controlla gli attributi
    for ($i = 0; $i < 3; $i++) {
      $created = $this->em->getRepository($this->entity)->find($data[$i]['id']);
      foreach (array_merge(['id', 'creato', 'modificato'], $this->fields) as $field) {
        $this->assertSame($data[$i][$field], $created->{'get'.ucfirst($field)}(),
          $this->entity.'::get'.ucfirst($field));
        if ($field == 'numeriTelefono') {
          $created->setNumeriTelefono(['1111','2222','3333']);
          $created->addNumeriTelefono('070.333.333');
          $created->addNumeriTelefono('2222');
          $this->assertSame(['1111','2222','3333','070.333.333'], $created->getNumeriTelefono(),
            $this->entity.'::addNumeroTelefono');
          $created->removeNumeriTelefono('2222');
          $created->removeNumeriTelefono('1111');
          $created->removeNumeriTelefono('2222');
          $this->assertEquals(array_values(['3333','070.333.333']), array_values($created->getNumeriTelefono()),
            $this->entity.'::removeNumeriTelefono');
        }
      }
    }
    // controlla metodi setId, setCreato e setModificato
    $rc = new \ReflectionClass($this->entity);
    $this->assertFalse($rc->hasMethod('setId'), 'Esiste metodo '.$this->entity.'::setId');
    $this->assertFalse($rc->hasMethod('setCreato'), 'Esiste metodo '.$this->entity.'::setCreato');
    $this->assertFalse($rc->hasMethod('setModificato'), 'Esiste metodo '.$this->entity.'::setModificato');
  }

  /**
   * Test altri metodi
   */
  public function testMetodi() {
    // carica oggetto esistente
    $existent = $this->em->getRepository($this->entity)->find(1);
    // getRoles
    $this->assertSame(['ROLE_AMMINISTRATORE', 'ROLE_UTENTE'], $existent->getRoles(), $this->entity.'::getRoles');
    // istanza di classe
    $this->assertTrue($existent instanceOf \App\Entity\Utente, $this->entity.'instanceOf Utente');
    $this->assertFalse($existent instanceOf \App\Entity\Alunno, $this->entity.'instanceOf Alunno');
    $this->assertFalse($existent instanceOf \App\Entity\Genitore, $this->entity.'instanceOf Genitore');
    $this->assertFalse($existent instanceOf \App\Entity\Ata, $this->entity.'instanceOf Ata');
    $this->assertFalse($existent instanceOf \App\Entity\Docente, $this->entity.'instanceOf Docente');
    $this->assertFalse($existent instanceOf \App\Entity\Staff, $this->entity.'instanceOf Staff');
    $this->assertFalse($existent instanceOf \App\Entity\Preside, $this->entity.'instanceOf Preside');
    $this->assertTrue($existent instanceOf \App\Entity\Amministratore, $this->entity.'instanceOf Amministratore');
    $this->assertTrue(is_a($existent, 'App\Entity\Utente'), $this->entity.'is_a Utente');
    $this->assertFalse(is_a($existent, 'App\Entity\Alunno'), $this->entity.'is_a Alunno');
    $this->assertFalse(is_a($existent, 'App\Entity\Genitore'), $this->entity.'is_a Genitore');
    $this->assertFalse(is_a($existent, 'App\Entity\Ata'), $this->entity.'is_a Ata');
    $this->assertFalse(is_a($existent, 'App\Entity\Docente'), $this->entity.'is_a Docente');
    $this->assertFalse(is_a($existent, 'App\Entity\Staff'), $this->entity.'is_a Staff');
    $this->assertFalse(is_a($existent, 'App\Entity\Preside'), $this->entity.'is_a Preside');
    $this->assertTrue(is_a($existent, 'App\Entity\Amministratore'), $this->entity.'is_a Amministratore');
  }

  /**
   * Test validazione dei dati
   */
  public function testValidazione() {
    // carica oggetto esistente
    $existent = $this->em->getRepository($this->entity)->findOneBy([]);
    if (!$existent->getCodiceFiscale()) {
      $existent->setCodiceFiscale('XCODE-0001');
      $this->em->flush();
    }
    $this->assertCount(0, $this->val->validate($existent), $this->entity.' - Oggetto valido');
    // unique - codiceFiscale
    $o = (new Amministratore())
      ->setCognome($existent->getCognome())
      ->setNome($existent->getCognome())
      ->setSesso($existent->getSesso())
      ->setUsername($existent->getUsername().'.XX')
      ->setEmail($existent->getEmail().'.XX')
      ->setPassword('PASSWORD-XX')
      ->setCodiceFiscale('XCODE-0002');
    $this->assertCount(0, $this->val->validate($o), $this->entity.' - Oggetto valido');
    $o->setCodiceFiscale($existent->getCodiceFiscale());
    $err = $this->val->validate($o);
    $this->assertTrue(count($err) == 1 && $err[0]->getMessageTemplate() == 'field.unique', $this->entity.'::codiceFiscale - UNIQUE');
  }

}

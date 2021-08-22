# language: it

Funzionalità: Inserimento dei documenti BES da parte del responsabile
  Per gestire l'inserimento dei documenti BES
  Come utente docente responsabile BES
  Bisogna controllare prerequisiti per inserimento documenti BES
  Bisogna caricare un documento da inserire come documento BES
  Bisogna controllare accesso a pagina


Contesto: login docente responsabile BES
	Dato login utente con ruolo esatto "Docente"
  E modifica utente connesso:
    | responsabileBes | responsabileBesSede |
    | si              | null                |


################################################################################
# Bisogna controllare prerequisiti per inserimento documenti BES

Scenario: visualizza pagina inserimento documento BES di nuovo alunno
  Quando pagina attiva "documenti_bes"
  E la sezione "#gs-main .alert" contiene "/Non sono presenti documenti/i"
  E click su "Aggiungi"
  Allora vedi pagina "documenti_bes_add"
  E la sezione "#gs-main .panel-title" contiene "/Inserisci il documento relativo all'alunno BES/"
  E la sezione "#gs-main .panel-body form .form-group:nth-of-type(1) label" contiene "Classe"
  E la sezione "#gs-main .panel-body form .form-group:nth-of-type(2) label" contiene "Alunno"
  E la sezione "#gs-main .panel-body form .form-group:nth-of-type(3) label" contiene "Tipo di documenti"
  E la sezione "#gs-main .panel-body form .form-group:nth-of-type(3) select" contiene "Diagnosi"
  E la sezione "#gs-main .panel-body form .form-group:nth-of-type(3) select" contiene "P.E.I."
  E la sezione "#gs-main .panel-body form .form-group:nth-of-type(3) select" contiene "P.D.P."
  E la sezione "#gs-main .panel-body form .form-group:nth-of-type(4) label" contiene "Documento"
  E la sezione "#gs-main .panel-body form button:nth-of-type(1)" contiene "Conferma"
  E la sezione "#gs-main .panel-body form button:nth-of-type(2)" contiene "Annulla"

Schema dello scenario: visualizza pagina inserimento nuovo documento BES di alunno con altro documento
  Data ricerca istanze di tipo "Classe":
    | id   | anno | sezione |
    | $cl1 | 2    | B       |
  E ricerca istanze di tipo "Alunno":
    | id  | classe | abilitato |
    | $a1 | $cl1   | si        |
  E istanze di tipo "Documento":
    | id  | classe | alunno | tipo   |
    | $d1 | $cl1   | $a1    | <tipo> |
  Quando pagina attiva "documenti_bes"
  E click su "Aggiungi" con indice "2"
  Allora vedi pagina "documenti_bes_add" con parametri:
    | alunno |
    | $a1:id |
  E la sezione "#gs-main .panel-title" contiene "/Inserisci il documento relativo all'alunno BES/"
  E la sezione "#gs-main .panel-body" contiene "$a1:classe,cognome,nome"
  E la sezione "#gs-main .panel-body form .form-group:nth-of-type(1) label" contiene "Tipo di documenti"
  E la sezione "#gs-main .panel-body form .form-group:nth-of-type(1) select" contiene "<opzione1>"
  E la sezione "#gs-main .panel-body form .form-group:nth-of-type(1) select" contiene "<opzione2>"
  Ma la sezione "#gs-main .panel-body form .form-group:nth-of-type(1) select" non contiene "<opzione3>"
  Ma la sezione "#gs-main .panel-body form .form-group:nth-of-type(1) select" non contiene "<opzione4>"
  E la sezione "#gs-main .panel-body form .form-group:nth-of-type(2) label" contiene "Documento"
  E la sezione "#gs-main .panel-body form button:nth-of-type(1)" contiene "Conferma"
  E la sezione "#gs-main .panel-body form button:nth-of-type(2)" contiene "Annulla"
  Ma la sezione "#gs-main .panel-body form label" non contiene "Classe"
  Ma la sezione "#gs-main .panel-body form label" non contiene "Alunno"
  Esempi:
    | tipo | opzione1 | opzione2 | opzione3 | opzione4 |
    | B    | P.E.I.   | P.D.P.   | Diagnosi | Diagnosi |
    | H    | Diagnosi | Diagnosi | P.E.I.   | P.D.P.   |
    | D    | Diagnosi | Diagnosi | P.E.I.   | P.D.P.   |

Schema dello scenario: impedisce visualizzazione pagina inserimento quando documenti BES già inseriti
  Data ricerca istanze di tipo "Classe":
    | id   | anno | sezione |
    | $cl1 | 2    | B       |
  E ricerca istanze di tipo "Alunno":
    | id  | classe | abilitato |
    | $a1 | $cl1   | si        |
  E istanze di tipo "Documento":
    | id  | classe | alunno | tipo    |
    | $d1 | $cl1   | $a1    | <tipo1> |
    | $d2 | $cl1   | $a1    | <tipo2> |
  Quando pagina attiva "documenti_bes"
  Allora vedi la tabella non ordinata:
    | alunno                                  | documento       | azione       |
    | $a1 $a1:classe,classe.corso,classe.sede | Documento Excel | /^Cancella$/ |
    | $a1 $a1:classe,classe.corso,classe.sede | Documento Pdf   | /^Cancella$/ |
  Esempi:
    | tipo1 | tipo2 |
    | B     | H     |
    | B     | D     |

Schema dello scenario: visualizza errore per pagina inserimento documenti BES già inseriti
  Data ricerca istanze di tipo "Classe":
    | id   | anno | sezione |
    | $cl1 | 2    | B       |
  E ricerca istanze di tipo "Alunno":
    | id  | classe | abilitato |
    | $a1 | $cl1   | si        |
  E istanze di tipo "Documento":
    | id  | classe | alunno | tipo    |
    | $d1 | $cl1   | $a1    | <tipo1> |
    | $d2 | $cl1   | $a1    | <tipo2> |
  Quando vai alla pagina "documenti_bes_add" con parametri:
    | alunno |
    | $a1:id |
  Allora vedi errore pagina "404"
  Esempi:
    | tipo1 | tipo2 |
    | B     | H     |
    | B     | D     |


################################################################################
# Bisogna caricare un documento da inserire come documento BES

Schema dello scenario: inserisce documento BES e lo visualizza su pagina inserimenti
  Quando pagina attiva "documenti_bes_add"
  E selezioni opzione "3ª A" da lista "documento_classe"
  E selezioni opzione "Pini Daniela" da pulsanti radio "documento_alunnoIndividuale"
  E selezioni opzione "<tipo>" da lista "documento_tipo"
  E alleghi file "documento-pdf.pdf" a dropzone
  E premi pulsante "Conferma"
  Allora vedi pagina "documenti_bes"
  E vedi la tabella:
    | alunno               | documento                | azione            |
    | /Pini Daniela.*3ª A/ | /<tipo>.*Pini Daniela/   | Aggiungi Cancella |
  E vedi file "archivio/classi/3A/riservato/<nome>-PINI-DANIELA.pdf" di dimensione "61514"
  Esempi:
    | tipo     | nome     |
    | Diagnosi | DIAGNOSI |
    | P.E.I.   | PEI      |
    | P.D.P.   | PDP      |

Schema dello scenario: annulla inserimento e torna a pagina inserimenti senza modifiche
  Quando pagina attiva "documenti_bes_add"
  E selezioni opzione "3ª A" da lista "documento_classe"
  E selezioni opzione "Pini Daniela" da pulsanti radio "documento_alunnoIndividuale"
  E selezioni opzione "<tipo>" da lista "documento_tipo"
  E alleghi file "documento-pdf.pdf" a dropzone
  E premi pulsante "Annulla"
  Allora vedi pagina "documenti_bes"
  E non vedi la tabella:
    | alunno | documento | azione |
  Ma la sezione "#gs-main .alert" contiene "/Non sono presenti documenti/i"
  E non vedi file "archivio/classi/3A/riservato/<nome>-PINI-DANIELA.pdf"
  Esempi:
    | tipo     | nome     |
    | Diagnosi | DIAGNOSI |
    | P.E.I.   | PEI      |
    | P.D.P.   | PDP      |

Scenario: errore inserimento documento BES senza selezione classe
  Quando pagina attiva "documenti_bes_add"
  E selezioni opzione "Diagnosi" da lista "documento_tipo"
  E alleghi file "documento-pdf.pdf" a dropzone
  E premi pulsante "Conferma"
  Allora vedi pagina "documenti_bes_add"
  E la sezione "#gs-main form .alert" contiene "/Non hai indicato l'alunno/i"

Scenario: errore inserimento documento BES senza selezione alunno
  Quando pagina attiva "documenti_bes_add"
  E selezioni opzione "3ª A" da lista "documento_classe"
  E selezioni opzione "Diagnosi" da lista "documento_tipo"
  E alleghi file "documento-pdf.pdf" a dropzone
  E premi pulsante "Conferma"
  Allora vedi pagina "documenti_bes_add"
  E la sezione "#gs-main form .alert" contiene "/Non hai indicato l'alunno/i"

Scenario: errore inserimento documento BES senza selezione tipo documento
  Quando pagina attiva "documenti_bes_add"
  E selezioni opzione "3ª A" da lista "documento_classe"
  E selezioni opzione "Pini Daniela" da pulsanti radio "documento_alunnoIndividuale"
  E alleghi file "documento-pdf.pdf" a dropzone
  E premi pulsante "Conferma"
  Allora vedi pagina "documenti_bes_add"
  E la sezione "#gs-main form .alert" contiene "/Non hai indicato il tipo di documento/i"

Scenario: impedisce inserimento documento BES con più di un allegato
  Quando pagina attiva "documenti_bes_add"
  E selezioni opzione "3ª A" da lista "documento_classe"
  E selezioni opzione "Pini Daniela" da pulsanti radio "documento_alunnoIndividuale"
  E selezioni opzione "Diagnosi" da lista "documento_tipo"
  E alleghi file "documento-pdf.pdf" a dropzone
  E alleghi file "documento-docx.docx" a dropzone
  Allora la sezione "#gs-main .dropzone .dz-error" contiene "/documento-docx\.docx.*Non puoi caricare altri file/i"

Scenario: impedisce inserimento documento BES senza allegato
  Quando pagina attiva "documenti_bes_add"
  E selezioni opzione "3ª A" da lista "documento_classe"
  E selezioni opzione "Pini Daniela" da pulsanti radio "documento_alunnoIndividuale"
  E selezioni opzione "Diagnosi" da lista "documento_tipo"
  Allora pulsante "Conferma" inattivo


################################################################################
# Bisogna controllare accesso a pagina

Scenario: mostra errore all'accesso pagina inserimento documenti BES senza utente
  Dato logout utente
  Quando vai alla pagina "documenti_bes_add"
  Allora vedi pagina "login_form"

Schema dello scenario: mostra errore all'accesso pagina inserimento documenti BES con altri utenti
  Dato logout utente
  E login utente con ruolo esatto <ruolo>
  Quando vai alla pagina "documenti_bes_add"
  Allora vedi errore pagina "403"
  Esempi:
    | ruolo          |
    | Amministratore |
    | Ata            |
    | Genitore       |
    | Alunno         |
    | Utente         |

Scenario: mostra errore all'accesso pagina inserimento documenti BES con docente non autorizzato
  Data modifica utente connesso:
    | responsabileBes |
    | no              |
  Quando vai alla pagina "documenti_bes_add"
  Allora vedi errore pagina "404"

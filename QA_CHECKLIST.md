# QA Checklist Sprint A/B

Questa checklist copre i flussi backend implementati finora.
Compilare `Esito` con `OK` o `KO` e aggiungere note se necessario.

## 1) Iscrizioni - Lista

| ID | Scenario | Risultato atteso | Esito | Note |
|---|---|---|---|---|
| REG-01 | Apri `Campagne > Iscrizioni` | Tabella allineata con colonne: ID, Codice, Referente, Tipo, Stato, Importo, Data, Azioni |  |  |
| REG-02 | Usa filtro stato | Vengono mostrate solo iscrizioni dello stato selezionato |  |  |
| REG-03 | Usa ricerca `s` | Filtra per nome/email/codice |  |  |
| REG-04 | Paginazione | Cambio pagina senza perdita filtri |  |  |
| REG-05 | Click `Visualizza` | Apre dettaglio iscrizione corretto |  |  |

## 2) Iscrizioni - Dettaglio

| ID | Scenario | Risultato atteso | Esito | Note |
|---|---|---|---|---|
| DET-01 | Apri dettaglio da lista | Vedi riepilogo, azioni, partecipanti, timeline |  |  |
| DET-02 | Click `Modifica dati iscrizione` | Entra in edit mode |  |  |
| DET-03 | Salva modifica iscrizione | Salvataggio OK e uscita automatica da edit mode |  |  |
| DET-04 | Modifica partecipante (in edit mode) | Dati aggiornati e notifica successo |  |  |
| DET-05 | Cambio stato iscrizione | Stato aggiornato + evento timeline |  |  |
| DET-06 | Aggiungi warning/info/pagamento | Evento visibile in timeline con testo corretto |  |  |

## 3) Creazione squadra da iscrizione

| ID | Scenario | Risultato atteso | Esito | Note |
|---|---|---|---|---|
| TEAM-01 | Team registration: crea squadra | Crea squadra e assegna tutti i partecipanti |  |  |
| TEAM-02 | Group/individual: assignment `all` | Crea squadra e assegna tutti i partecipanti |  |  |
| TEAM-03 | Group/individual: assignment `selected` | Crea squadra e assegna solo selezionati |  |  |
| TEAM-04 | Iscrizione già assegnata | Non crea duplicati e mostra errore coerente |  |  |

## 4) Squadre - Lista

| ID | Scenario | Risultato atteso | Esito | Note |
|---|---|---|---|---|
| SQ-01 | Apri `Campagne > Squadre` | Vedi colonne con stato e azioni |  |  |
| SQ-02 | Filtro stato | Mostra solo squadre dello stato selezionato |  |  |
| SQ-03 | Paginazione con filtro stato | Mantiene filtro in URL |  |  |
| SQ-04 | Azione `Gestisci` | Apre dettaglio squadra corretto |  |  |

## 5) Squadre - Dettaglio / Workflow

| ID | Scenario | Risultato atteso | Esito | Note |
|---|---|---|---|---|
| SWF-01 | Cambia stato (transizione valida) | Stato aggiornato + evento timeline squadra |  |  |
| SWF-02 | Transizione non valida | Operazione bloccata con messaggio errore |  |  |
| SWF-03 | Modifica dati squadra (non locked) | Salvataggio OK + evento log |  |  |
| SWF-04 | `locked`: modifica dati squadra | Bloccata da UI e backend |  |  |

## 6) Squadre - Composizione

| ID | Scenario | Risultato atteso | Esito | Note |
|---|---|---|---|---|
| CMP-01 | Bulk `remove` con selezione | Rimozione corretta + notifica con conteggio |  |  |
| CMP-02 | Bulk `move` senza target | Bloccato con errore `target_required` |  |  |
| CMP-03 | Bulk `move` con target | Spostamento corretto + notifica con conteggio |  |  |
| CMP-04 | Nessuna selezione bulk | Bloccato con errore `no_selection` |  |  |
| CMP-05 | Aggiungi componenti da non assegnati | Aggiunta corretta + conteggio |  |  |
| CMP-06 | Stato `locked` su composizione | Azioni disabilitate UI + blocco backend |  |  |

## 7) Export CSV

| ID | Scenario | Risultato atteso | Esito | Note |
|---|---|---|---|---|
| CSV-01 | Export Iscrizioni | CSV coerente coi filtri correnti |  |  |
| CSV-02 | Export Partecipanti | CSV coerente coi filtri correnti |  |  |
| CSV-03 | Export Squadre con filtro stato | CSV contiene solo stato filtrato |  |  |


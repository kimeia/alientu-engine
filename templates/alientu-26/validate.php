<?php
/**
 * AW_Validator_Alientu_26
 *
 * Validazione server-side per il template alientu-26.
 * Replica le stesse regole di validateForm() in alientu.js.
 *
 * Convenzione: ogni template espone una classe AW_Validator_{template_id}
 * con metodo validate(array $payload): array che restituisce un array di errori.
 * Array vuoto = validazione superata.
 */

defined( 'ABSPATH' ) || exit;

class AW_Validator_Alientu_26 {

    /**
     * Valida il payload di iscrizione.
     *
     * @param array $payload Output grezzo di collectFormData().
     * @return string[] Array di messaggi di errore. Vuoto se tutto ok.
     */
    public function validate( array $payload ): array {
        $errors = [];
        $type   = $payload['_meta']['form'] ?? '';

        if ( ! in_array( $type, [ 'team', 'individual', 'social' ], true ) ) {
            $errors[] = 'Tipo di iscrizione non valido.';
            return $errors;
        }

        // ── Referente (comune a tutti i tipi) ───────────────────────────────
        $ref = $payload['referente'] ?? [];

        if ( ! $this->validate_text( $ref['first_name'] ?? '', 4 ) ) {
            $errors[] = 'Il nome del referente deve contenere almeno 4 caratteri.';
        }

        if ( ! $this->validate_text( $ref['last_name'] ?? '', 3 ) ) {
            $errors[] = 'Il cognome del referente deve contenere almeno 3 caratteri.';
        }

        if ( ! $this->validate_email( $ref['email'] ?? '' ) ) {
            $errors[] = 'Inserisci un indirizzo email valido.';
        }

        if ( ! $this->validate_phone( $ref['phone'] ?? '' ) ) {
            $errors[] = 'Il numero di telefono deve contenere tra 8 e 15 cifre.';
        }

        // ── Consensi (comuni a tutti) ────────────────────────────────────────
        if ( empty( $ref['accepted_rules'] ) || empty( $ref['accepted_privacy'] ) ) {
            $errors[] = 'Devi accettare il regolamento e l\'informativa privacy.';
        }

        // ── Validazione specifica per tipo ───────────────────────────────────

        switch ( $type ) {
            case 'team':
                $errors = array_merge( $errors, $this->validate_team( $payload ) );
                break;

            case 'individual':
                $errors = array_merge( $errors, $this->validate_individual( $payload ) );
                break;

            case 'social':
                $errors = array_merge( $errors, $this->validate_social( $payload ) );
                break;
        }

        return $errors;
    }

    // ─── Validazione per tipo TEAM ───────────────────────────────────────────

    private function validate_team( array $payload ): array {
        $errors = [];
        $team   = $payload['team'] ?? [];

        // Nome squadra
        if ( ! $this->validate_text( $team['name'] ?? '', 3, 20 ) ) {
            $errors[] = 'Il nome della squadra deve contenere tra 3 e 20 caratteri.';
        }

        // Almeno un colore selezionato
        if ( empty( $team['color_pref_1'] ) && empty( $team['color_custom'] ) ) {
            $errors[] = 'Seleziona almeno un colore per la squadra.';
        }

        // Se colore custom, deve avere descrizione
        if ( ! empty( $team['color_custom'] ) && ! $this->validate_text( $team['color_custom'], 1 ) ) {
            $errors[] = 'Descrivi il colore personalizzato.';
        }

        // Numero giocatori
        $players = $payload['players'] ?? [];
        $count   = count( $players );

        if ( $count < 6 || $count > 12 ) {
            $errors[] = 'La squadra deve avere tra 6 e 12 partecipanti.';
        }

        // Ogni giocatore deve avere nome, cognome, fascia
        foreach ( $players as $i => $p ) {
            if ( ! $this->validate_text( $p['first_name'] ?? '', 2 ) ) {
                $errors[] = sprintf( 'Giocatore %d: nome mancante o troppo corto.', $i + 1 );
            }
            if ( ! $this->validate_text( $p['last_name'] ?? '', 2 ) ) {
                $errors[] = sprintf( 'Giocatore %d: cognome mancante o troppo corto.', $i + 1 );
            }
            if ( empty( $p['age_band'] ) || ! in_array( $p['age_band'], [ 'A', 'B', 'C', 'D' ], true ) ) {
                $errors[] = sprintf( 'Giocatore %d: seleziona la fascia d\'età.', $i + 1 );
            }
        }

        // Composizione squadra: almeno 3 fasce diverse, almeno 2 per fascia
        if ( $count >= 6 ) {
            $bands = [ 'A' => 0, 'B' => 0, 'C' => 0, 'D' => 0 ];
            foreach ( $players as $p ) {
                $band = $p['age_band'] ?? '';
                if ( isset( $bands[ $band ] ) ) {
                    $bands[ $band ]++;
                }
            }

            $present = array_filter( $bands, fn( $n ) => $n > 0 );

            if ( count( $present ) < 3 ) {
                $errors[] = 'La squadra deve avere almeno 3 fasce d\'età diverse.';
            }

            foreach ( $present as $band => $n ) {
                if ( $n < 2 ) {
                    $errors[] = sprintf( 'Fascia %s: servono almeno 2 partecipanti (presenti: %d).', $band, $n );
                }
            }
        }

        // Conviviale e trasporti obbligatori
        $social_mode = $payload['social']['mode'] ?? null;
        if ( empty( $social_mode ) ) {
            $errors[] = 'Indica se partecipi al momento conviviale.';
        }

        $transport = $payload['transport'] ?? [];
        if ( empty( $transport['mode'] ) ) {
            $errors[] = 'Indica la tua situazione trasporti.';
        } elseif ( $transport['mode'] === 'seek' || $transport['mode'] === 'offer' ) {
            if ( ! $this->validate_text( $transport['location'] ?? '', 2 ) ) {
                $errors[] = 'Indica il luogo di partenza per i trasporti.';
            }
            if ( $transport['mode'] === 'seek' && ( empty( $transport['seats_needed'] ) || (int) $transport['seats_needed'] < 1 ) ) {
                $errors[] = 'Indica quanti posti ti servono.';
            }
            if ( $transport['mode'] === 'offer' && ( empty( $transport['seats_offered'] ) || (int) $transport['seats_offered'] < 1 ) ) {
                $errors[] = 'Indica quanti posti puoi offrire.';
            }
        }

        return $errors;
    }

    // ─── Validazione per tipo INDIVIDUAL ──────────────────────────────────────

    private function validate_individual( array $payload ): array {
        $errors = [];
        $ref    = $payload['referente'] ?? [];

        // Fascia età obbligatoria
        if ( empty( $ref['fascia'] ) || ! in_array( $ref['fascia'], [ 'A', 'B', 'C', 'D' ], true ) ) {
            $errors[] = 'Seleziona la tua fascia d\'età.';
        }

        // Profilo scout/sport
        $profile = $payload['profile'] ?? [];
        if ( ! isset( $profile['is_scout'] ) || ! in_array( $profile['is_scout'], [ 'si', 'no' ], true ) ) {
            $errors[] = 'Indica se hai esperienza scout.';
        }
        if ( ! isset( $profile['is_sport'] ) || ! in_array( $profile['is_sport'], [ 'si', 'no' ], true ) ) {
            $errors[] = 'Indica se pratichi sport di squadra.';
        }

        // Conviviale e trasporti obbligatori (come team)
        $social_mode = $payload['social']['mode'] ?? null;
        if ( empty( $social_mode ) ) {
            $errors[] = 'Indica se partecipi al momento conviviale.';
        }

        $transport = $payload['transport'] ?? [];
        if ( empty( $transport['mode'] ) ) {
            $errors[] = 'Indica la tua situazione trasporti.';
        } elseif ( $transport['mode'] === 'seek' || $transport['mode'] === 'offer' ) {
            if ( ! $this->validate_text( $transport['location'] ?? '', 2 ) ) {
                $errors[] = 'Indica il luogo di partenza per i trasporti.';
            }
            if ( $transport['mode'] === 'seek' && ( empty( $transport['seats_needed'] ) || (int) $transport['seats_needed'] < 1 ) ) {
                $errors[] = 'Indica quanti posti ti servono.';
            }
            if ( $transport['mode'] === 'offer' && ( empty( $transport['seats_offered'] ) || (int) $transport['seats_offered'] < 1 ) ) {
                $errors[] = 'Indica quanti posti puoi offrire.';
            }
        }

        return $errors;
    }

    // ─── Validazione per tipo SOCIAL ──────────────────────────────────────────

    private function validate_social( array $payload ): array {
        $errors      = [];
        $participants = $payload['social_participants'] ?? [];

        if ( count( $participants ) < 1 ) {
            $errors[] = 'Inserisci almeno un partecipante al conviviale.';
        }

        foreach ( $participants as $i => $p ) {
            if ( ! $this->validate_text( $p['first_name'] ?? '', 2 ) ) {
                $errors[] = sprintf( 'Partecipante %d: nome mancante o troppo corto.', $i + 1 );
            }
            if ( ! $this->validate_text( $p['last_name'] ?? '', 2 ) ) {
                $errors[] = sprintf( 'Partecipante %d: cognome mancante o troppo corto.', $i + 1 );
            }
        }

        return $errors;
    }

    // ─── Helpers di validazione ──────────────────────────────────────────────

    private function validate_text( string $value, int $min = 1, int $max = 0 ): bool {
        $value = trim( $value );
        $len   = mb_strlen( $value );
        return $len >= $min && ( $max === 0 || $len <= $max );
    }

    private function validate_email( string $value ): bool {
        return (bool) filter_var( trim( $value ), FILTER_VALIDATE_EMAIL );
    }

    private function validate_phone( string $value ): bool {
        $digits = preg_replace( '/\D/', '', $value );
        $len    = strlen( $digits );
        return $len >= 8 && $len <= 15;
    }
}
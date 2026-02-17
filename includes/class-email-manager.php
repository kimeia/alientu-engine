<?php
/**
 * AW\Email_Manager
 *
 * Gestisce l'invio di email transazionali legate alle iscrizioni.
 *
 * Sprint 1: email plain text con dati essenziali.
 * Sprint 2: template HTML con merge tags, attachment PDF.
 */

namespace AW;

defined( 'ABSPATH' ) || exit;

class Email_Manager {

    /**
     * Invia un'email per una specifica iscrizione e template.
     *
     * @param int    $registration_id  ID iscrizione.
     * @param string $template_id      ID del template campagna (es. alientu-26).
     * @param string $template_name    Nome del template email (es. received, confirmed).
     *
     * @throws \RuntimeException Se l'invio fallisce (facoltativo: errori non bloccanti per chiamante).
     */
    public function send( int $registration_id, string $template_id, string $template_name ): void {
        $repo         = new Registration_Repo();
        $registration = $repo->find( $registration_id );

        if ( ! $registration ) {
            throw new \RuntimeException( "Iscrizione #{$registration_id} non trovata." );
        }

        // Carica config del template per subject
        $config = $this->load_template_config( $template_id );

        $subject = $this->get_subject( $config, $template_name, $registration );
        $body    = $this->render_body( $template_name, $registration );
        $to      = $registration->referente_email;

        if ( ! $to || ! is_email( $to ) ) {
            throw new \RuntimeException( "Email referente non valida: {$to}" );
        }

        $headers = [
            'Content-Type: text/plain; charset=UTF-8',
            'From: Alientu <noreply@alientu.it>', // TODO: configurabile da settings
        ];

        $sent = wp_mail( $to, $subject, $body, $headers );

        if ( ! $sent ) {
            throw new \RuntimeException( "Invio email fallito per iscrizione #{$registration_id}." );
        }
    }

    /**
     * Carica config.json del template.
     */
    private function load_template_config( string $template_id ): ?object {
        $file = AW_PLUGIN_DIR . "templates/{$template_id}/config.json";

        if ( ! file_exists( $file ) ) {
            return null;
        }

        $json = file_get_contents( $file );
        return json_decode( $json );
    }

    /**
     * Restituisce il subject dell'email con merge dei placeholder.
     */
    private function get_subject( ?object $config, string $template_name, object $registration ): string {
        $default = "Alientu 2026 — Notifica [{$registration->registration_code}]";

        if ( ! $config || ! isset( $config->emails->{$template_name}->subject ) ) {
            return $default;
        }

        $subject = $config->emails->{$template_name}->subject;

        // Merge tag {{registration_code}}
        return str_replace( '{{registration_code}}', $registration->registration_code, $subject );
    }

    /**
     * Renderizza il body dell'email.
     *
     * Sprint 1: plain text con dati essenziali.
     * Sprint 2: caricare template HTML da templates/{template_id}/emails/{template_name}.html
     */
    private function render_body( string $template_name, object $registration ): string {
        $code = $registration->registration_code;
        $name = $registration->referente_name;
        $type = $this->get_type_label( $registration->registration_type );

        switch ( $template_name ) {
            case 'received':
                return $this->body_received( $code, $name, $type, $registration );

            case 'waiting_payment':
                return $this->body_waiting_payment( $code, $name, $registration );

            case 'confirmed':
                return $this->body_confirmed( $code, $name );

            case 'needs_review':
                return $this->body_needs_review( $code, $name );

            case 'cancelled':
                return $this->body_cancelled( $code, $name );

            default:
                return "Notifica Alientu 2026\n\nCodice iscrizione: {$code}\n\nGrazie.";
        }
    }

    // ─── Template body Sprint 1 (plain text) ─────────────────────────────────

    private function body_received( string $code, string $name, string $type, object $registration ): string {
        $total = number_format( (float) $registration->total_final, 2, ',', '.' );

        return <<<EOT
Ciao {$name},

Abbiamo ricevuto la tua richiesta di iscrizione per Alientu 2026.

Tipo: {$type}
Codice: {$code}
Importo totale: € {$total}

Riceverai a breve una email con le istruzioni per il pagamento.

Grazie,
Il team di Alientu
EOT;
    }

    private function body_waiting_payment( string $code, string $name, object $registration ): string {
        $total   = number_format( (float) $registration->total_final, 2, ',', '.' );
        $causale = $this->build_causale( $registration );

        return <<<EOT
Ciao {$name},

La tua iscrizione è stata approvata! Per completarla, effettua il bonifico con i seguenti dati:

Importo: € {$total}
IBAN: IT00X0000000000000000000000 (TODO: configurabile)
Causale: {$causale}

Una volta ricevuto il pagamento, ti invieremo la conferma definitiva.

Grazie,
Il team di Alientu
EOT;
    }

    private function body_confirmed( string $code, string $name ): string {
        return <<<EOT
Ciao {$name},

La tua iscrizione è confermata! 🎉

Codice: {$code}

Ci vediamo all'evento. A breve riceverai ulteriori dettagli.

Grazie,
Il team di Alientu
EOT;
    }

    private function body_needs_review( string $code, string $name ): string {
        return <<<EOT
Ciao {$name},

La tua iscrizione (codice {$code}) necessita di una revisione.

Ti contatteremo a breve per chiarimenti o integrazioni.

Grazie,
Il team di Alientu
EOT;
    }

    private function body_cancelled( string $code, string $name ): string {
        return <<<EOT
Ciao {$name},

La tua iscrizione (codice {$code}) è stata annullata.

Per qualsiasi chiarimento, contattaci.

Grazie,
Il team di Alientu
EOT;
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function get_type_label( string $type ): string {
        return match ( $type ) {
            'team'       => 'Iscrizione Squadra',
            'individual' => 'Iscrizione Individuale',
            'social'     => 'Solo Conviviale',
            default      => 'Iscrizione',
        };
    }

    /**
     * Costruisce la causale completa per il bonifico.
     * Formato: ALIENTU26 – SQUADRA – ALIENTU_2026-000123 – ROSSI
     */
    private function build_causale( object $registration ): string {
        $payload = json_decode( $registration->payload_json, true );
        $type_causale = match ( $registration->registration_type ) {
            'team'       => 'SQUADRA',
            'individual' => 'INDIVIDUALE',
            'social'     => 'CONVIVIALE',
            default      => 'ISCRIZIONE',
        };

        $cognome = strtoupper(
            $payload['referente']['last_name']
            ?? explode( ' ', $registration->referente_name )[1]
            ?? 'REFERENTE'
        );

        // Estrae il prefisso dal codice (es. ALIENTU26 da ALIENTU26-000123)
        $prefix = explode( '-', $registration->registration_code )[0] ?? 'ALIENTU26';

        return "{$prefix} – {$type_causale} – {$registration->registration_code} – {$cognome}";
    }
}
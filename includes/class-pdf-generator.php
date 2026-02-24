<?php
/**
 * AW\Pdf_Generator
 *
 * Generatore PDF per ricevute e documenti iscrizione.
 *
 * Sprint 1: stub vuoto - funzionalità rimandata a fase successiva.
 * Sprint 2+: implementazione con mPDF o libreria equivalente.
 */

namespace AW;

defined( 'ABSPATH' ) || exit;

class Pdf_Generator {

    /**
     * Genera un PDF per una specifica iscrizione.
     *
     * @param int    $registration_id  ID iscrizione.
     * @param string $template_name    Nome template PDF (es. receipt, confirmation).
     * @return string|false            Path del file PDF generato, o false se fallisce.
     */
    public function generate( int $registration_id, string $template_name ) {
        // TODO: implementazione futura con mPDF
        // 1. Caricare dati iscrizione da Registration_Repo
        // 2. Caricare template HTML da templates/{template_id}/pdfs/{template_name}.html
        // 3. Fare merge dei dati nel template
        // 4. Generare PDF con mPDF
        // 5. Salvare in wp-content/uploads/alientu-pdfs/
        // 6. Restituire path assoluto del file

        return false;
    }

    /**
     * Genera e allega un PDF a un'email.
     *
     * @param int    $registration_id  ID iscrizione.
     * @param string $template_name    Nome template PDF.
     * @return array Array con 'path' e 'filename' per attachment, o vuoto se fallisce.
     */
    public function generate_for_email( int $registration_id, string $template_name ): array {
        $path = $this->generate( $registration_id, $template_name );

        if ( ! $path || ! file_exists( $path ) ) {
            return [];
        }

        return [
            'path'     => $path,
            'filename' => basename( $path ),
        ];
    }

    /**
     * Elimina PDF per una specifica iscrizione.
     *
     * @param int $registration_id ID iscrizione.
     * @return bool True se eliminato con successo.
     */
    public function delete( int $registration_id ): bool {
        // TODO: implementazione futura
        // Trovare tutti i PDF legati a registration_id ed eliminarli
        return false;
    }
}

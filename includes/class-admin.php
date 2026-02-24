<?php
/**
 * AW\Admin
 *
 * Interfaccia amministrativa per la gestione backend.
 */

namespace AW;

defined( 'ABSPATH' ) || exit;

class Admin {

    private const CAMPAIGN_META_KEY = '_aw_selected_campaign_id';

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_menu_pages' ] );
        add_action( 'admin_menu', [ $this, 'remove_new_registration_submenu' ], 99 );
        add_action( 'admin_post_aw_change_status', [ $this, 'handle_change_status' ] );
        add_action( 'admin_post_aw_add_registration_warning', [ $this, 'handle_add_registration_warning' ] );
        add_action( 'admin_post_aw_update_registration_details', [ $this, 'handle_update_registration_details' ] );
        add_action( 'admin_post_aw_update_registration_participant', [ $this, 'handle_update_registration_participant' ] );
        add_action( 'admin_post_aw_create_registration_team', [ $this, 'handle_create_registration_team' ] );
        add_action( 'admin_post_aw_update_team_details', [ $this, 'handle_update_team_details' ] );
        add_action( 'admin_post_aw_change_team_status', [ $this, 'handle_change_team_status' ] );
        add_action( 'admin_post_aw_team_bulk_move', [ $this, 'handle_team_bulk_move' ] );
        add_action( 'admin_post_aw_team_add_members', [ $this, 'handle_team_add_members' ] );
        add_action( 'admin_post_aw_manual_registration', [ $this, 'handle_manual_registration' ] );
        add_action( 'admin_post_aw_seed_demo_data', [ $this, 'handle_seed_demo_data' ] );
        add_action( 'admin_post_aw_cleanup_demo_data', [ $this, 'handle_cleanup_demo_data' ] );
        add_action( 'admin_post_aw_export_csv', [ $this, 'handle_export_csv' ] );
    }

    /**
     * Aggiunge le pagine admin sotto il menu CPT "Campagne".
     */
    public function add_menu_pages(): void {
        add_submenu_page(
            'edit.php?post_type=aw_campaign',
            'Dashboard',
            'Dashboard ' . $this->get_dashboard_badge_html(),
            'edit_posts',
            'aw-dashboard',
            [ $this, 'render_dashboard_page' ]
        );

        add_submenu_page(
            'edit.php?post_type=aw_campaign',
            'Iscrizioni',
            'Iscrizioni',
            'edit_posts',
            'aw-registrations',
            [ $this, 'render_registrations_page' ]
        );

        add_submenu_page(
            'edit.php?post_type=aw_campaign',
            'Partecipanti',
            'Partecipanti',
            'edit_posts',
            'aw-participants',
            [ $this, 'render_participants_page' ]
        );

        add_submenu_page(
            'edit.php?post_type=aw_campaign',
            'Squadre',
            'Squadre',
            'edit_posts',
            'aw-teams',
            [ $this, 'render_teams_page' ]
        );

        add_submenu_page(
            'edit.php?post_type=aw_campaign',
            'Nuova iscrizione',
            'Nuova iscrizione',
            'edit_posts',
            'aw-new-registration',
            [ $this, 'render_new_registration_page' ]
        );

        add_submenu_page(
            'edit.php?post_type=aw_campaign',
            'Impostazioni',
            'Impostazioni',
            'edit_posts',
            'aw-settings',
            [ $this, 'render_settings_page' ]
        );
    }

    /**
     * Dashboard: KPI base Sprint A.
     */
    public function render_dashboard_page(): void {
        $campaigns = $this->get_campaigns( true );
        $campaign  = $this->resolve_campaign( $campaigns );

        echo '<div class="wrap">';
        echo '<h1 class="wp-heading-inline">Dashboard</h1>';
        echo '<hr class="wp-header-end">';

        if ( ! $campaign ) {
            echo '<div class="notice notice-warning"><p>Nessuna campagna disponibile. <a href="' . esc_url( admin_url( 'post-new.php?post_type=aw_campaign' ) ) . '">Crea la tua prima campagna</a>.</p></div>';
            echo '</div>';
            return;
        }

        $this->render_campaign_selector( 'aw-dashboard', $campaign->ID, $campaigns );
        $m = $this->get_dashboard_metrics( $campaign->ID );

        echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;margin-top:16px;">';
        $this->render_kpi_card( 'Iscrizioni', (string) $m['registrations_total'], 'Team: ' . $m['type_counts']['team'] . ' | Individual: ' . $m['type_counts']['individual'] . ' | Group: ' . $m['type_counts']['group'] . ' | Social: ' . $m['type_counts']['social'] );
        $this->render_kpi_card( 'Partecipanti gioco', (string) $m['participants_game_total'], 'Conviviale: ' . $m['participants_social_total'] );
        $this->render_kpi_card( 'Squadre', (string) $m['teams_total'], 'Da iscrizioni team' );
        $this->render_kpi_card( 'Pagamenti', $m['payments_expected_fmt'], 'Confermati: ' . $m['payments_confirmed_fmt'] );
        echo '</div>';

        echo '</div>';
    }

    /**
     * Vista dettaglio di una singola iscrizione.
     */
    private function render_registration_detail_page( int $registration_id, int $campaign_id, string $status, string $search, int $paged ): void {
        $repo         = new Registration_Repo();
        $registration = $repo->find( $registration_id );

        echo '<div class="wrap">';
        echo '<h1 class="wp-heading-inline">Dettaglio iscrizione</h1>';
        echo '<hr class="wp-header-end">';

        $back_url = add_query_arg(
            [
                'post_type'   => 'aw_campaign',
                'page'        => 'aw-registrations',
                'campaign_id' => $campaign_id,
                'status'      => $status,
                's'           => $search,
                'paged'       => $paged,
                'orderby'     => isset( $_GET['orderby'] ) ? sanitize_key( (string) $_GET['orderby'] ) : 'created_at',
                'order'       => isset( $_GET['order'] ) ? strtoupper( sanitize_text_field( (string) $_GET['order'] ) ) : 'DESC',
            ],
            admin_url( 'edit.php' )
        );
        echo '<p><a class="button" href="' . esc_url( $back_url ) . '">&larr; Torna alla lista</a></p>';

        if ( isset( $_GET['warning_added'] ) && (int) $_GET['warning_added'] === 1 ) {
            echo '<div class="notice notice-success is-dismissible"><p>Nota operativa aggiunta alla timeline.</p></div>';
        }
        if ( isset( $_GET['registration_updated'] ) && (int) $_GET['registration_updated'] === 1 ) {
            echo '<div class="notice notice-success is-dismissible"><p>Dati iscrizione aggiornati.</p></div>';
        }
        if ( isset( $_GET['participant_updated'] ) && (int) $_GET['participant_updated'] === 1 ) {
            echo '<div class="notice notice-success is-dismissible"><p>Dati partecipante aggiornati.</p></div>';
        }
        if ( isset( $_GET['team_created'] ) && (int) $_GET['team_created'] === 1 ) {
            echo '<div class="notice notice-success is-dismissible"><p>Squadra creata e partecipanti assegnati.</p></div>';
        }

        if ( ! $registration ) {
            echo '<div class="notice notice-error"><p>Iscrizione non trovata.</p></div>';
            echo '</div>';
            return;
        }

        if ( $campaign_id > 0 && (int) $registration->campaign_id !== $campaign_id ) {
            echo '<div class="notice notice-error"><p>Questa iscrizione non appartiene alla campagna selezionata.</p></div>';
            echo '</div>';
            return;
        }

        $payload = json_decode( (string) $registration->payload_json, true );
        if ( ! is_array( $payload ) ) {
            $payload = [];
        }

        $participant_repo = new Participant_Repo();
        $participants     = $participant_repo->get_by_registration( $registration_id );
        $team_repo        = new Team_Repo();

        $linked_team_id = 0;
        foreach ( $participants as $p ) {
            if ( ! empty( $p->team_id ) ) {
                $linked_team_id = (int) $p->team_id;
                break;
            }
        }
        $linked_team = $linked_team_id > 0 ? $team_repo->find( $linked_team_id ) : null;
        $edit_mode   = isset( $_GET['edit'] ) && (int) $_GET['edit'] === 1;

        echo '<div style="display:grid;grid-template-columns:2fr 1fr;gap:16px;align-items:start;">';
        echo '<div style="background:#fff;border:1px solid #dcdcde;padding:14px;">';
        echo '<h2 style="margin-top:0;">Riepilogo</h2>';
        echo '<table class="widefat striped" style="margin:0;">';
        echo '<tbody>';
        echo '<tr><th style="width:220px;">ID</th><td>' . esc_html( (string) $registration->id ) . '</td></tr>';
        echo '<tr><th>Codice</th><td><code>' . esc_html( (string) $registration->registration_code ) . '</code></td></tr>';
        echo '<tr><th>Tipo</th><td>' . esc_html( $this->get_type_label( (string) $registration->registration_type ) ) . '</td></tr>';
        echo '<tr><th>Stato</th><td>' . $this->render_status_badge( (string) $registration->status ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo '<tr><th>Referente</th><td><strong>' . esc_html( (string) $registration->referente_name ) . '</strong></td></tr>';
        echo '<tr><th>Email</th><td>' . esc_html( (string) $registration->referente_email ) . '</td></tr>';
        echo '<tr><th>Telefono</th><td>' . esc_html( (string) $registration->referente_phone ) . '</td></tr>';
        echo '<tr><th>Totale</th><td>EUR ' . esc_html( number_format_i18n( (float) $registration->total_final, 2 ) ) . '</td></tr>';
        echo '<tr><th>Creata</th><td>' . esc_html( date_i18n( 'd/m/Y H:i', strtotime( (string) $registration->created_at ) ) ) . '</td></tr>';
        echo '<tr><th>Aggiornata</th><td>' . esc_html( ! empty( $registration->updated_at ) ? date_i18n( 'd/m/Y H:i', strtotime( (string) $registration->updated_at ) ) : '-' ) . '</td></tr>';
        echo '</tbody>';
        echo '</table>';
        if ( $edit_mode ) {
            echo '<hr>';
            echo '<h3 style="margin:12px 0 8px;">Modifica iscrizione</h3>';
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
            echo '<input type="hidden" name="action" value="aw_update_registration_details">';
            echo '<input type="hidden" name="_wpnonce" value="' . esc_attr( wp_create_nonce( 'aw_update_registration_details' ) ) . '">';
            echo '<input type="hidden" name="registration_id" value="' . esc_attr( (string) $registration_id ) . '">';
            echo '<input type="hidden" name="campaign_id" value="' . esc_attr( (string) $campaign_id ) . '">';
            echo '<label style="display:block;margin-bottom:6px;"><strong>Referente</strong></label>';
            echo '<input type="text" name="referente_name" value="' . esc_attr( (string) $registration->referente_name ) . '" style="width:100%;margin-bottom:8px;" required>';
            echo '<label style="display:block;margin-bottom:6px;"><strong>Email referente</strong></label>';
            echo '<input type="email" name="referente_email" value="' . esc_attr( (string) $registration->referente_email ) . '" style="width:100%;margin-bottom:8px;" required>';
            echo '<label style="display:block;margin-bottom:6px;"><strong>Telefono referente</strong></label>';
            echo '<input type="text" name="referente_phone" value="' . esc_attr( (string) $registration->referente_phone ) . '" style="width:100%;margin-bottom:8px;">';
            if ( $linked_team ) {
                echo '<input type="hidden" name="team_id" value="' . esc_attr( (string) $linked_team->id ) . '">';
                echo '<label style="display:block;margin-bottom:6px;"><strong>Nome squadra</strong></label>';
                echo '<input type="text" name="team_name" value="' . esc_attr( (string) $linked_team->name ) . '" style="width:100%;margin-bottom:8px;">';
                echo '<label style="display:block;margin-bottom:6px;"><strong>Colore squadra</strong></label>';
                echo '<input type="text" name="team_color" value="' . esc_attr( (string) $linked_team->color ) . '" style="width:100%;margin-bottom:8px;">';
            }
            echo '<p><button type="submit" class="button button-primary">Salva modifiche</button></p>';
            echo '</form>';
        }
        echo '</div>';

        echo '<div style="background:#fff;border:1px solid #dcdcde;padding:14px;">';
        echo '<h2 style="margin-top:0;">Azioni</h2>';
        $edit_url = add_query_arg(
            [
                'post_type'       => 'aw_campaign',
                'page'            => 'aw-registrations',
                'campaign_id'     => (int) $registration->campaign_id,
                'registration_id' => $registration_id,
                'edit'            => 1,
            ],
            admin_url( 'edit.php' )
        );
        $view_url = add_query_arg(
            [
                'post_type'       => 'aw_campaign',
                'page'            => 'aw-registrations',
                'campaign_id'     => (int) $registration->campaign_id,
                'registration_id' => $registration_id,
            ],
            admin_url( 'edit.php' )
        );
        if ( ! $edit_mode ) {
            echo '<p><a class="button button-primary" href="' . esc_url( $edit_url ) . '">Modifica dati iscrizione</a></p>';
        } else {
            echo '<p><a class="button" href="' . esc_url( $view_url ) . '">Fine modifica</a></p>';
        }
        $workflow  = new Workflow();
        $available = $workflow->available_transitions( (string) $registration->status );
        if ( empty( $available ) ) {
            echo '<p>Nessuna transizione disponibile.</p>';
        } else {
            echo '<p>';
            $items = [];
            foreach ( $available as $new_status ) {
                $items[] = sprintf(
                    '<a href="#" class="button" style="margin:0 6px 6px 0;" onclick="awChangeStatus(%d, \'%s\', %d, %d); return false;">%s</a>',
                    (int) $registration->id,
                    esc_attr( $new_status ),
                    (int) $registration->campaign_id,
                    (int) $registration->id,
                    esc_html( $this->get_status_action_label( $new_status ) )
                );
            }
            echo implode( '', $items ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo '</p>';
            add_action( 'admin_footer', [ $this, 'render_change_status_script' ], 1 );
        }

        echo '<hr>';
        echo '<h3 style="margin:12px 0 8px;">Aggiungi warning/nota</h3>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        echo '<input type="hidden" name="action" value="aw_add_registration_warning">';
        echo '<input type="hidden" name="_wpnonce" value="' . esc_attr( wp_create_nonce( 'aw_add_registration_warning' ) ) . '">';
        echo '<input type="hidden" name="registration_id" value="' . esc_attr( (string) $registration_id ) . '">';
        echo '<input type="hidden" name="campaign_id" value="' . esc_attr( (string) $campaign_id ) . '">';
        echo '<label style="display:block;margin-bottom:6px;"><strong>Tipo</strong></label>';
        echo '<select name="note_type" style="width:100%;margin-bottom:8px;">';
        echo '<option value="warning">Warning</option>';
        echo '<option value="info">Info</option>';
        echo '<option value="payment">Pagamento</option>';
        echo '</select>';
        echo '<label style="display:block;margin-bottom:6px;"><strong>Messaggio</strong></label>';
        echo '<textarea name="note_text" rows="4" style="width:100%;" placeholder="Es: Bonifico ricevuto il 12/03. Verifica contabile in corso." required></textarea>';
        echo '<p><button type="submit" class="button button-primary">Salva nota</button></p>';
        echo '</form>';
        echo '</div>';
        echo '</div>';
        echo '<div style="margin-top:16px;background:#fff;border:1px solid #dcdcde;padding:14px;">';
        echo '<h2 style="margin-top:0;">Gestione squadra</h2>';
        if ( $linked_team ) {
            echo '<p>Questa iscrizione risulta assegnata alla squadra <strong>' . esc_html( (string) $linked_team->name ) . '</strong> (ID ' . esc_html( (string) $linked_team->id ) . ').</p>';
            $team_url = add_query_arg(
                [
                    'post_type'   => 'aw_campaign',
                    'page'        => 'aw-teams',
                    'campaign_id' => (int) $registration->campaign_id,
                    's'           => (string) $linked_team->name,
                ],
                admin_url( 'edit.php' )
            );
            echo '<p><a class="button" href="' . esc_url( $team_url ) . '">Apri squadra</a></p>';
        } elseif ( empty( $participants ) ) {
            echo '<p>Nessun partecipante disponibile per assegnazione.</p>';
        } else {
            $proposed_team_name  = trim( (string) ( $payload['team']['name'] ?? '' ) );
            $proposed_team_color = trim( (string) ( $payload['team']['color_custom'] ?? $payload['team']['color_pref_1'] ?? '' ) );
            if ( $proposed_team_name === '' ) {
                $proposed_team_name = 'Team ' . (string) $registration->registration_code;
            }

            $is_team_registration = (string) $registration->registration_type === 'team';
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
            echo '<input type="hidden" name="action" value="aw_create_registration_team">';
            echo '<input type="hidden" name="_wpnonce" value="' . esc_attr( wp_create_nonce( 'aw_create_registration_team' ) ) . '">';
            echo '<input type="hidden" name="registration_id" value="' . esc_attr( (string) $registration_id ) . '">';
            echo '<input type="hidden" name="campaign_id" value="' . esc_attr( (string) $campaign_id ) . '">';
            echo '<label style="display:block;margin-bottom:6px;"><strong>Nome squadra</strong></label>';
            echo '<input type="text" name="team_name" value="' . esc_attr( $proposed_team_name ) . '" style="width:100%;max-width:420px;margin-bottom:8px;" required>';
            echo '<label style="display:block;margin-bottom:6px;"><strong>Colore squadra</strong></label>';
            echo '<input type="text" name="team_color" value="' . esc_attr( $proposed_team_color ) . '" style="width:100%;max-width:420px;margin-bottom:8px;">';
            echo '<label style="display:block;margin-bottom:6px;"><strong>Capacita</strong></label>';
            echo '<input type="number" min="2" max="20" name="capacity" value="12" style="width:120px;margin-bottom:10px;">';

            if ( $is_team_registration ) {
                echo '<input type="hidden" name="assignment_mode" value="all">';
                echo '<p><em>Iscrizione team: verranno assegnati tutti i partecipanti.</em></p>';
            } else {
                echo '<label style="display:block;margin-bottom:6px;"><strong>Assegnazione partecipanti</strong></label>';
                echo '<select name="assignment_mode" style="margin-bottom:8px;">';
                echo '<option value="all">Tutti i partecipanti</option>';
                echo '<option value="selected">Solo selezionati</option>';
                echo '</select>';
                echo '<div style="border:1px solid #dcdcde;padding:8px;max-width:520px;max-height:180px;overflow:auto;">';
                foreach ( $participants as $p ) {
                    $label = trim( (string) $p->first_name . ' ' . (string) $p->last_name );
                    echo '<label style="display:block;margin:3px 0;">';
                    echo '<input type="checkbox" name="participant_ids[]" value="' . esc_attr( (string) $p->id ) . '"> ';
                    echo esc_html( $label );
                    echo '</label>';
                }
                echo '</div>';
                echo '<p><small>Con modalita "Solo selezionati" scegli almeno un partecipante.</small></p>';
            }

            echo '<p><button type="submit" class="button button-primary">Crea squadra e assegna</button></p>';
            echo '</form>';
        }
        echo '</div>';

        echo '<div style="margin-top:16px;background:#fff;border:1px solid #dcdcde;padding:14px;">';
        echo '<h2 style="margin-top:0;">Partecipanti</h2>';
        if ( empty( $participants ) ) {
            echo '<p>Nessun partecipante associato.</p>';
        } else {
            echo '<table class="wp-list-table widefat striped">';
            echo '<thead><tr>';
            echo '<th style="width:50px;">ID</th>';
            echo '<th>Nome</th>';
            echo '<th>Contatti</th>';
            echo '<th>Fascia</th>';
            echo '<th>Conviviale</th>';
            echo '<th>Squadra</th>';
            if ( $edit_mode ) {
                echo '<th>Modifica</th>';
            }
            echo '</tr></thead><tbody>';

            foreach ( $participants as $p ) {
                $team_name = '-';
                if ( ! empty( $p->team_id ) ) {
                    $team = $team_repo->find( (int) $p->team_id );
                    if ( $team ) {
                        $team_name = (string) $team->name;
                    }
                }
                echo '<tr>';
                echo '<td>' . esc_html( (string) $p->id ) . '</td>';
                echo '<td><strong>' . esc_html( trim( (string) $p->first_name . ' ' . (string) $p->last_name ) ) . '</strong></td>';
                echo '<td>' . esc_html( (string) $p->email ) . '<br><small>' . esc_html( (string) $p->phone ) . '</small></td>';
                echo '<td>' . esc_html( $this->get_age_band_label( (string) $p->age_band ) ) . '</td>';
                echo '<td>' . ( (int) $p->attends_social === 1 ? 'Si' : 'No' ) . '</td>';
                echo '<td>' . esc_html( $team_name ) . '</td>';
                if ( $edit_mode ) {
                    echo '<td>';
                    echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:grid;grid-template-columns:repeat(6,minmax(90px,1fr));gap:6px;align-items:end;">';
                    echo '<input type="hidden" name="action" value="aw_update_registration_participant">';
                    echo '<input type="hidden" name="_wpnonce" value="' . esc_attr( wp_create_nonce( 'aw_update_registration_participant' ) ) . '">';
                    echo '<input type="hidden" name="registration_id" value="' . esc_attr( (string) $registration_id ) . '">';
                    echo '<input type="hidden" name="campaign_id" value="' . esc_attr( (string) $campaign_id ) . '">';
                    echo '<input type="hidden" name="participant_id" value="' . esc_attr( (string) $p->id ) . '">';
                    echo '<input type="text" name="first_name" value="' . esc_attr( (string) $p->first_name ) . '" placeholder="Nome">';
                    echo '<input type="text" name="last_name" value="' . esc_attr( (string) $p->last_name ) . '" placeholder="Cognome">';
                    echo '<input type="email" name="email" value="' . esc_attr( (string) $p->email ) . '" placeholder="Email">';
                    echo '<input type="text" name="phone" value="' . esc_attr( (string) $p->phone ) . '" placeholder="Telefono">';
                    echo '<select name="age_band">';
                    echo '<option value=""' . selected( (string) $p->age_band, '', false ) . '>Fascia</option>';
                    echo '<option value="A"' . selected( (string) $p->age_band, 'A', false ) . '>A (8-11)</option>';
                    echo '<option value="B"' . selected( (string) $p->age_band, 'B', false ) . '>B (11-17)</option>';
                    echo '<option value="C"' . selected( (string) $p->age_band, 'C', false ) . '>C (17-39)</option>';
                    echo '<option value="D"' . selected( (string) $p->age_band, 'D', false ) . '>D (39+)</option>';
                    echo '</select>';
                    echo '<select name="attends_social">';
                    echo '<option value="1"' . selected( (int) $p->attends_social, 1, false ) . '>Conviviale: Si</option>';
                    echo '<option value="0"' . selected( (int) $p->attends_social, 0, false ) . '>Conviviale: No</option>';
                    echo '</select>';
                    echo '<button type="submit" class="button">Salva</button>';
                    echo '</form>';
                    echo '</td>';
                }
                echo '</tr>';
            }

            echo '</tbody></table>';
        }
        echo '</div>';

        $logs = $workflow->get_log( $registration_id );
        echo '<div style="margin-top:16px;background:#fff;border:1px solid #dcdcde;padding:14px;">';
        echo '<h2 style="margin-top:0;">Timeline stato</h2>';
        if ( empty( $logs ) ) {
            echo '<p>Nessun evento log.</p>';
        } else {
            echo '<table class="wp-list-table widefat striped">';
            echo '<thead><tr><th style="width:180px;">Data/Ora</th><th>Transizione</th><th>Nota</th><th>Attore</th></tr></thead><tbody>';
            foreach ( $logs as $log ) {
                $from = ! empty( $log->from_status ) ? (string) $log->from_status : '-';
                $to   = (string) $log->to_status;
                echo '<tr>';
                echo '<td>' . esc_html( date_i18n( 'd/m/Y H:i', strtotime( (string) $log->created_at ) ) ) . '</td>';
                echo '<td><code>' . esc_html( $from ) . ' -> ' . esc_html( $to ) . '</code></td>';
                $note = (string) $log->note;
                if ( strpos( $note, '[WARNING]' ) === 0 ) {
                    $note = '[WARNING] ' . trim( substr( $note, 9 ) );
                } elseif ( strpos( $note, '[INFO]' ) === 0 ) {
                    $note = '[INFO] ' . trim( substr( $note, 6 ) );
                } elseif ( strpos( $note, '[PAYMENT]' ) === 0 ) {
                    $note = '[PAYMENT] ' . trim( substr( $note, 9 ) );
                }
                echo '<td>' . esc_html( $note ) . '</td>';
                echo '<td>' . esc_html( (string) $log->triggered_by ) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div>';

        echo '<div style="margin-top:16px;background:#fff;border:1px solid #dcdcde;padding:14px;">';
        echo '<h2 style="margin-top:0;">Dati tecnici</h2>';
        if ( empty( $payload ) ) {
            echo '<p>Payload non disponibile.</p>';
        } else {
            echo '<details>';
            echo '<summary style="cursor:pointer;"><strong>Mostra payload raw JSON</strong></summary>';
            echo '<pre style="margin-top:10px;white-space:pre-wrap;max-height:420px;overflow:auto;">' . esc_html( wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) . '</pre>';
            echo '</details>';
        }
        echo '</div>';

        echo '</div>';
    }

    /**
     * Lista iscrizioni con filtri base.
     * Ricerca: usa il parametro standard WordPress "s".
     */
    public function render_registrations_page(): void {
        $campaigns = $this->get_campaigns( true );
        $campaign  = $this->resolve_campaign( $campaigns );
        $campaign_id = $campaign ? (int) $campaign->ID : 0;

        if ( isset( $_GET['campaign_id'] ) && (int) $_GET['campaign_id'] === 0 ) {
            $campaign_id = 0;
        }

        $status = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '';
        $search = isset( $_GET['s'] ) ? sanitize_text_field( $_GET['s'] ) : '';
        $paged  = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
        $orderby = isset( $_GET['orderby'] ) ? sanitize_key( (string) $_GET['orderby'] ) : 'created_at';
        $order   = isset( $_GET['order'] ) && strtoupper( (string) $_GET['order'] ) === 'ASC' ? 'ASC' : 'DESC';
        $allowed_orderby = [ 'created_at', 'updated_at', 'status', 'registration_type', 'referente_name' ];
        if ( ! in_array( $orderby, $allowed_orderby, true ) ) {
            $orderby = 'created_at';
        }
        $registration_id = isset( $_GET['registration_id'] ) ? (int) $_GET['registration_id'] : 0;

        if ( $registration_id > 0 ) {
            $this->render_registration_detail_page( $registration_id, $campaign_id, $status, $search, $paged );
            return;
        }

        $repo = new Registration_Repo();
        $args = [
            'campaign_id' => $campaign_id ?: null,
            'status'      => $status ?: null,
            'search'      => $search ?: null,
            'per_page'    => 20,
            'page'        => $paged,
            'orderby'     => $orderby,
            'order'       => $order,
        ];

        $registrations = $repo->get_list( $args );
        $total         = $repo->count( $args );
        $total_pages   = (int) ceil( $total / 20 );

        echo '<div class="wrap">';
        echo '<h1 class="wp-heading-inline">Iscrizioni</h1>';
        $new_url = add_query_arg(
            [
                'post_type'   => 'aw_campaign',
                'page'        => 'aw-new-registration',
                'campaign_id' => $campaign_id,
            ],
            admin_url( 'edit.php' )
        );
        echo '<a href="' . esc_url( $new_url ) . '" class="page-title-action">Nuova iscrizione</a>';
        $export_url = add_query_arg(
            [
                'action'      => 'aw_export_csv',
                'type'        => 'registrations',
                'campaign_id' => $campaign_id,
                'status'      => $status,
                's'           => $search,
            ],
            admin_url( 'admin-post.php' )
        );
        $export_url = wp_nonce_url( $export_url, 'aw_export_csv' );
        echo '<a href="' . esc_url( $export_url ) . '" class="page-title-action">Esporta CSV</a>';
        echo '<hr class="wp-header-end">';

        $this->render_feedback_notices();

        if ( ! empty( $campaigns ) ) {
            $this->render_campaign_selector( 'aw-registrations', $campaign_id, $campaigns, true );
        }

        echo '<form method="get" class="aw-filters" style="margin:16px 0;display:flex;gap:10px;align-items:end;flex-wrap:wrap;">';
        echo '<input type="hidden" name="post_type" value="aw_campaign">';
        echo '<input type="hidden" name="page" value="aw-registrations">';
        echo '<input type="hidden" name="campaign_id" value="' . esc_attr( (string) $campaign_id ) . '">';
        echo '<input type="hidden" name="orderby" value="' . esc_attr( $orderby ) . '">';
        echo '<input type="hidden" name="order" value="' . esc_attr( $order ) . '">';

        echo '<label style="display:flex;flex-direction:column;">';
        echo '<span>Stato</span>';
        echo '<select name="status">';
        echo '<option value="">Tutti gli stati</option>';
        echo '<option value="received" ' . selected( $status, 'received', false ) . '>Ricevuta</option>';
        echo '<option value="needs_review" ' . selected( $status, 'needs_review', false ) . '>Da revisionare</option>';
        echo '<option value="waiting_payment" ' . selected( $status, 'waiting_payment', false ) . '>In attesa pagamento</option>';
        echo '<option value="confirmed" ' . selected( $status, 'confirmed', false ) . '>Confermata</option>';
        echo '<option value="cancelled" ' . selected( $status, 'cancelled', false ) . '>Annullata</option>';
        echo '<option value="archived" ' . selected( $status, 'archived', false ) . '>Archiviata</option>';
        echo '</select>';
        echo '</label>';

        echo '<label style="display:flex;flex-direction:column;">';
        echo '<span>Cerca</span>';
        echo '<input type="search" name="s" value="' . esc_attr( $search ) . '" placeholder="Nome, email, codice...">';
        echo '</label>';

        echo '<button type="submit" class="button">Filtra</button>';
        if ( $status || $search ) {
            $reset_url = add_query_arg(
                [
                    'post_type'    => 'aw_campaign',
                    'page'         => 'aw-registrations',
                    'campaign_id'  => $campaign_id,
                ],
                admin_url( 'edit.php' )
            );
            echo '<a href="' . esc_url( $reset_url ) . '" class="button">Reset</a>';
        }
        echo '</form>';

        if ( empty( $registrations ) ) {
            echo '<p>Nessuna iscrizione trovata.</p>';
            echo '</div>';
            return;
        }

        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th style="width:50px;">ID</th>';
        echo '<th>Codice</th>';
        echo '<th>' . $this->render_sortable_header( 'Referente', 'referente_name', [ 'post_type' => 'aw_campaign', 'page' => 'aw-registrations', 'campaign_id' => $campaign_id, 'status' => $status, 's' => $search ], $orderby, $order ) . '</th>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo '<th>' . $this->render_sortable_header( 'Tipo', 'registration_type', [ 'post_type' => 'aw_campaign', 'page' => 'aw-registrations', 'campaign_id' => $campaign_id, 'status' => $status, 's' => $search ], $orderby, $order ) . '</th>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo '<th>' . $this->render_sortable_header( 'Stato', 'status', [ 'post_type' => 'aw_campaign', 'page' => 'aw-registrations', 'campaign_id' => $campaign_id, 'status' => $status, 's' => $search ], $orderby, $order ) . '</th>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo '<th>Importo</th>';
        echo '<th>' . $this->render_sortable_header( 'Data', 'created_at', [ 'post_type' => 'aw_campaign', 'page' => 'aw-registrations', 'campaign_id' => $campaign_id, 'status' => $status, 's' => $search ], $orderby, $order ) . '</th>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo '<th>Azioni</th>';
        echo '</tr></thead><tbody>';

        foreach ( $registrations as $r ) {
            $detail_url = add_query_arg(
                [
                    'post_type'       => 'aw_campaign',
                    'page'            => 'aw-registrations',
                    'campaign_id'     => $campaign_id,
                    'registration_id' => (int) $r->id,
                    'status'          => $status,
                    's'               => $search,
                    'paged'           => $paged,
                    'orderby'         => $orderby,
                    'order'           => $order,
                ],
                admin_url( 'edit.php' )
            );
            echo '<tr>';
            echo '<td>' . esc_html( $r->id ) . '</td>';
            echo '<td><a href="' . esc_url( $detail_url ) . '"><code>' . esc_html( $r->registration_code ) . '</code></a></td>';
            echo '<td><strong>' . esc_html( $r->referente_name ) . '</strong><br><small>' . esc_html( $r->referente_email ) . '</small></td>';
            echo '<td>' . esc_html( $this->get_type_label( $r->registration_type ) ) . '</td>';
            echo '<td>' . $this->render_status_badge( $r->status ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo '<td>EUR ' . esc_html( number_format_i18n( (float) $r->total_final, 2 ) ) . '</td>';
            echo '<td>' . esc_html( date_i18n( 'd/m/Y H:i', strtotime( $r->created_at ) ) ) . '</td>';
            echo '<td>' . $this->render_actions( $r ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo '</tr>';
        }

        echo '</tbody></table>';

        if ( $total_pages > 1 ) {
            $pagination_base = add_query_arg(
                [
                    'post_type'   => 'aw_campaign',
                    'page'        => 'aw-registrations',
                    'campaign_id' => $campaign_id,
                    'status'      => $status,
                    's'           => $search,
                    'orderby'     => $orderby,
                    'order'       => $order,
                    'paged'       => '%#%',
                ],
                admin_url( 'edit.php' )
            );

            echo '<div class="tablenav" style="margin-top:20px;"><div class="tablenav-pages">';
            echo wp_kses_post(
                paginate_links(
                    [
                        'base'      => $pagination_base,
                        'format'    => '',
                        'current'   => $paged,
                        'total'     => $total_pages,
                        'prev_text' => '&laquo;',
                        'next_text' => '&raquo;',
                    ]
                )
            );
            echo '</div></div>';
        }

        echo '</div>';
    }

    /**
     * Nasconde "Nuova iscrizione" dal menu laterale.
     * La pagina resta raggiungibile dalla vista Iscrizioni.
     */
    public function remove_new_registration_submenu(): void {
        remove_submenu_page( 'edit.php?post_type=aw_campaign', 'aw-new-registration' );
    }

    public function render_participants_page(): void {
        $campaigns = $this->get_campaigns( true );
        $campaign  = $this->resolve_campaign( $campaigns );

        echo '<div class="wrap">';
        echo '<h1 class="wp-heading-inline">Partecipanti</h1>';
        $export_url = add_query_arg(
            [
                'action'          => 'aw_export_csv',
                'type'            => 'participants',
                'campaign_id'     => (int) ( $campaign->ID ?? 0 ),
                'team_id'         => isset( $_GET['team_id'] ) ? (int) $_GET['team_id'] : -1,
                'attends_social'  => isset( $_GET['attends_social'] ) ? sanitize_text_field( (string) $_GET['attends_social'] ) : '',
                's'               => isset( $_GET['s'] ) ? sanitize_text_field( (string) $_GET['s'] ) : '',
            ],
            admin_url( 'admin-post.php' )
        );
        $export_url = wp_nonce_url( $export_url, 'aw_export_csv' );
        echo '<a href="' . esc_url( $export_url ) . '" class="page-title-action">Esporta CSV</a>';
        echo '<hr class="wp-header-end">';

        if ( ! $campaign ) {
            echo '<div class="notice notice-warning"><p>Nessuna campagna disponibile. <a href="' . esc_url( admin_url( 'post-new.php?post_type=aw_campaign' ) ) . '">Crea la tua prima campagna</a>.</p></div>';
            echo '</div>';
            return;
        }

        $campaign_id = (int) $campaign->ID;
        $this->render_campaign_selector( 'aw-participants', $campaign_id, $campaigns );

        $search       = isset( $_GET['s'] ) ? sanitize_text_field( $_GET['s'] ) : '';
        $team_filter  = isset( $_GET['team_id'] ) ? (int) $_GET['team_id'] : -1; // -1=tutti, 0=senza squadra, >0 squadra
        $social_raw   = isset( $_GET['attends_social'] ) ? sanitize_text_field( $_GET['attends_social'] ) : '';
        $social_filter = in_array( $social_raw, [ '0', '1' ], true ) ? $social_raw : '';
        $paged        = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
        $orderby      = isset( $_GET['orderby'] ) ? sanitize_key( (string) $_GET['orderby'] ) : 'name';
        $order        = isset( $_GET['order'] ) && strtoupper( (string) $_GET['order'] ) === 'ASC' ? 'ASC' : 'DESC';
        $per_page     = 20;
        $allowed_sort = [
            'id'                => 'p.id',
            'name'              => 'p.last_name',
            'email'             => 'p.email',
            'age_band'          => 'p.age_band',
            'attends_social'    => 'p.attends_social',
            'team_name'         => 't.name',
            'registration_code' => 'r.registration_code',
        ];
        if ( ! isset( $allowed_sort[ $orderby ] ) ) {
            $orderby = 'name';
        }

        $team_repo = new Team_Repo();
        $teams     = $team_repo->get_by_campaign( $campaign_id );

        global $wpdb;
        $p_table = "{$wpdb->prefix}aw_participants";
        $t_table = "{$wpdb->prefix}aw_teams";
        $r_table = "{$wpdb->prefix}aw_registrations";

        $where  = [ 'p.campaign_id = %d' ];
        $params = [ $campaign_id ];

        if ( $team_filter === 0 ) {
            $where[] = 'p.team_id IS NULL';
        } elseif ( $team_filter > 0 ) {
            $where[]  = 'p.team_id = %d';
            $params[] = $team_filter;
        }

        if ( $social_filter !== '' ) {
            $where[]  = 'p.attends_social = %d';
            $params[] = (int) $social_filter;
        }

        if ( $search !== '' ) {
            $like     = '%' . $wpdb->esc_like( $search ) . '%';
            $where[]  = '(p.first_name LIKE %s OR p.last_name LIKE %s OR p.email LIKE %s OR p.phone LIKE %s OR r.registration_code LIKE %s)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $where_sql = implode( ' AND ', $where );
        $offset    = ( $paged - 1 ) * $per_page;

        $count_sql = "SELECT COUNT(*) FROM {$p_table} p LEFT JOIN {$r_table} r ON r.id = p.source_registration_id WHERE {$where_sql}";
        $total     = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) );
        $total_pages = (int) ceil( $total / $per_page );

        $list_sql = "SELECT p.*, t.name AS team_name, r.registration_code
            FROM {$p_table} p
            LEFT JOIN {$t_table} t ON t.id = p.team_id
            LEFT JOIN {$r_table} r ON r.id = p.source_registration_id
            WHERE {$where_sql}
            ORDER BY {$allowed_sort[$orderby]} {$order}, p.first_name {$order}
            LIMIT %d OFFSET %d";

        $list_params   = $params;
        $list_params[] = $per_page;
        $list_params[] = $offset;
        $rows = $wpdb->get_results( $wpdb->prepare( $list_sql, $list_params ) ) ?: [];

        echo '<form method="get" style="margin:16px 0;display:flex;gap:10px;align-items:end;flex-wrap:wrap;">';
        echo '<input type="hidden" name="post_type" value="aw_campaign">';
        echo '<input type="hidden" name="page" value="aw-participants">';
        echo '<input type="hidden" name="campaign_id" value="' . esc_attr( (string) $campaign_id ) . '">';
        echo '<input type="hidden" name="orderby" value="' . esc_attr( $orderby ) . '">';
        echo '<input type="hidden" name="order" value="' . esc_attr( $order ) . '">';

        echo '<label style="display:flex;flex-direction:column;"><span>Squadra</span><select name="team_id">';
        echo '<option value="-1"' . selected( $team_filter, -1, false ) . '>Tutte</option>';
        echo '<option value="0"' . selected( $team_filter, 0, false ) . '>Senza squadra</option>';
        foreach ( $teams as $team_item ) {
            echo '<option value="' . esc_attr( (string) $team_item->id ) . '"' . selected( $team_filter, (int) $team_item->id, false ) . '>' . esc_html( (string) $team_item->name ) . '</option>';
        }
        echo '</select></label>';

        echo '<label style="display:flex;flex-direction:column;"><span>Conviviale</span><select name="attends_social">';
        echo '<option value=""' . selected( $social_filter, '', false ) . '>Tutti</option>';
        echo '<option value="1"' . selected( $social_filter, '1', false ) . '>Si</option>';
        echo '<option value="0"' . selected( $social_filter, '0', false ) . '>No</option>';
        echo '</select></label>';

        echo '<label style="display:flex;flex-direction:column;"><span>Cerca</span>';
        echo '<input type="search" name="s" value="' . esc_attr( $search ) . '" placeholder="Nome, email, codice...">';
        echo '</label>';

        echo '<button type="submit" class="button">Filtra</button>';
        if ( $search !== '' || $team_filter !== -1 || $social_filter !== '' ) {
            $reset_url = add_query_arg(
                [
                    'post_type'   => 'aw_campaign',
                    'page'        => 'aw-participants',
                    'campaign_id' => $campaign_id,
                ],
                admin_url( 'edit.php' )
            );
            echo '<a href="' . esc_url( $reset_url ) . '" class="button">Reset</a>';
        }
        echo '</form>';

        if ( empty( $rows ) ) {
            echo '<p>Nessun partecipante trovato.</p>';
            echo '</div>';
            return;
        }

        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr>';
        $participants_sort_base = [
            'post_type'      => 'aw_campaign',
            'page'           => 'aw-participants',
            'campaign_id'    => $campaign_id,
            'team_id'        => $team_filter,
            'attends_social' => $social_filter,
            's'              => $search,
        ];
        echo '<th style="width:50px;">' . $this->render_sortable_header( 'ID', 'id', $participants_sort_base, $orderby, $order ) . '</th>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo '<th>' . $this->render_sortable_header( 'Nome', 'name', $participants_sort_base, $orderby, $order ) . '</th>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo '<th>' . $this->render_sortable_header( 'Email / Telefono', 'email', $participants_sort_base, $orderby, $order ) . '</th>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo '<th>' . $this->render_sortable_header( 'Fascia', 'age_band', $participants_sort_base, $orderby, $order ) . '</th>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo '<th>' . $this->render_sortable_header( 'Conviviale', 'attends_social', $participants_sort_base, $orderby, $order ) . '</th>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo '<th>' . $this->render_sortable_header( 'Squadra', 'team_name', $participants_sort_base, $orderby, $order ) . '</th>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo '<th>' . $this->render_sortable_header( 'Iscrizione', 'registration_code', $participants_sort_base, $orderby, $order ) . '</th>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo '<th>Azioni</th>';
        echo '</tr></thead><tbody>';

        foreach ( $rows as $row ) {
            echo '<tr>';
            echo '<td>' . esc_html( $row->id ) . '</td>';
            $full_name = trim( (string) $row->first_name . ' ' . (string) $row->last_name );
            echo '<td><strong>' . esc_html( $full_name ) . '</strong></td>';
            echo '<td>' . esc_html( (string) $row->email ) . '<br><small>' . esc_html( (string) $row->phone ) . '</small></td>';
            echo '<td>' . esc_html( $this->get_age_band_label( (string) $row->age_band ) ) . '</td>';
            echo '<td>' . esc_html( (int) $row->attends_social === 1 ? 'Si' : 'No' ) . '</td>';
            echo '<td>' . esc_html( ! empty( $row->team_name ) ? (string) $row->team_name : '-' ) . '</td>';
            echo '<td><code>' . esc_html( (string) ( $row->registration_code ?? '' ) ) . '</code></td>';
            $registration_link = add_query_arg(
                [
                    'post_type'   => 'aw_campaign',
                    'page'        => 'aw-registrations',
                    'campaign_id' => $campaign_id,
                    'registration_id' => (int) $row->source_registration_id,
                ],
                admin_url( 'edit.php' )
            );
            echo '<td><a href="' . esc_url( $registration_link ) . '">Vedi iscrizione</a></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';

        if ( $total_pages > 1 ) {
            $pagination_base = add_query_arg(
                [
                    'post_type'       => 'aw_campaign',
                    'page'            => 'aw-participants',
                    'campaign_id'     => $campaign_id,
                    'team_id'         => $team_filter,
                    'attends_social'  => $social_filter,
                    's'               => $search,
                    'orderby'         => $orderby,
                    'order'           => $order,
                    'paged'           => '%#%',
                ],
                admin_url( 'edit.php' )
            );

            echo '<div class="tablenav" style="margin-top:20px;"><div class="tablenav-pages">';
            echo wp_kses_post(
                paginate_links(
                    [
                        'base'      => $pagination_base,
                        'format'    => '',
                        'current'   => $paged,
                        'total'     => $total_pages,
                        'prev_text' => '&laquo;',
                        'next_text' => '&raquo;',
                    ]
                )
            );
            echo '</div></div>';
        }

        echo '</div>';
    }

    public function render_teams_page(): void {
        $campaigns = $this->get_campaigns( true );
        $campaign  = $this->resolve_campaign( $campaigns );
        $campaign_id = (int) ( $campaign->ID ?? 0 );
        $team_id = isset( $_GET['team_id'] ) ? (int) $_GET['team_id'] : 0;

        if ( $team_id > 0 ) {
            $this->render_team_detail_page( $team_id, $campaign_id );
            return;
        }

        echo '<div class="wrap">';
        echo '<h1 class="wp-heading-inline">Squadre</h1>';
        $export_url = add_query_arg(
            [
                'action'      => 'aw_export_csv',
                'type'        => 'teams',
                'campaign_id' => $campaign_id,
                's'           => isset( $_GET['s'] ) ? sanitize_text_field( (string) $_GET['s'] ) : '',
                'status'      => isset( $_GET['status'] ) ? sanitize_key( (string) $_GET['status'] ) : '',
            ],
            admin_url( 'admin-post.php' )
        );
        $export_url = wp_nonce_url( $export_url, 'aw_export_csv' );
        echo '<a href="' . esc_url( $export_url ) . '" class="page-title-action">Esporta CSV</a>';
        echo '<hr class="wp-header-end">';

        if ( ! $campaign ) {
            echo '<div class="notice notice-warning"><p>Nessuna campagna disponibile. <a href="' . esc_url( admin_url( 'post-new.php?post_type=aw_campaign' ) ) . '">Crea la tua prima campagna</a>.</p></div>';
            echo '</div>';
            return;
        }

        $this->render_campaign_selector( 'aw-teams', $campaign_id, $campaigns );

        $search   = isset( $_GET['s'] ) ? sanitize_text_field( $_GET['s'] ) : '';
        $status_filter = isset( $_GET['status'] ) ? sanitize_key( (string) $_GET['status'] ) : '';
        $paged    = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
        $orderby  = isset( $_GET['orderby'] ) ? sanitize_key( (string) $_GET['orderby'] ) : 'name';
        $order    = isset( $_GET['order'] ) && strtoupper( (string) $_GET['order'] ) === 'ASC' ? 'ASC' : 'DESC';
        $per_page = 20;

        $team_repo = new Team_Repo();
        $rows      = $team_repo->get_by_campaign_with_count( $campaign_id );

        if ( $status_filter !== '' ) {
            $rows = array_values(
                array_filter(
                    $rows,
                    static fn( $row ) => (string) ( $row->status ?? 'draft' ) === $status_filter
                )
            );
        }

        if ( $search !== '' ) {
            $needle = function_exists( 'mb_strtolower' ) ? mb_strtolower( $search ) : strtolower( $search );
            $rows = array_values(
                array_filter(
                    $rows,
                    static function ( $row ) use ( $needle ) {
                        $haystack = (string) ( $row->name ?? '' ) . ' ' . (string) ( $row->color ?? '' );
                        $haystack = function_exists( 'mb_strtolower' ) ? mb_strtolower( $haystack ) : strtolower( $haystack );
                        return str_contains( $haystack, $needle );
                    }
                )
            );
        }

        $allowed_team_orderby = [ 'id', 'name', 'color', 'status', 'capacity', 'participant_count', 'created_at' ];
        if ( ! in_array( $orderby, $allowed_team_orderby, true ) ) {
            $orderby = 'name';
        }
        usort(
            $rows,
            static function ( $a, $b ) use ( $orderby, $order ) {
                $va = $a->{$orderby} ?? '';
                $vb = $b->{$orderby} ?? '';
                if ( in_array( $orderby, [ 'id', 'capacity', 'participant_count' ], true ) ) {
                    $cmp = (int) $va <=> (int) $vb;
                } else {
                    $cmp = strcmp( strtolower( (string) $va ), strtolower( (string) $vb ) );
                }
                return $order === 'ASC' ? $cmp : -$cmp;
            }
        );

        $total       = count( $rows );
        $total_pages = (int) ceil( $total / $per_page );
        $offset      = ( $paged - 1 ) * $per_page;
        $rows        = array_slice( $rows, $offset, $per_page );

        echo '<form method="get" style="margin:16px 0;display:flex;gap:10px;align-items:end;flex-wrap:wrap;">';
        echo '<input type="hidden" name="post_type" value="aw_campaign">';
        echo '<input type="hidden" name="page" value="aw-teams">';
        echo '<input type="hidden" name="campaign_id" value="' . esc_attr( (string) $campaign_id ) . '">';
        echo '<input type="hidden" name="orderby" value="' . esc_attr( $orderby ) . '">';
        echo '<input type="hidden" name="order" value="' . esc_attr( $order ) . '">';
        echo '<label style="display:flex;flex-direction:column;"><span>Stato</span>';
        echo '<select name="status">';
        echo '<option value="">Tutti</option>';
        foreach ( $this->get_team_status_options() as $st => $lb ) {
            echo '<option value="' . esc_attr( $st ) . '"' . selected( $status_filter, $st, false ) . '>' . esc_html( $lb ) . '</option>';
        }
        echo '</select>';
        echo '</label>';
        echo '<label style="display:flex;flex-direction:column;"><span>Cerca</span>';
        echo '<input type="search" name="s" value="' . esc_attr( $search ) . '" placeholder="Nome squadra o colore...">';
        echo '</label>';
        echo '<button type="submit" class="button">Filtra</button>';
        if ( $search !== '' || $status_filter !== '' ) {
            $reset_url = add_query_arg(
                [
                    'post_type'   => 'aw_campaign',
                    'page'        => 'aw-teams',
                    'campaign_id' => $campaign_id,
                ],
                admin_url( 'edit.php' )
            );
            echo '<a href="' . esc_url( $reset_url ) . '" class="button">Reset</a>';
        }
        echo '</form>';

        if ( empty( $rows ) ) {
            echo '<p>Nessuna squadra trovata.</p>';
            echo '</div>';
            return;
        }

        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr>';
        $teams_sort_base = [
            'post_type'   => 'aw_campaign',
            'page'        => 'aw-teams',
            'campaign_id' => $campaign_id,
            'status'      => $status_filter,
            's'           => $search,
        ];
        echo '<th style="width:60px;">' . $this->render_sortable_header( 'ID', 'id', $teams_sort_base, $orderby, $order ) . '</th>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo '<th>' . $this->render_sortable_header( 'Nome', 'name', $teams_sort_base, $orderby, $order ) . '</th>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo '<th>' . $this->render_sortable_header( 'Colore', 'color', $teams_sort_base, $orderby, $order ) . '</th>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo '<th>' . $this->render_sortable_header( 'Stato', 'status', $teams_sort_base, $orderby, $order ) . '</th>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo '<th>' . $this->render_sortable_header( 'Capacita', 'capacity', $teams_sort_base, $orderby, $order ) . '</th>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo '<th>' . $this->render_sortable_header( 'Giocatori', 'participant_count', $teams_sort_base, $orderby, $order ) . '</th>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo '<th>' . $this->render_sortable_header( 'Creata', 'created_at', $teams_sort_base, $orderby, $order ) . '</th>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo '<th>Azioni</th>';
        echo '</tr></thead><tbody>';

        foreach ( $rows as $row ) {
            echo '<tr>';
            echo '<td>' . esc_html( $row->id ) . '</td>';
            echo '<td><strong>' . esc_html( (string) $row->name ) . '</strong></td>';
            echo '<td>' . esc_html( (string) ( $row->color ?: '-' ) ) . '</td>';
            echo '<td>' . $this->render_team_status_badge( (string) ( $row->status ?? 'draft' ) ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo '<td>' . esc_html( (int) $row->capacity ) . '</td>';
            echo '<td>' . esc_html( (int) $row->participant_count ) . '</td>';
            echo '<td>' . esc_html( date_i18n( 'd/m/Y H:i', strtotime( (string) $row->created_at ) ) ) . '</td>';
            $team_manage_link = add_query_arg(
                [
                    'post_type'   => 'aw_campaign',
                    'page'        => 'aw-teams',
                    'campaign_id' => $campaign_id,
                    'team_id'     => (int) $row->id,
                ],
                admin_url( 'edit.php' )
            );
            $team_participants_link = add_query_arg(
                [
                    'post_type'   => 'aw_campaign',
                    'page'        => 'aw-participants',
                    'campaign_id' => $campaign_id,
                    'team_id'     => (int) $row->id,
                ],
                admin_url( 'edit.php' )
            );
            echo '<td><a href="' . esc_url( $team_manage_link ) . '">Gestisci</a> | <a href="' . esc_url( $team_participants_link ) . '">Vedi composizione</a></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';

        if ( $total_pages > 1 ) {
            $pagination_base = add_query_arg(
                [
                    'post_type'   => 'aw_campaign',
                    'page'        => 'aw-teams',
                    'campaign_id' => $campaign_id,
                    'status'      => $status_filter,
                    's'           => $search,
                    'orderby'     => $orderby,
                    'order'       => $order,
                    'paged'       => '%#%',
                ],
                admin_url( 'edit.php' )
            );

            echo '<div class="tablenav" style="margin-top:20px;"><div class="tablenav-pages">';
            echo wp_kses_post(
                paginate_links(
                    [
                        'base'      => $pagination_base,
                        'format'    => '',
                        'current'   => $paged,
                        'total'     => $total_pages,
                        'prev_text' => '&laquo;',
                        'next_text' => '&raquo;',
                    ]
                )
            );
            echo '</div></div>';
        }

        echo '</div>';
    }

    private function render_team_detail_page( int $team_id, int $campaign_id ): void {
        $team_repo = new Team_Repo();
        $team      = $team_repo->find( $team_id );

        echo '<div class="wrap">';
        echo '<h1 class="wp-heading-inline">Gestione squadra</h1>';
        echo '<hr class="wp-header-end">';

        $back_url = add_query_arg(
            [
                'post_type'   => 'aw_campaign',
                'page'        => 'aw-teams',
                'campaign_id' => $campaign_id,
            ],
            admin_url( 'edit.php' )
        );
        echo '<p><a class="button" href="' . esc_url( $back_url ) . '">&larr; Torna alle squadre</a></p>';

        if ( ! $team ) {
            echo '<div class="notice notice-error"><p>Squadra non trovata.</p></div>';
            echo '</div>';
            return;
        }

        if ( $campaign_id > 0 && (int) $team->campaign_id !== $campaign_id ) {
            echo '<div class="notice notice-error"><p>La squadra non appartiene alla campagna selezionata.</p></div>';
            echo '</div>';
            return;
        }

        if ( isset( $_GET['team_updated'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>Dati squadra aggiornati.</p></div>';
        }
        if ( isset( $_GET['team_status_updated'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>Stato squadra aggiornato.</p></div>';
        }
        if ( isset( $_GET['members_moved'] ) ) {
            $moved_count = isset( $_GET['moved_count'] ) ? (int) $_GET['moved_count'] : 0;
            if ( $moved_count > 0 ) {
                echo '<div class="notice notice-success is-dismissible"><p>Composizione squadra aggiornata. Componenti modificati: ' . esc_html( (string) $moved_count ) . '.</p></div>';
            } else {
                echo '<div class="notice notice-warning is-dismissible"><p>Nessun componente modificato.</p></div>';
            }
        }
        if ( isset( $_GET['members_added'] ) ) {
            $added_count = isset( $_GET['added_count'] ) ? (int) $_GET['added_count'] : 0;
            if ( $added_count > 0 ) {
                echo '<div class="notice notice-success is-dismissible"><p>Nuovi componenti aggiunti: ' . esc_html( (string) $added_count ) . '.</p></div>';
            } else {
                echo '<div class="notice notice-warning is-dismissible"><p>Nessun componente aggiunto.</p></div>';
            }
        }
        if ( isset( $_GET['team_error'] ) ) {
            $error_key = sanitize_key( (string) $_GET['team_error'] );
            $messages  = [
                'locked'              => 'La squadra e bloccata. Sblocca lo stato per modificare i componenti o i dati.',
                'invalid_bulk_action' => 'Azione composizione non valida.',
                'target_required'     => 'Seleziona una squadra destinazione per lo spostamento.',
                'no_selection'        => 'Seleziona almeno un componente.',
            ];
            $message = $messages[ $error_key ] ?? 'Operazione non completata.';
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
        }

        $participants_repo = new Participant_Repo();
        $members           = $participants_repo->get_by_team( $team_id );
        $unassigned        = $participants_repo->get_by_campaign( (int) $team->campaign_id, [ 'team_id' => 0, 'per_page' => 500, 'page' => 1 ] );
        $all_teams         = $team_repo->get_by_campaign( (int) $team->campaign_id );
        $team_log          = $this->get_team_log( $team_id );
        $status            = (string) ( $team->status ?? 'draft' );
        $is_locked         = $status === 'locked';
        if ( $is_locked ) {
            echo '<div class="notice notice-warning"><p>Squadra bloccata: composizione e dettagli non modificabili. Puoi solo cambiare lo stato.</p></div>';
        }

        echo '<div style="display:grid;grid-template-columns:2fr 1fr;gap:16px;align-items:start;">';
        echo '<div style="background:#fff;border:1px solid #dcdcde;padding:14px;">';
        echo '<h2 style="margin-top:0;">Riepilogo squadra</h2>';
        echo '<table class="widefat striped" style="margin:0;"><tbody>';
        echo '<tr><th style="width:220px;">ID</th><td>' . esc_html( (string) $team->id ) . '</td></tr>';
        echo '<tr><th>Nome</th><td><strong>' . esc_html( (string) $team->name ) . '</strong></td></tr>';
        echo '<tr><th>Colore</th><td>' . esc_html( (string) ( $team->color ?: '-' ) ) . '</td></tr>';
        echo '<tr><th>Stato</th><td>' . $this->render_team_status_badge( $status ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo '<tr><th>Capacita</th><td>' . esc_html( (string) $team->capacity ) . '</td></tr>';
        echo '<tr><th>Creata</th><td>' . esc_html( date_i18n( 'd/m/Y H:i', strtotime( (string) $team->created_at ) ) ) . '</td></tr>';
        echo '<tr><th>Aggiornata</th><td>' . esc_html( ! empty( $team->updated_at ) ? date_i18n( 'd/m/Y H:i', strtotime( (string) $team->updated_at ) ) : '-' ) . '</td></tr>';
        echo '</tbody></table>';

        echo '<hr><h3 style="margin:12px 0 8px;">Modifica dati squadra</h3>';
        if ( $is_locked ) {
            echo '<p><em>Modifica dettagli non disponibile in stato bloccata.</em></p>';
        } else {
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
            echo '<input type="hidden" name="action" value="aw_update_team_details">';
            echo '<input type="hidden" name="_wpnonce" value="' . esc_attr( wp_create_nonce( 'aw_update_team_details' ) ) . '">';
            echo '<input type="hidden" name="team_id" value="' . esc_attr( (string) $team->id ) . '">';
            echo '<input type="hidden" name="campaign_id" value="' . esc_attr( (string) $team->campaign_id ) . '">';
            echo '<label style="display:block;margin-bottom:6px;"><strong>Nome</strong></label>';
            echo '<input type="text" name="name" value="' . esc_attr( (string) $team->name ) . '" style="width:100%;margin-bottom:8px;" required>';
            echo '<label style="display:block;margin-bottom:6px;"><strong>Colore</strong></label>';
            echo '<input type="text" name="color" value="' . esc_attr( (string) $team->color ) . '" style="width:100%;margin-bottom:8px;">';
            echo '<label style="display:block;margin-bottom:6px;"><strong>Capacita</strong></label>';
            echo '<input type="number" min="2" max="20" name="capacity" value="' . esc_attr( (string) $team->capacity ) . '" style="width:120px;margin-bottom:8px;">';
            echo '<label style="display:block;margin-bottom:6px;"><strong>Nota revisione</strong></label>';
            echo '<textarea name="review_note" rows="3" style="width:100%;">' . esc_textarea( (string) ( $team->review_note ?? '' ) ) . '</textarea>';
            echo '<p><button type="submit" class="button button-primary">Salva squadra</button></p>';
            echo '</form>';
        }
        echo '</div>';

        echo '<div style="background:#fff;border:1px solid #dcdcde;padding:14px;">';
        echo '<h2 style="margin-top:0;">Workflow</h2>';
        $available = $this->get_team_available_transitions( $status );
        if ( empty( $available ) ) {
            echo '<p>Nessuna transizione disponibile.</p>';
        } else {
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
            echo '<input type="hidden" name="action" value="aw_change_team_status">';
            echo '<input type="hidden" name="_wpnonce" value="' . esc_attr( wp_create_nonce( 'aw_change_team_status' ) ) . '">';
            echo '<input type="hidden" name="team_id" value="' . esc_attr( (string) $team->id ) . '">';
            echo '<input type="hidden" name="campaign_id" value="' . esc_attr( (string) $team->campaign_id ) . '">';
            echo '<label style="display:block;margin-bottom:6px;"><strong>Cambia stato in</strong></label>';
            echo '<select name="new_status" style="width:100%;margin-bottom:8px;">';
            foreach ( $available as $to ) {
                echo '<option value="' . esc_attr( $to ) . '">' . esc_html( $this->get_team_status_label( $to ) ) . '</option>';
            }
            echo '</select>';
            echo '<label style="display:block;margin-bottom:6px;"><strong>Nota (opzionale)</strong></label>';
            echo '<textarea name="note" rows="3" style="width:100%;"></textarea>';
            echo '<p><button type="submit" class="button button-primary">Applica</button></p>';
            echo '</form>';
        }
        echo '</div></div>';

        echo '<div style="margin-top:16px;background:#fff;border:1px solid #dcdcde;padding:14px;">';
        echo '<h2 style="margin-top:0;">Componenti squadra</h2>';
        if ( empty( $members ) ) {
            echo '<p>Nessun componente assegnato.</p>';
        } else {
            echo '<table class="wp-list-table widefat striped">';
            echo '<thead><tr><th>Nome</th><th>Contatti</th><th>Fascia eta</th><th>Conviviale</th></tr></thead><tbody>';
            foreach ( $members as $m ) {
                $name = trim( (string) $m->first_name . ' ' . (string) $m->last_name );
                echo '<tr><td>' . esc_html( $name ) . '</td><td>' . esc_html( (string) $m->email ) . '<br><small>' . esc_html( (string) $m->phone ) . '</small></td><td><strong>' . esc_html( $this->get_age_band_label( (string) $m->age_band ) ) . '</strong></td><td>' . ( (int) $m->attends_social ? 'Si' : 'No' ) . '</td></tr>';
            }
            echo '</tbody></table>';
        }

        echo '<div style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap;">';
        echo '<button type="button" class="button" onclick="awToggleTeamPanel(\'move\')" ' . ( $is_locked || empty( $members ) ? 'disabled' : '' ) . '>Sposta componenti</button>';
        echo '<button type="button" class="button" onclick="awToggleTeamPanel(\'remove\')" ' . ( $is_locked || empty( $members ) ? 'disabled' : '' ) . '>Rimuovi componenti</button>';
        echo '<button type="button" class="button button-primary" onclick="awToggleTeamPanel(\'add\')" ' . ( $is_locked || empty( $unassigned ) ? 'disabled' : '' ) . '>Aggiungi componenti</button>';
        echo '</div>';

        if ( $is_locked ) {
            echo '<p style="margin-top:10px;"><em>Squadra bloccata: composizione non modificabile.</em></p>';
        }

        echo '<div id="aw-team-panel-move" style="display:none;margin-top:12px;border:1px solid #dcdcde;padding:10px;background:#f9f9f9;">';
        echo '<h3 style="margin-top:0;">Sposta componenti</h3>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return awValidateTeamAction(this, \'move\');">';
        echo '<input type="hidden" name="action" value="aw_team_bulk_move">';
        echo '<input type="hidden" name="_wpnonce" value="' . esc_attr( wp_create_nonce( 'aw_team_bulk_move' ) ) . '">';
        echo '<input type="hidden" name="team_id" value="' . esc_attr( (string) $team->id ) . '">';
        echo '<input type="hidden" name="campaign_id" value="' . esc_attr( (string) $team->campaign_id ) . '">';
        echo '<input type="hidden" name="bulk_action" value="move">';
        echo '<p><label><strong>Squadra destinazione</strong><br><select name="target_team_id" required><option value="">-- seleziona --</option>';
        foreach ( $all_teams as $t ) {
            if ( (int) $t->id === (int) $team->id ) {
                continue;
            }
            echo '<option value="' . esc_attr( (string) $t->id ) . '">' . esc_html( (string) $t->name ) . '</option>';
        }
        echo '</select></label></p>';
        echo '<div style="border:1px solid #dcdcde;padding:8px;max-height:220px;overflow:auto;">';
        foreach ( $members as $m ) {
            $name = trim( (string) $m->first_name . ' ' . (string) $m->last_name );
            echo '<label style="display:block;margin:3px 0;"><input type="checkbox" name="participant_ids[]" value="' . esc_attr( (string) $m->id ) . '"> ' . esc_html( $name ) . ' - Fascia ' . esc_html( $this->get_age_band_label( (string) $m->age_band ) ) . '</label>';
        }
        echo '</div>';
        echo '<p><button type="submit" class="button button-primary">Conferma spostamento</button></p>';
        echo '</form>';
        echo '</div>';

        echo '<div id="aw-team-panel-remove" style="display:none;margin-top:12px;border:1px solid #dcdcde;padding:10px;background:#f9f9f9;">';
        echo '<h3 style="margin-top:0;">Rimuovi componenti</h3>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return awValidateTeamAction(this, \'remove\');">';
        echo '<input type="hidden" name="action" value="aw_team_bulk_move">';
        echo '<input type="hidden" name="_wpnonce" value="' . esc_attr( wp_create_nonce( 'aw_team_bulk_move' ) ) . '">';
        echo '<input type="hidden" name="team_id" value="' . esc_attr( (string) $team->id ) . '">';
        echo '<input type="hidden" name="campaign_id" value="' . esc_attr( (string) $team->campaign_id ) . '">';
        echo '<input type="hidden" name="bulk_action" value="remove">';
        echo '<div style="border:1px solid #dcdcde;padding:8px;max-height:220px;overflow:auto;">';
        foreach ( $members as $m ) {
            $name = trim( (string) $m->first_name . ' ' . (string) $m->last_name );
            echo '<label style="display:block;margin:3px 0;"><input type="checkbox" name="participant_ids[]" value="' . esc_attr( (string) $m->id ) . '"> ' . esc_html( $name ) . ' - Fascia ' . esc_html( $this->get_age_band_label( (string) $m->age_band ) ) . '</label>';
        }
        echo '</div>';
        echo '<p><button type="submit" class="button">Conferma rimozione</button></p>';
        echo '</form>';
        echo '</div>';

        echo '<div id="aw-team-panel-add" style="display:none;margin-top:12px;border:1px solid #dcdcde;padding:10px;background:#f9f9f9;">';
        echo '<h3 style="margin-top:0;">Aggiungi componenti</h3>';
        if ( empty( $unassigned ) ) {
            echo '<p>Nessun partecipante senza squadra disponibile.</p>';
        } else {
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return awValidateTeamAction(this, \'add\');">';
            echo '<input type="hidden" name="action" value="aw_team_add_members">';
            echo '<input type="hidden" name="_wpnonce" value="' . esc_attr( wp_create_nonce( 'aw_team_add_members' ) ) . '">';
            echo '<input type="hidden" name="team_id" value="' . esc_attr( (string) $team->id ) . '">';
            echo '<input type="hidden" name="campaign_id" value="' . esc_attr( (string) $team->campaign_id ) . '">';
            echo '<div style="border:1px solid #dcdcde;padding:8px;max-height:220px;overflow:auto;">';
            foreach ( $unassigned as $u ) {
                $name = trim( (string) $u->first_name . ' ' . (string) $u->last_name );
                echo '<label style="display:block;margin:3px 0;"><input type="checkbox" name="participant_ids[]" value="' . esc_attr( (string) $u->id ) . '"> ' . esc_html( $name ) . ' - Fascia ' . esc_html( $this->get_age_band_label( (string) $u->age_band ) ) . '</label>';
            }
            echo '</div>';
            echo '<p><button type="submit" class="button button-primary">Conferma aggiunta</button></p>';
            echo '</form>';
        }
        echo '</div>';

        echo '<script>
            function awToggleTeamPanel(mode) {
                var panels = [\'move\', \'remove\', \'add\'];
                for (var i = 0; i < panels.length; i++) {
                    var el = document.getElementById(\'aw-team-panel-\' + panels[i]);
                    if (!el) continue;
                    el.style.display = (panels[i] === mode && el.style.display !== \'block\') ? \'block\' : \'none\';
                }
            }
            function awValidateTeamAction(form, mode) {
                var checked = form.querySelectorAll(\'input[name="participant_ids[]"]:checked\').length;
                if (checked === 0) {
                    alert("Seleziona almeno un componente.");
                    return false;
                }
                if (mode === "move") {
                    var target = form.querySelector(\'select[name="target_team_id"]\');
                    if (!target || !target.value) {
                        alert("Seleziona una squadra destinazione.");
                        return false;
                    }
                    return confirm("Confermi lo spostamento dei componenti selezionati?");
                }
                if (mode === "remove") {
                    return confirm("Confermi la rimozione dei componenti selezionati dalla squadra?");
                }
                return confirm("Confermi l\'aggiunta dei componenti selezionati?");
            }
        </script>';
        echo '</div>';

        echo '<div style="margin-top:16px;background:#fff;border:1px solid #dcdcde;padding:14px;">';
        echo '<h2 style="margin-top:0;">Timeline squadra</h2>';
        if ( empty( $team_log ) ) {
            echo '<p>Nessun evento registrato.</p>';
        } else {
            echo '<table class="wp-list-table widefat striped">';
            echo '<thead><tr><th style="width:180px;">Data/Ora</th><th>Transizione</th><th>Nota</th><th>Attore</th></tr></thead><tbody>';
            foreach ( $team_log as $log ) {
                echo '<tr><td>' . esc_html( date_i18n( 'd/m/Y H:i', strtotime( (string) $log->created_at ) ) ) . '</td><td><code>' . esc_html( (string) ( $log->from_status ?: '-' ) ) . ' -> ' . esc_html( (string) ( $log->to_status ?: '-' ) ) . '</code></td><td>' . esc_html( (string) $log->note ) . '</td><td>' . esc_html( (string) $log->triggered_by ) . '</td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div>';
        echo '</div>';
    }

    public function render_settings_page(): void {
        $campaigns = $this->get_campaigns( true );
        $campaign  = $this->resolve_campaign( $campaigns );

        echo '<div class="wrap">';
        echo '<h1 class="wp-heading-inline">Impostazioni</h1>';
        echo '<hr class="wp-header-end">';

        if ( isset( $_GET['seeded'] ) ) {
            $created = isset( $_GET['created'] ) ? (int) $_GET['created'] : 0;
            $errors  = isset( $_GET['errors'] ) ? (int) $_GET['errors'] : 0;
            echo '<div class="notice notice-success is-dismissible"><p>Dati demo generati. Iscrizioni create: ' . esc_html( (string) $created ) . '. Errori: ' . esc_html( (string) $errors ) . '.</p></div>';
        }
        if ( isset( $_GET['cleaned'] ) ) {
            $deleted = isset( $_GET['deleted'] ) ? (int) $_GET['deleted'] : 0;
            echo '<div class="notice notice-success is-dismissible"><p>Pulizia completata. Iscrizioni demo eliminate: ' . esc_html( (string) $deleted ) . '.</p></div>';
        }

        if ( ! $campaign ) {
            echo '<div class="notice notice-warning"><p>Nessuna campagna disponibile. <a href="' . esc_url( admin_url( 'post-new.php?post_type=aw_campaign' ) ) . '">Crea la tua prima campagna</a>.</p></div>';
            echo '</div>';
            return;
        }

        echo '<div style="background:#fff;border:1px solid #dcdcde;padding:16px;max-width:760px;">';
        echo '<h2 style="margin-top:0;">Dati di esempio</h2>';
        echo '<p>Genera un set base di iscrizioni demo (team, group, individual, social) per testare il backend.</p>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:flex;gap:10px;align-items:end;flex-wrap:wrap;">';
        echo '<input type="hidden" name="action" value="aw_seed_demo_data">';
        echo '<input type="hidden" name="_wpnonce" value="' . esc_attr( wp_create_nonce( 'aw_seed_demo_data' ) ) . '">';
        echo '<input type="hidden" name="campaign_id" value="' . esc_attr( (string) $campaign->ID ) . '">';
        echo '<button type="submit" class="button button-primary">Genera dati di esempio</button>';
        echo '</form>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:10px;display:flex;gap:10px;align-items:end;flex-wrap:wrap;" onsubmit="return confirm(\'Eliminare i dati demo della campagna corrente?\');">';
        echo '<input type="hidden" name="action" value="aw_cleanup_demo_data">';
        echo '<input type="hidden" name="_wpnonce" value="' . esc_attr( wp_create_nonce( 'aw_cleanup_demo_data' ) ) . '">';
        echo '<input type="hidden" name="campaign_id" value="' . esc_attr( (string) $campaign->ID ) . '">';
        echo '<button type="submit" class="button">Pulisci dati demo</button>';
        echo '</form>';
        echo '</div>';

        echo '</div>';
    }

    /**
     * Gestisce il cambio stato iscrizione.
     */
    public function handle_change_status(): void {
        check_admin_referer( 'aw_change_status' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_die( 'Permessi insufficienti.' );
        }

        $registration_id = isset( $_POST['registration_id'] ) ? (int) $_POST['registration_id'] : 0;
        $new_status      = isset( $_POST['new_status'] ) ? sanitize_key( $_POST['new_status'] ) : '';
        $note            = isset( $_POST['note'] ) ? sanitize_textarea_field( $_POST['note'] ) : '';
        $campaign_id     = isset( $_POST['campaign_id'] ) ? (int) $_POST['campaign_id'] : 0;
        $return_registration_id = isset( $_POST['return_registration_id'] ) ? (int) $_POST['return_registration_id'] : 0;

        if ( ! $registration_id || ! $new_status ) {
            wp_die( 'Parametri non validi.' );
        }

        $workflow = new Workflow();
        $result   = $workflow->transition( $registration_id, $new_status, $note, 'admin' );

        if ( ! $result['success'] ) {
            wp_die( 'Errore: ' . ( $result['error'] ?? 'Transizione non riuscita.' ) );
        }

        if ( ! empty( $result['actions'] ) ) {
            $repo         = new Registration_Repo();
            $registration = $repo->find( $registration_id );

            if ( $registration ) {
                $template_id = get_post_meta( $registration->campaign_id, '_aw_template_id', true ) ?: 'alientu-26';

                foreach ( $result['actions'] as $action ) {
                    if ( $action['type'] === 'send_email' ) {
                        try {
                            $email_manager = new Email_Manager();
                            $email_manager->send( $registration_id, $template_id, $action['template'] );
                        } catch ( \Throwable $e ) {
                            error_log( '[Alientu Engine] Email fallita: ' . $e->getMessage() );
                        }
                    }
                }
            }
        }

        $redirect_args = [
            'post_type' => 'aw_campaign',
            'page'      => 'aw-registrations',
            'updated'   => '1',
        ];
        if ( $campaign_id > 0 ) {
            $redirect_args['campaign_id'] = $campaign_id;
        }
        if ( $return_registration_id > 0 ) {
            $redirect_args['registration_id'] = $return_registration_id;
        }

        wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'edit.php' ) ) );
        exit;
    }

    /**
     * Aggiunge una nota operativa (warning/info/pagamento) al log iscrizione.
     */
    public function handle_add_registration_warning(): void {
        check_admin_referer( 'aw_add_registration_warning' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_die( 'Permessi insufficienti.' );
        }

        $registration_id = isset( $_POST['registration_id'] ) ? (int) $_POST['registration_id'] : 0;
        $campaign_id     = isset( $_POST['campaign_id'] ) ? (int) $_POST['campaign_id'] : 0;
        $note_type       = isset( $_POST['note_type'] ) ? sanitize_key( (string) $_POST['note_type'] ) : 'warning';
        $note_text       = isset( $_POST['note_text'] ) ? sanitize_textarea_field( (string) $_POST['note_text'] ) : '';

        if ( $registration_id <= 0 || $note_text === '' ) {
            wp_die( 'Dati non validi.' );
        }

        $repo         = new Registration_Repo();
        $registration = $repo->find( $registration_id );
        if ( ! $registration ) {
            wp_die( 'Iscrizione non trovata.' );
        }

        $prefix = match ( $note_type ) {
            'info'    => '[INFO]',
            'payment' => '[PAYMENT]',
            default => '-',
        };
        $note = $prefix . ' ' . $note_text;

        global $wpdb;
        $wpdb->insert(
            "{$wpdb->prefix}aw_registration_log",
            [
                'registration_id' => $registration_id,
                'from_status'     => (string) $registration->status,
                'to_status'       => (string) $registration->status,
                'note'            => $note,
                'triggered_by'    => 'admin_note',
                'created_at'      => current_time( 'mysql', true ),
            ],
            [ '%d', '%s', '%s', '%s', '%s', '%s' ]
        );

        if ( $campaign_id <= 0 ) {
            $campaign_id = (int) $registration->campaign_id;
        }

        wp_safe_redirect(
            add_query_arg(
                [
                    'post_type'       => 'aw_campaign',
                    'page'            => 'aw-registrations',
                    'campaign_id'     => $campaign_id,
                    'registration_id' => $registration_id,
                    'warning_added'   => 1,
                ],
                admin_url( 'edit.php' )
            )
        );
        exit;
    }

    /**
     * Aggiorna i campi principali dell'iscrizione (e squadra collegata, se presente).
     */
    public function handle_update_registration_details(): void {
        check_admin_referer( 'aw_update_registration_details' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_die( 'Permessi insufficienti.' );
        }

        $registration_id = isset( $_POST['registration_id'] ) ? (int) $_POST['registration_id'] : 0;
        $campaign_id     = isset( $_POST['campaign_id'] ) ? (int) $_POST['campaign_id'] : 0;
        $ref_name        = isset( $_POST['referente_name'] ) ? sanitize_text_field( (string) $_POST['referente_name'] ) : '';
        $ref_email       = isset( $_POST['referente_email'] ) ? sanitize_email( (string) $_POST['referente_email'] ) : '';
        $ref_phone       = isset( $_POST['referente_phone'] ) ? sanitize_text_field( (string) $_POST['referente_phone'] ) : '';
        $team_id         = isset( $_POST['team_id'] ) ? (int) $_POST['team_id'] : 0;
        $team_name       = isset( $_POST['team_name'] ) ? sanitize_text_field( (string) $_POST['team_name'] ) : '';
        $team_color      = isset( $_POST['team_color'] ) ? sanitize_text_field( (string) $_POST['team_color'] ) : '';

        if ( $registration_id <= 0 || $ref_name === '' || ! is_email( $ref_email ) ) {
            wp_die( 'Dati non validi.' );
        }

        $repo         = new Registration_Repo();
        $registration = $repo->find( $registration_id );
        if ( ! $registration ) {
            wp_die( 'Iscrizione non trovata.' );
        }

        $repo->update(
            $registration_id,
            [
                'referente_name'  => $ref_name,
                'referente_email' => $ref_email,
                'referente_phone' => $ref_phone,
            ]
        );

        if ( $team_id > 0 ) {
            $team_repo = new Team_Repo();
            $team_repo->update(
                $team_id,
                [
                    'name'  => $team_name !== '' ? $team_name : 'Team ' . (string) $registration->registration_code,
                    'color' => $team_color,
                ]
            );
        }

        $this->append_registration_log_note( $registration_id, (string) $registration->status, '[INFO] Dati iscrizione aggiornati.', 'admin_edit' );
        $this->redirect_to_registration_detail( $campaign_id ?: (int) $registration->campaign_id, $registration_id, 'registration_updated', 1 );
    }

    /**
     * Aggiorna un partecipante collegato all'iscrizione.
     */
    public function handle_update_registration_participant(): void {
        check_admin_referer( 'aw_update_registration_participant' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_die( 'Permessi insufficienti.' );
        }

        $registration_id = isset( $_POST['registration_id'] ) ? (int) $_POST['registration_id'] : 0;
        $campaign_id     = isset( $_POST['campaign_id'] ) ? (int) $_POST['campaign_id'] : 0;
        $participant_id  = isset( $_POST['participant_id'] ) ? (int) $_POST['participant_id'] : 0;
        $first_name      = isset( $_POST['first_name'] ) ? sanitize_text_field( (string) $_POST['first_name'] ) : '';
        $last_name       = isset( $_POST['last_name'] ) ? sanitize_text_field( (string) $_POST['last_name'] ) : '';
        $email           = isset( $_POST['email'] ) ? sanitize_email( (string) $_POST['email'] ) : '';
        $phone           = isset( $_POST['phone'] ) ? sanitize_text_field( (string) $_POST['phone'] ) : '';
        $age_band        = isset( $_POST['age_band'] ) ? sanitize_text_field( (string) $_POST['age_band'] ) : '';
        $attends_social  = isset( $_POST['attends_social'] ) ? (int) $_POST['attends_social'] : 0;

        if ( $registration_id <= 0 || $participant_id <= 0 || $first_name === '' || $last_name === '' ) {
            wp_die( 'Dati non validi.' );
        }

        $part_repo    = new Participant_Repo();
        $participant  = $part_repo->find( $participant_id );
        $reg_repo     = new Registration_Repo();
        $registration = $reg_repo->find( $registration_id );

        if ( ! $participant || ! $registration ) {
            wp_die( 'Record non trovato.' );
        }
        if ( (int) $participant->source_registration_id !== $registration_id ) {
            wp_die( 'Partecipante non associato a questa iscrizione.' );
        }

        $part_repo->update(
            $participant_id,
            [
                'first_name'     => $first_name,
                'last_name'      => $last_name,
                'email'          => $email,
                'phone'          => $phone,
                'age_band'       => $age_band,
                'attends_social' => $attends_social ? 1 : 0,
            ]
        );

        $this->append_registration_log_note(
            $registration_id,
            (string) $registration->status,
            '[INFO] Partecipante aggiornato: ' . trim( $first_name . ' ' . $last_name ),
            'admin_edit'
        );

        $this->redirect_to_registration_detail( $campaign_id ?: (int) $registration->campaign_id, $registration_id, 'participant_updated', 1 );
    }

    /**
     * Crea una nuova squadra e assegna partecipanti dell'iscrizione.
     */
    public function handle_create_registration_team(): void {
        check_admin_referer( 'aw_create_registration_team' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_die( 'Permessi insufficienti.' );
        }

        $registration_id = isset( $_POST['registration_id'] ) ? (int) $_POST['registration_id'] : 0;
        $campaign_id     = isset( $_POST['campaign_id'] ) ? (int) $_POST['campaign_id'] : 0;
        $team_name       = isset( $_POST['team_name'] ) ? sanitize_text_field( (string) $_POST['team_name'] ) : '';
        $team_color      = isset( $_POST['team_color'] ) ? sanitize_text_field( (string) $_POST['team_color'] ) : '';
        $capacity        = isset( $_POST['capacity'] ) ? max( 2, min( 20, (int) $_POST['capacity'] ) ) : 12;
        $assignment_mode = isset( $_POST['assignment_mode'] ) ? sanitize_key( (string) $_POST['assignment_mode'] ) : 'all';
        $selected_ids    = isset( $_POST['participant_ids'] ) && is_array( $_POST['participant_ids'] ) ? array_map( 'intval', $_POST['participant_ids'] ) : [];

        if ( $registration_id <= 0 || $team_name === '' ) {
            wp_die( 'Dati non validi.' );
        }

        $reg_repo      = new Registration_Repo();
        $registration  = $reg_repo->find( $registration_id );
        $part_repo     = new Participant_Repo();
        $participants  = $part_repo->get_by_registration( $registration_id );
        $team_repo     = new Team_Repo();

        if ( ! $registration ) {
            wp_die( 'Iscrizione non trovata.' );
        }
        if ( empty( $participants ) ) {
            wp_die( 'Nessun partecipante da assegnare.' );
        }

        foreach ( $participants as $p ) {
            if ( ! empty( $p->team_id ) ) {
                wp_die( 'Esiste gia una squadra associata a questa iscrizione.' );
            }
        }

        $all_participant_ids = array_map( static fn( $p ) => (int) $p->id, $participants );
        if ( (string) $registration->registration_type === 'team' || $assignment_mode === 'all' ) {
            $assign_ids = $all_participant_ids;
        } else {
            $assign_ids = array_values( array_intersect( $all_participant_ids, $selected_ids ) );
        }

        if ( empty( $assign_ids ) ) {
            wp_die( 'Seleziona almeno un partecipante da assegnare.' );
        }

        $team_id = $team_repo->insert(
            [
                'campaign_id' => (int) $registration->campaign_id,
                'event_id'    => (string) $registration->event_id,
                'name'        => $team_name,
                'color'       => $team_color,
                'capacity'    => $capacity,
                'notes'       => (string) $registration->registration_type === 'team' ? 'Creata da iscrizione team' : 'Squadra temporanea da dettaglio iscrizione',
            ]
        );

        if ( ! $team_id ) {
            wp_die( 'Errore nella creazione della squadra.' );
        }

        foreach ( $assign_ids as $pid ) {
            $part_repo->assign_team( (int) $pid, (int) $team_id );
        }

        $this->append_registration_log_note(
            $registration_id,
            (string) $registration->status,
            '[INFO] Squadra creata: ' . $team_name . ' (ID ' . (int) $team_id . '). Assegnati ' . count( $assign_ids ) . ' partecipanti.',
            'admin_team'
        );

        $this->redirect_to_registration_detail( $campaign_id ?: (int) $registration->campaign_id, $registration_id, 'team_created', 1 );
    }

    public function handle_update_team_details(): void {
        check_admin_referer( 'aw_update_team_details' );
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_die( 'Permessi insufficienti.' );
        }

        $team_id     = isset( $_POST['team_id'] ) ? (int) $_POST['team_id'] : 0;
        $campaign_id = isset( $_POST['campaign_id'] ) ? (int) $_POST['campaign_id'] : 0;
        $name        = isset( $_POST['name'] ) ? sanitize_text_field( (string) $_POST['name'] ) : '';
        $color       = isset( $_POST['color'] ) ? sanitize_text_field( (string) $_POST['color'] ) : '';
        $capacity    = isset( $_POST['capacity'] ) ? max( 2, min( 20, (int) $_POST['capacity'] ) ) : 12;
        $review_note = isset( $_POST['review_note'] ) ? sanitize_textarea_field( (string) $_POST['review_note'] ) : '';

        if ( $team_id <= 0 || $name === '' ) {
            wp_die( 'Dati non validi.' );
        }

        $team_repo = new Team_Repo();
        $team      = $team_repo->find( $team_id );
        if ( ! $team ) {
            wp_die( 'Squadra non trovata.' );
        }
        if ( (string) ( $team->status ?? 'draft' ) === 'locked' ) {
            $this->redirect_to_team_detail( $campaign_id ?: (int) $team->campaign_id, $team_id, 'team_error', 1, [ 'team_error' => 'locked' ] );
        }

        $team_repo->update(
            $team_id,
            [
                'name'        => $name,
                'color'       => $color,
                'capacity'    => $capacity,
                'review_note' => $review_note,
            ]
        );
        $this->append_team_log( $team_id, (string) ( $team->status ?? 'draft' ), (string) ( $team->status ?? 'draft' ), 'Dettagli squadra aggiornati.', 'admin_team' );
        $this->redirect_to_team_detail( $campaign_id ?: (int) $team->campaign_id, $team_id, 'team_updated', 1 );
    }

    public function handle_change_team_status(): void {
        check_admin_referer( 'aw_change_team_status' );
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_die( 'Permessi insufficienti.' );
        }

        $team_id     = isset( $_POST['team_id'] ) ? (int) $_POST['team_id'] : 0;
        $campaign_id = isset( $_POST['campaign_id'] ) ? (int) $_POST['campaign_id'] : 0;
        $new_status  = isset( $_POST['new_status'] ) ? sanitize_key( (string) $_POST['new_status'] ) : '';
        $note        = isset( $_POST['note'] ) ? sanitize_textarea_field( (string) $_POST['note'] ) : '';

        $team_repo = new Team_Repo();
        $team      = $team_repo->find( $team_id );
        if ( ! $team ) {
            wp_die( 'Squadra non trovata.' );
        }

        $from_status = (string) ( $team->status ?? 'draft' );
        if ( ! in_array( $new_status, $this->get_team_available_transitions( $from_status ), true ) ) {
            wp_die( 'Transizione non consentita.' );
        }

        $team_repo->update( $team_id, [ 'status' => $new_status ] );
        $this->append_team_log( $team_id, $from_status, $new_status, $note !== '' ? $note : 'Stato squadra aggiornato.', 'admin_team' );
        $this->redirect_to_team_detail( $campaign_id ?: (int) $team->campaign_id, $team_id, 'team_status_updated', 1 );
    }

    public function handle_team_bulk_move(): void {
        check_admin_referer( 'aw_team_bulk_move' );
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_die( 'Permessi insufficienti.' );
        }

        $team_id     = isset( $_POST['team_id'] ) ? (int) $_POST['team_id'] : 0;
        $campaign_id = isset( $_POST['campaign_id'] ) ? (int) $_POST['campaign_id'] : 0;
        $action      = isset( $_POST['bulk_action'] ) ? sanitize_key( (string) $_POST['bulk_action'] ) : '';
        $target_id   = isset( $_POST['target_team_id'] ) ? (int) $_POST['target_team_id'] : 0;
        $ids         = isset( $_POST['participant_ids'] ) && is_array( $_POST['participant_ids'] ) ? array_map( 'intval', $_POST['participant_ids'] ) : [];

        if ( $team_id <= 0 || empty( $ids ) ) {
            $this->redirect_to_team_detail( $campaign_id, $team_id, 'team_error', 1, [ 'team_error' => 'no_selection' ] );
        }

        $team_repo = new Team_Repo();
        $team      = $team_repo->find( $team_id );
        if ( ! $team ) {
            wp_die( 'Squadra non trovata.' );
        }
        if ( (string) ( $team->status ?? 'draft' ) === 'locked' ) {
            $this->redirect_to_team_detail( $campaign_id ?: (int) $team->campaign_id, $team_id, 'team_error', 1, [ 'team_error' => 'locked' ] );
        }
        if ( ! in_array( $action, [ 'move', 'remove' ], true ) ) {
            $this->redirect_to_team_detail( $campaign_id ?: (int) $team->campaign_id, $team_id, 'team_error', 1, [ 'team_error' => 'invalid_bulk_action' ] );
        }
        if ( $action === 'move' && $target_id <= 0 ) {
            $this->redirect_to_team_detail( $campaign_id ?: (int) $team->campaign_id, $team_id, 'team_error', 1, [ 'team_error' => 'target_required' ] );
        }

        $part_repo = new Participant_Repo();
        $done      = 0;
        foreach ( $ids as $pid ) {
            $p = $part_repo->find( $pid );
            if ( ! $p || (int) $p->team_id !== $team_id ) {
                continue;
            }
            $ok = false;
            if ( $action === 'remove' ) {
                $ok = $part_repo->assign_team( $pid, null );
            } elseif ( $action === 'move' && $target_id > 0 && $target_id !== $team_id ) {
                $ok = $part_repo->assign_team( $pid, $target_id );
            }
            if ( $ok ) {
                $done++;
            }
        }

        if ( $done > 0 ) {
            $note = $action === 'remove'
                ? sprintf( 'Rimossi %d componenti.', $done )
                : sprintf( 'Spostati %d componenti verso squadra #%d.', $done, $target_id );
            $this->append_team_log( $team_id, (string) ( $team->status ?? 'draft' ), (string) ( $team->status ?? 'draft' ), $note, 'admin_team' );
        }

        $this->redirect_to_team_detail(
            $campaign_id ?: (int) $team->campaign_id,
            $team_id,
            'members_moved',
            1,
            [ 'moved_count' => $done ]
        );
    }

    public function handle_team_add_members(): void {
        check_admin_referer( 'aw_team_add_members' );
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_die( 'Permessi insufficienti.' );
        }

        $team_id     = isset( $_POST['team_id'] ) ? (int) $_POST['team_id'] : 0;
        $campaign_id = isset( $_POST['campaign_id'] ) ? (int) $_POST['campaign_id'] : 0;
        $ids         = isset( $_POST['participant_ids'] ) && is_array( $_POST['participant_ids'] ) ? array_map( 'intval', $_POST['participant_ids'] ) : [];

        if ( $team_id <= 0 || empty( $ids ) ) {
            $this->redirect_to_team_detail( $campaign_id, $team_id, 'team_error', 1, [ 'team_error' => 'no_selection' ] );
        }

        $team_repo = new Team_Repo();
        $team      = $team_repo->find( $team_id );
        if ( ! $team ) {
            wp_die( 'Squadra non trovata.' );
        }
        if ( (string) ( $team->status ?? 'draft' ) === 'locked' ) {
            $this->redirect_to_team_detail( $campaign_id ?: (int) $team->campaign_id, $team_id, 'team_error', 1, [ 'team_error' => 'locked' ] );
        }

        $part_repo = new Participant_Repo();
        $done      = 0;
        foreach ( $ids as $pid ) {
            $p = $part_repo->find( $pid );
            if ( ! $p || (int) $p->campaign_id !== (int) $team->campaign_id || ! empty( $p->team_id ) ) {
                continue;
            }
            if ( $part_repo->assign_team( $pid, $team_id ) ) {
                $done++;
            }
        }

        if ( $done > 0 ) {
            $this->append_team_log( $team_id, (string) ( $team->status ?? 'draft' ), (string) ( $team->status ?? 'draft' ), sprintf( 'Aggiunti %d componenti.', $done ), 'admin_team' );
        }

        $this->redirect_to_team_detail(
            $campaign_id ?: (int) $team->campaign_id,
            $team_id,
            'members_added',
            1,
            [ 'added_count' => $done ]
        );
    }

    /**
     * Renderizza la pagina "Nuova iscrizione" con il form frontend riusato in admin.
     */
    public function render_new_registration_page(): void {
        $campaigns = $this->get_campaigns( true );
        $campaign  = $this->resolve_campaign( $campaigns );

        if ( ! $campaign ) {
            echo '<div class="wrap"><h1>Nuova iscrizione</h1><div class="notice notice-warning"><p>Nessuna campagna pubblicata. <a href="' . esc_url( admin_url( 'post-new.php?post_type=aw_campaign' ) ) . '">Crea una campagna</a> prima di aggiungere iscrizioni manuali.</p></div></div>';
            return;
        }

        $event_id       = get_post_meta( $campaign->ID, '_aw_event_id', true ) ?: 'ALIENTU_2026';
        $causale_prefix = get_post_meta( $campaign->ID, '_aw_causale_prefix', true ) ?: 'ALIENTU26';
        $template_id    = get_post_meta( $campaign->ID, '_aw_template_id', true ) ?: 'alientu-26';

        $config_file = AW_PLUGIN_DIR . "templates/{$template_id}/config.json";
        $config      = file_exists( $config_file ) ? json_decode( (string) file_get_contents( $config_file ) ) : null;

        wp_enqueue_style( 'aw-alientu', AW_PLUGIN_URL . 'assets/css/alientu.css', [], AW_VERSION );
        wp_enqueue_script( 'aw-alientu', AW_PLUGIN_URL . 'assets/js/alientu.js', [], AW_VERSION, true );

        wp_localize_script(
            'aw-alientu',
            'alientuConfig',
            [
                'campaign_id' => $campaign->ID,
                'event_id'    => $event_id,
                'priceGame'   => $config->prices->game ?? 3,
                'priceSocial' => $config->prices->social ?? 5,
                'eventYear'   => $config->event->year ?? '2026',
                'restUrl'     => admin_url( 'admin-post.php' ),
                'nonce'       => wp_create_nonce( 'aw_manual_registration' ),
                'isAdmin'     => true,
            ]
        );

        $form_template = AW_PLUGIN_DIR . "templates/{$template_id}/form-body.html";

        echo '<div class="wrap">';
        echo '<h1>Nuova iscrizione manuale</h1>';
        $this->render_campaign_selector( 'aw-new-registration', (int) $campaign->ID, $campaigns );

        echo '<div class="aw-admin-form-wrapper" style="background:white;padding:30px;border:1px solid #ccc;border-radius:4px;">';
        if ( file_exists( $form_template ) ) {
            include $form_template;
        } else {
            echo '<p>Template form non trovato.</p>';
        }
        echo '</div>';
        ?>
        <script>
        (function() {
            window.submitForm = function() {
                const data = collectFormData();
                data._meta.campaign_id = <?php echo (int) $campaign->ID; ?>;
                showLoader();

                const form = document.createElement('form');
                form.method = 'POST';
                form.action = '<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>';
                form.innerHTML = `
                    <input type="hidden" name="action" value="aw_manual_registration">
                    <input type="hidden" name="payload_b64" value="${btoa(unescape(encodeURIComponent(JSON.stringify(data))))}">
                    <input type="hidden" name="campaign_id" value="<?php echo (int) $campaign->ID; ?>">
                    <input type="hidden" name="event_id" value="<?php echo esc_attr( $event_id ); ?>">
                    <input type="hidden" name="causale_prefix" value="<?php echo esc_attr( $causale_prefix ); ?>">
                    <input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( 'aw_manual_registration' ) ); ?>">
                `;
                document.body.appendChild(form);
                form.submit();
            };
        })();
        </script>
        <?php
        echo '</div>';
    }

    /**
     * Submit iscrizione manuale.
     */
    public function handle_manual_registration(): void {
        check_admin_referer( 'aw_manual_registration' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_die( 'Permessi insufficienti.' );
        }

        $campaign_id    = isset( $_POST['campaign_id'] ) ? (int) $_POST['campaign_id'] : 0;
        $event_id       = isset( $_POST['event_id'] ) ? sanitize_text_field( $_POST['event_id'] ) : '';
        $causale_prefix = isset( $_POST['causale_prefix'] ) ? sanitize_text_field( $_POST['causale_prefix'] ) : '';

        $payload_json = '';
        if ( isset( $_POST['payload_b64'] ) ) {
            $decoded      = base64_decode( wp_unslash( (string) $_POST['payload_b64'] ), true );
            $payload_json = is_string( $decoded ) ? $decoded : '';
        } elseif ( isset( $_POST['payload'] ) ) {
            $payload_json = wp_unslash( (string) $_POST['payload'] );
        }
        $payload = json_decode( $payload_json, true );

        if ( ! $campaign_id || ! $event_id || ! $causale_prefix || ! is_array( $payload ) ) {
            wp_die( 'Dati non validi.' );
        }

        $workflow = new Workflow();
        $result   = $workflow->create_registration( $campaign_id, $event_id, $payload, $causale_prefix );

        if ( ! $result['success'] ) {
            wp_die( 'Errore creazione iscrizione: ' . implode( ', ', $result['errors'] ?? [] ) );
        }

        if ( ! empty( $result['actions'] ) ) {
            $template_id = get_post_meta( $campaign_id, '_aw_template_id', true ) ?: 'alientu-26';

            foreach ( $result['actions'] as $action ) {
                if ( $action['type'] === 'send_email' ) {
                    try {
                        $email_manager = new Email_Manager();
                        $email_manager->send( $result['registration_id'], $template_id, $action['template'] );
                    } catch ( \Throwable $e ) {
                        error_log( '[Alientu Engine] Email fallita (admin): ' . $e->getMessage() );
                    }
                }
            }
        }

        $this->save_selected_campaign( $campaign_id );

        wp_safe_redirect(
            add_query_arg(
                [
                    'post_type'   => 'aw_campaign',
                    'page'        => 'aw-registrations',
                    'campaign_id' => $campaign_id,
                    'created'     => '1',
                    'code'        => $result['registration_code'] ?? '',
                ],
                admin_url( 'edit.php' )
            )
        );
        exit;
    }

    /**
     * Genera dati demo per test backend.
     */
    public function handle_seed_demo_data(): void {
        check_admin_referer( 'aw_seed_demo_data' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_die( 'Permessi insufficienti.' );
        }

        $campaign_id = isset( $_POST['campaign_id'] ) ? (int) $_POST['campaign_id'] : 0;
        $campaign    = get_post( $campaign_id );
        if ( ! $campaign || $campaign->post_type !== 'aw_campaign' ) {
            wp_die( 'Campagna non valida.' );
        }

        $event_id       = get_post_meta( $campaign_id, '_aw_event_id', true ) ?: 'ALIENTU_2026';
        $causale_prefix = get_post_meta( $campaign_id, '_aw_causale_prefix', true ) ?: 'ALIENTU26';
        $workflow       = new Workflow();

        $created = 0;
        $errors  = 0;
        foreach ( $this->build_demo_payloads() as $index => $payload ) {
            $result = $workflow->create_registration( $campaign_id, (string) $event_id, $payload, (string) $causale_prefix );
            if ( ! empty( $result['success'] ) ) {
                $created++;

                $registration_id = (int) ( $result['registration_id'] ?? 0 );
                if ( $registration_id > 0 ) {
                    if ( $index % 4 === 1 ) {
                        $workflow->transition( $registration_id, Workflow::STATUS_NEEDS_REVIEW, 'Seed demo: review', 'system' );
                    } elseif ( $index % 4 === 2 ) {
                        $workflow->transition( $registration_id, Workflow::STATUS_NEEDS_REVIEW, 'Seed demo: review', 'system' );
                        $workflow->transition( $registration_id, Workflow::STATUS_WAITING_PAYMENT, 'Seed demo: waiting payment', 'system' );
                    } elseif ( $index % 4 === 3 ) {
                        $workflow->transition( $registration_id, Workflow::STATUS_NEEDS_REVIEW, 'Seed demo: review', 'system' );
                        $workflow->transition( $registration_id, Workflow::STATUS_WAITING_PAYMENT, 'Seed demo: waiting payment', 'system' );
                        $workflow->transition( $registration_id, Workflow::STATUS_CONFIRMED, 'Seed demo: confirmed', 'system' );
                    }
                }
            } else {
                $errors++;
            }
        }

        $this->save_selected_campaign( $campaign_id );
        wp_safe_redirect(
            add_query_arg(
                [
                    'post_type'   => 'aw_campaign',
                    'page'        => 'aw-settings',
                    'campaign_id' => $campaign_id,
                    'seeded'      => 1,
                    'created'     => $created,
                    'errors'      => $errors,
                ],
                admin_url( 'edit.php' )
            )
        );
        exit;
    }

    /**
     * Rimuove i dati demo dalla campagna corrente.
     */
    public function handle_cleanup_demo_data(): void {
        check_admin_referer( 'aw_cleanup_demo_data' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_die( 'Permessi insufficienti.' );
        }

        $campaign_id = isset( $_POST['campaign_id'] ) ? (int) $_POST['campaign_id'] : 0;
        $campaign    = get_post( $campaign_id );
        if ( ! $campaign || $campaign->post_type !== 'aw_campaign' ) {
            wp_die( 'Campagna non valida.' );
        }

        global $wpdb;
        $r_table = "{$wpdb->prefix}aw_registrations";
        $p_table = "{$wpdb->prefix}aw_participants";
        $l_table = "{$wpdb->prefix}aw_registration_log";
        $t_table = "{$wpdb->prefix}aw_teams";

        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT id FROM {$r_table}
                 WHERE campaign_id = %d
                   AND referente_email LIKE %s",
                $campaign_id,
                '%.demo@example.com'
            )
        );

        $deleted_regs = 0;
        if ( ! empty( $ids ) ) {
            $ids = array_map( 'intval', $ids );
            $in  = implode( ',', $ids );

            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query( "DELETE FROM {$l_table} WHERE registration_id IN ({$in})" );
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query( "DELETE FROM {$p_table} WHERE source_registration_id IN ({$in})" );
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $deleted_regs = (int) $wpdb->query( "DELETE FROM {$r_table} WHERE id IN ({$in})" );
        }

        // Pulizia squadre demo note (se rimaste senza componenti).
        $team_names = [ 'Lupi Blu', 'Falchi Verdi' ];
        foreach ( $team_names as $name ) {
            $team_id = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM {$t_table} WHERE campaign_id = %d AND name = %s LIMIT 1",
                    $campaign_id,
                    $name
                )
            );
            if ( $team_id <= 0 ) {
                continue;
            }

            $count_players = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$p_table} WHERE team_id = %d",
                    $team_id
                )
            );
            if ( $count_players === 0 ) {
                $wpdb->delete( $t_table, [ 'id' => $team_id ], [ '%d' ] );
            }
        }

        $this->save_selected_campaign( $campaign_id );
        wp_safe_redirect(
            add_query_arg(
                [
                    'post_type'   => 'aw_campaign',
                    'page'        => 'aw-settings',
                    'campaign_id' => $campaign_id,
                    'cleaned'     => 1,
                    'deleted'     => $deleted_regs,
                ],
                admin_url( 'edit.php' )
            )
        );
        exit;
    }

    /**
     * Export CSV per liste backend.
     */
    public function handle_export_csv(): void {
        check_admin_referer( 'aw_export_csv' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_die( 'Permessi insufficienti.' );
        }

        $type        = isset( $_GET['type'] ) ? sanitize_key( (string) $_GET['type'] ) : '';
        $campaign_id = isset( $_GET['campaign_id'] ) ? (int) $_GET['campaign_id'] : 0;
        if ( $campaign_id <= 0 ) {
            wp_die( 'Campagna non valida.' );
        }

        $filename = 'alientu_' . $type . '_campaign_' . $campaign_id . '_' . gmdate( 'Ymd_His' ) . '.csv';

        if ( ob_get_length() ) {
            ob_end_clean();
        }

        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=' . $filename );

        $out = fopen( 'php://output', 'w' );
        if ( ! $out ) {
            wp_die( 'Impossibile generare il file CSV.' );
        }

        switch ( $type ) {
            case 'registrations':
                $this->export_registrations_csv( $out, $campaign_id );
                break;
            case 'participants':
                $this->export_participants_csv( $out, $campaign_id );
                break;
            case 'teams':
                $this->export_teams_csv( $out, $campaign_id );
                break;
            default:
                fclose( $out );
                wp_die( 'Tipo export non valido.' );
        }

        fclose( $out );
        exit;
    }

    /**
     * @param resource $out
     */
    private function export_registrations_csv( $out, int $campaign_id ): void {
        $status = isset( $_GET['status'] ) ? sanitize_key( (string) $_GET['status'] ) : '';
        $search = isset( $_GET['s'] ) ? sanitize_text_field( (string) $_GET['s'] ) : '';

        $repo = new Registration_Repo();
        $rows = $repo->get_list(
            [
                'campaign_id' => $campaign_id,
                'status'      => $status ?: null,
                'search'      => $search ?: null,
                'per_page'    => 5000,
                'page'        => 1,
                'orderby'     => 'created_at',
                'order'       => 'DESC',
            ]
        );

        fputcsv( $out, [ 'ID', 'Codice', 'Referente', 'Email', 'Telefono', 'Tipo', 'Stato', 'Totale', 'Creata il' ] );
        foreach ( $rows as $r ) {
            fputcsv(
                $out,
                [
                    (int) $r->id,
                    (string) $r->registration_code,
                    (string) $r->referente_name,
                    (string) $r->referente_email,
                    (string) $r->referente_phone,
                    $this->get_type_label( (string) $r->registration_type ),
                    (string) $r->status,
                    (float) $r->total_final,
                    (string) $r->created_at,
                ]
            );
        }
    }

    /**
     * @param resource $out
     */
    private function export_participants_csv( $out, int $campaign_id ): void {
        $search        = isset( $_GET['s'] ) ? sanitize_text_field( (string) $_GET['s'] ) : '';
        $team_filter   = isset( $_GET['team_id'] ) ? (int) $_GET['team_id'] : -1;
        $social_raw    = isset( $_GET['attends_social'] ) ? sanitize_text_field( (string) $_GET['attends_social'] ) : '';
        $social_filter = in_array( $social_raw, [ '0', '1' ], true ) ? $social_raw : '';

        global $wpdb;
        $p_table = "{$wpdb->prefix}aw_participants";
        $t_table = "{$wpdb->prefix}aw_teams";
        $r_table = "{$wpdb->prefix}aw_registrations";

        $where  = [ 'p.campaign_id = %d' ];
        $params = [ $campaign_id ];

        if ( $team_filter === 0 ) {
            $where[] = 'p.team_id IS NULL';
        } elseif ( $team_filter > 0 ) {
            $where[]  = 'p.team_id = %d';
            $params[] = $team_filter;
        }

        if ( $social_filter !== '' ) {
            $where[]  = 'p.attends_social = %d';
            $params[] = (int) $social_filter;
        }

        if ( $search !== '' ) {
            $like     = '%' . $wpdb->esc_like( $search ) . '%';
            $where[]  = '(p.first_name LIKE %s OR p.last_name LIKE %s OR p.email LIKE %s OR p.phone LIKE %s OR r.registration_code LIKE %s OR t.name LIKE %s)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $where_sql = implode( ' AND ', $where );
        $sql = "SELECT p.*, t.name AS team_name, r.registration_code
                FROM {$p_table} p
                LEFT JOIN {$t_table} t ON t.id = p.team_id
                LEFT JOIN {$r_table} r ON r.id = p.source_registration_id
                WHERE {$where_sql}
                ORDER BY p.last_name ASC, p.first_name ASC";
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ) ) ?: [];

        fputcsv( $out, [ 'ID', 'Nome', 'Cognome', 'Email', 'Telefono', 'Fascia', 'Conviviale', 'Squadra', 'Codice Iscrizione' ] );
        foreach ( $rows as $row ) {
            fputcsv(
                $out,
                [
                    (int) $row->id,
                    (string) $row->first_name,
                    (string) $row->last_name,
                    (string) $row->email,
                    (string) $row->phone,
                    $this->get_age_band_label( (string) $row->age_band ),
                    (int) $row->attends_social === 1 ? 'si' : 'no',
                    (string) ( $row->team_name ?? '' ),
                    (string) ( $row->registration_code ?? '' ),
                ]
            );
        }
    }

    /**
     * @param resource $out
     */
    private function export_teams_csv( $out, int $campaign_id ): void {
        $search = isset( $_GET['s'] ) ? sanitize_text_field( (string) $_GET['s'] ) : '';
        $status = isset( $_GET['status'] ) ? sanitize_key( (string) $_GET['status'] ) : '';

        $repo = new Team_Repo();
        $rows = $repo->get_by_campaign_with_count( $campaign_id );

        if ( $status !== '' ) {
            $rows = array_values(
                array_filter(
                    $rows,
                    static fn( $row ) => (string) ( $row->status ?? 'draft' ) === $status
                )
            );
        }

        if ( $search !== '' ) {
            $needle = function_exists( 'mb_strtolower' ) ? mb_strtolower( $search ) : strtolower( $search );
            $rows = array_values(
                array_filter(
                    $rows,
                    static function ( $row ) use ( $needle ) {
                        $haystack = (string) ( $row->name ?? '' ) . ' ' . (string) ( $row->color ?? '' );
                        $haystack = function_exists( 'mb_strtolower' ) ? mb_strtolower( $haystack ) : strtolower( $haystack );
                        return str_contains( $haystack, $needle );
                    }
                )
            );
        }

        fputcsv( $out, [ 'ID', 'Nome', 'Colore', 'Stato', 'Capacita', 'Giocatori', 'Creata il' ] );
        foreach ( $rows as $row ) {
            fputcsv(
                $out,
                [
                    (int) $row->id,
                    (string) $row->name,
                    (string) ( $row->color ?? '' ),
                    $this->get_team_status_label( (string) ( $row->status ?? 'draft' ) ),
                    (int) $row->capacity,
                    (int) $row->participant_count,
                    (string) $row->created_at,
                ]
            );
        }
    }

    /**
     * Payload demo coerenti con il template alientu-26.
     *
     * @return array<int,array<string,mixed>>
     */
    private function build_demo_payloads(): array {
        return [
            // TEAM
            [
                '_meta' => [ 'form' => 'team' ],
                'referente' => [
                    'first_name' => 'Marco',
                    'last_name' => 'Rossi',
                    'email' => 'marco.rossi.demo@example.com',
                    'phone' => '3331112233',
                    'accepted_rules' => true,
                    'accepted_privacy' => true,
                    'in_team' => true,
                ],
                'team' => [
                    'name' => 'Lupi Blu',
                    'color_pref_1' => 'Blu',
                    'color_custom' => '',
                ],
                'players' => $this->build_demo_players( 'Marco', 'Rossi', 8 ),
                'social' => [
                    'mode' => 'some',
                    'food_notes' => 'Nessuna nota',
                    'referente_social' => false,
                ],
                'transport' => [ 'mode' => 'none' ],
                'quotes' => [ 'donation' => 0 ],
            ],
            [
                '_meta' => [ 'form' => 'team' ],
                'referente' => [
                    'first_name' => 'Giulia',
                    'last_name' => 'Bianchi',
                    'email' => 'giulia.bianchi.demo@example.com',
                    'phone' => '3331112244',
                    'accepted_rules' => true,
                    'accepted_privacy' => true,
                    'in_team' => false,
                ],
                'team' => [
                    'name' => 'Falchi Verdi',
                    'color_pref_1' => 'Verde',
                    'color_custom' => '',
                ],
                'players' => $this->build_demo_players( 'Luca', 'Verdi', 8 ),
                'social' => [
                    'mode' => 'all',
                    'food_notes' => '1 vegetariano',
                    'referente_social' => true,
                ],
                'transport' => [ 'mode' => 'seek', 'location' => 'Torino', 'seats_needed' => 2 ],
                'quotes' => [ 'donation' => 5 ],
            ],

            // GROUP
            [
                '_meta' => [ 'form' => 'group' ],
                'referente' => [
                    'first_name' => 'Paolo',
                    'last_name' => 'Neri',
                    'email' => 'paolo.neri.demo@example.com',
                    'phone' => '3331112255',
                    'accepted_rules' => true,
                    'accepted_privacy' => true,
                ],
                'players' => $this->build_demo_players( 'Paolo', 'Neri', 3 ),
                'social' => [ 'mode' => 'some', 'food_notes' => '' ],
                'transport' => [ 'mode' => 'offer', 'location' => 'Milano', 'seats_offered' => 3 ],
                'profile' => [ 'note' => 'Gruppo amici, prima esperienza', 'team_pref' => 'Possibile gruppo con over 18' ],
                'quotes' => [ 'donation' => 10 ],
            ],
            [
                '_meta' => [ 'form' => 'group' ],
                'referente' => [
                    'first_name' => 'Sara',
                    'last_name' => 'Gallo',
                    'email' => 'sara.gallo.demo@example.com',
                    'phone' => '3331112266',
                    'accepted_rules' => true,
                    'accepted_privacy' => true,
                ],
                'players' => $this->build_demo_players( 'Sara', 'Gallo', 5 ),
                'social' => [ 'mode' => 'none', 'food_notes' => '' ],
                'transport' => [ 'mode' => 'none' ],
                'profile' => [ 'note' => 'Gruppo misto per test', 'team_pref' => '' ],
                'quotes' => [ 'donation' => 0 ],
            ],

            // INDIVIDUAL
            [
                '_meta' => [ 'form' => 'individual' ],
                'referente' => [
                    'first_name' => 'Elena',
                    'last_name' => 'Fontana',
                    'email' => 'elena.fontana.demo@example.com',
                    'phone' => '3331112277',
                    'accepted_rules' => true,
                    'accepted_privacy' => true,
                    'fascia' => 'B',
                ],
                'social' => [ 'mode' => 'yes_b', 'food_notes' => 'No lattosio' ],
                'transport' => [ 'mode' => 'seek', 'location' => 'Bergamo', 'seats_needed' => 1 ],
                'profile' => [ 'is_scout' => 'si', 'is_sport' => 'no', 'note' => 'Disponibile come jolly', 'team_pref' => '' ],
                'quotes' => [ 'donation' => 5 ],
            ],
            [
                '_meta' => [ 'form' => 'individual' ],
                'referente' => [
                    'first_name' => 'Marta',
                    'last_name' => 'Conti',
                    'email' => 'marta.conti.demo@example.com',
                    'phone' => '3331112288',
                    'accepted_rules' => true,
                    'accepted_privacy' => true,
                    'fascia' => 'C',
                ],
                'social' => [ 'mode' => 'none', 'food_notes' => '' ],
                'transport' => [ 'mode' => 'none' ],
                'profile' => [ 'is_scout' => 'no', 'is_sport' => 'si', 'note' => '', 'team_pref' => 'Team con fascia C/D' ],
                'quotes' => [ 'donation' => 0 ],
            ],

            // SOCIAL
            [
                '_meta' => [ 'form' => 'social' ],
                'referente' => [
                    'first_name' => 'Andrea',
                    'last_name' => 'Bruni',
                    'email' => 'andrea.bruni.demo@example.com',
                    'phone' => '3331112299',
                    'accepted_rules' => true,
                    'accepted_privacy' => true,
                ],
                'social_participants' => [
                    [ 'first_name' => 'Andrea', 'last_name' => 'Bruni', 'intolleranze' => '' ],
                    [ 'first_name' => 'Laura', 'last_name' => 'Bruni', 'intolleranze' => 'No glutine' ],
                    [ 'first_name' => 'Luca', 'last_name' => 'Bruni', 'intolleranze' => '' ],
                ],
                'quotes' => [ 'donation' => 10 ],
            ],
            [
                '_meta' => [ 'form' => 'social' ],
                'referente' => [
                    'first_name' => 'Chiara',
                    'last_name' => 'Ferri',
                    'email' => 'chiara.ferri.demo@example.com',
                    'phone' => '3331112200',
                    'accepted_rules' => true,
                    'accepted_privacy' => true,
                ],
                'social_participants' => [
                    [ 'first_name' => 'Chiara', 'last_name' => 'Ferri', 'intolleranze' => '' ],
                ],
                'quotes' => [ 'donation' => 0 ],
            ],
        ];
    }

    /**
     * Crea giocatori demo con fasce distribuite.
     *
     * @return array<int,array<string,mixed>>
     */
    private function build_demo_players( string $first_seed, string $last_seed, int $count ): array {
        $bands   = [ 'A', 'B', 'C', 'D' ];
        $players = [];
        for ( $i = 1; $i <= $count; $i++ ) {
            $players[] = [
                'first_name' => $i === 1 ? $first_seed : "{$first_seed}{$i}",
                'last_name'  => $last_seed,
                'age_band'   => $bands[ ( $i - 1 ) % count( $bands ) ],
                'email'      => '',
                'phone'      => '',
                'social'     => $i % 2 === 0,
            ];
        }

        return $players;
    }

    /**
     * Card KPI minimale.
     */
    private function render_kpi_card( string $title, string $value, string $meta ): void {
        echo '<div style="background:#fff;border:1px solid #dcdcde;padding:14px;border-radius:4px;">';
        echo '<div style="font-size:12px;text-transform:uppercase;color:#50575e;letter-spacing:.02em;">' . esc_html( $title ) . '</div>';
        echo '<div style="font-size:28px;line-height:1.2;font-weight:700;margin:6px 0 4px;">' . esc_html( $value ) . '</div>';
        echo '<div style="font-size:12px;color:#646970;">' . esc_html( $meta ) . '</div>';
        echo '</div>';
    }

    /**
     * Pagina base con selettore campagna.
     */
    private function render_basic_page( string $title, string $page_slug, string $message ): void {
        $campaigns = $this->get_campaigns( true );
        $campaign  = $this->resolve_campaign( $campaigns );

        echo '<div class="wrap">';
        echo '<h1 class="wp-heading-inline">' . esc_html( $title ) . '</h1>';
        echo '<hr class="wp-header-end">';

        if ( ! $campaign ) {
            echo '<div class="notice notice-warning"><p>Nessuna campagna disponibile. <a href="' . esc_url( admin_url( 'post-new.php?post_type=aw_campaign' ) ) . '">Crea la tua prima campagna</a>.</p></div>';
            echo '</div>';
            return;
        }

        $this->render_campaign_selector( $page_slug, (int) $campaign->ID, $campaigns );
        echo '<div class="notice notice-info" style="margin-top:16px;"><p>' . esc_html( $message ) . '</p></div>';
        echo '</div>';
    }

    /**
     * Notifiche successo in lista iscrizioni.
     */
    private function render_feedback_notices(): void {
        if ( isset( $_GET['created'] ) && '1' === (string) $_GET['created'] ) {
            $code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['code'] ) ) : '';
            $msg  = $code ? 'Iscrizione creata con successo: ' . $code : 'Iscrizione creata con successo.';
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
        }

        if ( isset( $_GET['updated'] ) && '1' === (string) $_GET['updated'] ) {
            echo '<div class="notice notice-success is-dismissible"><p>Stato aggiornato con successo.</p></div>';
        }
    }

    /**
     * Selettore campagna condiviso.
     */
    private function render_campaign_selector( string $page_slug, int $selected_campaign_id, array $campaigns, bool $allow_all = false ): void {
        echo '<form method="get" style="margin:16px 0;padding:12px;background:#f6f7f7;border:1px solid #dcdcde;display:inline-flex;gap:8px;align-items:center;">';
        echo '<input type="hidden" name="post_type" value="aw_campaign">';
        echo '<input type="hidden" name="page" value="' . esc_attr( $page_slug ) . '">';
        echo '<label for="aw-campaign-id"><strong>Campagna:</strong></label>';
        echo '<select id="aw-campaign-id" name="campaign_id" onchange="this.form.submit()">';

        if ( $allow_all ) {
            echo '<option value="0"' . selected( $selected_campaign_id, 0, false ) . '>Tutte le campagne</option>';
        }

        foreach ( $campaigns as $c ) {
            echo '<option value="' . esc_attr( $c->ID ) . '"' . selected( $selected_campaign_id, (int) $c->ID, false ) . '>' . esc_html( $c->post_title ) . '</option>';
        }
        echo '</select>';
        echo '</form>';
    }

    /**
     * Badge menu dashboard con count pending.
     */
    private function get_dashboard_badge_html(): string {
        $campaigns = $this->get_campaigns( true );
        $campaign  = $this->resolve_campaign( $campaigns );
        $count     = $campaign ? $this->get_pending_count( (int) $campaign->ID ) : 0;

        if ( $count <= 0 ) {
            return '';
        }

        return '<span class="awaiting-mod count-' . (int) $count . '"><span class="pending-count">' . (int) $count . '</span></span>';
    }

    /**
     * KPI dati dashboard.
     *
     * @return array<string,mixed>
     */
    private function get_dashboard_metrics( int $campaign_id ): array {
        $reg_repo   = new Registration_Repo();
        $team_repo  = new Team_Repo();
        $part_repo  = new Participant_Repo();

        $registrations_total = $reg_repo->count( [ 'campaign_id' => $campaign_id ] );
        $type_counts = [
            'team'       => $reg_repo->count( [ 'campaign_id' => $campaign_id, 'registration_type' => 'team' ] ),
            'individual' => $reg_repo->count( [ 'campaign_id' => $campaign_id, 'registration_type' => 'individual' ] ),
            'group'      => $reg_repo->count( [ 'campaign_id' => $campaign_id, 'registration_type' => 'group' ] ),
            'social'     => $reg_repo->count( [ 'campaign_id' => $campaign_id, 'registration_type' => 'social' ] ),
        ];

        $participants_game_total   = $part_repo->count_by_campaign( $campaign_id );
        $participants_social_total = $part_repo->count_by_campaign( $campaign_id, [ 'attends_social' => true ] );
        $teams_total               = $team_repo->count_by_campaign( $campaign_id );

        global $wpdb;
        $table = "{$wpdb->prefix}aw_registrations";
        $payments_expected = (float) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COALESCE(SUM(total_final),0) FROM {$table} WHERE campaign_id = %d",
                $campaign_id
            )
        );
        $payments_confirmed = (float) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COALESCE(SUM(total_final),0) FROM {$table} WHERE campaign_id = %d AND status = %s",
                $campaign_id,
                'confirmed'
            )
        );

        return [
            'registrations_total'       => (int) $registrations_total,
            'type_counts'               => $type_counts,
            'participants_game_total'   => (int) $participants_game_total,
            'participants_social_total' => (int) $participants_social_total,
            'teams_total'               => (int) $teams_total,
            'payments_expected_fmt'     => 'EUR ' . number_format_i18n( $payments_expected, 2 ),
            'payments_confirmed_fmt'    => 'EUR ' . number_format_i18n( $payments_confirmed, 2 ),
        ];
    }

    /**
     * Campagne disponibili ordinate per data desc.
     *
     * @return \WP_Post[]
     */
    private function get_campaigns( bool $publish_only = true ): array {
        $status = $publish_only ? 'publish' : 'any';
        $campaigns = get_posts(
            [
                'post_type'      => 'aw_campaign',
                'post_status'    => $status,
                'posts_per_page' => -1,
                'orderby'        => 'date',
                'order'          => 'DESC',
            ]
        );

        return is_array( $campaigns ) ? $campaigns : [];
    }

    /**
     * Risolve campagna corrente: GET > saved > piu recente.
     */
    private function resolve_campaign( array $campaigns ): ?\WP_Post {
        if ( empty( $campaigns ) ) {
            return null;
        }

        $ids = array_map( static fn( $c ) => (int) $c->ID, $campaigns );

        if ( isset( $_GET['campaign_id'] ) ) {
            $requested = (int) $_GET['campaign_id'];
            if ( $requested > 0 && in_array( $requested, $ids, true ) ) {
                $this->save_selected_campaign( $requested );
                return get_post( $requested ) ?: null;
            }
        }

        $saved = $this->get_saved_campaign_id();
        if ( $saved > 0 && in_array( $saved, $ids, true ) ) {
            return get_post( $saved ) ?: null;
        }

        $fallback = (int) $campaigns[0]->ID;
        $this->save_selected_campaign( $fallback );
        return $campaigns[0];
    }

    private function save_selected_campaign( int $campaign_id ): void {
        $user_id = get_current_user_id();
        if ( $user_id > 0 && $campaign_id > 0 ) {
            update_user_meta( $user_id, self::CAMPAIGN_META_KEY, $campaign_id );
        }
    }

    private function get_saved_campaign_id(): int {
        $user_id = get_current_user_id();
        if ( $user_id <= 0 ) {
            return 0;
        }

        return (int) get_user_meta( $user_id, self::CAMPAIGN_META_KEY, true );
    }

    private function get_pending_count( int $campaign_id ): int {
        $repo = new Registration_Repo();
        return (int) $repo->count(
            [
                'campaign_id' => $campaign_id,
                'status'      => 'received',
            ]
        ) + (int) $repo->count(
            [
                'campaign_id' => $campaign_id,
                'status'      => 'needs_review',
            ]
        );
    }

    /**
     * @return array<string,string>
     */
    private function get_team_status_options(): array {
        return [
            'draft'          => 'Bozza',
            'pending_review' => 'In revisione',
            'needs_changes'  => 'Richiede modifiche',
            'approved'       => 'Approvata',
            'locked'         => 'Bloccata',
        ];
    }

    /**
     * @return string[]
     */
    private function get_team_available_transitions( string $from_status ): array {
        $map = [
            'draft'          => [ 'pending_review' ],
            'pending_review' => [ 'approved', 'needs_changes' ],
            'needs_changes'  => [ 'pending_review' ],
            'approved'       => [ 'locked', 'needs_changes' ],
            'locked'         => [ 'approved' ],
        ];

        return $map[ $from_status ] ?? [];
    }

    private function get_team_status_label( string $status ): string {
        $options = $this->get_team_status_options();
        return $options[ $status ] ?? ucfirst( $status );
    }

    private function render_team_status_badge( string $status ): string {
        $colors = [
            'draft'          => '#7a7a7a',
            'pending_review' => '#f0ad4e',
            'needs_changes'  => '#d9534f',
            'approved'       => '#5cb85c',
            'locked'         => '#4a4a4a',
        ];

        $color = $colors[ $status ] ?? '#7a7a7a';
        $label = $this->get_team_status_label( $status );
        return sprintf(
            '<span style="display:inline-block;padding:3px 8px;border-radius:3px;background:%s;color:#fff;font-size:11px;font-weight:600;">%s</span>',
            esc_attr( $color ),
            esc_html( $label )
        );
    }

    private function append_team_log( int $team_id, string $from_status, string $to_status, string $note, string $triggered_by ): void {
        global $wpdb;
        $wpdb->insert(
            "{$wpdb->prefix}aw_team_log",
            [
                'team_id'       => $team_id,
                'from_status'   => $from_status !== '' ? $from_status : null,
                'to_status'     => $to_status !== '' ? $to_status : null,
                'note'          => $note,
                'triggered_by'  => $triggered_by,
                'created_at'    => current_time( 'mysql', true ),
            ],
            [ '%d', '%s', '%s', '%s', '%s', '%s' ]
        );
    }

    private function get_team_log( int $team_id ): array {
        global $wpdb;
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}aw_team_log WHERE team_id = %d ORDER BY created_at DESC LIMIT 100",
                $team_id
            )
        ) ?: [];
    }

    private function redirect_to_team_detail( int $campaign_id, int $team_id, string $flag = '', int $value = 1, array $extra_args = [] ): void {
        $args = [
            'post_type'   => 'aw_campaign',
            'page'        => 'aw-teams',
            'campaign_id' => $campaign_id,
            'team_id'     => $team_id,
        ];
        if ( $flag !== '' ) {
            $args[ $flag ] = $value;
        }
        if ( ! empty( $extra_args ) ) {
            $args = array_merge( $args, $extra_args );
        }
        wp_safe_redirect( add_query_arg( $args, admin_url( 'edit.php' ) ) );
        exit;
    }

    private function append_registration_log_note( int $registration_id, string $status, string $note, string $triggered_by ): void {
        global $wpdb;
        $wpdb->insert(
            "{$wpdb->prefix}aw_registration_log",
            [
                'registration_id' => $registration_id,
                'from_status'     => $status,
                'to_status'       => $status,
                'note'            => $note,
                'triggered_by'    => $triggered_by,
                'created_at'      => current_time( 'mysql', true ),
            ],
            [ '%d', '%s', '%s', '%s', '%s', '%s' ]
        );
    }

    private function redirect_to_registration_detail( int $campaign_id, int $registration_id, string $flag = '', int $value = 1, array $extra_args = [] ): void {
        $args = [
            'post_type'       => 'aw_campaign',
            'page'            => 'aw-registrations',
            'campaign_id'     => $campaign_id,
            'registration_id' => $registration_id,
        ];
        if ( $flag !== '' ) {
            $args[ $flag ] = $value;
        }
        if ( ! empty( $extra_args ) ) {
            $args = array_merge( $args, $extra_args );
        }

        wp_safe_redirect( add_query_arg( $args, admin_url( 'edit.php' ) ) );
        exit;
    }

    /**
     * Renderizza un header tabella ordinabile.
     *
     * @param array<string,mixed> $base_args
     */
    private function render_sortable_header( string $label, string $target_orderby, array $base_args, string $current_orderby, string $current_order ): string {
        $is_current = $target_orderby === $current_orderby;
        $next_order = ( $is_current && $current_order === 'ASC' ) ? 'DESC' : 'ASC';
        $indicator  = '';
        if ( $is_current ) {
            $indicator = $current_order === 'ASC' ? ' ▲' : ' ▼';
        }

        $args = $base_args;
        $args['orderby'] = $target_orderby;
        $args['order']   = $next_order;
        $args['paged']   = 1;

        return '<a href="' . esc_url( add_query_arg( $args, admin_url( 'edit.php' ) ) ) . '">' . esc_html( $label . $indicator ) . '</a>';
    }

    private function render_status_badge( string $status ): string {
        $colors = [
            'received'        => '#999',
            'needs_review'    => '#f0ad4e',
            'waiting_payment' => '#5bc0de',
            'confirmed'       => '#5cb85c',
            'cancelled'       => '#d9534f',
            'archived'        => '#777',
        ];

        $labels = [
            'received'        => 'Ricevuta',
            'needs_review'    => 'Da revisionare',
            'waiting_payment' => 'In attesa pagamento',
            'confirmed'       => 'Confermata',
            'cancelled'       => 'Annullata',
            'archived'        => 'Archiviata',
        ];

        $color = $colors[ $status ] ?? '#999';
        $label = $labels[ $status ] ?? ucfirst( $status );

        return sprintf(
            '<span style="display:inline-block;padding:3px 8px;border-radius:3px;background:%s;color:#fff;font-size:11px;font-weight:600;">%s</span>',
            esc_attr( $color ),
            esc_html( $label )
        );
    }

    private function render_actions( object $registration ): string {
        $campaign_id = isset( $_GET['campaign_id'] ) ? (int) $_GET['campaign_id'] : 0;
        $status      = isset( $_GET['status'] ) ? sanitize_key( (string) $_GET['status'] ) : '';
        $search      = isset( $_GET['s'] ) ? sanitize_text_field( (string) $_GET['s'] ) : '';
        $paged       = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
        $orderby     = isset( $_GET['orderby'] ) ? sanitize_key( (string) $_GET['orderby'] ) : 'created_at';
        $order       = isset( $_GET['order'] ) && strtoupper( (string) $_GET['order'] ) === 'ASC' ? 'ASC' : 'DESC';

        $detail_url = add_query_arg(
            [
                'post_type'        => 'aw_campaign',
                'page'             => 'aw-registrations',
                'campaign_id'      => $campaign_id,
                'registration_id'  => (int) $registration->id,
                'status'           => $status,
                's'                => $search,
                'paged'            => $paged,
                'orderby'          => $orderby,
                'order'            => $order,
            ],
            admin_url( 'edit.php' )
        );

        return '<a href="' . esc_url( $detail_url ) . '">Visualizza</a>';
    }

    /**
     * Script inline per cambio stato.
     */
    public function render_change_status_script(): void {
        static $rendered = false;
        if ( $rendered ) {
            return;
        }
        $rendered = true;
        ?>
        <script>
        function awChangeStatus(id, status, campaignId, returnRegistrationId) {
            const note = prompt('Nota (facoltativa):');
            if (note === null) return;

            const form = document.createElement('form');
            form.method = 'POST';
            form.action = '<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>';
            form.innerHTML = `
                <input type="hidden" name="action" value="aw_change_status">
                <input type="hidden" name="registration_id" value="${id}">
                <input type="hidden" name="new_status" value="${status}">
                <input type="hidden" name="campaign_id" value="${campaignId || 0}">
                <input type="hidden" name="return_registration_id" value="${returnRegistrationId || 0}">
                <input type="hidden" name="note" value="${note}">
                <input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( 'aw_change_status' ) ); ?>">
            `;
            document.body.appendChild(form);
            form.submit();
        }
        </script>
        <?php
    }

    private function get_age_band_label( string $age_band ): string {
        return match ( strtoupper( trim( $age_band ) ) ) {
            'A' => '8-11',
            'B' => '11-17',
            'C' => '17-39',
            'D' => '39+',
            default => '-',
        };
    }

    private function get_status_action_label( string $status ): string {
        return match ( $status ) {
            'needs_review'    => 'Richiedi revisione',
            'waiting_payment' => 'Approva -> Pagamento',
            'confirmed'       => 'Conferma',
            'cancelled'       => 'Annulla',
            'archived'        => 'Archivia',
            default           => ucfirst( $status ),
        };
    }

    private function get_type_label( string $type ): string {
        return match ( $type ) {
            'team'       => 'Squadra',
            'individual' => 'Individuale',
            'group'      => 'Gruppo',
            'social'     => 'Conviviale',
            default      => ucfirst( $type ),
        };
    }
}


<?php
/**
 * AW\Campaign
 *
 * Gestisce il CPT aw_campaign e lo shortcode [aw_campaign_form].
 *
 * Responsabilità:
 *  - Registrare il CPT aw_campaign
 *  - Caricare il template dal filesystem (config.json)
 *  - Renderizzare il form via shortcode
 *  - Enqueue di CSS/JS con config iniettato
 */

namespace AW;

defined( 'ABSPATH' ) || exit;

class Campaign {

    public function __construct() {
        add_action( 'init', [ $this, 'register_cpt' ] );
        add_shortcode( 'aw_campaign_form', [ $this, 'shortcode_handler' ] );
    }

    // ─── CPT Registration ────────────────────────────────────────────────────

    /**
     * Registra il CPT aw_campaign.
     * Metodo statico per permettere la chiamata da activation hook.
     */
    public static function register_cpt(): void {
        $labels = [
            'name'               => 'Campagne',
            'singular_name'      => 'Campagna',
            'add_new'            => 'Aggiungi Campagna',
            'add_new_item'       => 'Aggiungi Nuova Campagna',
            'edit_item'          => 'Modifica Campagna',
            'new_item'           => 'Nuova Campagna',
            'view_item'          => 'Vedi Campagna',
            'search_items'       => 'Cerca Campagne',
            'not_found'          => 'Nessuna campagna trovata',
            'not_found_in_trash' => 'Nessuna campagna nel cestino',
            'menu_name'          => 'Alientu',
        ];

        $args = [
            'labels'              => $labels,
            'public'              => false,
            'publicly_queryable'  => false,
            'show_ui'             => true,
            'show_in_menu'        => true,
            'menu_icon'           => 'dashicons-groups',
            'capability_type'     => 'post',
            'hierarchical'        => false,
            'supports'            => [ 'title', 'editor' ],
            'has_archive'         => false,
            'rewrite'             => false,
            'show_in_rest'        => false,
        ];

        register_post_type( 'aw_campaign', $args );

        // Meta box per configurazione campagna (Sprint 2: UI completa)
        add_action( 'add_meta_boxes', [ __CLASS__, 'add_meta_boxes' ] );
        add_action( 'save_post_aw_campaign', [ __CLASS__, 'save_meta_boxes' ], 10, 2 );
    }

    /**
     * Aggiunge meta box nel backend per configurare la campagna.
     */
    public static function add_meta_boxes(): void {
        add_meta_box(
            'aw_campaign_config',
            'Configurazione Campagna',
            [ __CLASS__, 'render_meta_box' ],
            'aw_campaign',
            'normal',
            'high'
        );
    }

    /**
     * Renderizza il meta box con i campi di configurazione.
     * Sprint 1: campi minimal, Sprint 2: UI completa con select template, ecc.
     */
    public static function render_meta_box( \WP_Post $post ): void {
        wp_nonce_field( 'aw_campaign_meta', 'aw_campaign_meta_nonce' );

        $event_id       = get_post_meta( $post->ID, '_aw_event_id',       true ) ?: 'ALIENTU_2026';
        $causale_prefix = get_post_meta( $post->ID, '_aw_causale_prefix', true ) ?: 'ALIENTU26';
        $template_id    = get_post_meta( $post->ID, '_aw_template_id',    true ) ?: 'alientu-26';

        ?>
        <table class="form-table">
            <tr>
                <th><label for="aw_event_id">Event ID</label></th>
                <td>
                    <input type="text" id="aw_event_id" name="aw_event_id" value="<?php echo esc_attr( $event_id ); ?>" class="regular-text">
                    <p class="description">Identificatore evento (es. ALIENTU_2026). Usato per filtrare i dati.</p>
                </td>
            </tr>
            <tr>
                <th><label for="aw_causale_prefix">Prefisso Causale</label></th>
                <td>
                    <input type="text" id="aw_causale_prefix" name="aw_causale_prefix" value="<?php echo esc_attr( $causale_prefix ); ?>" class="regular-text">
                    <p class="description">Prefisso per il codice iscrizione (es. ALIENTU26).</p>
                </td>
            </tr>
            <tr>
                <th><label for="aw_template_id">Template</label></th>
                <td>
                    <input type="text" id="aw_template_id" name="aw_template_id" value="<?php echo esc_attr( $template_id ); ?>" class="regular-text">
                    <p class="description">ID del template (cartella in templates/). Default: alientu-26.</p>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Salva i meta campi della campagna.
     */
    public static function save_meta_boxes( int $post_id, \WP_Post $post ): void {
        // Verifica nonce
        if ( ! isset( $_POST['aw_campaign_meta_nonce'] ) || ! wp_verify_nonce( $_POST['aw_campaign_meta_nonce'], 'aw_campaign_meta' ) ) {
            return;
        }

        // Verifica permessi
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        // Evita autosave
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        // Salva meta
        $fields = [ 'aw_event_id', 'aw_causale_prefix', 'aw_template_id' ];
        foreach ( $fields as $field ) {
            if ( isset( $_POST[ $field ] ) ) {
                update_post_meta( $post_id, '_' . $field, sanitize_text_field( $_POST[ $field ] ) );
            }
        }
    }

    // ─── Shortcode ───────────────────────────────────────────────────────────

    /**
     * Handler per [aw_campaign_form id="123"] o [aw_campaign_form event_id="ALIENTU_2026"].
     *
     * @param array $atts Attributi shortcode.
     * @return string HTML del form.
     */
    public function shortcode_handler( array $atts ): string {
        $atts = shortcode_atts(
            [
                'id'       => 0,
                'event_id' => '',
            ],
            $atts,
            'aw_campaign_form'
        );

        // Carica la campagna
        $campaign = $this->get_campaign_for_shortcode( $atts );

        if ( ! $campaign ) {
            return '<div class="aw-error">Campagna non trovata o non pubblicata.</div>';
        }

        // Carica il config del template
        $config = $this->load_template_config( $campaign->template_id );

        if ( ! $config ) {
            return '<div class="aw-error">Template non configurato correttamente.</div>';
        }

        // Enqueue assets
        $this->enqueue_assets( $campaign, $config );

        // Renderizza il form
        return $this->render_form( $campaign, $config );
    }

    /**
     * Carica la campagna in base agli attributi shortcode.
     */
    private function get_campaign_for_shortcode( array $atts ): ?object {
        if ( ! empty( $atts['id'] ) ) {
            $post = get_post( (int) $atts['id'] );
        } elseif ( ! empty( $atts['event_id'] ) ) {
            // Ricerca per event_id custom field
            $query = new \WP_Query( [
                'post_type'      => 'aw_campaign',
                'post_status'    => 'publish',
                'posts_per_page' => 1,
                'meta_query'     => [
                    [
                        'key'   => '_aw_event_id',
                        'value' => sanitize_text_field( $atts['event_id'] ),
                    ],
                ],
            ] );

            $post = $query->have_posts() ? $query->posts[0] : null;
        } else {
            return null;
        }

        if ( ! $post || $post->post_type !== 'aw_campaign' || $post->post_status !== 'publish' ) {
            return null;
        }

        return (object) [
            'id'             => $post->ID,
            'title'          => $post->post_title,
            'event_id'       => get_post_meta( $post->ID, '_aw_event_id',       true ) ?: 'ALIENTU_2026',
            'causale_prefix' => get_post_meta( $post->ID, '_aw_causale_prefix', true ) ?: 'ALIENTU26',
            'template_id'    => get_post_meta( $post->ID, '_aw_template_id',    true ) ?: 'alientu-26',
        ];
    }

    /**
     * Carica config.json del template.
     *
     * @return object|null Config decodificato, null se non trovato.
     */
    private function load_template_config( string $template_id ): ?object {
        $file = AW_PLUGIN_DIR . "templates/{$template_id}/config.json";

        if ( ! file_exists( $file ) ) {
            return null;
        }

        $json = file_get_contents( $file );
        $data = json_decode( $json );

        return ( $data && json_last_error() === JSON_ERROR_NONE ) ? $data : null;
    }

    /**
     * Enqueue CSS, JS e inietta window.alientuConfig.
     */
    private function enqueue_assets( object $campaign, object $config ): void {
        // CSS plugin
        wp_enqueue_style(
            'aw-alientu',
            AW_PLUGIN_URL . 'assets/css/alientu.css',
            [],
            AW_VERSION
        );

        // JS plugin
        wp_enqueue_script(
            'aw-alientu',
            AW_PLUGIN_URL . 'assets/js/alientu.js',
            [],
            AW_VERSION,
            true
        );

        // Inietta config per il JS
        wp_localize_script(
            'aw-alientu',
            'alientuConfig',
            [
                'campaign_id' => $campaign->id,
                'event_id'    => $campaign->event_id,
                'priceGame'   => $config->prices->game ?? 3,
                'priceSocial' => $config->prices->social ?? 5,
                'eventYear'   => $config->event->year ?? '2026',
                'restUrl'     => rest_url( 'aw/v1/register' ),
                //'nonce'       => wp_create_nonce( 'aw_register' ),
                'nonce'       => wp_create_nonce( 'wp_rest' ),  // <-- usa 'wp_rest' standard
            ]
        );
    }

    /**
     * Renderizza l'HTML del form.
     * Carica il template HTML dal filesystem del template.
     */
    private function render_form( object $campaign, object $config ): string {
        error_log('CONFIG: ' . print_r($config, true));
        error_log('step1.intro.title: ' . ($config->step1->intro->title ?? 'NULL'));
        // Scorciatoie leggibili
        $s   = $config->sections;
        $s1  = $config->step1;
        $btn = $config->buttons;
        $msg = $config->messages;
        $st  = $config->steps;
        $lim = $config->limits;

        // Helper: esc_html su un valore di config potenzialmente null
        $t = fn( $v ) => esc_html( $v ?? '' );
        // Helper: testo con HTML permesso (strong, ecc.) già definito nel config
        $th = fn( $v ) => wp_kses( $v ?? '', [ 'strong' => [], 'em' => [], 'a' => [ 'href' => [], 'target' => [] ] ] );

        ob_start();
        ?>

        <div class="aw-wrapper pb-5">

        <!-- STEP INDICATOR -->
        <nav class="aw-steps" aria-label="Avanzamento iscrizione">
            <div class="aw-step active" data-step="1"><div class="aw-step-dot">1</div><span><?php $t( $st->step1 ); ?></span></div>
            <div class="aw-step-connector" data-after="1"></div>
            <div class="aw-step" data-step="2"><div class="aw-step-dot">2</div><span><?php echo $t( $st->step2 ); ?></span></div>
            <div class="aw-step-connector" data-after="2"></div>
            <div class="aw-step" data-step="3"><div class="aw-step-dot">3</div><span><?php echo $t( $st->step3 ); ?></span></div>
        </nav>

        <!-- BACK BAR -->
        <div class="aw-back-bar d-none" id="back_bar">
            <button type="button" class="aw-btn-back" id="btn_back">
                <i class="fa-solid fa-arrow-left"></i> <?php echo $t( $btn->back ); ?>
            </button>
            <span class="aw-selected-type"></span>
        </div>

        <!-- ════════════════════════════════════════════════════════
             STEP 1 — SELEZIONE TIPO
             ════════════════════════════════════════════════════════ -->
        <div id="section_type">
            <div class="aw-intro-block mb-4">
                <h2><?php echo $t( $s1->intro->title ); ?></h2>
                <p><?php echo $t( $s1->intro->text ); ?></p>
            </div>
            <div class="row g-3">
                <?php
                $icons = [ 'team' => 'fa-shield-halved', 'individual' => 'fa-user-plus', 'social' => 'fa-utensils' ];
                foreach ( [ 'team', 'individual', 'social' ] as $type ) :
                    $card = $s1->cards->{$type};
                    $icon = $icons[ $type ];
                ?>
                <div class="col-12 col-md-4">
                    <div class="aw-type-card h-100" data-type="<?php echo esc_attr( $type ); ?>">
                        <i class="fa-solid <?php echo esc_attr( $icon ); ?> aw-card-icon"></i>
                        <div class="aw-card-label"><?php echo $t( $card->label ); ?></div>
                        <div class="aw-card-title"><?php echo $t( $card->title ); ?></div>
                        <p class="aw-card-desc"><?php echo $t( $card->desc ); ?></p>
                        <div class="aw-card-rules">
                            <?php foreach ( $card->rules as $rule ) : ?>
                                <span class="aw-card-rule"><?php echo $t( $rule ); ?></span>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" class="aw-card-btn w-100 mt-auto pt-3"><?php echo $t( $card->btn ); ?></button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div><!-- /section_type -->

        <!-- ════════════════════════════════════════════════════════
             STEP 2 — FORM UNIFICATO
             ════════════════════════════════════════════════════════ -->
        <div id="section_form" class="d-none">
        <form id="aw-form" novalidate>
            <input type="hidden" id="registration_type" name="registration_type" value="">
            <div class="aw-intro-block mt-4 mb-3" id="form_intro"></div>

            <!-- A1/B1/C1 — DATI REFERENTE -->
            <?php $ref = $s->referente; $f = $ref->fields; ?>
            <div class="aw-form-section" id="sec_referente">
                <div class="aw-section-header">
                    <span class="aw-sec-num" id="num_referente">1</span>
                    <h3 id="title_referente"><?php echo $t( $ref->title_team ); ?></h3>
                </div>
                <div class="aw-section-body">
                    <div class="row g-3">
                        <div class="col-12 col-sm-6">
                            <label for="ref_nome" class="aw-label"><?php echo $t( $f->nome->label ); ?> <span class="aw-req">*</span></label>
                            <input type="text" class="form-control aw-input" id="ref_nome" name="ref_nome"
                                minlength="4" placeholder="<?php echo esc_attr( $f->nome->placeholder ); ?>"
                                autocomplete="given-name" required aria-required="true" aria-describedby="err_ref_nome">
                            <div class="aw-field-error" id="err_ref_nome" role="alert" aria-live="polite"><?php echo $t( $f->nome->error ); ?></div>
                        </div>
                        <div class="col-12 col-sm-6">
                            <label for="ref_cognome" class="aw-label"><?php echo $t( $f->cognome->label ); ?> <span class="aw-req">*</span></label>
                            <input type="text" class="form-control aw-input" id="ref_cognome" name="ref_cognome"
                                minlength="3" placeholder="<?php echo esc_attr( $f->cognome->placeholder ); ?>"
                                autocomplete="family-name" required aria-required="true" aria-describedby="err_ref_cognome">
                            <div class="aw-field-error" id="err_ref_cognome"><?php echo $t( $f->cognome->error ); ?></div>
                        </div>
                        <div class="col-12 col-sm-6">
                            <label for="ref_email" class="aw-label"><?php echo $t( $f->email->label ); ?> <span class="aw-req">*</span></label>
                            <input type="email" class="form-control aw-input" id="ref_email" name="ref_email"
                                placeholder="<?php echo esc_attr( $f->email->placeholder ); ?>"
                                autocomplete="email" required aria-required="true" aria-describedby="err_ref_email">
                            <div class="aw-field-error" id="err_ref_email"><?php echo $t( $f->email->error ); ?></div>
                        </div>
                        <div class="col-12 col-sm-6">
                            <label for="ref_tel" class="aw-label"><?php echo $t( $f->tel->label ); ?> <span class="aw-req">*</span></label>
                            <input type="tel" class="form-control aw-input" id="ref_tel" name="ref_tel"
                                placeholder="<?php echo esc_attr( $f->tel->placeholder ); ?>"
                                autocomplete="tel" inputmode="tel" pattern="^\+?[0-9\s]{8,20}$"
                                required aria-required="true" aria-describedby="err_ref_tel">
                            <div class="aw-field-error" id="err_ref_tel" role="alert" aria-live="polite"><?php echo $t( $f->tel->error ); ?></div>
                        </div>

                        <!-- Fascia età — solo Form B -->
                        <?php $fascia = $f->fascia; ?>
                        <div class="col-12 d-none" id="field_ref_fascia">
                            <fieldset class="aw-fieldset">
                                <legend class="aw-label mb-0"><?php echo $t( $fascia->label ); ?> <span class="aw-req">*</span></legend>
                                <div class="d-flex flex-wrap gap-3 mt-1" role="radiogroup" aria-describedby="err_ref_fascia">
                                    <?php foreach ( (array) $fascia->bands as $val => $hint ) : ?>
                                    <div class="aw-radio-item">
                                        <input type="radio" name="ref_fascia" value="<?php echo esc_attr( $val ); ?>"
                                            id="ref_band<?php echo esc_attr( $val ); ?>" required aria-required="true">
                                        <label for="ref_band<?php echo esc_attr( $val ); ?>">
                                            <?php echo esc_html( $val ); ?> <small class="aw-band-hint">(<?php echo esc_html( $hint ); ?>)</small>
                                        </label>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <div class="aw-field-error" id="err_ref_fascia" role="alert" aria-live="polite"><?php echo $t( $fascia->error ); ?></div>
                            </fieldset>
                        </div>

                        <!-- Consensi -->
                        <?php $con = $ref->consensi; ?>
                        <div class="col-12">
                            <fieldset class="aw-fieldset">
                                <legend class="aw-label mb-2"><?php echo $t( $con->label ); ?> <span class="aw-req">*</span></legend>
                                <div class="aw-consent-block" id="cb_regolamento">
                                    <label class="aw-check-item">
                                        <input type="checkbox" id="acc_regolamento" name="acc_regolamento"
                                            required aria-required="true" aria-describedby="err_consents">
                                        <span><?php echo $th( $con->regolamento ); ?> <span class="aw-req">*</span></span>
                                    </label>
                                </div>
                                <div class="aw-consent-block" id="cb_privacy">
                                    <label class="aw-check-item">
                                        <input type="checkbox" id="acc_privacy" name="acc_privacy"
                                            required aria-required="true" aria-describedby="err_consents">
                                        <span><?php echo $th( $con->privacy ); ?> <span class="aw-req">*</span></span>
                                    </label>
                                </div>
                                <div class="aw-field-error" id="err_consents" role="alert" aria-live="polite"><?php echo $t( $con->error ); ?></div>
                            </fieldset>
                        </div>
                    </div>
                </div>
            </div>

            <!-- A2 — IDENTITÀ SQUADRA -->
            <?php $ti = $s->team_identity; $tif = $ti->fields; ?>
            <div class="aw-form-section d-none" id="sec_team_identity">
                <div class="aw-section-header">
                    <span class="aw-sec-num">A2</span><h3><?php echo $t( $ti->title ); ?></h3>
                </div>
                <div class="aw-section-body">
                    <div class="row g-3">
                        <?php $col = $tif->colori; ?>
                        <div class="col-12">
                            <label class="aw-label"><?php echo $t( $col->label ); ?> <span class="aw-req">*</span></label>
                            <p class="aw-hint"><?php echo $t( $col->hint ); ?></p>
                            <div class="d-flex flex-column gap-2 mt-2">
                                <?php
                                $empties = [ $col->pref1_empty, $col->pref2_empty, $col->pref3_empty ];
                                for ( $i = 1; $i <= 3; $i++ ) :
                                ?>
                                <div class="aw-color-slot">
                                    <span class="aw-slot-label"><span class="aw-pref-num"><?php echo $i; ?></span>Pref.</span>
                                    <select class="form-select aw-input" id="color_<?php echo $i; ?>" name="color_<?php echo $i; ?>">
                                        <option value=""><?php echo esc_html( $empties[ $i - 1 ] ); ?></option>
                                        <?php foreach ( $col->options as $opt ) : ?>
                                            <option value="<?php echo esc_attr( $opt ); ?>"><?php echo esc_html( ucfirst( $opt ) ); ?></option>
                                        <?php endforeach; ?>
                                        <option value="custom"><?php echo esc_html( $col->custom_option ); ?></option>
                                    </select>
                                </div>
                                <?php endfor; ?>
                            </div>
                            <div class="aw-field-error" id="err_color_1"><?php echo $t( $col->error ); ?></div>
                        </div>

                        <?php $cc = $tif->color_custom; ?>
                        <div class="col-12 d-none" id="field_custom_color">
                            <label for="color_custom_desc" class="aw-label"><?php echo $t( $cc->label ); ?> <span class="aw-req">*</span></label>
                            <input type="text" class="form-control aw-input" id="color_custom_desc" name="color_custom_desc"
                                placeholder="<?php echo esc_attr( $cc->placeholder ); ?>">
                            <p class="aw-hint"><?php echo $t( $cc->hint ); ?></p>
                            <div class="aw-field-error" id="err_color_custom_desc"><?php echo $t( $cc->error ); ?></div>
                        </div>

                        <?php $tn = $tif->team_name; ?>
                        <div class="col-12 col-sm-6">
                            <label for="team_name" class="aw-label"><?php echo $t( $tn->label ); ?> <span class="aw-req">*</span></label>
                            <input type="text" class="form-control aw-input" id="team_name" name="team_name"
                                minlength="<?php echo (int) $lim->team_name_min; ?>"
                                maxlength="<?php echo (int) $lim->team_name_max; ?>"
                                placeholder="<?php echo esc_attr( $tn->placeholder ); ?>">
                            <p class="aw-hint"><?php echo $t( $tn->hint ); ?></p>
                            <div class="aw-field-error" id="err_team_name"><?php echo $t( $tn->error ); ?></div>
                        </div>

                        <?php $bn = $tif->banner; ?>
                        <div class="col-12">
                            <label class="aw-label"><?php echo $t( $bn->label ); ?></label>
                            <p class="aw-hint"><?php echo $t( $bn->hint ); ?></p>
                            <div class="d-flex flex-column gap-2 mt-1">
                                <div class="aw-radio-item">
                                    <input type="radio" name="banner_provider" value="org" id="banner_org" checked>
                                    <label for="banner_org"><?php echo $t( $bn->opt_org ); ?></label>
                                </div>
                                <div class="aw-radio-item">
                                    <input type="radio" name="banner_provider" value="team" id="banner_team">
                                    <label for="banner_team"><?php echo $t( $bn->opt_team ); ?></label>
                                </div>
                            </div>
                        </div>

                        <?php $bnn = $tif->banner_notes; ?>
                        <div class="col-12 d-none" id="field_banner_notes">
                            <label for="banner_notes" class="aw-label"><?php echo $t( $bnn->label ); ?> <span class="aw-opt">facoltativo</span></label>
                            <textarea class="form-control aw-input" id="banner_notes" name="banner_notes"
                                rows="3" placeholder="<?php echo esc_attr( $bnn->placeholder ); ?>"></textarea>
                        </div>
                    </div>
                </div>
            </div>

            <!-- A3 — COMPOSIZIONE SQUADRA -->
            <?php $comp = $s->composition; ?>
            <div class="aw-form-section d-none" id="sec_composition">
                <div class="aw-section-header">
                    <span class="aw-sec-num">A3</span><h3><?php echo $t( $comp->title ); ?></h3>
                </div>
                <div class="aw-section-body">
                    <div class="aw-rules-box mb-3"><?php echo $t( $comp->rules_box ); ?></div>
                    <div class="row g-3">
                        <?php $np = $comp->fields->num_players; ?>
                        <div class="col-6 col-sm-3">
                            <label for="num_players" class="aw-label"><?php echo $t( $np->label ); ?> <span class="aw-req">*</span></label>
                            <input type="number" class="form-control aw-input" id="num_players" name="num_players"
                                min="<?php echo (int) $lim->team_min_players; ?>"
                                max="<?php echo (int) $lim->team_max_players; ?>"
                                placeholder="<?php echo esc_attr( $np->placeholder ); ?>">
                            <div class="aw-field-error" id="err_num_players"><?php echo $t( $np->error ); ?></div>
                        </div>
                    </div>
                    <div class="aw-validation-panel d-none mt-3" id="validation_panel">
                        <strong>Composizione squadra</strong>
                        <ul id="validation_list" class="mt-1 mb-0"></ul>
                    </div>
                    <div class="d-flex flex-column gap-3 mt-3" id="players_container"></div>
                </div>
            </div>

            <!-- B2 — PROFILO PARTECIPANTE -->
            <?php $prof = $s->profile; $pf = $prof->fields; ?>
            <div class="aw-form-section d-none" id="sec_profile">
                <div class="aw-section-header">
                    <span class="aw-sec-num">B2</span><h3><?php echo $t( $prof->title ); ?></h3>
                </div>
                <div class="aw-section-body">
                    <div class="row g-3">
                        <?php foreach ( [ 'is_scout' => $pf->is_scout, 'is_sport' => $pf->is_sport ] as $name => $field ) : ?>
                        <div class="col-12">
                            <fieldset class="aw-fieldset">
                                <legend class="aw-label mb-0"><?php echo $t( $field->label ); ?> <span class="aw-req">*</span></legend>
                                <div class="d-flex gap-3 mt-1" role="radiogroup" aria-describedby="err_<?php echo esc_attr( $name ); ?>">
                                    <div class="aw-radio-item">
                                        <input type="radio" name="<?php echo esc_attr( $name ); ?>" value="si"
                                            id="<?php echo esc_attr( $name ); ?>_si" required aria-required="true">
                                        <label for="<?php echo esc_attr( $name ); ?>_si"><?php echo $t( $field->opt_si ); ?></label>
                                    </div>
                                    <div class="aw-radio-item">
                                        <input type="radio" name="<?php echo esc_attr( $name ); ?>" value="no"
                                            id="<?php echo esc_attr( $name ); ?>_no" required aria-required="true">
                                        <label for="<?php echo esc_attr( $name ); ?>_no"><?php echo $t( $field->opt_no ); ?></label>
                                    </div>
                                </div>
                                <div class="aw-field-error" id="err_<?php echo esc_attr( $name ); ?>" role="alert" aria-live="polite"><?php echo $t( $field->error ); ?></div>
                            </fieldset>
                        </div>
                        <?php endforeach; ?>

                        <div class="col-12 d-none" id="field_sport_desc">
                            <label for="sport_desc" class="aw-label"><?php echo $t( $pf->sport_desc->label ); ?> <span class="aw-opt">facoltativo</span></label>
                            <textarea class="form-control aw-input" id="sport_desc" name="sport_desc"
                                rows="2" placeholder="<?php echo esc_attr( $pf->sport_desc->placeholder ); ?>"></textarea>
                        </div>
                        <div class="col-12">
                            <label for="profile_notes" class="aw-label"><?php echo $t( $pf->profile_notes->label ); ?> <span class="aw-opt">facoltativo</span></label>
                            <textarea class="form-control aw-input" id="profile_notes" name="profile_notes"
                                rows="2" placeholder="<?php echo esc_attr( $pf->profile_notes->placeholder ); ?>"></textarea>
                        </div>
                        <div class="col-12">
                            <label for="team_pref" class="aw-label"><?php echo $t( $pf->team_pref->label ); ?> <span class="aw-opt">facoltativo</span></label>
                            <textarea class="form-control aw-input" id="team_pref" name="team_pref"
                                rows="2" placeholder="<?php echo esc_attr( $pf->team_pref->placeholder ); ?>"></textarea>
                        </div>
                    </div>
                </div>
            </div>

            <!-- A5/B3 — CONVIVIALE -->
            <?php $soc = $s->social; $sf = $soc->fields; ?>
            <div class="aw-form-section d-none" id="sec_social">
                <div class="aw-section-header">
                    <span class="aw-sec-num" id="num_social">A5</span><h3><?php echo $t( $soc->title ); ?></h3>
                </div>
                <div class="aw-section-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <fieldset class="aw-fieldset">
                                <legend class="aw-label mb-0" id="label_social_question"><?php echo $t( $sf->question_team ); ?> <span class="aw-req">*</span></legend>
                                <div class="d-flex flex-column gap-2 mt-1" role="radiogroup" aria-describedby="err_social_mode">
                                    <div class="aw-radio-item" id="social_opt_all">
                                        <input type="radio" name="social_mode" value="all" id="social_all" required aria-required="true">
                                        <label for="social_all"><?php echo $t( $sf->opt_all ); ?></label>
                                    </div>
                                    <div class="aw-radio-item" id="social_opt_some">
                                        <input type="radio" name="social_mode" value="some" id="social_some" required aria-required="true">
                                        <label for="social_some"><?php echo $t( $sf->opt_some ); ?></label>
                                    </div>
                                    <div class="aw-radio-item d-none" id="social_opt_yes">
                                        <input type="radio" name="social_mode" value="yes_b" id="social_yes" required aria-required="true">
                                        <label for="social_yes"><?php echo $t( $sf->opt_yes ); ?></label>
                                    </div>
                                    <div class="aw-radio-item">
                                        <input type="radio" name="social_mode" value="none" id="social_none" required aria-required="true">
                                        <label for="social_none"><?php echo $t( $sf->opt_none ); ?></label>
                                    </div>
                                </div>
                                <div class="aw-field-error" id="err_social_mode" role="alert" aria-live="polite"><?php echo $t( $sf->error ); ?></div>
                            </fieldset>
                        </div>
                        <div class="col-12 d-none" id="social_players_list"></div>
                        <div class="col-12 d-none" id="field_food_notes">
                            <label for="food_notes" class="aw-label"><?php echo $t( $sf->food_notes->label ); ?> <span class="aw-opt">facoltativo</span></label>
                            <textarea class="form-control aw-input" id="food_notes" name="food_notes"
                                rows="2" placeholder="<?php echo esc_attr( $sf->food_notes->placeholder ); ?>"></textarea>
                        </div>
                    </div>
                </div>
            </div>

            <!-- C2 — PARTECIPANTI CONVIVIALE -->
            <?php $sp = $s->social_participants; ?>
            <div class="aw-form-section d-none" id="sec_social_participants">
                <div class="aw-section-header">
                    <span class="aw-sec-num">C2</span><h3><?php echo $t( $sp->title ); ?></h3>
                </div>
                <div class="aw-section-body">
                    <p class="aw-hint mb-3"><?php echo $t( $sp->hint ); ?></p>
                    <div class="d-flex flex-column gap-3" id="social_participants_container"></div>
                    <button type="button" class="aw-btn-add mt-3" id="btn_add_social_participant">
                        <i class="fa-solid fa-plus"></i> <?php echo $t( $sp->btn_add ); ?>
                    </button>
                </div>
            </div>

            <!-- A6/B4 — TRASPORTI -->
            <?php $tr = $s->transport; $trf = $tr->fields; ?>
            <div class="aw-form-section d-none" id="sec_transport">
                <div class="aw-section-header">
                    <span class="aw-sec-num" id="num_transport">A6</span><h3><?php echo $t( $tr->title ); ?></h3>
                </div>
                <div class="aw-section-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <fieldset class="aw-fieldset">
                                <legend class="aw-label mb-0"><?php echo $t( $tr->question ); ?> <span class="aw-req">*</span></legend>
                                <div class="d-flex flex-column gap-2 mt-1" role="radiogroup" aria-describedby="err_transport_mode">
                                    <div class="aw-radio-item">
                                        <input type="radio" name="transport_mode" value="self" id="tr_self" required aria-required="true">
                                        <label for="tr_self"><?php echo $t( $trf->opt_self ); ?></label>
                                    </div>
                                    <div class="aw-radio-item">
                                        <input type="radio" name="transport_mode" value="seek" id="tr_seek" required aria-required="true">
                                        <label for="tr_seek"><?php echo $t( $trf->opt_seek ); ?></label>
                                    </div>
                                    <div class="aw-radio-item">
                                        <input type="radio" name="transport_mode" value="offer" id="tr_offer" required aria-required="true">
                                        <label for="tr_offer"><?php echo $t( $trf->opt_offer ); ?></label>
                                    </div>
                                </div>
                                <div class="aw-field-error" id="err_transport_mode" role="alert" aria-live="polite"><?php echo $t( $tr->error ); ?></div>
                            </fieldset>
                        </div>
                        <div class="col-12 col-sm-6 d-none" id="field_transport_location">
                            <label for="transport_location" class="aw-label"><?php echo $t( $trf->location->label ); ?> <span class="aw-req">*</span></label>
                            <input type="text" class="form-control aw-input" id="transport_location" name="transport_location"
                                placeholder="<?php echo esc_attr( $trf->location->placeholder ); ?>">
                            <div class="aw-field-error" id="err_transport_location"><?php echo $t( $trf->location->error ); ?></div>
                        </div>
                        <div class="col-6 col-sm-3 d-none" id="field_transport_seats_needed">
                            <label for="transport_seats_needed" class="aw-label"><?php echo $t( $trf->seats_needed->label ); ?> <span class="aw-req">*</span></label>
                            <input type="number" class="form-control aw-input" id="transport_seats_needed" name="transport_seats_needed"
                                min="1" max="20" placeholder="<?php echo esc_attr( $trf->seats_needed->placeholder ); ?>">
                            <div class="aw-field-error" id="err_transport_seats_needed"><?php echo $t( $trf->seats_needed->error ); ?></div>
                        </div>
                        <div class="col-6 col-sm-3 d-none" id="field_transport_seats">
                            <label for="transport_seats" class="aw-label"><?php echo $t( $trf->seats_offered->label ); ?> <span class="aw-req">*</span></label>
                            <input type="number" class="form-control aw-input" id="transport_seats" name="transport_seats"
                                min="1" max="9" placeholder="<?php echo esc_attr( $trf->seats_offered->placeholder ); ?>">
                            <div class="aw-field-error" id="err_transport_seats"><?php echo $t( $trf->seats_offered->error ); ?></div>
                        </div>
                        <div class="col-12">
                            <p class="aw-hint"><?php echo $t( $tr->hint ); ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- A7/B5/C3 — QUOTE -->
            <?php $q = $s->quotes; $prices = $config->prices; ?>
            <div class="aw-form-section d-none" id="sec_quotes">
                <div class="aw-section-header">
                    <span class="aw-sec-num" id="num_quotes">A7</span><h3><?php echo $t( $q->title ); ?></h3>
                </div>
                <div class="aw-section-body">
                    <div class="aw-quote-panel">
                        <div class="aw-quote-row" id="qrow_game">
                            <span class="aw-ql"><?php echo $t( $q->label_game ); ?> (<span id="q_n_players">0</span> giocatori &times; <?php echo (int) $prices->game; ?> &euro;)</span>
                            <span class="aw-qv">&euro; <span id="q_total_game">0.00</span></span>
                        </div>
                        <div class="aw-quote-row" id="qrow_social">
                            <span class="aw-ql"><?php echo $t( $q->label_social ); ?> (<span id="q_n_social">0</span> pers. &times; <?php echo (int) $prices->social; ?> &euro;)</span>
                            <span class="aw-qv">&euro; <span id="q_total_social">0.00</span></span>
                        </div>
                        <div class="aw-quote-total">
                            <span class="aw-ql"><?php echo $t( $q->label_minimum ); ?></span>
                            <span class="aw-qv">&euro; <span id="q_total_min">0.00</span></span>
                        </div>
                        <div class="mt-3">
                            <label for="donation" class="aw-label" style="color:var(--warm-gray)"><?php echo $t( $q->donation->label ); ?> <span class="aw-opt">facoltativa</span></label>
                            <input type="number" class="form-control aw-input aw-donation-input" id="donation" name="donation" min="0" step="0.50" placeholder="0.00">
                            <p class="aw-hint mt-1" style="color:rgba(255,255,255,.45)"><?php echo nl2br( $t( $q->donation->hint ) ); ?></p>
                        </div>
                        <div class="aw-quote-total mt-2">
                            <span class="aw-ql"><?php echo $t( $q->label_final ); ?></span>
                            <span class="aw-qv aw-qv-final">&euro; <span id="q_total_final">0.00</span></span>
                        </div>
                    </div>
                    <div class="aw-payment-info mt-3">
                        <h4><i class="fa-solid fa-building-columns me-2"></i><?php echo $t( $q->payment->title ); ?></h4>
                        <p><?php echo nl2br( $t( $q->payment->text ) ); ?></p>
                        <div class="aw-causale"><?php echo esc_html( $config->event->causale_prefix ); ?> &ndash; <span id="causale_label">SQUADRA</span> &ndash; [CODICE] &ndash; <span id="causale_cognome">[COGNOME]</span></div>
                        <p class="mt-2" style="font-size:.8rem;color:var(--bark)"><?php echo $t( $q->payment->footer ); ?></p>
                    </div>
                </div>
            </div>

            <div class="text-center mt-4">
                <button type="button" class="aw-btn-submit" id="btn_to_review">
                    <i class="fa-solid fa-eye me-2"></i><?php echo $t( $btn->to_review ); ?>
                </button>
            </div>

        </form>
        </div><!-- /section_form -->

        <!-- ════════════════════════════════════════════════════════
             STEP 3 — RIEPILOGO
             ════════════════════════════════════════════════════════ -->
        <?php $rv = $config->review->sections; ?>
        <div id="section_review" class="d-none pb-4">
            <div class="aw-intro-block mt-4 mb-3">
                <h2>verifica i dati inseriti</h2>
                <p>Controlla le informazioni prima di inviare la richiesta. Usa "modifica" per correggere qualcosa.</p>
            </div>
            <div class="aw-form-section">
                <div class="aw-section-header d-flex justify-content-between align-items-center">
                    <h4 class="mb-0"><?php echo $t( $rv->referente ); ?></h4>
                    <button type="button" class="aw-btn-edit" onclick="goToStep(2)"><i class="fa-solid fa-pen-to-square"></i> <?php echo $t( $btn->edit ); ?></button>
                </div>
                <div class="row g-2 p-3" id="rv_referente"></div>
            </div>
            <div class="aw-form-section d-none" id="rv_sec_team">
                <div class="aw-section-header d-flex justify-content-between align-items-center">
                    <h4 class="mb-0"><?php echo $t( $rv->team ); ?></h4>
                    <button type="button" class="aw-btn-edit" onclick="goToStep(2)"><i class="fa-solid fa-pen-to-square"></i> <?php echo $t( $btn->edit ); ?></button>
                </div>
                <div class="row g-2 p-3" id="rv_team"></div>
            </div>
            <div class="aw-form-section d-none" id="rv_sec_players">
                <div class="aw-section-header d-flex justify-content-between align-items-center">
                    <h4 class="mb-0"><?php echo $t( $rv->players ); ?></h4>
                    <button type="button" class="aw-btn-edit" onclick="goToStep(2)"><i class="fa-solid fa-pen-to-square"></i> <?php echo $t( $btn->edit ); ?></button>
                </div>
                <div class="p-3" id="rv_players"></div>
            </div>
            <div class="aw-form-section d-none" id="rv_sec_profile">
                <div class="aw-section-header d-flex justify-content-between align-items-center">
                    <h4 class="mb-0"><?php echo $t( $rv->profile ); ?></h4>
                    <button type="button" class="aw-btn-edit" onclick="goToStep(2)"><i class="fa-solid fa-pen-to-square"></i> <?php echo $t( $btn->edit ); ?></button>
                </div>
                <div class="row g-2 p-3" id="rv_profile"></div>
            </div>
            <div class="aw-form-section d-none" id="rv_sec_social">
                <div class="aw-section-header d-flex justify-content-between align-items-center">
                    <h4 class="mb-0"><?php echo $t( $rv->social ); ?></h4>
                    <button type="button" class="aw-btn-edit" onclick="goToStep(2)"><i class="fa-solid fa-pen-to-square"></i> <?php echo $t( $btn->edit ); ?></button>
                </div>
                <div class="row g-2 p-3" id="rv_social"></div>
            </div>
            <div class="aw-form-section">
                <div class="aw-section-header"><h4 class="mb-0"><?php echo $t( $rv->quotes ); ?></h4></div>
                <div class="row g-2 p-3" id="rv_quotes"></div>
                <div class="d-flex justify-content-between align-items-center px-3 py-3 aw-review-total-bar">
                    <span><?php echo $t( $rv->total_label ); ?></span>
                    <span id="rv_total_final" class="aw-review-total-value">€ 0.00</span>
                </div>
            </div>
            <div class="d-flex justify-content-end gap-3 mt-4">
                <button type="button" class="aw-btn-back-form" onclick="goToStep(2)">
                    <i class="fa-solid fa-arrow-left"></i> <?php echo $t( $btn->back_form ); ?>
                </button>
                <button type="button" class="aw-btn-confirm" id="btn_confirm_send">
                    <i class="fa-solid fa-paper-plane me-2"></i><?php echo $t( $btn->confirm_send ); ?>
                </button>
            </div>
        </div><!-- /section_review -->

        </div><!-- /aw-wrapper -->

        <!-- LOADER -->
        <div class="aw-loader-overlay" id="loader_overlay">
            <div class="aw-loader-spinner"></div>
            <div class="aw-loader-text"><?php echo $t( $msg->loader ); ?></div>
        </div>

        <!-- MESSAGE BOX — successo -->
        <div class="aw-msg-overlay" id="msg_success">
            <div class="aw-msg-box">
                <i class="fa-solid fa-circle-check aw-msg-icon aw-msg-ok"></i>
                <div class="aw-msg-title"><?php echo $t( $msg->success->title ); ?></div>
                <p class="aw-msg-body"><?php echo nl2br( $t( $msg->success->body ) ); ?></p>
                <div class="aw-registration-code d-none">
                    <strong>Codice iscrizione:</strong> <code id="msg_success_code"></code>
                </div>
                <button type="button" class="aw-btn-msg-ok"
                    onclick="document.getElementById('msg_success').classList.remove('show')">
                    <i class="fa-solid fa-check me-1"></i> <?php echo $t( $msg->success->btn_close ); ?>
                </button>
            </div>
        </div>

        <!-- MESSAGE BOX — errore -->
        <div class="aw-msg-overlay" id="msg_error">
            <div class="aw-msg-box">
                <i class="fa-solid fa-circle-exclamation aw-msg-icon aw-msg-error"></i>
                <div class="aw-msg-title"><?php echo $t( $msg->error->title ); ?></div>
                <p class="aw-msg-body">
                    <span id="msg_error_detail"><?php echo nl2br( $t( $msg->error->body ) ); ?></span>
                </p>
                <button type="button" class="aw-btn-msg-retry"
                    onclick="document.getElementById('msg_error').classList.remove('show')">
                    <i class="fa-solid fa-arrow-left me-1"></i> <?php echo $t( $msg->error->btn_retry ); ?>
                </button>
            </div>
        </div>

        <!-- MESSAGE BOX — errori validazione -->
        <div class="aw-msg-overlay" id="msg_validation">
            <div class="aw-msg-box">
                <i class="fa-solid fa-triangle-exclamation aw-msg-icon aw-msg-error"></i>
                <div class="aw-msg-title"><?php echo $t( $msg->validation->title ); ?></div>
                <div class="aw-msg-body">
                    <ul class="aw-msg-list mb-0" id="validation_summary_list"></ul>
                </div>
                <div class="d-flex gap-2 justify-content-center mt-3">
                    <button type="button" class="aw-btn-msg-retry" id="btn_validation_goto">
                        <i class="fa-solid fa-arrow-down me-1"></i> <?php echo $t( $msg->validation->btn_goto ); ?>
                    </button>
                    <button type="button" class="aw-btn-msg-ok" id="btn_validation_close">
                        <i class="fa-solid fa-check me-1"></i> <?php echo $t( $msg->validation->btn_close ); ?>
                    </button>
                </div>
            </div>
        </div>

        <?php
        return ob_get_clean();
    }
}
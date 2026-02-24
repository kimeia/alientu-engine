/**

 * alientu.js — Alientu 2026

 * Logica unificata form A (squadra) · B (individuale) · C (conviviale)

 *

 * Sezioni:

 *  1. Config & costanti

 *  2. Helpers DOM

 *  3. Step indicator & navigazione

 *  4. Routing: attiva sezioni per tipo

 *  5. Quote (calcolo + UI)

 *  6. Form A — colori & stendardo

 *  7. Form A — ripetitore giocatori

 *  8. Form A — validazione composizione

 *  9. Form A/B — conviviale

 * 10. Form A/B — trasporti

 * 11. Form B — profilo

 * 12. Form C — ripetitore partecipanti

 * 13. Raccolta dati → JSON

 * 14. Validazione submit

 * 15. Riepilogo (step 3)

 * 16. Invio, loader, message box

 * 17. Init

 */



'use strict';



/* ════ 1. CONFIG & COSTANTI ════════════════════════════════════ */



const CFG = window.alientuConfig || { priceGame: 3, priceSocial: 5, eventYear: '2026' };
const LIMITS = { team: { min: 6, max: 12 }, group: { min: 2, max: 5 } };

const BANDS = { A: '8–11', B: '11–17', C: '17–39', D: '39+' };

const TYPE_LABELS = { team: 'Iscrizione squadra', individual: 'Iscrizione individuale', group: 'Iscrizione gruppo', social: 'Solo conviviale' };

const TYPE_CAUSALE = { team: 'SQUADRA', individual: 'INDIVIDUALE', group: 'GRUPPO', social: 'CONVIVIALE' };



const INTROS = {

  team:       { h2: 'Iscrivi la tua squadra al grande gioco', p: 'Per essere valida, la squadra deve rispettare questi requisiti: 6–12 partecipanti, almeno 3 fasce d\'età diverse, almeno 2 per fascia.' },

  individual: { h2: 'Iscriviti come singolo partecipante',   p: 'Ti inseriremo noi in una squadra, cercando la combinazione migliore in base a fascia d\'età e profilo.' },

  group:      { h2: 'Iscrivi il tuo gruppo',                  p: 'Inserisci da 2 a 5 partecipanti. Non ci sono vincoli di composizione per fasce: lo staff userà il profilo gruppo per completare le squadre.' },

  social:     { h2: 'Iscrizione al momento conviviale',      p: 'Inserisci i dati del referente e di tutti i partecipanti. La quota è di 5 € a persona.' },

};

const COMPOSITION_META = {
  team: {
    title: 'Composizione Squadra',
    rules: 'Regole di composizione: 6-12 partecipanti, almeno 3 fasce d\'età diverse, e almeno 2 partecipanti per ciascuna fascia presente.',
    errPlayers: 'inserisci un numero tra 6 e 12'
  },
  group: {
    title: 'Composizione Gruppo',
    rules: 'Regole gruppo: 2-5 partecipanti, nessun vincolo di composizione per fascia d\'età.',
    errPlayers: 'inserisci un numero tra 2 e 5'
  }
};



/* ════ 2. HELPERS DOM ══════════════════════════════════════════ */



const $id  = id  => document.getElementById(id);

const $all = sel => document.querySelectorAll(sel);



function show(id)  { $id(id)?.classList.remove('d-none'); }

function hide(id)  { $id(id)?.classList.add('d-none'); }

function showEl(el) { el?.classList.remove('d-none'); }

function hideEl(el) { el?.classList.add('d-none'); }



function showError(id, on = true) { const e = $id(id); if (e) e.classList.toggle('visible', on); }

function setError(id, on = true)  {

  const e = $id(id);

  if (!e) return;

  e.classList.toggle('error', on);

  e.setAttribute('aria-invalid', on ? 'true' : 'false');

}



function fmt(n) { return parseFloat(n || 0).toFixed(2); }



const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

function validateText(v, min = 1, max = 0)  { const s = String(v || '').trim(); return s.length >= min && (max === 0 || s.length <= max); }

function validateEmail(v)  { return EMAIL_RE.test(String(v || '').trim()); }

function validatePhone(v)  {

  const digits = String(v || '').replace(/\D/g, ''); // solo cifre

  return digits.length >= 8 && digits.length <= 15;

}



function focusFirstError() {

  const form = $id('aw-form');

  if (!form) return;



  // 1) priorità: primo messaggio errore visibile (ordine DOM)

  const errMsg = form.querySelector('.aw-field-error.visible');

  if (errMsg) {

    const errId = errMsg.id;



    // cerca un campo che punti a quell’errore via aria-describedby

    let field = errId ? form.querySelector(`[aria-describedby~="${errId}"]`) : null;



    // fallback: prova a trovare input/select/textarea "vicino" all’errore

    if (!field) {

      const wrap = errMsg.closest('.col-12, .col-sm-6, .col-sm-3, .aw-consent-block, .aw-form-section') || errMsg.parentElement;

      field = wrap?.querySelector('input, select, textarea') || null;

    }



    // scroll sempre sul messaggio, focus sul campo se possibile

    errMsg.scrollIntoView({ behavior: 'smooth', block: 'center' });

    if (field && typeof field.focus === 'function') {

      setTimeout(() => field.focus({ preventScroll: true }), 250);

    }

    return;

  }



  // 2) fallback: primo input con classe error

  const bad = form.querySelector('input.error, select.error, textarea.error');

  if (bad) {

    bad.scrollIntoView({ behavior: 'smooth', block: 'center' });

    setTimeout(() => bad.focus({ preventScroll: true }), 250);

  }

}

function showValidationBox(items) {

  const ov = $id('msg_validation');

  const ul = $id('validation_summary_list');

  if (!ov || !ul) return;



  ul.innerHTML = items.map(it => `<li>${it}</li>`).join('');

  ov.classList.add('show');

}



function hideValidationBox() {

  $id('msg_validation')?.classList.remove('show');

}



function collectVisibleErrors() {

  const form = $id('aw-form');

  if (!form) return [];



  const items = [];

  const seen = new Set();



  // 1) errori già resi visibili da validateForm()

  form.querySelectorAll('.aw-field-error.visible').forEach(el => {

    const txt = el.textContent.trim();

    if (!txt || seen.has(txt)) return;

    seen.add(txt);

    items.push(txt);

  });



  // 2) composizione squadra (pannello invalid)

  const panel = $id('validation_panel');

  if (panel && panel.classList.contains('invalid')) {

    ($id('validation_list')?.querySelectorAll('li') || []).forEach(li => {

      const txt = li.textContent.trim();

      if (txt && !seen.has(txt)) { seen.add(txt); items.push(txt); }

    });

  }



  return items;

}











/* ════ 3. STEP INDICATOR & NAVIGAZIONE ═════════════════════════ */



let currentStep = 1;



function setStep(n) {

  currentStep = n;



  $all('.aw-step').forEach(el => {

    const s = parseInt(el.dataset.step, 10);



    el.classList.remove('active', 'done');



    if (s === n) {

      el.classList.add('active');

      el.setAttribute('aria-current', 'step');

    } else {

      el.removeAttribute('aria-current');

    }



    if (s < n) el.classList.add('done');



    // se vuoi rendere chiaro che i passi futuri non sono "cliccabili"

    el.setAttribute('aria-disabled', s > n ? 'true' : 'false');

  });



  $all('.aw-step-connector').forEach(el => {

    el.classList.toggle('done', parseInt(el.dataset.after, 10) < n);

  });

}





function goToStep(n) {

  hide('section_type');

  hide('section_form');

  hide('section_review');

  hideValidationBox();

  if (n === 1) { show('section_type'); setStep(1); $id('back_bar')?.classList.add('d-none'); }

  if (n === 2) { show('section_form'); setStep(2); $id('back_bar')?.classList.remove('d-none'); }

  if (n === 3) { buildReview(); show('section_review'); setStep(3); }

  window.scrollTo({ top: 0, behavior: 'smooth' });

}



/* ════ 4. ROUTING: ATTIVA SEZIONI PER TIPO ═════════════════════ */



let currentType = null;
let activePlayerIndex = -1;



// sezioni e loro visibilità per tipo

const SECTIONS = {

  // id sezione       team   individual group  social
  sec_referente:      [true,  true,      true,  true ],
  sec_team_identity:  [true,  false,     false, false],
  sec_composition:    [true,  false,     true,  false],
  sec_profile:        [false, true,      true,  false],
  sec_social:         [true,  true,      true,  false],
  sec_social_participants: [false, false, false, true],
  sec_transport:      [true,  true,      true,  false],
  sec_quotes:         [true,  true,      true,  true ],

};



function activateType(type) {

  currentType = type;

  const idx = { team: 0, individual: 1, group: 2, social: 3 }[type];



  // mostra/nasconde sezioni

  for (const [id, flags] of Object.entries(SECTIONS)) {

    flags[idx] ? show(id) : hide(id);

  }



  // intro block

  const intro = INTROS[type];

  const el = $id('form_intro');

  if (el) el.innerHTML = `<h2>${intro.h2}</h2><p>${intro.p}</p>`;

  const compMeta = COMPOSITION_META[type] || COMPOSITION_META.team;
  const compTitle = $id('title_composition');
  if (compTitle) compTitle.textContent = compMeta.title;
  const compRules = $id('composition_rules');
  if (compRules) compRules.textContent = compMeta.rules;
  const errPlayers = $id('err_num_players');
  if (errPlayers) errPlayers.textContent = compMeta.errPlayers;



  // campo nascosto

  const rt = $id('registration_type');

  if (rt) rt.value = type;



  // causale label

  const cl = $id('causale_label');

  if (cl) cl.textContent = TYPE_CAUSALE[type];



  // titolo referente

  const tr = $id('title_referente');

  if (tr) tr.textContent = type === 'social' ? 'Dati Referente' : type === 'individual' ? 'Dati Personali' : 'Dati Referente';
  const tp = $id('title_profile');
  if (tp) tp.textContent = type === 'group' ? 'Profilo Squadra' : 'Profilo Partecipante';



  // fascia età (solo B)

  type === 'individual' ? show('field_ref_fascia') : hide('field_ref_fascia');
  type === 'team' ? show('field_ref_in_team') : hide('field_ref_in_team');
  type === 'group' ? hide('profile_individual_fields') : show('profile_individual_fields');



  // conviviale: opzioni diverse per A vs B

  if (type === 'individual') {

    hide('social_opt_all'); hide('social_opt_some'); show('social_opt_yes');

    const lbl = document.querySelector('label[for="social_none"]');

    if (lbl) lbl.textContent = 'no, grazie';

    $id('num_social') && ($id('num_social').textContent = 'B3');

    $id('num_transport') && ($id('num_transport').textContent = 'B4');

    $id('num_quotes')    && ($id('num_quotes').textContent    = 'B5');

  } else if (type === 'team' || type === 'group') {

    show('social_opt_all'); show('social_opt_some'); hide('social_opt_yes');
    if (type === 'team') {
      const refInTeamYes = $id('ref_in_team_yes');
      if (refInTeamYes) refInTeamYes.checked = true;
      $id('num_social') && ($id('num_social').textContent = 'A5');
      $id('num_transport') && ($id('num_transport').textContent = 'A6');
      $id('num_quotes')    && ($id('num_quotes').textContent    = 'A7');
      $id('num_profile')   && ($id('num_profile').textContent   = 'B2');
      $id('num_composition') && ($id('num_composition').textContent = 'A4');
    } else {
      $id('num_profile')   && ($id('num_profile').textContent   = 'G2');
      $id('num_composition') && ($id('num_composition').textContent = 'G3');
      $id('num_social') && ($id('num_social').textContent = 'G4');
      $id('num_transport') && ($id('num_transport').textContent = 'G5');
      $id('num_quotes')    && ($id('num_quotes').textContent    = 'G6');
    }

    const np = $id('num_players');
    if (np) {
      const lim = type === 'team' ? LIMITS.team : LIMITS.group;
      np.min = String(lim.min);
      np.max = String(lim.max);
      np.placeholder = `${lim.min}-${lim.max}`;
    }

  } else {

    // C: nessun modale conviviale, solo quote e partecipanti

    $id('num_quotes') && ($id('num_quotes').textContent = 'C3');
    $id('num_profile')   && ($id('num_profile').textContent   = 'B2');
    $id('num_composition') && ($id('num_composition').textContent = 'A3');

  }



  // righe quote visibili per tipo

  const qrowGame   = $id('qrow_game');

  const qrowSocial = $id('qrow_social');

  if (type === 'social') { hideEl(qrowGame); }

  else { showEl(qrowGame); }



  // Form C: inizializza ripetitore con referente precompilato

  if (type === 'social') initSocialParticipants();



  // Form A: i giocatori compaiono solo dopo selezione numero
  if (type === 'team' || type === 'group') {
    const np = $id('num_players');
    if (np) np.value = '';
    renderPlayers(0);
    if (type === 'team') syncReferenteToTeamFirst();
  }



  // aggiorna causale e quote

  updateQuotes();

  updateCausale();



  // back bar

  const bar = $id('back_bar');

  if (bar) {

    bar.classList.remove('d-none');

    const span = bar.querySelector('.aw-selected-type');

    if (span) span.innerHTML = `stai compilando: <strong>${TYPE_LABELS[type]}</strong>`;

  }



  goToStep(2);

}



/* ════ 5. QUOTE ════════════════════════════════════════════════ */



function calcQuotes({ nPlayers = 0, nSocial = 0, donation = 0 } = {}) {

  const totalGame    = nPlayers * CFG.priceGame;

  const totalSocial  = nSocial  * CFG.priceSocial;

  const totalMinimum = totalGame + totalSocial;

  const totalFinal   = totalMinimum + Math.max(0, donation);

  return { totalGame, totalSocial, totalMinimum, totalFinal };

}



function countSocialForQuotes() {

  if (currentType === 'social') {

    return document.querySelectorAll('#social_participants_container .aw-social-participant-block').length;

  }

  const mode = document.querySelector('input[name="social_mode"]:checked')?.value;
  const refInTeam = isReferenteInTeam();

  if (!mode || mode === 'none') return 0;

  if (mode === 'all' || mode === 'yes_b') {

    if (currentType === 'team' || currentType === 'group') {
      const basePlayers = parseInt($id('num_players')?.value) || 0;
      return basePlayers + (currentType === 'team' && !refInTeam ? 1 : 0);
    }
    return 1;

  }

  const playersSelected = $all('input[name^="social_players"]:checked').length;
  const referenteSelected = !refInTeam && $id('social_ref')?.checked ? 1 : 0;
  return playersSelected + referenteSelected;

}

function isReferenteInTeam() {
  if (currentType !== 'team') return false;
  const selected = document.querySelector('input[name="ref_in_team"]:checked');
  return (selected?.value ?? '1') === '1';
}



function updateQuotes() {

  const nPlayers = currentType === 'social' ? 0

                 : currentType === 'individual' ? 1

                 : (parseInt($id('num_players')?.value) || 0);

  const nSocial  = countSocialForQuotes();

  const donation = parseFloat($id('donation')?.value) || 0;

  const t = calcQuotes({ nPlayers, nSocial, donation });



  const set = (id, v) => { const e = $id(id); if (e) e.textContent = v; };

  set('q_n_players',   nPlayers);

  set('q_total_game',  fmt(t.totalGame));

  set('q_n_social',    nSocial);

  set('q_total_social',fmt(t.totalSocial));

  set('q_total_min',   fmt(t.totalMinimum));

  set('q_total_final', fmt(t.totalFinal));

}



function updateCausale() {

  const cognome = ($id('ref_cognome')?.value || '').trim().toUpperCase() || '[COGNOME]';

  const el = $id('causale_cognome');

  if (el) el.textContent = cognome;

}



/* ════ 6. FORM A — COLORI & STENDARDO ═════════════════════════ */



function hasCustomColor() {

  return ['color_1','color_2','color_3'].some(id => $id(id)?.value === 'custom');

}



function syncColorSelects() {

  const custom = hasCustomColor();

  custom ? show('field_custom_color') : hide('field_custom_color');

  if (custom) { $id('banner_org').disabled = true; $id('banner_team').checked = true; show('field_banner_notes'); }

  else { $id('banner_org').disabled = false; }

  updateQuotes(); updateCausale();

}



/* ════ 7. FORM A — RIPETITORE GIOCATORI ════════════════════════ */



function buildPlayerBlock(i) {
  const div = document.createElement('div');
  div.className = 'aw-player-block is-collapsed';
  div.dataset.index = i;
  div.dataset.complete = '0';

  div.innerHTML = `
    <button type="button" class="aw-player-header" onclick="openPlayerBlock(${i})" onkeydown="handlePlayerHeaderKey(event, ${i})" aria-expanded="false">
      <span class="aw-player-head-left">
        <span class="aw-player-num">Giocatore ${i+1}</span>
        <span class="aw-player-summary" id="summary_p${i}">da compilare</span>
      </span>
      <span class="aw-player-head-right">
        <span class="aw-player-status" id="status_p${i}">da compilare</span>
        <span class="aw-fascia-badge" id="badge_p${i}">-</span>
        <i class="fa-solid fa-chevron-down aw-player-chevron" id="chev_p${i}"></i>
      </span>
    </button>

    <div class="aw-player-body d-none">
      <div class="row g-2 mb-2">
        <div class="col-12 col-sm-6">
          <label class="aw-label">Nome <span class="aw-req">*</span></label>
          <input type="text" class="form-control aw-input" id="p${i}_nome" name="players[${i}][first_name]" minlength="2" placeholder="nome">
        </div>
        <div class="col-12 col-sm-6">
          <label class="aw-label">Cognome <span class="aw-req">*</span></label>
          <input type="text" class="form-control aw-input" id="p${i}_cognome" name="players[${i}][last_name]" minlength="2" placeholder="cognome">
        </div>
      </div>

      <div class="mb-2">
        <label class="aw-label">Fascia d'eta <span class="aw-req">*</span></label>
        <div class="d-flex flex-wrap gap-3 mt-1">
          ${['A','B','C','D'].map(b => `
            <div class="aw-radio-item">
              <input type="radio" name="players[${i}][age_band]" value="${b}" id="p${i}_band${b}" onchange="onBandChange(${i},'${b}')">
              <label for="p${i}_band${b}">${b} <small class="aw-band-hint">(${BANDS[b]})</small></label>
            </div>`).join('')}
        </div>
        <p class="aw-hint">le fasce hanno un anno di sovrapposizione per maggiore elasticita.</p>
      </div>

      <div class="mb-2">
        <button type="button" class="aw-btn-contact-toggle" id="btn_ct_${i}" onclick="toggleContact(${i})">
          <i class="fa-solid fa-plus"></i> aggiungi contatti (facoltativo)
        </button>
      </div>

      <div id="contact_fields_${i}" class="d-none">
        <div class="row g-2 mb-1">
          <div class="col-12 col-sm-6">
            <label class="aw-label">Email <span class="aw-opt">facoltativa</span></label>
            <input type="email" class="form-control aw-input" id="p${i}_email" name="players[${i}][email]" placeholder="email@esempio.it">
          </div>
          <div class="col-12 col-sm-6">
            <label class="aw-label">Telefono <span class="aw-opt">facoltativo</span></label>
            <input type="tel" class="form-control aw-input" id="p${i}_tel" name="players[${i}][phone]" placeholder="+39 333 0000000">
          </div>
        </div>
        <p class="aw-contact-note">email e telefono sono facoltativi. se forniti, potranno essere usati per comunicazioni legate all'evento.</p>
      </div>
    </div>`;

  const refresh = () => refreshPlayerBlockState(i, true);
  $id(`p${i}_nome`)?.addEventListener('input', refresh);
  $id(`p${i}_cognome`)?.addEventListener('input', refresh);
  $id(`p${i}_email`)?.addEventListener('input', () => updateSocialList());
  $id(`p${i}_tel`)?.addEventListener('input', () => updateSocialList());

  return div;
}

function handlePlayerHeaderKey(e, i) {
  if (e.key === 'Enter' || e.key === ' ') {
    e.preventDefault();
    openPlayerBlock(i);
  }
}

function toggleContact(i) {
  const f = $id(`contact_fields_${i}`);
  const b = $id(`btn_ct_${i}`);
  const open = f.classList.contains('d-none');
  f.classList.toggle('d-none', !open);
  b.classList.toggle('open', open);
  b.innerHTML = open
    ? '<i class="fa-solid fa-minus"></i> nascondi contatti'
    : '<i class="fa-solid fa-plus"></i> aggiungi contatti (facoltativo)';
}

function isPlayerComplete(i) {
  const nome = $id(`p${i}_nome`)?.value || '';
  const cognome = $id(`p${i}_cognome`)?.value || '';
  const band = document.querySelector(`input[name="players[${i}][age_band]"]:checked`)?.value || '';
  return validateText(nome, 2) && validateText(cognome, 2) && ['A','B','C','D'].includes(band);
}

function playerSummary(i) {
  const nome = ($id(`p${i}_nome`)?.value || '').trim();
  const cognome = ($id(`p${i}_cognome`)?.value || '').trim();
  const band = document.querySelector(`input[name="players[${i}][age_band]"]:checked`)?.value || '';
  if (!nome && !cognome && !band) return 'da compilare';
  const full = `${nome} ${cognome}`.trim() || `Giocatore ${i + 1}`;
  const bandLabel = band ? `Fascia ${band}` : 'Fascia -';
  return `${full} - ${bandLabel}`;
}

function findNextIncompletePlayer(startIdx) {
  const blocks = $id('players_container')?.querySelectorAll('.aw-player-block') || [];
  for (let i = startIdx + 1; i < blocks.length; i++) {
    if (!isPlayerComplete(i)) return i;
  }
  return -1;
}

function openPlayerBlock(i) {
  const blocks = $id('players_container')?.querySelectorAll('.aw-player-block') || [];
  if (!blocks.length || i < 0 || i >= blocks.length) return;

  blocks.forEach((block, idx) => {
    const collapsed = idx !== i;
    block.classList.toggle('is-collapsed', collapsed);
    block.querySelector('.aw-player-body')?.classList.toggle('d-none', collapsed);
    block.querySelector('.aw-player-header')?.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
  });

  activePlayerIndex = i;
}

function refreshPlayerBlockState(i, autoAdvance = false) {
  const block = $id('players_container')?.querySelector(`.aw-player-block[data-index="${i}"]`);
  if (!block) return;

  const complete = isPlayerComplete(i);
  const wasComplete = block.dataset.complete === '1';
  block.dataset.complete = complete ? '1' : '0';
  block.classList.toggle('is-ok', complete);

  const band = document.querySelector(`input[name="players[${i}][age_band]"]:checked`)?.value || '';
  const badge = $id(`badge_p${i}`);
  if (badge) {
    badge.textContent = band ? `Fascia ${band} (${BANDS[band]})` : '-';
    badge.className = band ? `aw-fascia-badge band-${band}` : 'aw-fascia-badge';
  }

  const status = $id(`status_p${i}`);
  if (status) {
    status.textContent = complete ? 'OK' : 'da compilare';
    status.classList.toggle('ok', complete);
  }

  const summary = $id(`summary_p${i}`);
  if (summary) summary.textContent = playerSummary(i);

  if (autoAdvance && complete && !wasComplete) {
    const next = findNextIncompletePlayer(i);
    if (next >= 0) openPlayerBlock(next);
  }

  if (autoAdvance && !complete && wasComplete) {
    openPlayerBlock(i);
  }
}

function onBandChange(i, band) {
  const badge = $id(`badge_p${i}`);
  if (badge) { badge.textContent = `Fascia ${band} (${BANDS[band]})`; badge.className = `aw-fascia-badge band-${band}`; }
  refreshPlayerBlockState(i, true);
  validateComposition(); updateSocialList(); updateQuotes();
}

function renderPlayers(count) {
  const container = $id('players_container');
  if (!container) return;

  if (!count || count < 1) {
    container.innerHTML = '';
    activePlayerIndex = -1;
    $id('validation_panel')?.classList.add('d-none');
    updateSocialList();
    updateQuotes();
    return;
  }

  while (container.children.length > count) container.removeChild(container.lastChild);
  while (container.children.length < count) container.appendChild(buildPlayerBlock(container.children.length));

  for (let i = 0; i < count; i++) refreshPlayerBlockState(i, false);
  syncReferenteToTeamFirst();

  const next = findNextIncompletePlayer(-1);
  openPlayerBlock(next >= 0 ? next : 0);

  updateSocialList(); updateQuotes();
}
/* ════ 8. FORM A — VALIDAZIONE COMPOSIZIONE ════════════════════ */



function validateComposition() {

  const blocks = $id('players_container')?.querySelectorAll('.aw-player-block') || [];

  const panel  = $id('validation_panel');

  const list   = $id('validation_list');

  if (!blocks.length || !panel) return false;

  if (currentType === 'group') {
    panel.classList.add('d-none');
    return true;
  }



  const count = { A:0, B:0, C:0, D:0 };

  blocks.forEach((_, i) => {

    const c = document.querySelector(`input[name="players[${i}][age_band]"]:checked`);

    if (c) count[c.value]++;

  });



  const present = Object.entries(count).filter(([,n]) => n > 0);

  const errors  = [];

  if (present.length < 3) errors.push(`fasce presenti: ${present.length} — ne servono almeno 3`);

  present.forEach(([b, n]) => { if (n < 2) errors.push(`fascia ${b}: ${n} partecipante — ne servono almeno 2`); });



  panel.classList.remove('d-none');

  if (errors.length === 0) {

    panel.className = 'aw-validation-panel valid';

    list.innerHTML = present.map(([b,n]) => `<li>fascia ${b} (${BANDS[b]}): ${n} ✓</li>`).join('');

    return true;

  }

  panel.className = 'aw-validation-panel invalid';

  list.innerHTML = errors.map(e => `<li>${e}</li>`).join('');

  return false;

}



/* ════ 9. FORM A/B — CONVIVIALE ════════════════════════════════ */



function updateSocialList() {

  const mode = document.querySelector('input[name="social_mode"]:checked')?.value;

  const list = $id('social_players_list');

  const food = $id('field_food_notes');

  if (!mode) return;



  if (food) food.classList.toggle('d-none', mode === 'none');



  if (mode === 'some') {

    list?.classList.remove('d-none');

    const blocks = $id('players_container')?.querySelectorAll('.aw-player-block') || [];
    const checkedPlayers = new Set(Array.from($all('input[name^="social_players"]:checked')).map(el => el.id));
    const checkedReferente = $id('social_ref')?.checked;
    const refInTeam = isReferenteInTeam();

    if (list) {

      list.innerHTML = '<p class="aw-hint mb-2">seleziona chi si ferma al conviviale:</p>';

      if (currentType === 'team' && !refInTeam) {
        const refLbl = document.createElement('label');
        refLbl.className = 'aw-check-item mb-1';
        refLbl.innerHTML = `<input type="checkbox" name="social_ref" value="1" id="social_ref" onchange="updateQuotes()"><span>Referente (${($id('ref_nome')?.value || '').trim()} ${($id('ref_cognome')?.value || '').trim()})</span>`;
        list.appendChild(refLbl);
        if (checkedReferente) {
          $id('social_ref').checked = true;
        }
      }

      blocks.forEach((_, i) => {

        const nome    = $id(`p${i}_nome`)?.value    || `Giocatore ${i+1}`;

        const cognome = $id(`p${i}_cognome`)?.value || '';

        const lbl = document.createElement('label');

        lbl.className = 'aw-check-item mb-1';

        lbl.innerHTML = `<input type="checkbox" name="social_players[${i}]" value="1" id="social_p${i}" onchange="updateQuotes()"><span>${nome} ${cognome}</span>`;

        list.appendChild(lbl);
        if (checkedPlayers.has(`social_p${i}`)) {
          $id(`social_p${i}`).checked = true;
        }

      });

    }

  } else {

    list?.classList.add('d-none');

  }

  updateQuotes();

}



/* ════ 10. FORM A/B — TRASPORTI ════════════════════════════════ */



function initTransportListeners() {

  $all('input[name="transport_mode"]').forEach(r => {

    r.addEventListener('change', function () {

      const v = this.value;

      $id('field_transport_location')?.classList.toggle('d-none', v === 'self');

      $id('field_transport_seats_needed')?.classList.toggle('d-none', v !== 'seek');

      $id('field_transport_seats')?.classList.toggle('d-none', v !== 'offer');

    });

  });

}



/* ════ 11. FORM B — PROFILO ════════════════════════════════════ */



function toggleSportDesc(show) {

  $id('field_sport_desc')?.classList.toggle('d-none', !show);

}



/* ════ 12. FORM C — RIPETITORE PARTECIPANTI ════════════════════ */



let socialParticipantCount = 0;



function buildSocialParticipantBlock(i, isFirst = false) {

  const div = document.createElement('div');

  div.className = 'aw-social-participant-block';

  div.dataset.index = i;

  const label = isFirst ? 'Referente (tu)' : `Partecipante ${i + 1}`;

  const removeBtn = isFirst ? '' : `<button type="button" class="aw-btn-remove-participant" onclick="removeSocialParticipant(this)"><i class="fa-solid fa-xmark"></i> rimuovi</button>`;

  div.innerHTML = `

    <div class="aw-social-participant-header">

      <span class="aw-social-participant-num">${label}</span>

      ${removeBtn}

    </div>

    <div class="aw-player-body">

      <div class="row g-2 mb-2">

        <div class="col-12 col-sm-6">

          <label class="aw-label">Nome <span class="aw-req">*</span></label>

          <input type="text" class="form-control aw-input" name="sp_nome_${i}" id="sp${i}_nome"

                 ${isFirst ? 'readonly' : ''} placeholder="nome">

        </div>

        <div class="col-12 col-sm-6">

          <label class="aw-label">Cognome <span class="aw-req">*</span></label>

          <input type="text" class="form-control aw-input" name="sp_cognome_${i}" id="sp${i}_cognome"

                 ${isFirst ? 'readonly' : ''} placeholder="cognome">

        </div>

      </div>

      <div class="mb-2">

        <label class="aw-label">Intolleranze alimentari <span class="aw-opt">facoltativo</span></label>

        <textarea class="form-control aw-input" name="sp_intolleranze_${i}" id="sp${i}_intolleranze"

                  rows="2" placeholder="Segnala eventuali intolleranze o allergie…"></textarea>

      </div>

      <div class="mb-2">

        <button type="button" class="aw-btn-contact-toggle" id="btn_sp_ct_${i}" onclick="toggleSocialContact(${i})">

          <i class="fa-solid fa-plus"></i> aggiungi contatti (facoltativo)

        </button>

      </div>

      <div id="sp_contact_fields_${i}" class="d-none">

        <div class="row g-2">

          <div class="col-12 col-sm-6">

            <label class="aw-label">Email <span class="aw-opt">facoltativa</span></label>

            <input type="email" class="form-control aw-input" name="sp_email_${i}" id="sp${i}_email"

                   ${isFirst ? 'readonly' : ''} placeholder="email@esempio.it">

          </div>

          <div class="col-12 col-sm-6">

            <label class="aw-label">Telefono <span class="aw-opt">facoltativo</span></label>

            <input type="tel" class="form-control aw-input" name="sp_tel_${i}" id="sp${i}_tel"

                   ${isFirst ? 'readonly' : ''} placeholder="+39 333 0000000">

          </div>

        </div>

      </div>

    </div>`;

  return div;

}



function initSocialParticipants() {

  const container = $id('social_participants_container');

  if (!container) return;

  container.innerHTML = '';

  socialParticipantCount = 0;

  const block = buildSocialParticipantBlock(0, true);

  container.appendChild(block);

  socialParticipantCount = 1;

  // sincronizza nome/cognome dal referente

  syncReferenteToFirst();

  updateQuotes();

}



function syncReferenteToFirst() {
  syncReferenteToTeamFirst();

  if (currentType === 'team') {
    updateSocialList();
    updateQuotes();
    return;
  }

  if (currentType !== 'social') return;

  const sp0nome    = $id('sp0_nome');

  const sp0cognome = $id('sp0_cognome');

  const sp0email   = $id('sp0_email');

  const sp0tel     = $id('sp0_tel');

  if (sp0nome)    sp0nome.value    = $id('ref_nome')?.value    || '';

  if (sp0cognome) sp0cognome.value = $id('ref_cognome')?.value || '';

  if (sp0email)   sp0email.value   = $id('ref_email')?.value   || '';

  if (sp0tel)     sp0tel.value     = $id('ref_tel')?.value     || '';

}

function syncReferenteToTeamFirst() {
  if (currentType !== 'team') return;
  if (!isReferenteInTeam()) return;

  const p0nome = $id('p0_nome');
  const p0cognome = $id('p0_cognome');
  if (!p0nome || !p0cognome) return;

  p0nome.value = $id('ref_nome')?.value || '';
  p0cognome.value = $id('ref_cognome')?.value || '';
  refreshPlayerBlockState(0, false);
  updateSocialList();
  updateQuotes();
}



function addSocialParticipant() {

  const container = $id('social_participants_container');

  if (!container) return;

  const block = buildSocialParticipantBlock(socialParticipantCount, false);

  container.appendChild(block);

  socialParticipantCount++;

  updateQuotes();

}



function removeSocialParticipant(btn) {

  btn.closest('.aw-social-participant-block')?.remove();

  updateQuotes();

}



function toggleSocialContact(i) {

  const f = $id(`sp_contact_fields_${i}`);

  const b = $id(`btn_sp_ct_${i}`);

  const open = f.classList.contains('d-none');

  f.classList.toggle('d-none', !open);

  b.classList.toggle('open', open);

  b.innerHTML = open

    ? '<i class="fa-solid fa-minus"></i> nascondi contatti'

    : '<i class="fa-solid fa-plus"></i> aggiungi contatti (facoltativo)';

}



/* ════ 13. RACCOLTA DATI → JSON ════════════════════════════════ */



function collectFormData() {

  const type       = $id('registration_type')?.value;

  const socialMode = document.querySelector('input[name="social_mode"]:checked')?.value || null;

  const trMode     = document.querySelector('input[name="transport_mode"]:checked')?.value || null;

  const donation   = parseFloat($id('donation')?.value) || 0;



  // giocatori (solo A)

  const nPlayers = (type === 'team' || type === 'group') ? (parseInt($id('num_players')?.value) || 0) : type === 'individual' ? 1 : 0;

  const players  = [];

  if (type === 'team' || type === 'group') {

    for (let i = 0; i < nPlayers; i++) {

      const band = document.querySelector(`input[name="players[${i}][age_band]"]:checked`)?.value || null;

      const sc   = $id(`social_p${i}`);

      players.push({

        index: i+1,

        first_name: $id(`p${i}_nome`)?.value.trim()    || '',

        last_name:  $id(`p${i}_cognome`)?.value.trim() || '',

        age_band:   band,

        email:      $id(`p${i}_email`)?.value.trim()   || null,

        phone:      $id(`p${i}_tel`)?.value.trim()     || null,

        social: socialMode === 'all' ? true : socialMode === 'some' ? (sc?.checked || false) : false,

      });

    }

  }



  // partecipanti conviviale (solo C)

  const socialParticipants = [];

  if (type === 'social') {

    $id('social_participants_container')?.querySelectorAll('.aw-social-participant-block').forEach((bl, i) => {

      socialParticipants.push({

        index:        i+1,

        first_name:   bl.querySelector(`[name^="sp_nome_"]`)?.value.trim()          || '',

        last_name:    bl.querySelector(`[name^="sp_cognome_"]`)?.value.trim()        || '',

        intolleranze: bl.querySelector(`[name^="sp_intolleranze_"]`)?.value.trim()  || null,

        email:        bl.querySelector(`[name^="sp_email_"]`)?.value.trim()         || null,

        phone:        bl.querySelector(`[name^="sp_tel_"]`)?.value.trim()           || null,

      });

    });

  }



  const nSocial = countSocialForQuotes();

  const totals  = calcQuotes({ nPlayers, nSocial, donation });



  return {

    _meta: { form: type, timestamp: new Date().toISOString() },

    referente: {

      first_name: $id('ref_nome')?.value.trim(),

      last_name:  $id('ref_cognome')?.value.trim(),

      email:      $id('ref_email')?.value.trim(),

      phone:      $id('ref_tel')?.value.trim(),
      in_team:    type === 'team' ? isReferenteInTeam() : null,

      fascia:     document.querySelector('input[name="ref_fascia"]:checked')?.value || null,

      accepted_rules:   $id('acc_regolamento')?.checked,

      accepted_privacy: $id('acc_privacy')?.checked,

    },

    team: type !== 'team' ? null : {

      name:           $id('team_name')?.value.trim(),

      color_pref_1:   $id('color_1')?.value || null,

      color_pref_2:   $id('color_2')?.value || null,

      color_pref_3:   $id('color_3')?.value || null,

      color_custom:   hasCustomColor() ? $id('color_custom_desc')?.value.trim() : null,

      banner_provider: document.querySelector('input[name="banner_provider"]:checked')?.value || 'org',

      banner_notes:   $id('banner_notes')?.value.trim() || null,

    },

    profile: (type !== 'individual' && type !== 'group') ? null : {

      is_scout:      type === 'group' ? null : (document.querySelector('input[name="is_scout"]:checked')?.value || null),

      is_sport:      type === 'group' ? null : (document.querySelector('input[name="is_sport"]:checked')?.value || null),

      sport_desc:    type === 'group' ? null : ($id('sport_desc')?.value.trim() || null),

      profile_notes: $id('profile_notes')?.value.trim()  || null,

      team_pref:     $id('team_pref')?.value.trim()       || null,

    },

    players,

    social_participants: socialParticipants,

    social: {
      mode: socialMode,
      count: nSocial,
      referente_social: type === 'team' && !isReferenteInTeam() ? !!$id('social_ref')?.checked : null,
      food_notes: $id('food_notes')?.value.trim() || null
    },

    transport: type === 'social' ? null : {

      mode:          trMode,

      location:      $id('transport_location')?.value.trim()     || null,

      seats_offered: trMode === 'offer' ? (parseInt($id('transport_seats')?.value)         || null) : null,

      seats_needed:  trMode === 'seek'  ? (parseInt($id('transport_seats_needed')?.value)  || null) : null,

    },

    quotes: {

      n_players: nPlayers, n_social: nSocial,

      total_game: totals.totalGame, total_social: totals.totalSocial,

      total_minimum: totals.totalMinimum, donation, total_final: totals.totalFinal,

    },

  };

}



/* ════ 14. VALIDAZIONE SUBMIT ══════════════════════════════════ */



function validateForm() {

  let ok = true;

  const type = currentType;



  const req = (id, errId, fn) => {

    const el = $id(id);

    if (!el) return;

    const pass = fn(el.value);

    setError(id, !pass); showError(errId, !pass);

    if (!pass) ok = false;

  };



  // Referente comune

  req('ref_nome',    'err_ref_nome',    v => validateText(v, 4));

  req('ref_cognome', 'err_ref_cognome', v => validateText(v, 3));

  req('ref_email',   'err_ref_email',   v => validateEmail(v));

  req('ref_tel',     'err_ref_tel',     v => validatePhone(v));



  // Consensi

  const reg = $id('acc_regolamento')?.checked;

  const prv = $id('acc_privacy')?.checked;

  $id('cb_regolamento')?.classList.toggle('error', !reg);

  $id('cb_privacy')?.classList.toggle('error', !prv);

  showError('err_consents', !reg || !prv);

  if (!reg || !prv) ok = false;



  if (type === 'team') {
    if (!document.querySelector('input[name="ref_in_team"]:checked')) {
      showError('err_ref_in_team', true); ok = false;
    } else {
      showError('err_ref_in_team', false);
    }

    req('color_1',   'err_color_1',   v => !!v);

    if (hasCustomColor()) req('color_custom_desc', 'err_color_custom_desc', v => validateText(v, 1));

    req('team_name', 'err_team_name', v => validateText(v, 3, 20));

    const np = parseInt($id('num_players')?.value);

    if (!np || np < 6 || np > 12) { setError('num_players',true); showError('err_num_players',true); ok=false; }

    else { setError('num_players',false); showError('err_num_players',false); }

    if (!validateComposition()) ok = false;

  }

  if (type === 'group') {
    const np = parseInt($id('num_players')?.value);
    if (!np || np < LIMITS.group.min || np > LIMITS.group.max) { setError('num_players',true); showError('err_num_players',true); ok=false; }
    else { setError('num_players',false); showError('err_num_players',false); }
    if (!validateComposition()) ok = false;
  }



  if (type === 'individual') {

    if (!document.querySelector('input[name="ref_fascia"]:checked')) {

      showError('err_ref_fascia', true); ok = false;

    } else showError('err_ref_fascia', false);

    if (!document.querySelector('input[name="is_scout"]:checked'))

      { showError('err_is_scout',true); ok=false; } else showError('err_is_scout',false);

    if (!document.querySelector('input[name="is_sport"]:checked'))

      { showError('err_is_sport',true); ok=false; } else showError('err_is_sport',false);

  }



  if (type === 'team' || type === 'individual' || type === 'group') {

    if (!document.querySelector('input[name="social_mode"]:checked'))

      { showError('err_social_mode',true); ok=false; } else showError('err_social_mode',false);

    if (!document.querySelector('input[name="transport_mode"]:checked'))

      { showError('err_transport_mode',true); ok=false; } else showError('err_transport_mode',false);

    const trMode = document.querySelector('input[name="transport_mode"]:checked')?.value;

    if (trMode === 'seek') {

      req('transport_location', 'err_transport_location', v => validateText(v,2));

    }

    if (trMode === 'offer') {

      req('transport_location', 'err_transport_location', v => validateText(v,2));

    }



    if (trMode === 'seek')  req('transport_seats_needed', 'err_transport_seats_needed', v => parseInt(v)>=1);

    if (trMode === 'offer') req('transport_seats',        'err_transport_seats',        v => parseInt(v)>=1);

  }



  return ok;

}



/* ════ 15. RIEPILOGO ═══════════════════════════════════════════ */



function rvField(label, value) {

  const v   = (value !== null && value !== undefined && String(value).trim()) ? value : null;

  const cls = v ? 'aw-rv-value' : 'aw-rv-value empty';

  return `<div class="col-12 col-sm-6 aw-rv-field">

    <div class="aw-rv-label">${label}</div>

    <div class="${cls}">${v || '—'}</div>

  </div>`;

}



function buildReview() {

  const data = collectFormData();

  const type = data._meta.form;



  // referente

  $id('rv_referente').innerHTML =

    rvField('Nome',     data.referente.first_name) +

    rvField('Cognome',  data.referente.last_name)  +

    rvField('Email',    data.referente.email)       +

    rvField('Telefono', data.referente.phone)       +

    (type === 'team' ? rvField('Referente nel gruppo', data.referente.in_team ? 'sì' : 'no') : '') +

    (data.referente.fascia ? rvField('Fascia', `${data.referente.fascia} (${BANDS[data.referente.fascia]})`) : '');



  // squadra

  if (type === 'team' && data.team) {

    show('rv_sec_team');

    const colors = [data.team.color_pref_1, data.team.color_pref_2, data.team.color_pref_3].filter(Boolean).join(', ');

    $id('rv_team').innerHTML =

      rvField('Nome squadra',  data.team.name) +

      rvField('Colori',        colors)          +

      rvField('Colore custom', data.team.color_custom) +

      rvField('Stendardo',     data.team.banner_provider === 'team' ? 'a cura della squadra' : 'fornito dall\'org.');

  } else hide('rv_sec_team');



  // giocatori

  if ((type === 'team' || type === 'group') && data.players.length) {

    show('rv_sec_players');

    const rows = data.players.map(p => `

      <tr>

        <td>${p.index}</td>

        <td>${p.first_name} ${p.last_name}</td>

        <td><span class="aw-fascia-badge band-${p.age_band||''}">${p.age_band ? p.age_band+' ('+BANDS[p.age_band]+')' : '—'}</span></td>

        <td>${p.social ? '<i class="fa-solid fa-check text-success"></i>' : '<i class="fa-solid fa-minus text-secondary"></i>'}</td>

      </tr>`).join('');

    $id('rv_players').innerHTML = `<table class="aw-rv-table">

      <thead><tr><th>#</th><th>Nome</th><th>Fascia</th><th>Conv.</th></tr></thead>

      <tbody>${rows}</tbody></table>`;

  } else hide('rv_sec_players');



  // profilo B

  if ((type === 'individual' || type === 'group') && data.profile) {

    show('rv_sec_profile');

    $id('rv_profile').innerHTML =

      (type === 'individual' ? rvField('Scout',     data.profile.is_scout === 'si' ? 'sì' : 'no') : '') +
      (type === 'individual' ? rvField('Sport',     data.profile.is_sport === 'si' ? 'sì' : 'no') : '') +
      (type === 'individual' ? rvField('Dettaglio sport', data.profile.sport_desc) : '') +
      rvField(type === 'group' ? 'Note gruppo' : 'Note', data.profile.profile_notes) +

      rvField('Preferenze squadra', data.profile.team_pref);

  } else hide('rv_sec_profile');



  // conviviale + trasporti

  const hasSocial = type === 'team' || type === 'individual' || type === 'group';

  if (hasSocial) {

    show('rv_sec_social');

    const socialLabels    = { all: 'tutti', some: 'solo alcuni', none: 'no', yes_b: 'sì' };

    const transportLabels = { self: 'mezzi propri', seek: 'cerca passaggio', offer: 'offre passaggi' };

    $id('rv_social').innerHTML =

      rvField('Conviviale',   socialLabels[data.social.mode])    +

      (data.social.count ? rvField('N. partecipanti', data.social.count) : '') +

      rvField('Intolleranze', data.social.food_notes)            +

      rvField('Trasporto',    transportLabels[data.transport?.mode]) +

      (data.transport?.location ? rvField('Luogo partenza', data.transport.location) : '') +

      (data.transport?.seats_offered ? rvField('Posti offerti', data.transport.seats_offered) : '') +

      (data.transport?.seats_needed  ? rvField('Posti necessari', data.transport.seats_needed) : '');

  } else if (type === 'social') {

    show('rv_sec_social');

    const spRows = data.social_participants.map(p => `

      <tr><td>${p.index}</td><td>${p.first_name} ${p.last_name}</td>

      <td>${p.intolleranze||'—'}</td></tr>`).join('');

    $id('rv_social').innerHTML = `<div class="col-12">

      <table class="aw-rv-table">

        <thead><tr><th>#</th><th>Nome</th><th>Intolleranze</th></tr></thead>

        <tbody>${spRows}</tbody>

      </table></div>`;

  } else hide('rv_sec_social');



  // quote

  $id('rv_quotes').innerHTML =

    (type !== 'social' ? rvField('Giocatori', `${data.quotes.n_players} × ${CFG.priceGame} €`) : '') +

    rvField('Quota ' + (type === 'social' ? 'conv.' : 'gioco'), `€ ${fmt(type === 'social' ? data.quotes.total_social : data.quotes.total_game)}`) +

    (type !== 'social' ? rvField('Quota conviviale', `€ ${fmt(data.quotes.total_social)}`) : '') +

    (data.quotes.donation > 0 ? rvField('Donazione', `€ ${fmt(data.quotes.donation)}`) : '');

  $id('rv_total_final').textContent = `€ ${fmt(data.quotes.total_final)}`;

}



/* ════ 16. INVIO, LOADER, MESSAGE BOX ══════════════════════════ */



function showLoader()  { $id('loader_overlay').classList.add('show'); }

function hideLoader()  { $id('loader_overlay').classList.remove('show'); }

function showSuccess() { $id('msg_success').classList.add('show'); }

function showFail()    { $id('msg_error').classList.add('show'); }



function submitForm() {

  // Prevenzione invii multipli: controlla se form già inviato con successo
  const submittedKey = `aw_submitted_${window.alientuConfig?.campaign_id || 'default'}`;
  if (sessionStorage.getItem(submittedKey)) {
    alert('Hai già inviato un\'iscrizione in questa sessione. Ricarica la pagina se vuoi inviarne un\'altra.');
    return;
  }


  const data = collectFormData();



  // Aggiunge campaign_id e nonce dal config iniettato dallo shortcode PHP

  if ( window.alientuConfig?.campaign_id ) {

    data._meta.campaign_id = window.alientuConfig.campaign_id;

  }



  showLoader();



  fetch( window.alientuConfig?.restUrl || '/wp-json/aw/v1/register', {

    method:  'POST',

    headers: {

      'Content-Type': 'application/json',

      'X-WP-Nonce':   window.alientuConfig?.nonce || '',

    },

    body: JSON.stringify( data ),

  } )

    .then( res => res.json() )

    .then( json => {

      hideLoader();

      if ( json.success ) {

        // Marca come inviato in sessionStorage
        const submittedKey = `aw_submitted_${window.alientuConfig?.campaign_id || 'default'}`;
        sessionStorage.setItem(submittedKey, 'true');

        // Mostra codice iscrizione nella box successo se presente

        const codeEl = document.getElementById( 'msg_success_code' );

        if ( codeEl && json.registration_code ) {

          codeEl.textContent = json.registration_code;

          codeEl.closest( '.aw-registration-code' )?.classList.remove( 'd-none' );

        }

        showSuccess();

      } else {

        // Mostra errori server nel message box errore

        const errEl = document.getElementById( 'msg_error_detail' );

        if ( errEl && json.errors?.length ) {

          errEl.textContent = json.errors.join( ' ' );

        }

        showFail();

      }

    } )

    .catch( () => {

      hideLoader();

      showFail();

    } );

}



/* ════ 17. INIT ════════════════════════════════════════════════ */



document.addEventListener('DOMContentLoaded', function () {



  // click card — sia sulla card che sul bottone

  $all('.aw-type-card').forEach(card => {

    card.addEventListener('click', function (e) {

      if (e.target.closest('.aw-card-btn')) return;

      activateType(this.dataset.type);

    });

  });

  $all('.aw-card-btn').forEach(btn => {

    btn.addEventListener('click', function (e) {

      e.stopPropagation();

      activateType(this.closest('.aw-type-card').dataset.type);

    });

  });



  // back bar

  $id('btn_back')?.addEventListener('click', () => goToStep(1));



  // Form A — colori

  ['color_1','color_2','color_3'].forEach(id => $id(id)?.addEventListener('change', syncColorSelects));



  // Form A — stendardo

  $all('input[name="banner_provider"]').forEach(r => r.addEventListener('change', function () {

    $id('field_banner_notes')?.classList.toggle('d-none', this.value !== 'team');

  }));



  // Form A — numero giocatori

  $id('num_players')?.addEventListener('input', function () {

    const v = parseInt(this.value);
    const lim = currentType === 'group' ? LIMITS.group : LIMITS.team;

    if (v >= lim.min && v <= lim.max) {
      renderPlayers(v);
      validateComposition();
      setError('num_players',false);
      showError('err_num_players',false);
    } else {
      renderPlayers(0);
    }

  });

  $all('input[name="ref_in_team"]').forEach(r => r.addEventListener('change', function () {
    showError('err_ref_in_team', false);
    syncReferenteToTeamFirst();
    updateSocialList();
    updateQuotes();
  }));



  // Form A/B — conviviale

  $all('input[name="social_mode"]').forEach(r => r.addEventListener('change', updateSocialList));



  // Form B — sport

  $id('sport_si')?.addEventListener('change', () => toggleSportDesc(true));

  $id('sport_no')?.addEventListener('change', () => toggleSportDesc(false));



  // Form A/B — trasporti

  initTransportListeners();



  // donazione + causale

  $id('donation')?.addEventListener('input', updateQuotes);

  $id('ref_cognome')?.addEventListener('input', () => { updateCausale(); syncReferenteToFirst(); });

  ['ref_nome','ref_email','ref_tel'].forEach(id => {

    $id(id)?.addEventListener('input', syncReferenteToFirst);

  });



  // Form C — aggiungi partecipante

  $id('btn_add_social_participant')?.addEventListener('click', addSocialParticipant);



  // step 2 → step 3

  $id('btn_to_review')?.addEventListener('click', function () {

    if (!validateForm()) {

      const items = collectVisibleErrors();

      showValidationBox(items.length ? items : ['Controlla i campi evidenziati.']);

      return;

    }

    goToStep(3);

  });





  // step 3 → invio

  $id('btn_confirm_send')?.addEventListener('click', submitForm);



  $id('btn_validation_close')?.addEventListener('click', hideValidationBox);

  $id('btn_validation_goto')?.addEventListener('click', function () {

    hideValidationBox();

    focusFirstError();

  });





});


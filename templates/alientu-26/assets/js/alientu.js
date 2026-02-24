/**
 * Alientu 2026 — Form di iscrizione
 * Logica completa per il form multistep
 */

// ═════════════════════════════════════════════════════════════════════════════
// CONFIGURAZIONE GLOBALE
// ═════════════════════════════════════════════════════════════════════════════

const CONFIG = {
  prices: {
    game: 3.00,
    social: 5.00
  },
  teams: {
    minPlayers: 6,
    maxPlayers: 12,
    minAges: 3,
    minPerAge: 2
  },
  ages: {
    A: '8–11',
    B: '11–17',
    C: '17–39',
    D: '39+'
  }
};

// Stato globale del form
let formState = {
  type: null,
  referente: {},
  team: {},
  players: [],
  profile: {},
  social: { mode: null, participants: [] },
  socialParticipants: [],
  transport: {},
  donation: 0,
  errors: {}
};

// ═════════════════════════════════════════════════════════════════════════════
// INIZIALIZZAZIONE
// ═════════════════════════════════════════════════════════════════════════════

document.addEventListener('DOMContentLoaded', function() {
  setupEventListeners();
});

function setupEventListeners() {
  // Step 1 — Type selection
  document.querySelectorAll('.aw-type-card').forEach(card => {
    card.addEventListener('click', function() {
      selectType(this.dataset.type);
    });
  });

  // Back button
  document.getElementById('btn_back').addEventListener('click', goBackToType);

  // Form submission
  document.getElementById('btn_to_review').addEventListener('click', goToReview);
  document.getElementById('btn_confirm_send').addEventListener('click', submitForm);

  // Validation messages close buttons
  document.getElementById('btn_validation_close').addEventListener('click', function() {
    document.getElementById('msg_validation').classList.remove('show');
  });

  document.getElementById('btn_validation_goto').addEventListener('click', function() {
    const firstError = document.querySelector('[aria-invalid="true"]');
    if (firstError) {
      firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
  });

  // Transport mode change
  document.querySelectorAll('input[name="transport_mode"]').forEach(radio => {
    radio.addEventListener('change', updateTransportFields);
  });

  // Sport practice
  document.querySelectorAll('input[name="is_sport"]').forEach(radio => {
    radio.addEventListener('change', updateSportFields);
  });

  // Social mode change
  document.querySelectorAll('input[name="social_mode"]').forEach(radio => {
    radio.addEventListener('change', updateSocialFields);
  });

  // Donation input
  document.getElementById('donation').addEventListener('input', recalculateQuotes);

  // Number of players
  document.getElementById('num_players').addEventListener('change', updatePlayerBlocks);

  // Banner provider
  document.querySelectorAll('input[name="banner_provider"]').forEach(radio => {
    radio.addEventListener('change', updateBannerFields);
  });

  // Color selection
  document.querySelectorAll('select[name^="color_"]').forEach(select => {
    select.addEventListener('change', updateColorFields);
  });

  // Add social participant button
  document.getElementById('btn_add_social_participant').addEventListener('click', addSocialParticipant);
}

// ═════════════════════════════════════════════════════════════════════════════
// STEP 1 — TYPE SELECTION
// ═════════════════════════════════════════════════════════════════════════════

function selectType(type) {
  formState.type = type;
  formState.players = [];
  formState.socialParticipants = [];

  // Update UI
  updateStepIndicator(2);
  document.getElementById('section_type').classList.add('d-none');
  document.getElementById('section_form').classList.remove('d-none');
  document.getElementById('back_bar').classList.remove('d-none');

  // Update selected type display
  const labels = {
    team: 'Iscrizione squadra',
    individual: 'Iscrizione individuale',
    social: 'Solo conviviale'
  };
  document.querySelector('.aw-selected-type').textContent = labels[type] || '';
  document.getElementById('registration_type').value = type;

  // Show/hide sections based on type
  updateFormSections(type);

  // Set intro text
  updateIntroText(type);

  // Initialize fields
  initializeFormFields(type);

  // Scroll to top
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

function updateFormSections(type) {
  // Common sections
  document.getElementById('sec_referente').classList.remove('d-none');
  document.getElementById('sec_transport').classList.remove('d-none');
  document.getElementById('sec_social').classList.remove('d-none');
  document.getElementById('sec_quotes').classList.remove('d-none');

  // Hide all type-specific sections first
  document.getElementById('sec_team_identity').classList.add('d-none');
  document.getElementById('sec_composition').classList.add('d-none');
  document.getElementById('sec_profile').classList.add('d-none');
  document.getElementById('sec_social_participants').classList.add('d-none');

  if (type === 'team') {
    document.getElementById('sec_team_identity').classList.remove('d-none');
    document.getElementById('sec_composition').classList.remove('d-none');
    document.getElementById('num_referente').textContent = '1';
    document.getElementById('num_social').textContent = 'A5';
    document.getElementById('num_transport').textContent = 'A6';
    document.getElementById('num_quotes').textContent = 'A7';
    document.querySelector('#label_social_question').textContent = 'volete partecipare alla salsicciata?';
    document.getElementById('field_ref_fascia').classList.add('d-none');
  } else if (type === 'individual') {
    document.getElementById('sec_profile').classList.remove('d-none');
    document.getElementById('num_referente').textContent = '1';
    document.getElementById('num_social').textContent = 'B3';
    document.getElementById('num_transport').textContent = 'B4';
    document.getElementById('num_quotes').textContent = 'B5';
    document.querySelector('#label_social_question').textContent = 'vuoi partecipare alla salsicciata?';
    document.getElementById('field_ref_fascia').classList.remove('d-none');
    updateSocialOptions(type);
  } else if (type === 'social') {
    document.getElementById('sec_social_participants').classList.remove('d-none');
    document.getElementById('sec_transport').classList.add('d-none');
    document.getElementById('sec_social').classList.add('d-none');
    document.getElementById('num_quotes').textContent = 'C3';
  }
}

function updateIntroText(type) {
  const texts = {
    team: {
      h2: 'Iscrivi la tua squadra al grande gioco',
      p: 'Per essere valida, la squadra deve rispettare questi requisiti: 6–12 partecipanti, almeno 3 fasce d\'età diverse, almeno 2 per fascia.'
    },
    individual: {
      h2: 'Iscriviti come singolo partecipante',
      p: 'Ti inseriremo noi in una squadra, cercando la combinazione migliore in base a fascia d\'età e profilo.'
    },
    social: {
      h2: 'Iscrizione al momento conviviale',
      p: 'Inserisci i dati del referente e di tutti i partecipanti. La quota è di 5 € a persona.'
    }
  };

  const intro = document.getElementById('form_intro');
  intro.innerHTML = `<h3>${texts[type].h2}</h3><p>${texts[type].p}</p>`;
}

function initializeFormFields(type) {
  // Reset all form fields
  document.getElementById('aw-form').reset();

  // Initialize quotes
  recalculateQuotes();
}

function updateSocialOptions(type) {
  // Show/hide social options based on type
  const allOption = document.getElementById('social_opt_all');
  const someOption = document.getElementById('social_opt_some');
  const yesOption = document.getElementById('social_opt_yes');

  if (type === 'team') {
    allOption.classList.remove('d-none');
    someOption.classList.remove('d-none');
    yesOption.classList.add('d-none');
  } else if (type === 'individual') {
    allOption.classList.add('d-none');
    someOption.classList.add('d-none');
    yesOption.classList.remove('d-none');
  }
}

// ═════════════════════════════════════════════════════════════════════════════
// CONDITIONAL FIELD UPDATES
// ═════════════════════════════════════════════════════════════════════════════

function updatePlayerBlocks() {
  const numPlayers = parseInt(document.getElementById('num_players').value) || 0;
  const container = document.getElementById('players_container');
  container.innerHTML = '';

  if (numPlayers < 6 || numPlayers > 12) {
    return;
  }

  for (let i = 0; i < numPlayers; i++) {
    const playerBlock = createPlayerBlock(i);
    container.appendChild(playerBlock);
  }

  // Update validation panel
  updateCompositionValidation();
}

function createPlayerBlock(index) {
  const div = document.createElement('div');
  div.className = 'aw-player-block';
  div.dataset.playerIndex = index;

  const num = index + 1;
  div.innerHTML = `
    <div class="aw-player-header">
      <div class="aw-player-num">${num}</div>
      ${index > 0 ? '<button type="button" class="aw-player-remove" data-action="remove-player" data-index="' + index + '">−</button>' : ''}
    </div>
    <div class="aw-player-body">
      <div>
        <span class="aw-player-input-label">Nome</span>
        <input type="text" class="form-control" placeholder="es. Marco" data-player-first-name="${index}" minlength="2">
      </div>
      <div>
        <span class="aw-player-input-label">Cognome</span>
        <input type="text" class="form-control" placeholder="es. Rossi" data-player-last-name="${index}" minlength="2">
      </div>
      <div>
        <span class="aw-player-input-label">Fascia d'età</span>
        <select class="form-select" data-player-age="${index}">
          <option value="">— scegli —</option>
          <option value="A">A (8–11)</option>
          <option value="B">B (11–17)</option>
          <option value="C">C (17–39)</option>
          <option value="D">D (39+)</option>
        </select>
      </div>
    </div>
  `;

  // Event listeners
  const removeBtn = div.querySelector('[data-action="remove-player"]');
  if (removeBtn) {
    removeBtn.addEventListener('click', function() {
      removePlayer(index);
    });
  }

  // Listen to changes for validation
  const inputs = div.querySelectorAll('input, select');
  inputs.forEach(input => {
    input.addEventListener('change', updateCompositionValidation);
    input.addEventListener('input', updateCompositionValidation);
  });

  return div;
}

function removePlayer(index) {
  const numPlayers = parseInt(document.getElementById('num_players').value) || 0;
  document.getElementById('num_players').value = numPlayers - 1;
  updatePlayerBlocks();
}

function updateCompositionValidation() {
  const players = collectPlayers();
  const panel = document.getElementById('validation_panel');
  const list = document.getElementById('validation_list');

  if (players.length === 0) {
    panel.classList.add('d-none');
    return;
  }

  panel.classList.remove('d-none');
  list.innerHTML = '';

  // Count ages
  const ageCounts = { A: 0, B: 0, C: 0, D: 0 };
  players.forEach(p => {
    if (p.age_band && ageCounts.hasOwnProperty(p.age_band)) {
      ageCounts[p.age_band]++;
    }
  });

  const presentAges = Object.entries(ageCounts).filter(([, count]) => count > 0);

  // Validation items
  const items = [
    { label: `Totale: ${players.length}`, valid: players.length >= 6 && players.length <= 12 },
    { label: `Fasce d'età: ${presentAges.length}`, valid: presentAges.length >= 3 },
    ...presentAges.map(([age, count]) => ({
      label: `Fascia ${age}: ${count} partecipanti`,
      valid: count >= 2
    }))
  ];

  items.forEach(item => {
    const li = document.createElement('li');
    li.className = item.valid ? '' : 'error';
    li.textContent = item.label;
    list.appendChild(li);
  });
}

function updateTransportFields() {
  const mode = document.querySelector('input[name="transport_mode"]:checked')?.value;

  const locationField = document.getElementById('field_transport_location');
  const seatsNeededField = document.getElementById('field_transport_seats_needed');
  const seatsField = document.getElementById('field_transport_seats');

  if (mode === 'seek') {
    locationField.classList.remove('d-none');
    seatsNeededField.classList.remove('d-none');
    seatsField.classList.add('d-none');
  } else if (mode === 'offer') {
    locationField.classList.remove('d-none');
    seatsNeededField.classList.add('d-none');
    seatsField.classList.remove('d-none');
  } else {
    locationField.classList.add('d-none');
    seatsNeededField.classList.add('d-none');
    seatsField.classList.add('d-none');
  }
}

function updateSportFields() {
  const isSport = document.querySelector('input[name="is_sport"]:checked')?.value === 'si';
  const sportDescField = document.getElementById('field_sport_desc');

  if (isSport) {
    sportDescField.classList.remove('d-none');
  } else {
    sportDescField.classList.add('d-none');
  }
}

function updateSocialFields() {
  const mode = document.querySelector('input[name="social_mode"]:checked')?.value;
  const playersListField = document.getElementById('social_players_list');
  const foodNotesField = document.getElementById('field_food_notes');

  if (formState.type === 'team' && mode === 'some') {
    playersListField.classList.remove('d-none');
    renderSocialPlayersList();
  } else {
    playersListField.classList.add('d-none');
  }

  if (mode === 'all' || mode === 'some' || mode === 'yes_b') {
    foodNotesField.classList.remove('d-none');
  } else {
    foodNotesField.classList.add('d-none');
  }

  recalculateQuotes();
}

function updateBannerFields() {
  const provider = document.querySelector('input[name="banner_provider"]:checked')?.value;
  const bannerNotesField = document.getElementById('field_banner_notes');

  if (provider === 'team') {
    bannerNotesField.classList.remove('d-none');
  } else {
    bannerNotesField.classList.add('d-none');
  }
}

function updateColorFields() {
  const customField = document.getElementById('field_custom_color');
  const hasCustom = document.getElementById('color_1').value === 'custom' ||
                    document.getElementById('color_2').value === 'custom' ||
                    document.getElementById('color_3').value === 'custom';

  if (hasCustom) {
    customField.classList.remove('d-none');
  } else {
    customField.classList.add('d-none');
  }
}

function renderSocialPlayersList() {
  const players = collectPlayers();
  const container = document.getElementById('social_players_list');
  container.innerHTML = '<label style="display:block; margin-bottom:1rem; font-weight:600;">Chi parteciperà alla salsicciata?</label>';

  if (players.length === 0) {
    return;
  }

  players.forEach((player, index) => {
    const playerName = `${player.first_name || '?'} ${player.last_name || '?'}`;
    const div = document.createElement('div');
    div.className = 'aw-social-player-item';
    div.innerHTML = `
      <input type="checkbox" id="social_player_${index}" name="social_player" value="${index}">
      <label for="social_player_${index}">${playerName}</label>
    `;
    container.appendChild(div);
  });
}

function recalculateQuotes() {
  const type = formState.type;
  const donation = parseFloat(document.getElementById('donation').value) || 0;

  let numGame = 0;
  let numSocial = 0;

  if (type === 'team') {
    const players = collectPlayers();
    numGame = players.length;

    const mode = document.querySelector('input[name="social_mode"]:checked')?.value;
    if (mode === 'all') {
      numSocial = players.length;
    } else if (mode === 'some') {
      const checkedPlayers = document.querySelectorAll('input[name="social_player"]:checked');
      numSocial = checkedPlayers.length;
    }
  } else if (type === 'individual') {
    numGame = 1;
    const mode = document.querySelector('input[name="social_mode"]:checked')?.value;
    if (mode === 'yes_b') {
      numSocial = 1;
    }
  } else if (type === 'social') {
    numSocial = collectSocialParticipants().length;
  }

  const totalGame = numGame * CONFIG.prices.game;
  const totalSocial = numSocial * CONFIG.prices.social;
  const totalMin = totalGame + totalSocial;
  const totalFinal = totalMin + donation;

  document.getElementById('q_n_players').textContent = numGame;
  document.getElementById('q_total_game').textContent = totalGame.toFixed(2);
  document.getElementById('q_n_social').textContent = numSocial;
  document.getElementById('q_total_social').textContent = totalSocial.toFixed(2);
  document.getElementById('q_total_min').textContent = totalMin.toFixed(2);
  document.getElementById('q_total_final').textContent = totalFinal.toFixed(2);

  // Show/hide game row
  if (type === 'social') {
    document.getElementById('qrow_game').classList.add('d-none');
  } else {
    document.getElementById('qrow_game').classList.remove('d-none');
  }
}

function addSocialParticipant() {
  const container = document.getElementById('social_participants_container');
  const numParticipants = container.children.length + 1;

  const block = createParticipantBlock(numParticipants);
  container.appendChild(block);
}

function createParticipantBlock(index) {
  const div = document.createElement('div');
  div.className = 'aw-participant-block';
  div.dataset.participantIndex = index;

  div.innerHTML = `
    <div class="aw-participant-header">
      <div class="aw-participant-num">${index}</div>
      ${index > 1 ? '<button type="button" class="aw-participant-remove" data-action="remove-participant" data-index="' + (index - 1) + '">−</button>' : ''}
    </div>
    <div class="aw-participant-body">
      <div>
        <label class="aw-label">Nome <span class="aw-req">*</span></label>
        <input type="text" class="form-control" placeholder="es. Marco" data-social-first-name="${index - 1}" minlength="2" required>
      </div>
      <div>
        <label class="aw-label">Cognome <span class="aw-req">*</span></label>
        <input type="text" class="form-control" placeholder="es. Rossi" data-social-last-name="${index - 1}" minlength="2" required>
      </div>
    </div>
  `;

  const removeBtn = div.querySelector('[data-action="remove-participant"]');
  if (removeBtn) {
    removeBtn.addEventListener('click', function() {
      removeSocialParticipant(index - 1);
    });
  }

  const inputs = div.querySelectorAll('input');
  inputs.forEach(input => {
    input.addEventListener('change', recalculateQuotes);
  });

  return div;
}

function removeSocialParticipant(index) {
  const container = document.getElementById('social_participants_container');
  const blocks = container.querySelectorAll('.aw-participant-block');
  if (blocks.length > 1) {
    blocks[index]?.remove();
    recalculateQuotes();
  }
}

// ═════════════════════════════════════════════════════════════════════════════
// FORM DATA COLLECTION
// ═════════════════════════════════════════════════════════════════════════════

function collectFormData() {
  const data = {
    _meta: { form: formState.type },
    referente: {
      first_name: document.getElementById('ref_nome').value,
      last_name: document.getElementById('ref_cognome').value,
      email: document.getElementById('ref_email').value,
      phone: document.getElementById('ref_tel').value,
      fascia: document.querySelector('input[name="ref_fascia"]:checked')?.value || '',
      accepted_rules: document.getElementById('acc_regolamento').checked,
      accepted_privacy: document.getElementById('acc_privacy').checked
    }
  };

  if (formState.type === 'team') {
    data.team = {
      name: document.getElementById('team_name').value,
      color_pref_1: document.getElementById('color_1').value,
      color_pref_2: document.getElementById('color_2').value,
      color_pref_3: document.getElementById('color_3').value,
      color_custom: document.getElementById('color_custom_desc')?.value || '',
      banner_provider: document.querySelector('input[name="banner_provider"]:checked')?.value || 'org',
      banner_notes: document.getElementById('banner_notes')?.value || ''
    };
    data.players = collectPlayers();
  } else if (formState.type === 'individual') {
    data.profile = {
      is_scout: document.querySelector('input[name="is_scout"]:checked')?.value || '',
      is_sport: document.querySelector('input[name="is_sport"]:checked')?.value || '',
      sport_desc: document.getElementById('sport_desc')?.value || '',
      profile_notes: document.getElementById('profile_notes')?.value || '',
      team_pref: document.getElementById('team_pref')?.value || ''
    };
  }

  if (formState.type !== 'social') {
    data.social = {
      mode: document.querySelector('input[name="social_mode"]:checked')?.value || '',
      food_notes: document.getElementById('food_notes')?.value || ''
    };
    data.transport = {
      mode: document.querySelector('input[name="transport_mode"]:checked')?.value || '',
      location: document.getElementById('transport_location')?.value || '',
      seats_needed: document.getElementById('transport_seats_needed')?.value || '',
      seats_offered: document.getElementById('transport_seats')?.value || ''
    };
  } else {
    data.social_participants = collectSocialParticipants();
  }

  data.donation = parseFloat(document.getElementById('donation').value) || 0;

  return data;
}

function collectPlayers() {
  const players = [];
  document.querySelectorAll('.aw-player-block').forEach((block, index) => {
    players.push({
      first_name: block.querySelector(`[data-player-first-name="${index}"]`)?.value || '',
      last_name: block.querySelector(`[data-player-last-name="${index}"]`)?.value || '',
      age_band: block.querySelector(`[data-player-age="${index}"]`)?.value || ''
    });
  });
  return players;
}

function collectSocialParticipants() {
  const participants = [];
  const referente = {
    first_name: document.getElementById('ref_nome').value,
    last_name: document.getElementById('ref_cognome').value
  };
  participants.push(referente);

  document.querySelectorAll('.aw-participant-block').forEach((block, index) => {
    if (index > 0) {
      participants.push({
        first_name: block.querySelector(`[data-social-first-name="${index}"]`)?.value || '',
        last_name: block.querySelector(`[data-social-last-name="${index}"]`)?.value || ''
      });
    }
  });
  return participants;
}

// ═════════════════════════════════════════════════════════════════════════════
// FORM VALIDATION
// ═════════════════════════════════════════════════════════════════════════════

function validateForm() {
  const data = collectFormData();
  const errors = [];

  // Referente validation
  if (!data.referente.first_name || data.referente.first_name.length < 4) {
    errors.push('Nome referente: minimo 4 caratteri');
  }
  if (!data.referente.last_name || data.referente.last_name.length < 3) {
    errors.push('Cognome referente: minimo 3 caratteri');
  }
  if (!isValidEmail(data.referente.email)) {
    errors.push('Email: inserisci un indirizzo valido');
  }
  if (!isValidPhone(data.referente.phone)) {
    errors.push('Telefono: inserisci un numero valido');
  }
  if (!data.referente.accepted_rules || !data.referente.accepted_privacy) {
    errors.push('Devi accettare regolamento e privacy');
  }

  if (formState.type === 'team') {
    if (!data.team.name || data.team.name.length < 3 || data.team.name.length > 20) {
      errors.push('Nome squadra: tra 3 e 20 caratteri');
    }
    if (!data.team.color_pref_1 && !data.team.color_custom) {
      errors.push('Seleziona almeno un colore');
    }
    if (!data.players || data.players.length < 6 || data.players.length > 12) {
      errors.push(`Squadra: tra 6 e 12 partecipanti (attuali: ${data.players.length})`);
    }
    if (!data.social.mode) {
      errors.push('Indica se parteciperete alla salsicciata');
    }
    if (!data.transport.mode) {
      errors.push('Indica la modalità di trasporto');
    }
  } else if (formState.type === 'individual') {
    if (!data.referente.fascia) {
      errors.push('Seleziona la tua fascia d\'età');
    }
    if (!data.profile.is_scout) {
      errors.push('Indica se sei stato scout');
    }
    if (!data.profile.is_sport) {
      errors.push('Indica se pratichi sport');
    }
    if (!data.social.mode) {
      errors.push('Indica se parteciperai alla salsicciata');
    }
    if (!data.transport.mode) {
      errors.push('Indica la modalità di trasporto');
    }
  } else if (formState.type === 'social') {
    if (!data.social_participants || data.social_participants.length < 1) {
      errors.push('Inserisci almeno un partecipante');
    }
  }

  return errors;
}

function isValidEmail(email) {
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
}

function isValidPhone(phone) {
  const digits = phone.replace(/\D/g, '');
  return digits.length >= 8 && digits.length <= 15;
}

// ═════════════════════════════════════════════════════════════════════════════
// STEP 2 → STEP 3 (REVIEW)
// ═════════════════════════════════════════════════════════════════════════════

function goToReview() {
  const errors = validateForm();

  if (errors.length > 0) {
    showValidationErrors(errors);
    return;
  }

  updateStepIndicator(3);
  document.getElementById('section_form').classList.add('d-none');
  document.getElementById('section_review').classList.remove('d-none');

  renderReview();
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

function renderReview() {
  const data = collectFormData();

  // Referente
  const rvReferente = document.getElementById('rv_referente');
  rvReferente.innerHTML = `
    <div class="col-12"><strong>Nome:</strong> ${data.referente.first_name}</div>
    <div class="col-12"><strong>Cognome:</strong> ${data.referente.last_name}</div>
    <div class="col-12"><strong>Email:</strong> ${data.referente.email}</div>
    <div class="col-12"><strong>Telefono:</strong> ${data.referente.phone}</div>
  `;

  if (formState.type === 'team') {
    // Team info
    const rvTeam = document.getElementById('rv_sec_team');
    document.getElementById('rv_team').innerHTML = `
      <div class="col-12"><strong>Nome squadra:</strong> ${data.team.name}</div>
      <div class="col-12"><strong>Colore 1:</strong> ${data.team.color_pref_1}</div>
      ${data.team.color_pref_2 ? `<div class="col-12"><strong>Colore 2:</strong> ${data.team.color_pref_2}</div>` : ''}
      ${data.team.color_pref_3 ? `<div class="col-12"><strong>Colore 3:</strong> ${data.team.color_pref_3}</div>` : ''}
      ${data.team.color_custom ? `<div class="col-12"><strong>Colore custom:</strong> ${data.team.color_custom}</div>` : ''}
    `;
    rvTeam.classList.remove('d-none');

    // Players
    const rvPlayers = document.getElementById('rv_sec_players');
    let playersHtml = '';
    data.players.forEach((p, i) => {
      playersHtml += `<div style="margin-bottom:0.5rem;"><strong>${i + 1}. ${p.first_name} ${p.last_name}</strong> — Fascia ${p.age_band}</div>`;
    });
    document.getElementById('rv_players').innerHTML = playersHtml;
    rvPlayers.classList.remove('d-none');
  }

  if (formState.type === 'individual') {
    const rvProfile = document.getElementById('rv_sec_profile');
    document.getElementById('rv_profile').innerHTML = `
      <div class="col-12"><strong>Fascia d'età:</strong> ${data.referente.fascia}</div>
      <div class="col-12"><strong>Scout:</strong> ${data.profile.is_scout === 'si' ? 'Sì' : 'No'}</div>
      <div class="col-12"><strong>Sport:</strong> ${data.profile.is_sport === 'si' ? 'Sì' : 'No'}</div>
    `;
    rvProfile.classList.remove('d-none');
  }

  if (formState.type !== 'social') {
    const rvSocial = document.getElementById('rv_sec_social');
    let socialText = data.social.mode;
    if (data.social.mode === 'all') socialText = 'Sì, tutti';
    else if (data.social.mode === 'some') socialText = 'Sì, alcuni';
    else if (data.social.mode === 'none') socialText = 'No';

    document.getElementById('rv_social').innerHTML = `
      <div class="col-12"><strong>Conviviale:</strong> ${socialText}</div>
      <div class="col-12"><strong>Trasporti:</strong> ${data.transport.mode}</div>
    `;
    rvSocial.classList.remove('d-none');
  }

  // Quotes
  document.getElementById('rv_quotes').innerHTML = `
    <div class="col-12"><strong>Subtotale:</strong> € ${(parseFloat(document.getElementById('q_total_min').textContent) || 0).toFixed(2)}</div>
    <div class="col-12"><strong>Donazione:</strong> € ${data.donation.toFixed(2)}</div>
  `;
  document.getElementById('rv_total_final').textContent = `€ ${(parseFloat(document.getElementById('q_total_final').textContent) || 0).toFixed(2)}`;
}

function goToStep(step) {
  if (step === 2) {
    updateStepIndicator(2);
    document.getElementById('section_form').classList.remove('d-none');
    document.getElementById('section_review').classList.add('d-none');
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }
}

// ═════════════════════════════════════════════════════════════════════════════
// FORM SUBMISSION
// ═════════════════════════════════════════════════════════════════════════════

async function submitForm() {
  const loader = document.getElementById('loader_overlay');
  loader.classList.add('show');

  try {
    const data = collectFormData();
    const response = await fetch('/wp-json/aw/v1/register', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': document.querySelector('input[name="_wpnonce"]')?.value || ''
      },
      body: JSON.stringify(data)
    });

    loader.classList.remove('show');

    if (response.ok) {
      const result = await response.json();
      document.getElementById('msg_success').classList.add('show');
      document.getElementById('section_review').classList.add('d-none');
    } else {
      document.getElementById('msg_error').classList.add('show');
    }
  } catch (error) {
    loader.classList.remove('show');
    document.getElementById('msg_error').classList.add('show');
  }
}

// ═════════════════════════════════════════════════════════════════════════════
// UTILITIES
// ═════════════════════════════════════════════════════════════════════════════

function goBackToType() {
  updateStepIndicator(1);
  document.getElementById('section_form').classList.add('d-none');
  document.getElementById('section_type').classList.remove('d-none');
  document.getElementById('back_bar').classList.add('d-none');
  formState = { type: null, players: [], socialParticipants: [], errors: {} };
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

function updateStepIndicator(step) {
  document.querySelectorAll('.aw-step').forEach((el, i) => {
    if (i + 1 <= step) {
      el.classList.add('active');
    } else {
      el.classList.remove('active');
    }
  });
}

function showValidationErrors(errors) {
  const list = document.getElementById('validation_summary_list');
  list.innerHTML = '';
  errors.forEach(error => {
    const li = document.createElement('li');
    li.textContent = error;
    list.appendChild(li);
  });
  document.getElementById('msg_validation').classList.add('show');
}

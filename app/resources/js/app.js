import JsSIP from 'jssip';

const initializeAdminNavigation = () => {
    const shell = document.querySelector('.app-shell');
    const sidebar = document.querySelector('#adminSidebar');
    const toggle = document.querySelector('#sidebarToggle');
    if (!shell || !sidebar || !toggle) return;

    const applyExpanded = (expanded) => {
        shell.classList.toggle('sidebar-expanded', expanded);
        sidebar.classList.toggle('expanded', expanded);
        toggle.setAttribute('aria-expanded', String(expanded));
        toggle.setAttribute('aria-label', expanded ? 'Recolher menu' : 'Expandir menu');
    };
    applyExpanded(window.localStorage.getItem('thconect-sidebar') === 'expanded');
    toggle.addEventListener('click', () => {
        const expanded = !sidebar.classList.contains('expanded');
        applyExpanded(expanded);
        window.localStorage.setItem('thconect-sidebar', expanded ? 'expanded' : 'collapsed');
    });
    sidebar.querySelectorAll('.side-menu-trigger').forEach((trigger) => trigger.addEventListener('click', () => {
        const menu = trigger.closest('.side-menu');
        if (window.matchMedia('(max-width: 760px)').matches) {
            const destination = menu?.querySelector('.side-submenu a')?.href;
            if (destination) window.location.assign(destination);
            return;
        }
        const open = !menu.classList.contains('open');
        menu.classList.toggle('open', open);
        trigger.setAttribute('aria-expanded', String(open));
        if (!sidebar.classList.contains('expanded')) {
            applyExpanded(true);
            window.localStorage.setItem('thconect-sidebar', 'expanded');
        }
    }));
};

const initializeAdminContextModals = () => {
    const admin = document.querySelector('.pbx-admin');
    if (!admin) return;

    document.querySelector('.pbx-rail')?.setAttribute('id', 'visao-geral');
    const createPanels = document.querySelectorAll('.pbx-form-grid > .panel');
    ['nova-rota', 'nova-empresa', 'usuarios-ramais'].forEach((id, index) => createPanels[index]?.setAttribute('id', id));
    document.querySelectorAll('.pbx-registry')[1]?.setAttribute('id', 'diagnostico');

    const modal = document.createElement('div');
    modal.className = 'context-modal';
    modal.hidden = true;
    modal.setAttribute('aria-hidden', 'true');
    modal.innerHTML = '<div class="context-modal-backdrop" data-context-close></div><section class="context-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="contextModalTitle"><header><div><p class="eyebrow">CONFIGURAÇÕES</p><h2 id="contextModalTitle">Gerenciar</h2></div><button type="button" class="context-modal-close" data-context-close aria-label="Fechar">×</button></header><div class="context-modal-body"></div></section>';
    document.body.append(modal);
    const body = modal.querySelector('.context-modal-body');
    const title = modal.querySelector('#contextModalTitle');
    let source = null;
    let placeholder = null;
    let opener = null;

    const close = () => {
        if (source && placeholder?.parentNode) placeholder.parentNode.replaceChild(source, placeholder);
        source = null; placeholder = null;
        modal.hidden = true;
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('modal-open');
        opener?.focus(); opener = null;
    };
    const open = (target, label, trigger) => {
        if (!target?.parentNode) return;
        opener = trigger;
        source = target;
        placeholder = document.createComment('modal-source');
        target.parentNode.replaceChild(placeholder, target);
        body.replaceChildren(target);
        if (target.matches('details')) target.open = true;
        title.textContent = label;
        modal.hidden = false;
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('modal-open');
        modal.querySelector('.context-modal-close').focus();
    };
    modal.querySelectorAll('[data-context-close]').forEach((button) => button.addEventListener('click', close));
    document.addEventListener('keydown', (event) => { if (event.key === 'Escape' && !modal.hidden) close(); });

    const createUser = admin.querySelector('.create-user-disclosure');
    if (createUser) {
        createUser.open = true;
        const button = document.createElement('button');
        button.type = 'button'; button.className = 'button button-primary'; button.textContent = 'Criar usuário e ramal';
        createUser.parentNode.insertBefore(button, createUser);
        button.addEventListener('click', () => open(createUser, 'Criar usuário e ramal', button));
    }

    admin.querySelectorAll('.tenant-card').forEach((card) => {
        card.open = true;
        const summary = card.querySelector(':scope > summary');
        summary?.addEventListener('click', (event) => event.preventDefault());
        const detail = card.querySelector('.tenant-detail-grid');
        if (!detail || !summary) return;
        const inlineForm = detail.querySelector(':scope > .inline-form');
        const routeList = detail.querySelector(':scope > .tenant-routes');
        const routePanel = document.createElement('div'); routePanel.className = 'tenant-route-management';
        if (inlineForm) routePanel.append(inlineForm);
        if (routeList) routePanel.append(routeList);
        detail.append(routePanel);
        const panels = [
            ['Editar empresa', detail.querySelector(':scope > .crud-full')],
            ['Usuários e ramais', detail.querySelector(':scope > .extension-list')],
            ['Vincular rotas', routePanel],
            ['Configurar pausas', detail.querySelector(':scope > .tenant-pause-settings')],
        ];
        const actions = document.createElement('div'); actions.className = 'tenant-card-actions';
        panels.forEach(([label, panel]) => {
            if (!panel) return;
            const count = label.startsWith('Usuários') ? card.querySelectorAll('.extension-list > details').length : label.startsWith('Configurar') ? card.querySelectorAll('.pause-item').length : label.startsWith('Vincular') ? card.querySelectorAll('.tenant-routes form').length : null;
            const button = document.createElement('button'); button.type = 'button'; button.className = 'button button-soft';
            button.textContent = count === null ? label : `${label} · ${count}`;
            button.addEventListener('click', () => open(panel, `${label} · ${summary.querySelector('strong')?.textContent || 'empresa'}`, button));
            actions.append(button);
        });
        summary.insertAdjacentElement('afterend', actions);
    });
};

document.addEventListener('DOMContentLoaded', () => {
    initializeAdminNavigation();
    initializeAdminContextModals();
});

const phoneInput = document.querySelector('#phone');
const phoneHelp = document.querySelector('#phoneHelp');

const normalizePhoneDigits = (value) => {
    let digits = String(value || '').replace(/\D/g, '');
    if (digits.startsWith('0055') && digits.length > 11) digits = digits.slice(4);
    else if (digits.startsWith('55') && digits.length > 11) digits = digits.slice(2);
    if (digits.startsWith('0') && digits.length > 11) digits = digits.slice(1);
    return digits.slice(0, 11);
};

const maskPhone = (value) => {
    const digits = normalizePhoneDigits(value);
    if (digits.length <= 2) return digits ? `(${digits}` : '';
    if (digits.length <= 6) return `(${digits.slice(0, 2)}) ${digits.slice(2)}`;
    if (digits.length <= 10) return `(${digits.slice(0, 2)}) ${digits.slice(2, 6)}-${digits.slice(6)}`;
    if (digits.length === 11) return `(${digits.slice(0, 2)}) ${digits.slice(2, 7)}-${digits.slice(7)}`;
    return digits;
};

phoneInput?.addEventListener('input', (event) => {
    event.target.value = maskPhone(event.target.value);
    event.target.setCustomValidity('');
    phoneHelp?.classList.remove('input-error');
    if (phoneHelp) phoneHelp.textContent = 'Informe DDD + telefone, com 10 ou 11 dígitos. Espaços e caracteres são removidos automaticamente.';
});

const config = window.__SIP_CONFIG__;

if (config) {
    delete window.__SIP_CONFIG__;
    JsSIP.debug.disable('JsSIP:*');

    const elements = {
        statusName: document.querySelector('#statusName'),
        statusOrb: document.querySelector('#statusOrb'),
        statusTimer: document.querySelector('#statusTimer'),
        lineMessage: document.querySelector('#lineMessage'),
        callTitle: document.querySelector('#callTitle'),
        callDirection: document.querySelector('#callDirection'),
        callButton: document.querySelector('#callButton'),
        activeCall: document.querySelector('#activeCall'),
        activeCallLabel: document.querySelector('#activeCallLabel'),
        activeNumber: document.querySelector('#activeNumber'),
        muteButton: document.querySelector('#muteButton'),
        holdButton: document.querySelector('#holdButton'),
        hangupButton: document.querySelector('#hangupButton'),
        incomingCall: document.querySelector('#incomingCall'),
        incomingNumber: document.querySelector('#incomingNumber'),
        historyBody: document.querySelector('#historyBody'),
        historyCount: document.querySelector('#historyCount'),
        historyLoader: document.querySelector('#historyLoader'),
        recordingIndicator: document.querySelector('#recordingIndicator'),
        remoteAudio: document.querySelector('#remoteAudio'),
        audioConsole: document.querySelector('#audioConsole'),
        audioConsoleToggle: document.querySelector('#audioConsoleToggle'),
        audioPermission: document.querySelector('#audioPermission'),
        microphoneSelect: document.querySelector('#microphoneSelect'),
        speakerSelect: document.querySelector('#speakerSelect'),
        microphoneState: document.querySelector('#microphoneState'),
        speakerState: document.querySelector('#speakerState'),
        microphoneLevel: document.querySelector('#microphoneLevel'),
        audioMeter: document.querySelector('.audio-meter'),
        audioConsoleMessage: document.querySelector('#audioConsoleMessage'),
        testMicrophoneButton: document.querySelector('#testMicrophoneButton'),
        testSpeakerButton: document.querySelector('#testSpeakerButton'),
        microphoneVolume: document.querySelector('#microphoneVolume'),
        microphoneVolumeValue: document.querySelector('#microphoneVolumeValue'),
        microphoneMuteButton: document.querySelector('#microphoneMuteButton'),
        speakerVolume: document.querySelector('#speakerVolume'),
        speakerVolumeValue: document.querySelector('#speakerVolumeValue'),
        speakerMuteButton: document.querySelector('#speakerMuteButton'),
        appointmentForm: document.querySelector('#appointmentForm'),
        appointmentName: document.querySelector('#appointmentName'),
        appointmentPhone: document.querySelector('#appointmentPhone'),
        appointmentDate: document.querySelector('#appointmentDate'),
        appointmentList: document.querySelector('#appointmentList'),
        appointmentCount: document.querySelector('#appointmentCount'),
        appointmentFormMessage: document.querySelector('#appointmentFormMessage'),
        appointmentAlert: document.querySelector('#appointmentAlert'),
        appointmentAlertTime: document.querySelector('#appointmentAlertTime'),
        appointmentAlertTitle: document.querySelector('#appointmentAlertTitle'),
        appointmentAlertPhone: document.querySelector('#appointmentAlertPhone'),
        appointmentCallButton: document.querySelector('#appointmentCallButton'),
        appointmentSnoozeButton: document.querySelector('#appointmentSnoozeButton'),
        appointmentSnoozeMinutes: document.querySelector('#appointmentSnoozeMinutes'),
    };

    const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
    const pcConfig = config.iceServers?.length ? { iceServers: config.iceServers } : undefined;
    const socket = new JsSIP.WebSocketInterface(config.websocketUrl);
    const uaOptions = {
        uri: config.uri,
        password: config.password,
        sockets: [socket],
        register: true,
        session_timers: false,
    };

    if (config.server) uaOptions.registrar_server = `sip:${config.server}`;

    const ua = new JsSIP.UA(uaOptions);
    config.password = null;
    let currentSession = null;
    let currentCallPromise = null;
    let callStartedAt = null;
    let callTimer = null;
    let lineStateStartedAt = new Date();
    let lineStateTimer = null;
    let callFinished = false;
    let mediaRecorder = null;
    let recordingContext = null;
    let recordingChunks = [];
    let failureResetTimer = null;
    let microphoneTestStream = null;
    let microphoneTestContext = null;
    let microphonePreviewAudio = null;
    let microphoneTestGain = null;
    let microphoneMeterFrame = null;
    let microphoneTestTimer = null;
    let callMicrophoneStream = null;
    let callMicrophoneContext = null;
    let callMicrophoneGain = null;
    let outgoingDial = null;
    let callSignalGeneration = 0;
    let callSignalTimer = null;
    const callSignalContexts = new Set();
    const audioPreferenceKey = `thconect-phone:audio:${config.uri}`;
    let savedAudioPreferences = {};
    try {
        savedAudioPreferences = JSON.parse(localStorage.getItem(audioPreferenceKey) || '{}');
    } catch {
        localStorage.removeItem(audioPreferenceKey);
    }
    let selectedMicrophoneId = savedAudioPreferences.microphoneId || '';
    let selectedSpeakerId = savedAudioPreferences.speakerId || '';
    let microphoneVolume = Number(savedAudioPreferences.microphoneVolume ?? 100);
    let speakerVolume = Number(savedAudioPreferences.speakerVolume ?? 100);
    let microphoneMuted = Boolean(savedAudioPreferences.microphoneMuted);
    let speakerMuted = Boolean(savedAudioPreferences.speakerMuted);
    let audioConsoleCollapsed = Boolean(savedAudioPreferences.audioConsoleCollapsed);
    let historyTotal = document.querySelectorAll('#historyBody tr:not(.history-empty)').length;
    const historyFilters = config.historyFilters || {};
    let historyNextCursor = config.historyNextCursor || null;
    let historyLoading = false;
    let appointments = [];
    let currentDueAppointment = null;
    let appointmentPollTimer = null;
    let appointmentDueTimer = null;
    let appointmentServerOffset = 0;
    let renderedDueKey = '';
    let lastAppointmentSignalId = null;
    const defaultDocumentTitle = document.title;

    const stopCallSignal = () => {
        callSignalGeneration += 1;
        clearTimeout(callSignalTimer);
        callSignalTimer = null;
        callSignalContexts.forEach(({ context, audio }) => {
            audio.pause();
            audio.srcObject = null;
            context.close().catch(() => {});
        });
        callSignalContexts.clear();
    };

    const playSignalTone = async (frequency, duration = 220, volume = 0.16, generation = callSignalGeneration) => {
        if (speakerMuted || speakerVolume <= 0 || generation !== callSignalGeneration) return;
        const AudioContextClass = window.AudioContext || window.webkitAudioContext;
        if (!AudioContextClass) return;

        const context = new AudioContextClass();
        const destination = context.createMediaStreamDestination();
        const oscillator = context.createOscillator();
        const gain = context.createGain();
        const audio = new Audio();
        const signal = { context, audio };
        callSignalContexts.add(signal);

        oscillator.type = 'sine';
        oscillator.frequency.value = frequency;
        gain.gain.setValueAtTime(0.0001, context.currentTime);
        gain.gain.exponentialRampToValueAtTime(volume, context.currentTime + 0.025);
        gain.gain.setValueAtTime(volume, context.currentTime + Math.max(0.03, duration / 1000 - 0.05));
        gain.gain.exponentialRampToValueAtTime(0.0001, context.currentTime + duration / 1000);
        oscillator.connect(gain).connect(destination);
        audio.srcObject = destination.stream;
        audio.volume = speakerVolume / 100;
        audio.muted = speakerMuted;

        try {
            if (typeof audio.setSinkId === 'function') await audio.setSinkId(selectedSpeakerId || 'default');
            await context.resume();
            await audio.play();
            oscillator.start();
            oscillator.stop(context.currentTime + duration / 1000);
        } catch (error) {
            console.warn('Não foi possível reproduzir o aviso sonoro.', error);
            audio.pause();
            audio.srcObject = null;
            context.close().catch(() => {});
            callSignalContexts.delete(signal);
            return;
        }

        oscillator.addEventListener('ended', () => {
            audio.pause();
            audio.srcObject = null;
            context.close().catch(() => {});
            callSignalContexts.delete(signal);
        }, { once: true });
    };

    const playSignalSequence = (tones) => {
        stopCallSignal();
        const generation = callSignalGeneration;
        let elapsed = 0;
        tones.forEach(({ frequency, duration, gap = 90, volume }) => {
            window.setTimeout(() => playSignalTone(frequency, duration, volume, generation), elapsed);
            elapsed += duration + gap;
        });
    };

    const startRingbackSignal = () => {
        stopCallSignal();
        const generation = callSignalGeneration;
        const pulse = () => {
            if (generation !== callSignalGeneration) return;
            playSignalTone(425, 950, 0.10, generation);
            callSignalTimer = window.setTimeout(pulse, 5000);
        };
        pulse();
    };

    const playFailureSignal = (statusCode) => {
        if (statusCode === 486) {
            playSignalSequence([
                { frequency: 425, duration: 220, gap: 180 },
                { frequency: 425, duration: 220, gap: 180 },
                { frequency: 425, duration: 220 },
            ]);
            return;
        }

        playSignalSequence([
            { frequency: 520, duration: 170, gap: 70 },
            { frequency: 390, duration: 190, gap: 70 },
            { frequency: 260, duration: 320 },
        ]);
    };

    const api = async (url, options = {}) => {
        const headers = { Accept: 'application/json', 'X-CSRF-TOKEN': csrf, ...(options.headers || {}) };
        if (options.body && !(options.body instanceof FormData)) headers['Content-Type'] = 'application/json';
        const response = await fetch(url, { ...options, headers });
        if (!response.ok) {
            const error = await response.json().catch(() => ({}));
            const failure = new Error(error.message || `Falha HTTP ${response.status}`);
            failure.status = response.status;
            failure.payload = error;
            throw failure;
        }
        return response.status === 204 ? {} : response.json();
    };

    const setLineState = (state, message, tone = '', since = null) => {
        const changed = elements.statusName.textContent !== state || elements.statusOrb.dataset.tone !== tone;
        elements.statusName.textContent = state;
        elements.lineMessage.textContent = message;
        elements.statusOrb.className = `status-orb ${tone}`.trim();
        elements.statusOrb.dataset.tone = tone;
        const suppliedSince = since ? new Date(since) : null;
        if (changed || (suppliedSince && suppliedSince.getTime() !== lineStateStartedAt.getTime())) {
            lineStateStartedAt = suppliedSince || new Date();
            elements.statusTimer.textContent = '00:00';
        }
    };

    const setAudioPermission = (state) => {
        const labels = { granted: 'Permitido', denied: 'Bloqueado', prompt: 'Permissão pendente' };
        elements.audioPermission.textContent = labels[state] || 'Verificando';
        elements.audioPermission.className = `audio-permission ${state === 'prompt' ? 'pending' : state}`;
    };

    const saveAudioPreferences = () => {
        localStorage.setItem(audioPreferenceKey, JSON.stringify({
            microphoneId: selectedMicrophoneId,
            speakerId: selectedSpeakerId,
            microphoneVolume,
            speakerVolume,
            microphoneMuted,
            speakerMuted,
            audioConsoleCollapsed,
        }));
    };

    const syncAudioConsoleVisibility = () => {
        elements.audioConsole.classList.toggle('collapsed', audioConsoleCollapsed);
        elements.audioConsoleToggle.setAttribute('aria-expanded', String(!audioConsoleCollapsed));
        elements.audioConsoleToggle.textContent = audioConsoleCollapsed ? 'Mostrar' : 'Ocultar';
    };

    const clampVolume = (value, maximum) => Math.max(0, Math.min(maximum, Number(value) || 0));

    microphoneVolume = clampVolume(microphoneVolume, 200);
    speakerVolume = clampVolume(speakerVolume, 100);

    const syncMicrophoneControls = () => {
        elements.microphoneVolume.value = String(microphoneVolume);
        elements.microphoneVolumeValue.value = `${microphoneVolume}%`;
        elements.microphoneMuteButton.setAttribute('aria-pressed', String(microphoneMuted));
        elements.microphoneMuteButton.textContent = microphoneMuted ? 'Microfone mudo' : 'Microfone ligado';
        const gain = microphoneMuted ? 0 : microphoneVolume / 100;
        if (microphoneTestGain) microphoneTestGain.gain.setTargetAtTime(gain, microphoneTestContext.currentTime, 0.015);
        if (callMicrophoneGain) callMicrophoneGain.gain.setTargetAtTime(gain, callMicrophoneContext.currentTime, 0.015);
    };

    const syncSpeakerControls = () => {
        elements.speakerVolume.value = String(speakerVolume);
        elements.speakerVolumeValue.value = `${speakerVolume}%`;
        elements.speakerMuteButton.setAttribute('aria-pressed', String(speakerMuted));
        elements.speakerMuteButton.textContent = speakerMuted ? 'Áudio mudo' : 'Áudio ligado';
        elements.remoteAudio.volume = speakerVolume / 100;
        elements.remoteAudio.muted = speakerMuted;
        if (microphonePreviewAudio) {
            microphonePreviewAudio.volume = speakerVolume / 100;
            microphonePreviewAudio.muted = speakerMuted;
        }
    };

    const audioConstraint = () => ({
        ...(selectedMicrophoneId ? { deviceId: { exact: selectedMicrophoneId } } : {}),
        echoCancellation: true,
        noiseSuppression: true,
        autoGainControl: true,
    });

    const releaseCallMicrophone = () => {
        callMicrophoneStream?.getTracks().forEach((track) => track.stop());
        callMicrophoneContext?.close();
        callMicrophoneStream = null;
        callMicrophoneContext = null;
        callMicrophoneGain = null;
    };

    const prepareCallMicrophone = async () => {
        releaseCallMicrophone();
        callMicrophoneStream = await navigator.mediaDevices.getUserMedia({ audio: audioConstraint(), video: false });
        const AudioContextClass = window.AudioContext || window.webkitAudioContext;
        callMicrophoneContext = new AudioContextClass();
        await callMicrophoneContext.resume();
        const source = callMicrophoneContext.createMediaStreamSource(callMicrophoneStream);
        callMicrophoneGain = callMicrophoneContext.createGain();
        const destination = callMicrophoneContext.createMediaStreamDestination();
        source.connect(callMicrophoneGain).connect(destination);
        syncMicrophoneControls();

        return destination.stream;
    };

    const fillDeviceSelect = (select, devices, selectedId, fallbackLabel) => {
        select.replaceChildren();
        devices.forEach((device, index) => {
            const option = document.createElement('option');
            option.value = device.deviceId;
            option.textContent = device.label || `${fallbackLabel} ${index + 1}`;
            option.selected = device.deviceId === selectedId;
            select.append(option);
        });

        if (!devices.length) {
            const option = document.createElement('option');
            option.textContent = fallbackLabel;
            select.append(option);
        }
    };

    const applySpeaker = async () => {
        if (typeof elements.remoteAudio.setSinkId !== 'function') {
            elements.speakerState.textContent = 'O navegador usará a saída padrão do sistema.';
            return;
        }

        try {
            await elements.remoteAudio.setSinkId(selectedSpeakerId || 'default');
            if (microphonePreviewAudio && typeof microphonePreviewAudio.setSinkId === 'function') {
                await microphonePreviewAudio.setSinkId(selectedSpeakerId || 'default');
            }
            const label = elements.speakerSelect.selectedOptions[0]?.textContent || 'Saída padrão';
            elements.speakerState.textContent = `Conectado: ${label}`;
        } catch (error) {
            console.warn('Não foi possível selecionar a saída de áudio.', error);
            elements.speakerState.textContent = 'Não foi possível usar esta saída. Mantida a saída padrão.';
        }
    };

    const refreshAudioDevices = async () => {
        if (!navigator.mediaDevices?.enumerateDevices) {
            setAudioPermission('denied');
            elements.audioConsoleMessage.textContent = 'Este navegador não oferece controle de dispositivos de áudio.';
            return;
        }

        const devices = await navigator.mediaDevices.enumerateDevices();
        const microphones = devices.filter((device) => device.kind === 'audioinput');
        const speakers = devices.filter((device) => device.kind === 'audiooutput');

        if (!microphones.some((device) => device.deviceId === selectedMicrophoneId)) {
            selectedMicrophoneId = microphones.find((device) => device.deviceId === 'default')?.deviceId || microphones[0]?.deviceId || '';
        }
        if (!speakers.some((device) => device.deviceId === selectedSpeakerId)) {
            selectedSpeakerId = speakers.find((device) => device.deviceId === 'default')?.deviceId || speakers[0]?.deviceId || '';
        }

        fillDeviceSelect(elements.microphoneSelect, microphones, selectedMicrophoneId, 'Microfone padrão');
        fillDeviceSelect(elements.speakerSelect, speakers, selectedSpeakerId, 'Saída padrão do navegador');
        elements.microphoneSelect.disabled = microphones.length === 0;
        elements.speakerSelect.disabled = speakers.length === 0 || typeof elements.remoteAudio.setSinkId !== 'function';
        elements.microphoneState.textContent = microphones.length
            ? `Conectado: ${elements.microphoneSelect.selectedOptions[0]?.textContent || 'Microfone padrão'}`
            : 'Nenhum microfone encontrado';
        elements.audioConsoleMessage.textContent = `${microphones.length} entrada(s) e ${speakers.length || 1} saída(s) de áudio detectadas.`;
        saveAudioPreferences();
        await applySpeaker();
    };

    const stopMicrophoneTest = async () => {
        clearTimeout(microphoneTestTimer);
        cancelAnimationFrame(microphoneMeterFrame);
        microphonePreviewAudio?.pause();
        if (microphonePreviewAudio) microphonePreviewAudio.srcObject = null;
        microphoneTestStream?.getTracks().forEach((track) => track.stop());
        await microphoneTestContext?.close();
        microphoneTestStream = null;
        microphoneTestContext = null;
        microphonePreviewAudio = null;
        microphoneTestGain = null;
        microphoneMeterFrame = null;
        elements.microphoneLevel.style.width = '0%';
        elements.audioMeter.setAttribute('aria-valuenow', '0');
        elements.testMicrophoneButton.textContent = 'Testar microfone';
    };

    const startMicrophoneTest = async () => {
        await stopMicrophoneTest();
        elements.testMicrophoneButton.disabled = true;
        elements.audioConsoleMessage.textContent = 'Solicitando acesso ao microfone…';

        try {
            microphoneTestStream = await navigator.mediaDevices.getUserMedia({ audio: audioConstraint(), video: false });
            setAudioPermission('granted');
            await refreshAudioDevices();

            const AudioContextClass = window.AudioContext || window.webkitAudioContext;
            microphoneTestContext = new AudioContextClass();
            await microphoneTestContext.resume();
            const analyser = microphoneTestContext.createAnalyser();
            analyser.fftSize = 256;
            const source = microphoneTestContext.createMediaStreamSource(microphoneTestStream);
            const previewDestination = microphoneTestContext.createMediaStreamDestination();
            microphoneTestGain = microphoneTestContext.createGain();
            source.connect(analyser);
            source.connect(microphoneTestGain).connect(previewDestination);
            syncMicrophoneControls();

            microphonePreviewAudio = new Audio();
            microphonePreviewAudio.autoplay = true;
            microphonePreviewAudio.srcObject = previewDestination.stream;
            if (typeof microphonePreviewAudio.setSinkId === 'function') {
                await microphonePreviewAudio.setSinkId(selectedSpeakerId || 'default');
            }
            microphonePreviewAudio.volume = speakerVolume / 100;
            microphonePreviewAudio.muted = speakerMuted;
            await microphonePreviewAudio.play();
            const samples = new Uint8Array(analyser.frequencyBinCount);

            const drawMeter = () => {
                analyser.getByteFrequencyData(samples);
                const average = samples.reduce((total, value) => total + value, 0) / samples.length;
                const level = Math.min(100, Math.round(average * 1.7));
                elements.microphoneLevel.style.width = `${level}%`;
                elements.audioMeter.setAttribute('aria-valuenow', String(level));
                microphoneMeterFrame = requestAnimationFrame(drawMeter);
            };

            drawMeter();
            elements.testMicrophoneButton.textContent = 'Testando microfone (10 s)';
            elements.audioConsoleMessage.textContent = microphoneMuted
                ? 'O microfone está mudo: o medidor continuará ativo, mas não haverá retorno até ativá-lo.'
                : 'Fale normalmente: o medidor acompanhará sua voz e você ouvirá o retorno na saída selecionada.';
            microphoneTestTimer = window.setTimeout(() => stopMicrophoneTest(), 10000);
        } catch (error) {
            console.warn('Teste do microfone falhou.', error);
            setAudioPermission('denied');
            elements.microphoneState.textContent = 'Permissão negada ou dispositivo indisponível';
            elements.audioConsoleMessage.textContent = 'Libere o microfone nas permissões do navegador e tente novamente.';
        } finally {
            elements.testMicrophoneButton.disabled = false;
        }
    };

    const testSpeaker = async () => {
        elements.testSpeakerButton.disabled = true;
        try {
            const AudioContextClass = window.AudioContext || window.webkitAudioContext;
            const context = new AudioContextClass();
            const destination = context.createMediaStreamDestination();
            const oscillator = context.createOscillator();
            const gain = context.createGain();
            const testAudio = new Audio();
            testAudio.srcObject = destination.stream;
            if (typeof testAudio.setSinkId === 'function') await testAudio.setSinkId(selectedSpeakerId || 'default');
            oscillator.frequency.value = 620;
            gain.gain.setValueAtTime(speakerMuted ? 0 : 0.08 * (speakerVolume / 100), context.currentTime);
            oscillator.connect(gain).connect(destination);
            await testAudio.play();
            oscillator.start();
            oscillator.stop(context.currentTime + 0.45);
            elements.audioConsoleMessage.textContent = speakerMuted
                ? 'O áudio de saída está mudo. Ative-o para ouvir o bip de teste.'
                : 'Teste de áudio de saída enviado para o dispositivo selecionado.';
            window.setTimeout(async () => {
                testAudio.pause();
                await context.close();
            }, 650);
        } catch (error) {
            console.warn('Teste da saída de áudio falhou.', error);
            elements.audioConsoleMessage.textContent = 'Não foi possível tocar na saída selecionada.';
        } finally {
            elements.testSpeakerButton.disabled = false;
        }
    };

    const setMicrophoneMuted = (muted) => {
        microphoneMuted = muted;
        if (currentSession) {
            muted ? currentSession.mute({ audio: true }) : currentSession.unmute({ audio: true });
        }
        const callMuteButton = document.querySelector('#muteButton');
        if (callMuteButton) {
            callMuteButton.setAttribute('aria-pressed', String(muted));
            callMuteButton.lastChild.textContent = muted ? 'Com áudio' : 'Silenciar';
        }
        syncMicrophoneControls();
        saveAudioPreferences();
    };

    const setSpeakerMuted = (muted) => {
        speakerMuted = muted;
        syncSpeakerControls();
        saveAudioPreferences();
    };

    const initializeAudioConsole = async () => {
        syncMicrophoneControls();
        syncSpeakerControls();
        if (!navigator.mediaDevices) {
            setAudioPermission('denied');
            elements.audioConsoleMessage.textContent = 'Use HTTPS ou localhost para liberar os dispositivos de áudio.';
            elements.testMicrophoneButton.disabled = true;
            elements.testSpeakerButton.disabled = true;
            return;
        }

        if (navigator.permissions?.query) {
            try {
                const permission = await navigator.permissions.query({ name: 'microphone' });
                setAudioPermission(permission.state);
                permission.addEventListener('change', () => setAudioPermission(permission.state));
            } catch {
                setAudioPermission('prompt');
            }
        } else {
            setAudioPermission('prompt');
        }

        try {
            await refreshAudioDevices();
        } catch (error) {
            console.warn('Não foi possível listar os dispositivos de áudio.', error);
            elements.audioConsoleMessage.textContent = 'Clique em “Testar microfone” para identificar seus dispositivos.';
        }

        navigator.mediaDevices.addEventListener?.('devicechange', () => {
            refreshAudioDevices().catch((error) => console.warn('Falha ao atualizar dispositivos de áudio.', error));
        });
    };

    elements.microphoneSelect.addEventListener('change', async (event) => {
        await stopMicrophoneTest();
        selectedMicrophoneId = event.target.value;
        saveAudioPreferences();
        elements.microphoneState.textContent = `Conectado: ${event.target.selectedOptions[0]?.textContent || 'Microfone padrão'}`;
        elements.audioConsoleMessage.textContent = 'Este microfone será usado na próxima chamada.';
    });
    elements.speakerSelect.addEventListener('change', async (event) => {
        selectedSpeakerId = event.target.value;
        saveAudioPreferences();
        await applySpeaker();
        elements.audioConsoleMessage.textContent = 'A saída selecionada será usada nas chamadas.';
    });
    elements.testMicrophoneButton.addEventListener('click', startMicrophoneTest);
    elements.testSpeakerButton.addEventListener('click', testSpeaker);
    elements.microphoneVolume.addEventListener('input', (event) => {
        microphoneVolume = clampVolume(event.target.value, 200);
        syncMicrophoneControls();
        saveAudioPreferences();
    });
    elements.speakerVolume.addEventListener('input', (event) => {
        speakerVolume = clampVolume(event.target.value, 100);
        syncSpeakerControls();
        saveAudioPreferences();
    });
    elements.microphoneMuteButton.addEventListener('click', () => setMicrophoneMuted(!microphoneMuted));
    elements.speakerMuteButton.addEventListener('click', () => setSpeakerMuted(!speakerMuted));
    elements.audioConsoleToggle.addEventListener('click', () => {
        audioConsoleCollapsed = !audioConsoleCollapsed;
        syncAudioConsoleVisibility();
        saveAudioPreferences();
    });

    const formatDuration = (seconds) => {
        const value = Math.max(0, Number(seconds) || 0);
        const hours = Math.floor(value / 3600);
        const minutes = Math.floor((value % 3600) / 60);
        const remaining = value % 60;
        return hours > 0
            ? `${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}:${String(remaining).padStart(2, '0')}`
            : `${String(minutes).padStart(2, '0')}:${String(remaining).padStart(2, '0')}`;
    };

    const startTimer = () => {
        callStartedAt = new Date();
    };

    const stopTimer = () => {
        clearInterval(callTimer);
        callTimer = null;
    };

    const statusLabel = (status) => ({
        completed: 'Atendida',
        failed: 'Não completada',
        rejected: 'Recusada',
        cancelled: 'Cancelada',
        answered: 'Em atendimento',
        no_answer: 'Não atendida',
        busy: 'Ocupado',
        voicemail: 'Caixa de mensagens',
        invalid_number: 'Número não existe',
        unavailable: 'Indisponível',
        ringing: 'Tocando',
        dialing: 'Chamando',
    }[status] || 'Iniciada');

    const directionLabel = (direction) => direction === 'incoming' ? 'Recebida' : 'Realizada';
    const dateLabel = (value) => value ? new Date(value).toLocaleString('pt-BR') : '—';

    const setCallData = (element, call) => {
        element.dataset.call = JSON.stringify(call);
    };

    const createCell = (text) => {
        const cell = document.createElement('td');
        cell.textContent = text;
        return cell;
    };

    const callMatchesHistoryFilters = (call) => {
        const number = String(call.remote_number || '').replace(/\D/g, '');
        if (historyFilters.phone && !number.includes(historyFilters.phone)) return false;
        if (historyFilters.result && call.status !== historyFilters.result) return false;

        const startedAt = new Date(call.started_at);
        if (Number.isNaN(startedAt.getTime())) return false;
        const date = `${startedAt.getFullYear()}-${String(startedAt.getMonth() + 1).padStart(2, '0')}-${String(startedAt.getDate()).padStart(2, '0')}`;

        return (!historyFilters.from || date >= historyFilters.from)
            && (!historyFilters.to || date <= historyFilters.to);
    };

    const updateHistoryCount = () => {
        historyTotal = document.querySelectorAll('#historyBody tr:not(.history-empty)').length;
        elements.historyCount.textContent = config.historyInfiniteEnabled
            ? `${historyTotal} ${historyTotal === 1 ? 'chamada carregada' : 'chamadas carregadas'}`
            : `${historyTotal} ${historyTotal === 1 ? 'chamada de hoje' : 'chamadas de hoje'}`;
    };

    const renderHistory = (call, { append = false, enforceLimit = !config.historyInfiniteEnabled } = {}) => {
        const existingRow = document.querySelector(`[data-call-id="${call.id}"]`);
        if (append && existingRow) return;
        existingRow?.remove();
        if (!callMatchesHistoryFilters(call)) {
            updateHistoryCount();
            return;
        }
        document.querySelector('.history-empty')?.remove();

        const row = document.createElement('tr');
        row.className = 'history-row';
        row.dataset.callId = call.id;
        setCallData(row, call);

        const numberCell = document.createElement('td');
        const numberButton = document.createElement('button');
        numberButton.type = 'button';
        numberButton.className = 'history-open';
        numberButton.textContent = call.remote_number || 'Não identificado';
        numberCell.appendChild(numberButton);
        row.append(numberCell);
        row.append(createCell(directionLabel(call.direction)));
        row.append(createCell(dateLabel(call.started_at)));
        row.append(createCell(call.result_label || statusLabel(call.status)));

        const durationCell = document.createElement('td');
        const duration = document.createElement('code');
        duration.textContent = formatDuration(call.duration_seconds);
        durationCell.appendChild(duration);
        row.append(durationCell);

        const recordingCell = document.createElement('td');
        const recordingButton = document.createElement('button');
        recordingButton.type = 'button';
        recordingButton.className = 'recording-cell history-open';
        recordingButton.textContent = call.has_recording ? '▶ Ouvir' : '—';
        recordingCell.appendChild(recordingButton);
        row.append(recordingCell);

        append ? elements.historyBody.append(row) : elements.historyBody.prepend(row);
        if (enforceLimit) {
            [...document.querySelectorAll('#historyBody tr:not(.history-empty)')].slice(25).forEach((oldRow) => oldRow.remove());
        }
        updateHistoryCount();
    };

    const finishHistoryLoading = () => {
        if (!elements.historyLoader) return;
        elements.historyLoader.classList.add('complete');
        elements.historyLoader.querySelector('span').textContent = 'Todos os resultados foram carregados.';
    };

    const loadMoreHistory = async () => {
        if (!config.historyInfiniteEnabled || !historyNextCursor || historyLoading) return;
        historyLoading = true;
        elements.historyLoader?.classList.remove('complete');
        if (elements.historyLoader) elements.historyLoader.querySelector('span').textContent = 'Carregando mais chamadas…';

        try {
            const url = new URL(config.historyUrl, window.location.origin);
            Object.entries(historyFilters).forEach(([key, value]) => {
                if (value) url.searchParams.set(key, value);
            });
            url.searchParams.set('cursor', historyNextCursor);
            const page = await api(url.toString());
            page.calls.forEach((call) => renderHistory(call, { append: true, enforceLimit: false }));
            historyNextCursor = page.next_cursor || null;
            if (!historyNextCursor) finishHistoryLoading();
            else if (elements.historyLoader) elements.historyLoader.querySelector('span').textContent = 'Role para carregar mais chamadas…';
        } catch (error) {
            console.warn('Não foi possível carregar mais chamadas.', error);
            if (elements.historyLoader) elements.historyLoader.querySelector('span').textContent = 'Não foi possível carregar. Role novamente para tentar.';
        } finally {
            historyLoading = false;
        }
    };

    // Asterisk AMI is the source of truth for call state and recordings.
    // O registro no navegador Ã© uma redundÃ¢ncia do AMI: se o listener estiver
    // em reconexÃ£o, a chamada e a gravaÃ§Ã£o continuam aparecendo no sistema.
    const persistCallStart = async (direction, remoteNumber) => api(config.callsBaseUrl, {
        method: 'POST',
        body: JSON.stringify({ direction, remote_number: remoteNumber }),
    });
    const updateCall = async (callId, status, durationSeconds = null, outcome = {}) => api(`${config.callsBaseUrl}/${callId}`, {
        method: 'PATCH',
        body: JSON.stringify({ status, ...(durationSeconds === null ? {} : { duration_seconds: durationSeconds }), ...outcome }),
    });

    const startRecording = async (session) => {
        if (!config.recordCalls || typeof MediaRecorder === 'undefined' || !session.connection) return;

        const tracks = [
            ...session.connection.getSenders().map((sender) => sender.track),
            ...session.connection.getReceivers().map((receiver) => receiver.track),
        ].filter((track) => track?.kind === 'audio' && track.readyState === 'live');

        if (!tracks.length) return;

        const AudioContextClass = window.AudioContext || window.webkitAudioContext;
        recordingContext = new AudioContextClass();
        await recordingContext.resume();
        const destination = recordingContext.createMediaStreamDestination();
        tracks.forEach((track) => recordingContext.createMediaStreamSource(new MediaStream([track])).connect(destination));

        const mimeType = ['audio/webm;codecs=opus', 'audio/webm', 'video/webm']
            .find((type) => MediaRecorder.isTypeSupported(type));
        mediaRecorder = new MediaRecorder(destination.stream, mimeType ? { mimeType } : undefined);
        recordingChunks = [];
        mediaRecorder.addEventListener('dataavailable', (event) => {
            if (event.data.size > 0) recordingChunks.push(event.data);
        });
        mediaRecorder.start(1000);
        elements.recordingIndicator.hidden = false;
    };

    const stopRecording = () => new Promise((resolve) => {
        if (!mediaRecorder || mediaRecorder.state === 'inactive') {
            resolve(null);
            return;
        }

        const recorder = mediaRecorder;
        recorder.addEventListener('stop', async () => {
            const blob = recordingChunks.length ? new Blob(recordingChunks, { type: recorder.mimeType || 'audio/webm' }) : null;
            await recordingContext?.close();
            mediaRecorder = null;
            recordingContext = null;
            recordingChunks = [];
            elements.recordingIndicator.hidden = true;
            resolve(blob);
        }, { once: true });
        recorder.stop();
    });

    const uploadRecording = async (callId, blob) => {
        if (!blob?.size) return null;
        const form = new FormData();
        form.append('recording', blob, `chamada-${callId}.webm`);
        return api(`${config.callsBaseUrl}/${callId}/gravacao`, { method: 'POST', body: form });
    };

    const finishCall = async (status, started, outcome = {}) => {
        if (callFinished) return;
        callFinished = true;
        const durationSeconds = callStartedAt ? Math.floor((Date.now() - callStartedAt.getTime()) / 1000) : 0;
        const recording = await stopRecording();

        try {
            let call = await currentCallPromise;
            if (!call?.id) return started;
            if (recording) call = await uploadRecording(call.id, recording);
            call = await updateCall(call.id, status, durationSeconds, outcome);
            renderHistory(call);
        } catch (error) {
            console.warn('Não foi possível salvar o histórico da chamada.', error);
        }

        return started;
    };

    const remoteNumber = (session) => session.remote_identity?.uri?.user || 'Número não identificado';

    const callFailureMessage = (event) => {
        const cause = String(event?.cause || '');
        const response = event?.message || event?.response;
        const statusCode = Number(response?.status_code || 0);
        const reasonPhrase = String(response?.reason_phrase || '');
        const detail = `${cause} ${reasonPhrase}`.toLowerCase();
        const sipCode = statusCode ? ` (SIP ${statusCode}${reasonPhrase ? ` — ${reasonPhrase}` : ''})` : '';

        if (detail.includes('denied media') || detail.includes('permission') || detail.includes('notallowederror')) {
            return 'O navegador bloqueou o microfone. Libere a permissão e tente novamente.';
        }
        if (statusCode === 403) return `O servidor recusou a saída deste ramal${sipCode}. Verifique a permissão de chamadas externas.`;
        if (statusCode === 404) return `O softswitch não encontrou o destino enviado pela rota${sipCode}. O administrador pode conferir o destino efetivo em Diagnóstico de discagem.`;
        if ([408, 480, 503].includes(statusCode)) return `O destino ou a rota está indisponível no momento${sipCode}.`;
        if (statusCode === 486) return `O destino está ocupado${sipCode}.`;
        if (statusCode === 488) return `O servidor recusou a negociação de áudio WebRTC${sipCode}.`;
        if (statusCode === 603) return `A telefonia recusou a chamada após receber o destino${sipCode}. Verifique bloqueios, saldo ou permissão da rota no softswitch.`;

        return `A chamada foi encerrada antes de ser atendida${sipCode || (cause ? `: ${cause}` : '.')}`;
    };

    const restoreRegisteredStateLater = () => {
        clearTimeout(failureResetTimer);
        failureResetTimer = window.setTimeout(() => {
            if (ua.isRegistered() && !currentSession) {
                setLineState('Registrado', 'Ramal pronto para fazer e receber chamadas.', 'available');
            }
        }, 12000);
    };

    const resetCallUi = () => {
        stopCallSignal();
        currentSession = null;
        currentCallPromise = null;
        outgoingDial = null;
        callStartedAt = null;
        elements.activeCall.hidden = true;
        elements.incomingCall.hidden = true;
        elements.recordingIndicator.hidden = true;
        elements.muteButton.disabled = false;
        elements.holdButton.disabled = false;
        elements.hangupButton.disabled = false;
        elements.hangupButton.lastChild.textContent = 'Desligar';
        elements.callTitle.textContent = 'Para quem vamos ligar?';
        elements.callDirection.textContent = 'Linha livre';
        phoneInput.disabled = !ua.isRegistered();
        elements.callButton.disabled = !ua.isRegistered();
        stopTimer();
        releaseCallMicrophone();
        if (ua.isRegistered()) setLineState('Registrado', 'Ramal pronto para fazer e receber chamadas.', 'available');
    };

    const brazilianDialVariants = (value) => {
        const original = normalizePhoneDigits(value);
        if (!original) return [];

        let national = original;
        if (national.startsWith('0055')) national = national.slice(4);
        else if (national.startsWith('55') && [12, 13].includes(national.length)) national = national.slice(2);
        if (national.startsWith('0') && [11, 12].includes(national.length)) national = national.slice(1);

        // The configured TECH route expects TECH + 55 + DDD + number.
        // Laravel/Asterisk adds TECH; the browser sends exactly one E.164 destination.
        if ([10, 11].includes(national.length)) return [`55${national}`];
        return [original];
    };

    const shouldTryNextFormat = (event) => {
        if (!outgoingDial || outgoingDial.accepted || outgoingDial.routeReached) return false;
        if (outgoingDial.index >= outgoingDial.variants.length - 1) return false;

        const statusCode = Number(event?.message?.status_code || 0);
        const cause = String(event?.cause || '').toLowerCase();
        if ([401, 407, 408, 486, 487, 488, 600, 603].includes(statusCode)) return false;
        return !['canceled', 'cancelled', 'no answer', 'timeout', 'webrtc', 'media', 'connection error']
            .some((blockedCause) => cause.includes(blockedCause));
    };

    const placeOutgoingAttempt = async () => {
        if (!outgoingDial || outgoingDial.index >= outgoingDial.variants.length) return;
        stopCallSignal();
        const number = outgoingDial.variants[outgoingDial.index];
        outgoingDial.routeReached = false;
        currentSession = null;
        callFinished = false;
        elements.callButton.disabled = true;
        elements.callTitle.textContent = `Chamando ${number}`;
        elements.callDirection.textContent = `Formato ${outgoingDial.index + 1} de ${outgoingDial.variants.length}`;
        setLineState('Chamando', `Tentando ${number}. Aguardando resposta do servidor.`, 'calling');

        try {
            const hasLiveTrack = outgoingDial.mediaStream?.getAudioTracks().some((track) => track.readyState === 'live');
            if (!hasLiveTrack) outgoingDial.mediaStream = await prepareCallMicrophone();
            ua.call(`sip:${number}@${config.domain}`, {
                mediaConstraints: { audio: audioConstraint(), video: false },
                mediaStream: outgoingDial.mediaStream,
                pcConfig,
            });
        } catch (error) {
            console.warn('Não foi possível iniciar esta tentativa de chamada.', error);
            if (outgoingDial.index < outgoingDial.variants.length - 1) {
                outgoingDial.index += 1;
                callSignalTimer = window.setTimeout(placeOutgoingAttempt, 450);
                return;
            }
            resetCallUi();
            setLineState('Chamada não iniciada', 'O navegador não conseguiu enviar a chamada ao servidor SIP.', 'error');
            playFailureSignal(0);
            restoreRegisteredStateLater();
        }
    };

    const attachSession = (session, direction) => {
        currentSession = session;
        callFinished = false;
        const number = remoteNumber(session);
        const historyNumber = direction === 'outgoing' && outgoingDial ? outgoingDial.original : number;
        const initiatedAt = new Date();
        currentCallPromise ??= persistCallStart(direction, historyNumber);

        if (direction === 'outgoing') {
            elements.activeCall.hidden = false;
            elements.activeCallLabel.textContent = 'CHAMANDO';
            elements.activeNumber.textContent = number;
            elements.muteButton.disabled = true;
            elements.holdButton.disabled = true;
            elements.hangupButton.disabled = false;
        }

        session.connection?.addEventListener('track', (event) => {
            if (event.streams[0]) elements.remoteAudio.srcObject = event.streams[0];
        });

        session.on('progress', (event) => {
            const statusCode = Number(event?.response?.status_code || 0);
            if (direction === 'outgoing' && outgoingDial && [180, 183].includes(statusCode)) {
                if (!outgoingDial.routeReached) startRingbackSignal();
                outgoingDial.routeReached = true;
            }
            setLineState('Chamando', `Aguardando ${number} atender.`, 'calling');
            elements.callDirection.textContent = direction === 'incoming' ? 'Recebendo' : 'Saída';
            if (direction === 'outgoing') elements.activeCallLabel.textContent = 'TOCANDO';
        });

        session.on('accepted', async () => {
            stopCallSignal();
            if (direction === 'outgoing' && outgoingDial) outgoingDial.accepted = true;
            playSignalSequence([
                { frequency: 660, duration: 100, gap: 55, volume: 0.10 },
                { frequency: 880, duration: 150, volume: 0.10 },
            ]);
            setLineState('Em chamada', `Áudio conectado com ${number}.`, 'in-call');
            elements.incomingCall.hidden = true;
            elements.activeCall.hidden = false;
            elements.activeCallLabel.textContent = direction === 'incoming' ? 'CHAMADA RECEBIDA' : 'CHAMADA REALIZADA';
            elements.activeNumber.textContent = number;
            elements.muteButton.disabled = false;
            elements.holdButton.disabled = false;
            elements.hangupButton.disabled = false;
            startTimer();
            try {
                const call = await currentCallPromise;
                if (call?.id) await updateCall(call.id, 'answered');
                await startRecording(session);
            } catch (error) {
                console.warn('Não foi possível iniciar o registro da chamada.', error);
            }
        });

        session.on('ended', async () => {
            stopCallSignal();
            await finishCall('completed', initiatedAt);
            resetCallUi();
            playSignalSequence([
                { frequency: 620, duration: 120, gap: 60, volume: 0.09 },
                { frequency: 440, duration: 180, volume: 0.09 },
            ]);
        });

        session.on('failed', async (event) => {
            stopCallSignal();
            if (direction === 'outgoing' && shouldTryNextFormat(event)) {
                outgoingDial.index += 1;
                currentSession = null;
                const nextNumber = outgoingDial.variants[outgoingDial.index];
                setLineState('Testando outro formato', `O servidor recusou o formato anterior. Próxima tentativa: ${nextNumber}.`, 'connecting');
                playSignalSequence([
                    { frequency: 520, duration: 90, gap: 55, volume: 0.08 },
                    { frequency: 660, duration: 90, volume: 0.08 },
                ]);
                callSignalTimer = window.setTimeout(placeOutgoingAttempt, 550);
                return;
            }

            const result = direction === 'incoming' && !callStartedAt ? 'rejected' : 'failed';
            const sipResponse = event?.message || event?.response;
            const statusCode = Number(sipResponse?.status_code || 0);
            const testedFormats = direction === 'outgoing' && outgoingDial && outgoingDial.variants.length > 1
                ? ` Formatos testados: ${outgoingDial.variants.slice(0, outgoingDial.index + 1).join(', ')}.`
                : '';
            const failureMessage = `${callFailureMessage(event)}${testedFormats}`;
            console.warn('Falha SIP na chamada.', {
                cause: event?.cause || null,
                statusCode: event?.message?.status_code || null,
                reasonPhrase: event?.message?.reason_phrase || null,
            });
            await finishCall(result, initiatedAt, {
                ...(statusCode ? { sip_code: statusCode } : {}),
                ...(sipResponse?.reason_phrase ? { reason_phrase: sipResponse.reason_phrase } : {}),
            });
            resetCallUi();
            setLineState('Chamada não completada', failureMessage, 'error');
            restoreRegisteredStateLater();
            playFailureSignal(statusCode);
        });
    };

    ua.on('connected', () => setLineState('Autenticando', 'Servidor WebSocket conectado. Validando o ramal.', 'connecting'));
    ua.on('registered', () => {
        setLineState('Registrado', 'Ramal pronto para fazer e receber chamadas.', 'available');
        phoneInput.disabled = false;
        elements.callButton.disabled = false;
        updateAppointmentAlert();
        heartbeat();
        phoneInput.focus();
    });
    ua.on('unregistered', () => {
        phoneInput.disabled = true;
        elements.callButton.disabled = true;
        updateAppointmentAlert();
        setLineState('Desconectado', 'O registro do ramal foi encerrado.', 'error');
    });
    ua.on('disconnected', () => setLineState('Reconectando', 'Conexão com o servidor perdida. Tentando novamente.', 'calling'));
    ua.on('registrationFailed', (event) => {
        phoneInput.disabled = true;
        elements.callButton.disabled = true;
        setLineState('Falha no registro', `Não foi possível registrar o ramal: ${event.cause || 'verifique ramal, senha e servidor'}.`, 'error');
    });

    ua.on('newRTCSession', ({ session }) => {
        if (currentSession && currentSession !== session) {
            session.terminate({ status_code: 486, reason_phrase: 'Busy Here' });
            return;
        }

        const direction = session.direction;
        attachSession(session, direction);

        if (direction === 'incoming') {
            startRingbackSignal();
            const number = remoteNumber(session);
            elements.incomingNumber.textContent = number;
            elements.incomingCall.hidden = false;
            elements.callTitle.textContent = 'Seu ramal está tocando';
            elements.callDirection.textContent = 'Entrada';
            setLineState('Recebendo chamada', `Chamada de ${number}.`, 'calling');
        }
    });

    const manualCallForm = document.querySelector('#manualCallForm');
    manualCallForm?.addEventListener('submit', async (event) => {
        event.preventDefault();
        const number = normalizePhoneDigits(phoneInput.value);
        phoneInput.value = maskPhone(number);
        if (!ua.isRegistered() || currentSession) {
            phoneInput.focus();
            return;
        }
        if (![10, 11].includes(number.length)) {
            const message = 'Informe um telefone válido com DDD: 10 dígitos para fixo ou 11 para celular.';
            phoneInput.setCustomValidity(message);
            phoneHelp?.classList.add('input-error');
            if (phoneHelp) phoneHelp.textContent = message;
            setLineState('Número inválido', message, 'error');
            phoneInput.reportValidity();
            phoneInput.focus();
            return;
        }

        clearTimeout(failureResetTimer);
        elements.callButton.disabled = true;
        setLineState('Preparando chamada', 'Validando o acesso ao microfone.', 'connecting');

        try {
            if (!navigator.mediaDevices?.getUserMedia) {
                throw new Error('microphone_unavailable');
            }

            const mediaStream = await prepareCallMicrophone();
            const variants = brazilianDialVariants(number);
            outgoingDial = {
                original: number,
                variants,
                index: 0,
                routeReached: false,
                accepted: false,
                mediaStream,
            };
            await placeOutgoingAttempt();
        } catch (error) {
            console.warn('Não foi possível iniciar a chamada.', error);
            releaseCallMicrophone();
            setLineState('Microfone bloqueado', 'Libere o microfone nas permissões do navegador e tente novamente.', 'error');
            elements.callButton.disabled = false;
            restoreRegisteredStateLater();
        }
    });

    document.querySelector('#answerButton')?.addEventListener('click', async () => {
        if (!currentSession) return;

        try {
            const mediaStream = await prepareCallMicrophone();
            currentSession.answer({
                mediaConstraints: { audio: audioConstraint(), video: false },
                mediaStream,
                pcConfig,
            });
        } catch (error) {
            console.warn('Não foi possível acessar o microfone para atender.', error);
            releaseCallMicrophone();
            setLineState('Microfone bloqueado', 'Libere o microfone nas permissões do navegador para atender.', 'error');
        }
    });
    document.querySelector('#rejectButton')?.addEventListener('click', () => currentSession?.terminate({ status_code: 486, reason_phrase: 'Busy Here' }));
    document.querySelector('#hangupButton')?.addEventListener('click', () => {
        if (!currentSession) return;
        elements.hangupButton.disabled = true;
        elements.hangupButton.lastChild.textContent = 'Encerrando';
        setLineState('Encerrando chamada', 'Aguarde enquanto a ligação é finalizada.', 'calling');
        currentSession.terminate();
    });
    document.querySelector('#muteButton')?.addEventListener('click', () => {
        if (!currentSession) return;
        setMicrophoneMuted(!microphoneMuted);
    });
    document.querySelector('#holdButton')?.addEventListener('click', (event) => {
        if (!currentSession) return;
        const pressed = event.currentTarget.getAttribute('aria-pressed') === 'true';
        pressed ? currentSession.unhold() : currentSession.hold();
        event.currentTarget.setAttribute('aria-pressed', String(!pressed));
        event.currentTarget.lastChild.textContent = pressed ? 'Espera' : 'Retomar';
    });

    const drawer = document.querySelector('#callDrawer');
    const backdrop = document.querySelector('#drawerBackdrop');
    const recordingAudio = document.querySelector('#recordingAudio');
    const drawerCallButton = document.querySelector('#drawerCallButton');
    const drawerCopyButton = document.querySelector('#drawerCopyButton');
    let drawerPhoneNumber = '';
    const closeDrawer = () => {
        recordingAudio.pause();
        recordingAudio.removeAttribute('src');
        drawer.classList.remove('open');
        backdrop.classList.remove('open');
        drawer.setAttribute('aria-hidden', 'true');
    };
    const openDrawer = (call) => {
        drawerPhoneNumber = String(call.remote_number || '').replace(/\D/g, '');
        drawerCallButton.disabled = drawerPhoneNumber.length < 3 || !ua.isRegistered() || Boolean(currentSession);
        drawerCopyButton.disabled = drawerPhoneNumber.length < 3;
        drawerCopyButton.textContent = 'Copiar';
        document.querySelector('#drawerNumber').textContent = call.remote_number || 'Não identificado';
        document.querySelector('#drawerDirection').textContent = directionLabel(call.direction);
        document.querySelector('#drawerStatus').textContent = call.result_label || statusLabel(call.status);
        document.querySelector('#drawerStarted').textContent = dateLabel(call.started_at);
        document.querySelector('#drawerAnswered').textContent = dateLabel(call.answered_at);
        document.querySelector('#drawerEnded').textContent = dateLabel(call.ended_at);
        document.querySelector('#drawerDuration').textContent = formatDuration(call.duration_seconds);
        document.querySelector('#recordingPlayer').hidden = !call.has_recording;
        document.querySelector('#noRecording').hidden = call.has_recording;
        if (call.has_recording) recordingAudio.src = call.recording_url;
        drawer.classList.add('open');
        backdrop.classList.add('open');
        drawer.setAttribute('aria-hidden', 'false');
    };

    const copyDrawerPhone = async () => {
        if (!drawerPhoneNumber) return;
        try {
            if (navigator.clipboard?.writeText) {
                await navigator.clipboard.writeText(drawerPhoneNumber);
            } else {
                const input = document.createElement('textarea');
                input.value = drawerPhoneNumber;
                input.style.position = 'fixed';
                input.style.opacity = '0';
                document.body.append(input);
                input.select();
                document.execCommand('copy');
                input.remove();
            }
            drawerCopyButton.textContent = 'Copiado';
            window.setTimeout(() => { drawerCopyButton.textContent = 'Copiar'; }, 1800);
        } catch (error) {
            console.warn('Não foi possível copiar o telefone.', error);
            drawerCopyButton.textContent = 'Falhou';
            window.setTimeout(() => { drawerCopyButton.textContent = 'Copiar'; }, 1800);
        }
    };

    elements.historyBody?.addEventListener('click', (event) => {
        const row = event.target.closest('.history-row');
        if (!row?.dataset.call) return;
        openDrawer(JSON.parse(row.dataset.call));
    });
    drawerCallButton?.addEventListener('click', () => {
        if (!drawerPhoneNumber || !ua.isRegistered() || currentSession) return;
        phoneInput.value = maskPhone(drawerPhoneNumber);
        closeDrawer();
        window.scrollTo({ top: 0, behavior: 'smooth' });
        manualCallForm?.requestSubmit();
    });
    drawerCopyButton?.addEventListener('click', copyDrawerPhone);
    document.querySelector('#drawerClose')?.addEventListener('click', closeDrawer);
    backdrop?.addEventListener('click', closeDrawer);
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && drawer.classList.contains('open')) closeDrawer();
    });

    const appointmentDateLabel = (value) => new Date(value).toLocaleString('pt-BR', {
        day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit',
    });
    const appointmentIsDue = (appointment) => appointment.status === 'pending'
        && (appointment.is_due || new Date(appointment.scheduled_for).getTime() <= Date.now() + appointmentServerOffset);

    const updateAppointmentAlert = () => {
        const due = appointments.find(appointmentIsDue);
        currentDueAppointment = due || null;
        elements.appointmentAlert.hidden = !due;
        document.title = due ? `🔔 Retorno: ${due.name} | Thconect` : defaultDocumentTitle;
        if (!due) return;

        elements.appointmentAlertTitle.textContent = due.name;
        elements.appointmentAlertPhone.textContent = maskPhone(due.phone);
        elements.appointmentAlertTime.textContent = new Date(due.scheduled_for).toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
        elements.appointmentCallButton.disabled = !ua.isRegistered() || Boolean(currentSession);
        if (lastAppointmentSignalId !== due.id) {
            lastAppointmentSignalId = due.id;
            playSignalSequence([
                { frequency: 660, duration: 160, gap: 90 },
                { frequency: 880, duration: 220 },
            ]);
        }
    };

    const renderAppointments = () => {
        elements.appointmentCount.textContent = `${appointments.length} ${appointments.length === 1 ? 'agendamento' : 'agendamentos'}`;
        elements.appointmentList.replaceChildren();
        if (!appointments.length) {
            renderedDueKey = '';
            const empty = document.createElement('div');
            empty.className = 'appointment-empty';
            empty.textContent = 'Nenhum retorno agendado. Use o formulário para criar o primeiro.';
            elements.appointmentList.append(empty);
            updateAppointmentAlert();
            return;
        }

        appointments.forEach((appointment) => {
            const isDue = appointmentIsDue(appointment);
            const item = document.createElement('article');
            item.className = `appointment-item${isDue ? ' due' : ''}`;
            item.dataset.appointmentId = appointment.id;
            const marker = document.createElement('i');
            marker.setAttribute('aria-hidden', 'true');
            const copy = document.createElement('div');
            const name = document.createElement('strong');
            name.textContent = appointment.name;
            const phone = document.createElement('span');
            phone.textContent = maskPhone(appointment.phone);
            copy.append(name, phone);
            const time = document.createElement('time');
            time.dateTime = appointment.scheduled_for;
            time.textContent = isDue ? `Agora · ${appointmentDateLabel(appointment.scheduled_for)}` : appointmentDateLabel(appointment.scheduled_for);
            const cancel = document.createElement('button');
            cancel.type = 'button';
            cancel.className = 'appointment-cancel';
            cancel.dataset.action = 'cancel';
            cancel.setAttribute('aria-label', `Cancelar retorno de ${appointment.name}`);
            cancel.textContent = '×';
            item.append(marker, copy, time, cancel);
            elements.appointmentList.append(item);
        });
        renderedDueKey = appointments.filter(appointmentIsDue).map((appointment) => appointment.id).join(',');
        updateAppointmentAlert();
    };

    const loadAppointments = async () => {
        try {
            const payload = await api(config.appointmentsUrl);
            appointments = payload.appointments || [];
            if (payload.server_now) appointmentServerOffset = new Date(payload.server_now).getTime() - Date.now();
            renderAppointments();
        } catch (error) {
            console.warn('Não foi possível atualizar a agenda.', error);
        }
    };

    const updateAppointment = async (appointment, action, minutes = null) => {
        const body = { action };
        if (minutes !== null) body.minutes = Number(minutes);
        await api(`${config.appointmentsUrl}/${appointment.id}`, { method: 'PATCH', body: JSON.stringify(body) });
        lastAppointmentSignalId = null;
        await loadAppointments();
    };

    const localDateTimeValue = (date) => {
        const offset = date.getTimezoneOffset() * 60000;
        return new Date(date.getTime() - offset).toISOString().slice(0, 16);
    };
    if (elements.appointmentDate) {
        const earliest = new Date(Date.now() + 60000);
        elements.appointmentDate.min = localDateTimeValue(earliest);
        elements.appointmentDate.value = localDateTimeValue(new Date(Date.now() + 30 * 60000));
    }
    elements.appointmentPhone?.addEventListener('input', (event) => {
        event.target.value = maskPhone(event.target.value);
    });
    elements.appointmentForm?.addEventListener('submit', async (event) => {
        event.preventDefault();
        const submit = event.currentTarget.querySelector('button[type="submit"]');
        submit.disabled = true;
        elements.appointmentFormMessage.className = 'appointment-form-message';
        elements.appointmentFormMessage.textContent = 'Salvando agendamento…';
        try {
            await api(config.appointmentsUrl, {
                method: 'POST',
                body: JSON.stringify({
                    name: elements.appointmentName.value,
                    phone: normalizePhoneDigits(elements.appointmentPhone.value),
                    scheduled_for: elements.appointmentDate.value,
                }),
            });
            elements.appointmentForm.reset();
            elements.appointmentDate.min = localDateTimeValue(new Date(Date.now() + 60000));
            elements.appointmentDate.value = localDateTimeValue(new Date(Date.now() + 30 * 60000));
            elements.appointmentFormMessage.classList.add('success');
            elements.appointmentFormMessage.textContent = 'Retorno agendado.';
            await loadAppointments();
        } catch (error) {
            elements.appointmentFormMessage.classList.add('error');
            elements.appointmentFormMessage.textContent = error.message;
        } finally {
            submit.disabled = false;
        }
    });
    elements.appointmentList?.addEventListener('click', async (event) => {
        const button = event.target.closest('[data-action="cancel"]');
        if (!button) return;
        const item = button.closest('[data-appointment-id]');
        button.disabled = true;
        try {
            await api(`${config.appointmentsUrl}/${item.dataset.appointmentId}`, { method: 'DELETE' });
            await loadAppointments();
        } catch (error) {
            button.disabled = false;
            elements.appointmentFormMessage.className = 'appointment-form-message error';
            elements.appointmentFormMessage.textContent = error.message;
        }
    });
    elements.appointmentSnoozeButton?.addEventListener('click', async () => {
        if (!currentDueAppointment) return;
        elements.appointmentSnoozeButton.disabled = true;
        try {
            await updateAppointment(currentDueAppointment, 'snooze', elements.appointmentSnoozeMinutes.value);
        } finally {
            elements.appointmentSnoozeButton.disabled = false;
        }
    });
    elements.appointmentCallButton?.addEventListener('click', async () => {
        if (!currentDueAppointment || !ua.isRegistered() || currentSession) return;
        const appointment = currentDueAppointment;
        elements.appointmentCallButton.disabled = true;
        try {
            await updateAppointment(appointment, 'complete');
            phoneInput.value = maskPhone(appointment.phone);
            window.scrollTo({ top: 0, behavior: 'smooth' });
            manualCallForm?.requestSubmit();
        } catch (error) {
            elements.appointmentCallButton.disabled = false;
            console.warn('Não foi possível iniciar o retorno agendado.', error);
        }
    });

    updateHistoryCount();
    if (config.historyInfiniteEnabled && elements.historyLoader && historyNextCursor) {
        if ('IntersectionObserver' in window) {
            const historyObserver = new IntersectionObserver((entries) => {
                if (entries.some((entry) => entry.isIntersecting)) loadMoreHistory();
            }, { rootMargin: '400px 0px' });
            historyObserver.observe(elements.historyLoader);
        } else {
            window.addEventListener('scroll', () => {
                const loaderTop = elements.historyLoader.getBoundingClientRect().top;
                if (loaderTop <= window.innerHeight + 300) loadMoreHistory();
            }, { passive: true });
        }
    }

    document.querySelector('#phoneLogout')?.addEventListener('submit', () => ua.stop());
    const pauseSelect = document.querySelector('#agentPauseSelect');
    const pauseButton = document.querySelector('#agentPauseButton');
    const heartbeat = () => api(config.presenceUrl, { method: 'POST', body: JSON.stringify({ state: ua.isRegistered() ? 'available' : 'offline' }) }).then((presence) => {
        if (pauseSelect && presence.pause_reason_id) {
            pauseSelect.value = String(presence.pause_reason_id);
            if (!currentSession) setLineState('Em pausa', `Pausa: ${pauseSelect.selectedOptions[0]?.textContent || 'em andamento'}.`, 'calling', presence.state_since);
        } else if (presence.state === 'available' && ua.isRegistered() && !currentSession) {
            setLineState('Registrado', 'Ramal pronto para fazer e receber chamadas.', 'available', presence.state_since);
        }
    }).catch((error) => {
        if (error.status === 401 || error.payload?.session_ended) {
            ua.stop();
            window.location.assign(config.sessionEndedUrl);
        }
    });
    pauseButton?.addEventListener('click', async () => {
        pauseButton.disabled = true;
        try {
            if (pauseSelect.value) {
                await api(config.pauseUrl, { method: 'POST', body: JSON.stringify({ pause_reason_id: Number(pauseSelect.value) }) });
                setLineState('Em pausa', `Pausa: ${pauseSelect.selectedOptions[0].textContent}.`, 'calling');
            } else {
                await api(config.pauseUrl, { method: 'DELETE' });
                if (ua.isRegistered() && !currentSession) setLineState('Registrado', 'Ramal pronto para fazer e receber chamadas.', 'available');
            }
        } catch (error) {
            setLineState('Pausa não alterada', error.message, 'error');
        } finally {
            pauseButton.disabled = false;
        }
    });
    const presenceTimer = window.setInterval(heartbeat, 20000);
    lineStateTimer = window.setInterval(() => {
        elements.statusTimer.textContent = formatDuration(Math.floor((Date.now() - lineStateStartedAt.getTime()) / 1000));
    }, 1000);
    appointmentDueTimer = window.setInterval(() => {
        const dueKey = appointments.filter(appointmentIsDue).map((appointment) => appointment.id).join(',');
        if (dueKey !== renderedDueKey) renderAppointments();
    }, 1000);
    window.addEventListener('beforeunload', () => {
        clearInterval(appointmentPollTimer);
        clearInterval(appointmentDueTimer);
        clearInterval(presenceTimer);
        clearInterval(lineStateTimer);
        microphonePreviewAudio?.pause();
        microphoneTestStream?.getTracks().forEach((track) => track.stop());
        releaseCallMicrophone();
        ua.stop();
    });

    try {
        syncAudioConsoleVisibility();
        initializeAudioConsole();
        loadAppointments();
        appointmentPollTimer = window.setInterval(loadAppointments, 15000);
        ua.start();
    } catch (error) {
        setLineState('Configuração inválida', error.message, 'error');
    }
}

const supervisionConfig = window.__SUPERVISION_CONFIG__;
if (supervisionConfig) {
    delete window.__SUPERVISION_CONFIG__;
    JsSIP.debug.disable('JsSIP:*');
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
    const tenantSelect = document.querySelector('#supervisionTenant');
    const tableBody = document.querySelector('#supervisionAgents');
    const search = document.querySelector('#agentSearch');
    const searchBar = search?.closest('.agent-search');
    const toggleOffline = document.createElement('button');
    toggleOffline.type = 'button';
    toggleOffline.className = 'offline-toggle';
    toggleOffline.setAttribute('aria-pressed', 'false');
    searchBar?.after(toggleOffline);
    const connection = document.querySelector('#supervisionConnection');
    const audio = document.querySelector('#supervisionAudio');
    const toast = document.querySelector('#supervisionToast');
    const dayDrawer = document.querySelector('#operatorDayDrawer');
    const dayBackdrop = document.querySelector('#operatorDayBackdrop');
    const dayDate = document.querySelector('#operatorDayDate');
    const dayCards = document.querySelector('#operatorDayCards');
    const pauseBreakdown = document.querySelector('#operatorPauseBreakdown');
    const operatorTimeline = document.querySelector('#operatorTimeline');
    const spyConsole = document.querySelector('#spyConsole');
    const spyAgentName = document.querySelector('#spyAgentName');
    const spyConsoleKicker = document.querySelector('#spyConsoleKicker');
    const spyConsoleStatus = document.querySelector('#spyConsoleStatus');
    const spyOpenDetails = document.querySelector('#spyOpenDetails');
    const spyExit = document.querySelector('#spyExit');
    let agents = [];
    let activeSession = null;
    let activeAuditId = null;
    let activeSpy = null;
    let startingSpy = false;
    let toastTimer = null;
    let selectedOperator = null;
    let showOffline = false;
    let sortKey = 'number';
    let sortDirection = 'asc';

    const socket = new JsSIP.WebSocketInterface(supervisionConfig.websocketUrl);
    const ua = new JsSIP.UA({ uri: supervisionConfig.uri, password: supervisionConfig.password, sockets: [socket], register: true, session_timers: false });
    supervisionConfig.password = null;
    const request = async (url, options = {}) => {
        const headers = { Accept: 'application/json', 'X-CSRF-TOKEN': csrf, ...(options.headers || {}) };
        if (options.body) headers['Content-Type'] = 'application/json';
        const response = await fetch(url, { ...options, headers });
        const payload = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(payload.message || `Falha HTTP ${response.status}`);
        return payload;
    };
    const notify = (message) => {
        clearTimeout(toastTimer); toast.textContent = message; toast.hidden = false;
        toastTimer = setTimeout(() => { toast.hidden = true; }, 5000);
    };
    const duration = (seconds) => {
        const total = Math.max(0, Math.floor(Number(seconds) || 0));
        const hours = Math.floor(total / 3600);
        const minutes = Math.floor((total % 3600) / 60);
        const value = `${String(minutes).padStart(2, '0')}:${String(total % 60).padStart(2, '0')}`;
        return hours ? `${hours}:${value}` : value;
    };
    const elapsed = (date) => date ? Math.max(0, Math.floor((Date.now() - new Date(date).getTime()) / 1000)) : 0;
    const node = (tag, className, text) => { const element = document.createElement(tag); if (className) element.className = className; if (text !== undefined) element.textContent = text; return element; };

    const render = () => {
        const term = search.value.trim().toLowerCase();
        const offlineCount = agents.filter((agent) => agent.state === 'offline').length;
        toggleOffline.textContent = showOffline ? 'Ocultar offline' : `Exibir offline (${offlineCount})`;
        toggleOffline.setAttribute('aria-pressed', String(showOffline));
        const statusOrder = { talking: 0, calling: 1, paused: 2, available: 3, offline: 4 };
        const comparable = (agent) => ({
            number: Number(agent.number) || 0, agent: `${agent.name} ${agent.email} ${agent.number}`.toLowerCase(),
            status: statusOrder[agent.state] ?? 9, logged_seconds: agent.logged_seconds,
            calls_today: agent.calls_today, talk_seconds: agent.talk_seconds, pause_seconds: agent.pause_seconds,
        }[sortKey]);
        const visible = agents
            .filter((agent) => (showOffline || agent.state !== 'offline') && `${agent.name} ${agent.email} ${agent.number}`.toLowerCase().includes(term))
            .sort((a, b) => {
                const left = comparable(a); const right = comparable(b);
                const result = typeof left === 'string' ? left.localeCompare(right, 'pt-BR') : left - right;
                return sortDirection === 'asc' ? result : -result;
            });
        tableBody.replaceChildren();
        document.querySelector('#agentTotal').textContent = agents.filter((agent) => agent.state !== 'offline').length;
        document.querySelectorAll('[data-state-counter]').forEach((counter) => { counter.querySelector('b').textContent = agents.filter((agent) => agent.state === counter.dataset.stateCounter).length; });
        if (!visible.length) { const row = node('tr'); const cell = node('td', 'empty-cell', 'Nenhum agente encontrado.'); cell.colSpan = 7; row.append(cell); tableBody.append(row); return; }
        visible.forEach((agent) => {
            const row = node('tr');
            const identityCell = node('td'); const identity = node('div', 'agent-identity'); identity.append(node('b', '', `${agent.number} · ${agent.name}`), node('small', '', agent.email || '')); identityCell.append(identity);
            const statusCell = node('td'); const status = node('span', `agent-status ${agent.state}`); if (agent.status_color) status.style.background = agent.status_color; status.append(node('i'), node('span', '', agent.status_label), node('time', '', duration(elapsed(agent.since)))); statusCell.append(status);
            const loggedCell = node('td', 'metric-cell', duration(agent.logged_seconds));
            const callsCell = node('td', 'metric-cell', String(agent.calls_today)); callsCell.title = `${agent.answered_today} atendidas`;
            const talkCell = node('td', 'metric-cell', duration(agent.talk_seconds));
            const pauseCell = node('td', 'metric-cell', duration(agent.pause_seconds));
            const actionCell = node('td'); const actions = node('div', 'supervision-actions');
            const details = node('button', 'supervision-action details', 'Ver dia'); details.type = 'button'; details.addEventListener('click', () => openOperatorDay(agent)); actions.append(details);
            [['listen','Ouvir'],['whisper','Sussurrar'],['barge','Entrar']].forEach(([mode,label]) => { const button = node('button', `supervision-action ${mode}`, label); button.type = 'button'; button.disabled = agent.state !== 'talking' || !ua.isRegistered() || startingSpy; button.addEventListener('click', () => startSupervision(agent, mode)); actions.append(button); });
            const logout = node('button', 'supervision-action force-logout', 'Deslogar'); logout.type = 'button'; logout.disabled = !agent.can_force_logout; logout.addEventListener('click', () => forceLogoutAgent(agent, logout)); actions.append(logout);
            actionCell.append(actions); row.append(identityCell, statusCell, loggedCell, callsCell, talkCell, pauseCell, actionCell); tableBody.append(row);
        });
    };

    const sortableColumns = [
        ['agent', 'Agente'], ['status', 'Status'], ['logged_seconds', 'Tempo logado'],
        ['calls_today', 'Ligações'], ['talk_seconds', 'Em chamada'], ['pause_seconds', 'Pausas'],
    ];
    document.querySelectorAll('.supervision-table thead th').forEach((header, index) => {
        if (!sortableColumns[index]) return;
        const [key, label] = sortableColumns[index];
        const button = node('button', 'supervision-sort', label); button.type = 'button'; button.dataset.sort = key;
        button.addEventListener('click', () => {
            sortDirection = sortKey === key && sortDirection === 'asc' ? 'desc' : 'asc';
            sortKey = key;
            document.querySelectorAll('.supervision-sort').forEach((item) => item.classList.toggle('active', item === button));
            button.dataset.direction = sortDirection;
            render();
        });
        header.replaceChildren(button);
    });
    toggleOffline.addEventListener('click', () => { showOffline = !showOffline; render(); });

    const forceLogoutAgent = async (agent, button) => {
        if (!window.confirm(`Deslogar ${agent.name} do telefone agora? A ação será registrada na auditoria.`)) return;
        button.disabled = true;
        try {
            const payload = await request(`${supervisionConfig.logoutUrl}/${agent.id}/deslogar`, { method: 'POST' });
            notify(payload.message);
            await loadAgents();
        } catch (error) {
            notify(error.message);
            button.disabled = false;
        }
    };

    const closeOperatorDay = () => { dayDrawer.hidden = true; dayBackdrop.hidden = true; selectedOperator = null; };
    const loadOperatorDay = async () => {
        if (!selectedOperator) return;
        dayCards.innerHTML = '<p class="muted">Carregando consolidado…</p>';
        pauseBreakdown.replaceChildren(); operatorTimeline.replaceChildren();
        try {
            const data = await request(`${supervisionConfig.dailyUrl}/${selectedOperator.id}/dia?date=${encodeURIComponent(dayDate.value)}`);
            document.querySelector('#operatorDayName').textContent = data.operator.name || `Ramal ${data.operator.number}`;
            document.querySelector('#operatorDayIdentity').textContent = `Ramal ${data.operator.number} · ${data.operator.email || ''}`;
            const cards = [
                ['Tempo logado', duration(data.summary.logged_seconds)], ['Ligações', String(data.summary.calls)],
                ['Atendidas', String(data.summary.answered)], ['Tempo em chamada', duration(data.summary.talk_seconds)],
                ['Pausas', duration(data.summary.pause_seconds)], ['Acessos', String(data.summary.sessions)],
            ];
            dayCards.replaceChildren(...cards.map(([label, value]) => { const card = node('div'); card.append(node('span', '', label), node('b', '', value)); return card; }));
            if (!data.pause_breakdown.length) pauseBreakdown.append(node('p', 'muted', 'Nenhuma pausa registrada nesta data.'));
            data.pause_breakdown.forEach((pause) => { const row = node('div'); row.append(node('b', '', pause.name), node('span', '', `${pause.count} ocorrência(s)`), node('time', '', duration(pause.seconds))); pauseBreakdown.append(row); });
            if (!data.timeline.length) operatorTimeline.append(node('p', 'muted', 'Nenhuma ação registrada nesta data.'));
            data.timeline.forEach((event) => { const row = node('div', `timeline-event ${event.action}`); const when = new Date(event.occurred_at).toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit', second: '2-digit' }); row.append(node('time', '', when), node('span', '', event.description)); operatorTimeline.append(row); });
        } catch (error) { dayCards.innerHTML = ''; dayCards.append(node('p', 'alert alert-error', error.message)); }
    };
    const openOperatorDay = (agent) => { selectedOperator = agent; dayDrawer.hidden = false; dayBackdrop.hidden = false; loadOperatorDay(); };
    const loadAgents = async () => {
        try {
            const payload = await request(`${supervisionConfig.agentsUrl}?tenant_id=${encodeURIComponent(tenantSelect.value)}`);
            agents = payload.agents;
            if (payload.degraded && payload.warning) notify(payload.warning);
            const currentTarget = activeSpy && agents.find((agent) => agent.id === activeSpy.agent.id);
            if (currentTarget && activeSpy) activeSpy.agent = currentTarget;
            if (currentTarget?.state === 'talking' && currentTarget.call?.id && activeSpy && !activeSession && !startingSpy && currentTarget.call.id !== activeSpy.callId && ua.isRegistered()) {
                startSupervision(currentTarget, activeSpy.mode, { reconnect: true });
            }
            renderSpyConsole(); render();
        }
        catch (error) { notify(`Não foi possível atualizar os agentes: ${error.message}`); }
    };
    const finishAudit = async () => {
        if (!activeAuditId) return;
        const id = activeAuditId; activeAuditId = null;
        await request(`${supervisionConfig.finishUrl}/${id}`, { method: 'PATCH' }).catch(() => {});
    };
    const legacyStartSupervision = async (agent, mode) => {
        const labels = { listen: 'ouvir silenciosamente', whisper: 'sussurrar para o agente', barge: 'entrar na ligação' };
        if (!window.confirm(`Confirma ${labels[mode]} na chamada de ${agent.name}? A ação será registrada para auditoria.`)) return;
        try {
            const payload = await request(`${supervisionConfig.startUrl}/${agent.id}`, { method: 'POST', body: JSON.stringify({ mode }) });
            activeAuditId = payload.session_id;
            const session = ua.call(`sip:${payload.dial_number}@${supervisionConfig.domain}`, { mediaConstraints: { audio: true, video: false } });
            activeSession = session; render(); notify(payload.message);
            session.on('peerconnection', () => session.connection?.addEventListener('track', (event) => { if (event.streams[0]) audio.srcObject = event.streams[0]; }));
            const ended = async () => { if (activeSession === session) activeSession = null; audio.srcObject = null; await finishAudit(); render(); };
            session.on('ended', ended); session.on('failed', ended);
        } catch (error) { await finishAudit(); notify(error.message); }
    };

    const modeLabel = (mode) => ({ listen: 'Só ouvir', whisper: 'Sussurrar para o agente', barge: 'Entrar na ligação' }[mode] || 'Acompanhamento');
    const setSpyModeButtons = () => document.querySelectorAll('[data-spy-mode]').forEach((button) => {
        button.classList.toggle('active', button.dataset.spyMode === activeSpy?.mode);
        button.disabled = !activeSpy || startingSpy;
    });
    const renderSpyConsole = () => {
        if (!spyConsole) return;
        spyConsole.hidden = !activeSpy;
        if (!activeSpy) return;
        spyAgentName.textContent = `${activeSpy.agent.number} · ${activeSpy.agent.name}`;
        spyConsoleKicker.textContent = activeSpy.mode === 'listen' ? 'MODO SPY — ESCUTA SILENCIOSA' : activeSpy.mode === 'whisper' ? 'MODO SPY — SUSSURRO PRIVADO' : 'MODO SPY — ENTRADA NA LIGAÇÃO';
        spyConsole.dataset.state = activeSession ? 'live' : activeSpy.state || 'waiting';
        spyConsoleStatus.textContent = activeSession
            ? activeSpy.state === 'audio-blocked'
                ? 'O navegador bloqueou o som. Clique novamente no modo selecionado para liberar o áudio.'
                : `${modeLabel(activeSpy.mode)} em andamento. O operador não recebe aviso na escuta silenciosa.`
            : activeSpy.state === 'error'
                ? 'Não foi possível entrar nesta chamada. O painel continua aberto para uma nova tentativa.'
                : 'Aguardando uma nova chamada deste agente. O acompanhamento permanece ativo até você sair.';
        setSpyModeButtons();
    };
    const detachSpyMedia = () => {
        audio.pause();
        audio.srcObject = null;
        audio.muted = false;
    };
    const endSpyCall = () => {
        if (activeSession && !activeSession.isEnded?.()) activeSession.terminate();
        activeSession = null;
        detachSpyMedia();
    };
    const closeSpy = async () => {
        endSpyCall();
        await finishAudit();
        activeSpy = null;
        renderSpyConsole(); render();
    };
    const enforceSpyMicrophoneMode = (session) => {
        const peer = session.__thSpyPeer || session.connection;
        const transmit = activeSpy?.mode !== 'listen';
        peer?.getSenders?.().forEach((sender) => {
            if (sender.track?.kind === 'audio') sender.track.enabled = transmit;
        });
    };
    const playSpyAudio = async (session) => {
        if (activeSession !== session || !session.__thSpyRemoteStream?.getAudioTracks().length) return;
        audio.autoplay = true;
        audio.playsInline = true;
        audio.muted = false;
        audio.volume = 1;
        if (audio.srcObject !== session.__thSpyRemoteStream) audio.srcObject = session.__thSpyRemoteStream;
        try {
            await audio.play();
        } catch {
            if (activeSpy) activeSpy.state = 'audio-blocked';
            renderSpyConsole();
            notify('O navegador bloqueou o som. Clique novamente no modo de acompanhamento para liberar o áudio.');
        }
    };
    const attachSpyAudio = (session, peerconnection) => {
        const peer = peerconnection || session.connection;
        if (!peer) return;
        session.__thSpyPeer = peer;
        session.__thSpyRemoteStream ||= new MediaStream();

        const addRemoteTrack = (track) => {
            if (!track || track.kind !== 'audio') return;
            if (!session.__thSpyRemoteStream.getTracks().some((item) => item.id === track.id)) {
                session.__thSpyRemoteStream.addTrack(track);
            }
            playSpyAudio(session);
        };

        if (session.__thSpyBoundPeer !== peer) {
            session.__thSpyBoundPeer = peer;
            peer.addEventListener('track', (event) => {
                event.streams?.forEach((stream) => stream.getAudioTracks().forEach(addRemoteTrack));
                addRemoteTrack(event.track);
            });
            peer.addEventListener('negotiationneeded', () => enforceSpyMicrophoneMode(session));
        }

        peer.getReceivers?.().forEach((receiver) => addRemoteTrack(receiver.track));
        enforceSpyMicrophoneMode(session);
    };
    const placeSpyCall = (payload) => {
        if (!activeSpy || !ua.isRegistered() || activeSession) return;
        const session = ua.call(`sip:${payload.dial_number}@${supervisionConfig.domain}`, { mediaConstraints: { audio: true, video: false } });
        activeSession = session;
        activeSpy.state = 'connecting';
        renderSpyConsole(); render();
        session.on('peerconnection', (event) => attachSpyAudio(session, event.peerconnection));
        attachSpyAudio(session, session.connection);
        ['progress', 'accepted', 'confirmed'].forEach((eventName) => session.on(eventName, () => {
            if (activeSession !== session) return;
            attachSpyAudio(session, session.connection);
            enforceSpyMicrophoneMode(session);
            if (eventName === 'confirmed') {
                activeSpy.state = 'live';
                playSpyAudio(session);
                renderSpyConsole(); render();
            }
        }));
        const stopped = (state = 'waiting') => {
            if (activeSession !== session) return;
            activeSession = null;
            detachSpyMedia();
            if (activeSpy) activeSpy.state = state;
            renderSpyConsole(); render();
        };
        session.on('ended', stopped);
        session.on('failed', (event) => {
            stopped('error');
            if (event?.cause) notify(`Supervisão: ${event.cause}. O painel continua aberto.`);
        });
    };
    const startSupervision = async (agent, mode, { reconnect = false } = {}) => {
        if (!ua.isRegistered() || startingSpy) return;
        startingSpy = true;
        try {
            if (activeSpy?.agent.id === agent.id && activeSession && activeSpy.mode === mode) {
                attachSpyAudio(activeSession, activeSession.connection);
                enforceSpyMicrophoneMode(activeSession);
                await playSpyAudio(activeSession);
                return;
            }
            if (activeSpy && activeSpy.agent.id !== agent.id) {
                endSpyCall();
                await finishAudit();
                activeSpy = null;
            }
            if (activeSpy && activeSpy.agent.id === agent.id && activeSession && activeSpy.mode !== mode) endSpyCall();
            const payload = await request(`${supervisionConfig.startUrl}/${agent.id}`, {
                method: 'POST',
                body: JSON.stringify({ mode, supervision_session_id: activeAuditId || undefined }),
            });
            activeAuditId = payload.session_id;
            activeSpy = { agent, mode, callId: payload.call_id, state: 'connecting' };
            renderSpyConsole(); render();
            placeSpyCall(payload);
            if (!reconnect) notify(payload.message);
        } catch (error) {
            if (activeSpy) activeSpy.state = 'error';
            renderSpyConsole(); render();
            notify(error.message);
        } finally {
            startingSpy = false;
            renderSpyConsole(); render();
        }
    };

    ua.on('registered', () => { connection.className = 'supervision-connection registered'; connection.querySelector('span').textContent = 'Ramal supervisor conectado'; render(); });
    ua.on('registrationFailed', () => { connection.className = 'supervision-connection error'; connection.querySelector('span').textContent = 'Falha no ramal supervisor'; render(); });
    ua.on('disconnected', () => { connection.className = 'supervision-connection error'; connection.querySelector('span').textContent = 'Reconectando supervisão'; render(); });
    tenantSelect.addEventListener('change', loadAgents); search.addEventListener('input', render); document.querySelector('#refreshSupervision').addEventListener('click', loadAgents);
    document.querySelector('#operatorDayClose')?.addEventListener('click', closeOperatorDay); dayBackdrop?.addEventListener('click', closeOperatorDay); dayDate?.addEventListener('change', loadOperatorDay);
    document.querySelectorAll('[data-spy-mode]').forEach((button) => button.addEventListener('click', () => {
        if (activeSpy) startSupervision(activeSpy.agent, button.dataset.spyMode);
    }));
    spyOpenDetails?.addEventListener('click', () => { if (activeSpy) openOperatorDay(activeSpy.agent); });
    spyExit?.addEventListener('click', closeSpy);
    const poll = setInterval(loadAgents, 5000); const timer = setInterval(render, 1000);
    window.addEventListener('beforeunload', () => { clearInterval(poll); clearInterval(timer); activeSession?.terminate(); ua.stop(); });
    ua.start(); loadAgents();
}

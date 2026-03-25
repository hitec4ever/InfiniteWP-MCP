/* IWP Update Scheduler - Frontend */

// API endpoint - adjust this path to match your deployment
const API = '../server/api.php';

const App = {
    sites: [],
    schedules: [],
    exceptions: [],

    // --- Init --------------------------------------------------------
    init() {
        document.getElementById('login-form').addEventListener('submit', e => {
            e.preventDefault();
            App.login();
        });

        document.querySelectorAll('.nav-link[data-view]').forEach(link => {
            link.addEventListener('click', e => {
                e.preventDefault();
                App.navigate(link.dataset.view);
            });
        });

        document.getElementById('modal-overlay').addEventListener('click', e => {
            if (e.target === e.currentTarget) App.closeModal();
        });

        // Try to load dashboard (will fail if not authenticated)
        App.tryAutoLogin();
    },

    async tryAutoLogin() {
        try {
            const res = await fetch(`${API}?action=dashboard`);
            if (res.ok) {
                App.showApp();
            }
        } catch (e) { /* show login */ }
    },

    async login() {
        const email = document.getElementById('login-email').value;
        const password = document.getElementById('login-password').value;
        const errEl = document.getElementById('login-error');

        try {
            const res = await fetch(`${API}?action=login`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ email, password })
            });
            const data = await res.json();
            if (data.success) {
                App.showApp();
            } else {
                errEl.textContent = data.error || 'Inloggen mislukt';
                errEl.style.display = 'block';
            }
        } catch (e) {
            errEl.textContent = 'Verbindingsfout';
            errEl.style.display = 'block';
        }
    },

    showApp() {
        document.getElementById('login-screen').style.display = 'none';
        document.getElementById('main-app').style.display = 'flex';
        App.navigate('dashboard');
    },

    // --- Navigation --------------------------------------------------
    navigate(view) {
        document.querySelectorAll('.view').forEach(v => v.classList.remove('active'));
        document.querySelectorAll('.nav-link').forEach(l => l.classList.remove('active'));
        document.getElementById(`view-${view}`).classList.add('active');
        document.querySelector(`.nav-link[data-view="${view}"]`)?.classList.add('active');

        switch (view) {
            case 'dashboard': App.loadDashboard(); break;
            case 'sites': App.loadSites(); break;
            case 'schedules': App.loadSchedules(); break;
            case 'exceptions': App.loadExceptions(); break;
            case 'history': App.loadHistory(); break;
        }
    },

    // --- API Helper --------------------------------------------------
    async api(action, params = {}, method = 'GET', body = null) {
        const url = new URL(API, window.location.href);
        url.searchParams.set('action', action);
        Object.entries(params).forEach(([k, v]) => url.searchParams.set(k, v));

        const opts = { method };
        if (body) {
            opts.headers = { 'Content-Type': 'application/json' };
            opts.body = JSON.stringify(body);
        }

        const res = await fetch(url, opts);
        return res.json();
    },

    // --- Dashboard ---------------------------------------------------
    async loadDashboard() {
        const el = document.getElementById('dashboard-stats');
        el.innerHTML = '<div class="loading"><div class="spinner"></div> Laden...</div>';

        const data = await App.api('dashboard');

        el.innerHTML = `
            <div class="stat-card highlight">
                <div class="stat-label">Totaal Sites</div>
                <div class="stat-value">${data.totalSites}</div>
            </div>
            <div class="stat-card warning">
                <div class="stat-label">Sites met Updates</div>
                <div class="stat-value">${data.sitesWithUpdates}</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Plugin Updates</div>
                <div class="stat-value">${data.totalPluginUpdates}</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Thema Updates</div>
                <div class="stat-value">${data.totalThemeUpdates}</div>
            </div>
            <div class="stat-card success">
                <div class="stat-label">Actieve Schema's</div>
                <div class="stat-value">${data.activeSchedules}</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Uitzonderingen</div>
                <div class="stat-value">${data.totalExceptions}</div>
            </div>
        `;
    },

    // --- Sites & Updates ---------------------------------------------
    async loadSites() {
        const el = document.getElementById('sites-list');
        el.innerHTML = '<div class="loading"><div class="spinner"></div> Sites laden...</div>';

        const [sites, updates] = await Promise.all([
            App.api('sites'),
            App.api('updates')
        ]);

        App.sites = sites;
        const updateMap = {};
        updates.forEach(u => updateMap[u.siteId] = u);

        if (!sites.length) {
            el.innerHTML = '<div class="empty-state"><p>Geen sites gevonden</p></div>';
            return;
        }

        el.innerHTML = sites.map(site => {
            const u = updateMap[site.id] || {};
            const total = site.totalUpdates;
            const plugins = u.plugins || [];
            const themes = u.themes || [];

            return `
                <div class="card">
                    <div class="card-header">
                        <h3>
                            ${site.name || site.url}
                            ${total > 0 ? `<span class="badge badge-plugin">${total} updates</span>` : '<span class="badge badge-success">Up-to-date</span>'}
                        </h3>
                        <span class="site-url">${site.url}</span>
                    </div>
                    ${total > 0 ? `
                    <div class="card-body">
                        <ul class="update-list">
                            ${plugins.map(p => `
                                <li class="update-item">
                                    <span class="plugin-name">${p.name || p.slug}</span>
                                    <span class="version-info">${p.oldVersion} <span class="version-arrow">&rarr;</span> ${p.newVersion}</span>
                                </li>
                            `).join('')}
                            ${themes.map(t => `
                                <li class="update-item">
                                    <span class="plugin-name">${t.name} <span class="badge badge-theme" style="font-size:10px">thema</span></span>
                                    <span class="version-info">${t.oldVersion} <span class="version-arrow">&rarr;</span> ${t.newVersion}</span>
                                </li>
                            `).join('')}
                            ${u.core ? `
                                <li class="update-item">
                                    <span class="plugin-name">WordPress Core <span class="badge badge-core" style="font-size:10px">core</span></span>
                                    <span class="version-info">${u.core.current} <span class="version-arrow">&rarr;</span> ${u.core.new}</span>
                                </li>
                            ` : ''}
                            ${u.translations ? `
                                <li class="update-item">
                                    <span class="plugin-name">Vertalingen <span class="badge badge-translation" style="font-size:10px">vertaling</span></span>
                                    <span class="version-info">beschikbaar</span>
                                </li>
                            ` : ''}
                        </ul>
                    </div>
                    ` : ''}
                </div>
            `;
        }).join('');
    },

    // --- Schedules ---------------------------------------------------
    async loadSchedules() {
        const el = document.getElementById('schedules-list');
        el.innerHTML = '<div class="loading"><div class="spinner"></div> Laden...</div>';

        App.schedules = await App.api('schedules');

        if (!App.schedules.length) {
            el.innerHTML = `
                <div class="empty-state">
                    <p>Nog geen update schema's aangemaakt</p>
                    <br>
                    <button class="btn btn-primary" onclick="App.showScheduleForm()">+ Eerste schema aanmaken</button>
                </div>
            `;
            return;
        }

        const dayNames = ['Zo', 'Ma', 'Di', 'Wo', 'Do', 'Vr', 'Za'];

        el.innerHTML = App.schedules.map(s => {
            const days = s.daysOfWeek.split(',').map(d => dayNames[parseInt(d)]).join(', ');
            const types = [];
            if (s.updatePlugins) types.push('Plugins');
            if (s.updateThemes) types.push('Thema\'s');
            if (s.updateCore) types.push('Core');
            if (s.updateTranslations) types.push('Vertalingen');
            const sitesLabel = s.siteIds ? `${s.siteIds.length} sites` : 'Alle sites';

            return `
                <div class="card" style="${!s.isActive ? 'opacity:.6' : ''}">
                    <div class="card-header">
                        <h3>${s.name}</h3>
                        <div class="card-actions">
                            <label class="toggle" title="${s.isActive ? 'Actief' : 'Inactief'}">
                                <input type="checkbox" ${s.isActive ? 'checked' : ''} onchange="App.toggleSchedule(${s.id})">
                                <span class="toggle-slider"></span>
                            </label>
                            <button class="btn btn-sm btn-success" onclick="App.runScheduleNow(${s.id})" title="Nu uitvoeren">Nu draaien</button>
                            <button class="btn btn-sm btn-secondary" onclick="App.showScheduleForm(${s.id})">Bewerken</button>
                            <button class="btn btn-sm btn-danger" onclick="App.deleteSchedule(${s.id})">Verwijder</button>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="schedule-meta">
                            <span>Tijd: <strong>${s.scheduleTime.substring(0,5)}</strong></span>
                            <span>Dagen: <strong>${days}</strong></span>
                            <span>Types: <strong>${types.join(', ')}</strong></span>
                            <span>Scope: <strong>${sitesLabel}</strong></span>
                            ${s.minUpdateAgeHours > 0 ? `<span>Min. leeftijd: <strong>${s.minUpdateAgeHours}u</strong></span>` : ''}
                            ${s.exceptionCount > 0 ? `<span class="badge badge-info">${s.exceptionCount} uitzonderingen</span>` : ''}
                        </div>
                        <div class="schedule-meta" style="margin-top:8px">
                            ${s.lastRun ? `<span>Laatste run: <strong>${formatDate(s.lastRun)}</strong></span>` : ''}
                            ${s.nextRun ? `<span>Volgende run: <strong>${formatDate(s.nextRun)}</strong></span>` : ''}
                        </div>
                    </div>
                </div>
            `;
        }).join('');
    },

    async showScheduleForm(editId = null) {
        // Load sites for the picker
        if (!App.sites.length) {
            App.sites = await App.api('sites');
        }

        let schedule = {
            name: '', scheduleTime: '02:00', daysOfWeek: '1,2,3,4,5,6,0',
            updatePlugins: true, updateThemes: true, updateCore: false, updateTranslations: true,
            minUpdateAgeHours: 24,
            siteIds: null
        };

        if (editId) {
            schedule = App.schedules.find(s => s.id === editId) || schedule;
        }

        const activeDays = schedule.daysOfWeek.split(',').map(Number);
        const dayLabels = ['Zo', 'Ma', 'Di', 'Wo', 'Do', 'Vr', 'Za'];

        App.openModal(`
            <div class="modal-header">
                <h3>${editId ? 'Schema bewerken' : 'Nieuw Update Schema'}</h3>
                <button class="btn-icon" onclick="App.closeModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label>Naam</label>
                    <input type="text" id="sched-name" value="${schedule.name}" placeholder="bijv. Nachtelijke updates">
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Tijdstip</label>
                        <input type="time" id="sched-time" value="${schedule.scheduleTime.substring(0,5)}">
                    </div>
                </div>
                <div class="form-group">
                    <label>Dagen</label>
                    <div class="day-toggles" id="sched-days">
                        ${dayLabels.map((d, i) => `
                            <button type="button" class="day-toggle ${activeDays.includes(i) ? 'active' : ''}" data-day="${i}">${d}</button>
                        `).join('')}
                    </div>
                </div>
                <div class="form-group">
                    <label>Update types</label>
                    <div class="checkbox-group">
                        <label><input type="checkbox" id="sched-plugins" ${schedule.updatePlugins ? 'checked' : ''}> Plugins</label>
                        <label><input type="checkbox" id="sched-themes" ${schedule.updateThemes ? 'checked' : ''}> Thema's</label>
                        <label><input type="checkbox" id="sched-core" ${schedule.updateCore ? 'checked' : ''}> WordPress Core</label>
                        <label><input type="checkbox" id="sched-translations" ${schedule.updateTranslations ? 'checked' : ''}> Vertalingen</label>
                    </div>
                </div>
                <div class="form-group">
                    <label>Minimale leeftijd update</label>
                    <select id="sched-min-age">
                        <option value="0" ${schedule.minUpdateAgeHours == 0 ? 'selected' : ''}>Geen minimum (direct updaten)</option>
                        <option value="12" ${schedule.minUpdateAgeHours == 12 ? 'selected' : ''}>12 uur</option>
                        <option value="24" ${schedule.minUpdateAgeHours == 24 ? 'selected' : ''}>24 uur</option>
                        <option value="48" ${schedule.minUpdateAgeHours == 48 ? 'selected' : ''}>48 uur</option>
                        <option value="72" ${schedule.minUpdateAgeHours == 72 ? 'selected' : ''}>72 uur (3 dagen)</option>
                        <option value="168" ${schedule.minUpdateAgeHours == 168 ? 'selected' : ''}>1 week</option>
                    </select>
                    <span style="font-size:12px;color:var(--gray-500);margin-top:4px;display:block">Updates die korter dan deze tijd beschikbaar zijn worden overgeslagen. Zo voorkom je dat je een buggy release installeert.</span>
                </div>
                <div class="form-group">
                    <label>Sites <span style="color:var(--gray-400);font-weight:400">(leeg = alle sites)</span></label>
                    <div class="site-picker" id="sched-sites">
                        ${App.sites.map(s => `
                            <label>
                                <input type="checkbox" value="${s.id}" ${schedule.siteIds && schedule.siteIds.includes(s.id) ? 'checked' : ''}>
                                ${s.name || s.url}
                            </label>
                        `).join('')}
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="App.closeModal()">Annuleren</button>
                <button class="btn btn-primary" onclick="App.saveSchedule(${editId || 'null'})">Opslaan</button>
            </div>
        `);

        // Day toggle clicks
        document.querySelectorAll('.day-toggle').forEach(btn => {
            btn.addEventListener('click', () => btn.classList.toggle('active'));
        });
    },

    async saveSchedule(editId) {
        const days = Array.from(document.querySelectorAll('.day-toggle.active'))
            .map(b => b.dataset.day).join(',');
        const siteCheckboxes = document.querySelectorAll('#sched-sites input:checked');
        const siteIds = siteCheckboxes.length > 0 && siteCheckboxes.length < App.sites.length
            ? Array.from(siteCheckboxes).map(cb => parseInt(cb.value))
            : null;

        const data = {
            id: editId,
            name: document.getElementById('sched-name').value || 'Nachtelijke updates',
            scheduleTime: document.getElementById('sched-time').value + ':00',
            daysOfWeek: days || '1,2,3,4,5,6,0',
            updatePlugins: document.getElementById('sched-plugins').checked,
            updateThemes: document.getElementById('sched-themes').checked,
            updateCore: document.getElementById('sched-core').checked,
            updateTranslations: document.getElementById('sched-translations').checked,
            minUpdateAgeHours: parseInt(document.getElementById('sched-min-age').value) || 0,
            siteIds: siteIds
        };

        await App.api('schedule-save', {}, 'POST', data);
        App.closeModal();
        App.loadSchedules();
    },

    async toggleSchedule(id) {
        await App.api('schedule-toggle', {}, 'POST', { id });
    },

    async deleteSchedule(id) {
        if (!confirm('Weet je zeker dat je dit schema wilt verwijderen?')) return;
        await App.api('schedule-delete', { id });
        App.loadSchedules();
    },

    async runScheduleNow(id) {
        if (!confirm('Nu alle updates voor dit schema uitvoeren?')) return;
        await App.api('run-now', {}, 'POST', { scheduleId: id });
        alert('Updates zijn gestart! Controleer de geschiedenis voor resultaten.');
    },

    // --- Exceptions --------------------------------------------------
    async loadExceptions() {
        const el = document.getElementById('exceptions-list');
        el.innerHTML = '<div class="loading"><div class="spinner"></div> Laden...</div>';

        App.exceptions = await App.api('exceptions');

        if (!App.exceptions.length) {
            el.innerHTML = `
                <div class="empty-state">
                    <p>Nog geen uitzonderingen ingesteld</p>
                    <br>
                    <button class="btn btn-primary" onclick="App.showExceptionForm()">+ Eerste uitzondering toevoegen</button>
                </div>
            `;
            return;
        }

        el.innerHTML = `
            <table>
                <thead>
                    <tr>
                        <th>Type</th>
                        <th>Naam</th>
                        <th>Slug</th>
                        <th>Site</th>
                        <th>Reden</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    ${App.exceptions.map(e => `
                        <tr style="cursor:pointer" onclick="App.editException(${e.id})">
                            <td><span class="badge ${e.type === 'plugin' ? 'badge-plugin' : 'badge-theme'}">${e.type}</span></td>
                            <td><strong>${e.name}</strong></td>
                            <td style="color:var(--gray-500)">${e.slug}</td>
                            <td>${e.siteName || '<em style="color:var(--gray-400)">Alle sites</em>'}</td>
                            <td>${e.reason || '-'}</td>
                            <td>
                                <button class="btn btn-sm btn-secondary" onclick="event.stopPropagation();App.editException(${e.id})">Bewerk</button>
                                <button class="btn btn-sm btn-danger" onclick="event.stopPropagation();App.deleteException(${e.id})">Verwijder</button>
                            </td>
                        </tr>
                    `).join('')}
                </tbody>
            </table>
        `;
    },

    _excItems: null,

    async showExceptionForm() {
        // Step 1: choose plugin or theme
        App.openModal(`
            <div class="modal-header">
                <h3>Uitzondering toevoegen</h3>
                <button class="btn-icon" onclick="App.closeModal()">&times;</button>
            </div>
            <div class="modal-body" style="text-align:center;padding:40px 24px">
                <p style="margin-bottom:20px;color:var(--gray-600)">Wat wil je uitsluiten van automatische updates?</p>
                <div style="display:flex;gap:12px;justify-content:center">
                    <button class="btn btn-primary" style="padding:16px 32px;font-size:15px" onclick="App.showExceptionPickerStep('plugin')">Plugins</button>
                    <button class="btn btn-secondary" style="padding:16px 32px;font-size:15px" onclick="App.showExceptionPickerStep('theme')">Thema's</button>
                </div>
            </div>
        `);
    },

    async showExceptionPickerStep(type) {
        // Load items if not cached
        if (!App._excItems) {
            App._excItems = await App.api('all-plugins');
        }
        if (!App.sites.length) {
            App.sites = await App.api('sites');
        }

        const items = type === 'plugin' ? App._excItems.plugins : App._excItems.themes;
        const typeLabel = type === 'plugin' ? 'Plugins' : 'Thema\'s';
        const existingSlugs = new Set(App.exceptions.filter(e => e.type === type).map(e => e.slug));

        App.openModal(`
            <div class="modal-header">
                <h3>${typeLabel} uitsluiten</h3>
                <button class="btn-icon" onclick="App.closeModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label>Zoeken</label>
                    <input type="text" id="exc-search" placeholder="Typ om te filteren..." oninput="App.filterExceptionList()">
                </div>
                <div class="form-group">
                    <label>${typeLabel} <span style="color:var(--gray-400);font-weight:400">(${items.length} geinstalleerd, vink aan om uit te sluiten)</span></label>
                    <div class="site-picker" id="exc-items-list" style="max-height:300px">
                        ${items.map(p => `
                            <label data-search="${p.name.toLowerCase()} ${p.slug.toLowerCase()}">
                                <input type="checkbox" value="${p.slug}" data-name="${escHtml(p.name)}" data-type="${type}"
                                    ${existingSlugs.has(p.slug) ? 'checked disabled' : ''}>
                                <span>${escHtml(p.name)}</span>
                                <span style="color:var(--gray-400);font-size:11px;margin-left:auto">${p.siteCount} sites${p.hasUpdate ? ' &bull; <span style="color:var(--warning)">update</span>' : ''}</span>
                            </label>
                        `).join('')}
                    </div>
                </div>
                <div class="form-group">
                    <label>Alleen voor specifieke site? <span style="color:var(--gray-400);font-weight:400">(optioneel)</span></label>
                    <select id="exc-site">
                        <option value="">Alle sites (globaal)</option>
                        ${App.sites.map(s => `<option value="${s.id}">${s.name || s.url}</option>`).join('')}
                    </select>
                </div>
                <div class="form-group">
                    <label>Reden <span style="color:var(--gray-400);font-weight:400">(optioneel)</span></label>
                    <input type="text" id="exc-reason" placeholder="bijv. Eerst handmatig testen">
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="App.showExceptionForm()">Terug</button>
                <button class="btn btn-primary" onclick="App.saveExceptions()">Geselecteerde toevoegen</button>
            </div>
        `);
    },

    filterExceptionList() {
        const query = document.getElementById('exc-search').value.toLowerCase();
        document.querySelectorAll('#exc-items-list label').forEach(label => {
            const searchText = label.dataset.search || '';
            label.style.display = searchText.includes(query) ? '' : 'none';
        });
    },

    async saveExceptions() {
        const checked = document.querySelectorAll('#exc-items-list input:checked:not(:disabled)');

        if (!checked.length) {
            alert('Selecteer minimaal een item');
            return;
        }

        const siteId = document.getElementById('exc-site').value || null;
        const reason = document.getElementById('exc-reason').value;

        for (const cb of checked) {
            const type = cb.dataset.type;
            const slug = cb.value;
            const name = cb.dataset.name;
            await App.api('exception-save', {}, 'POST', { type, slug, name, siteId, reason });
        }

        App._excItems = null; // clear cache
        App.closeModal();
        App.loadExceptions();
    },

    async editException(id) {
        const exc = App.exceptions.find(e => e.id === id);
        if (!exc) return;

        if (!App.sites.length) {
            App.sites = await App.api('sites');
        }

        App.openModal(`
            <div class="modal-header">
                <h3>Uitzondering bewerken</h3>
                <button class="btn-icon" onclick="App.closeModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label>Type</label>
                    <input type="text" value="${exc.type === 'plugin' ? 'Plugin' : 'Thema'}" disabled>
                </div>
                <div class="form-group">
                    <label>Naam</label>
                    <input type="text" value="${escHtml(exc.name)}" disabled>
                </div>
                <div class="form-group">
                    <label>Slug</label>
                    <input type="text" value="${escHtml(exc.slug)}" disabled style="color:var(--gray-500)">
                </div>
                <div class="form-group">
                    <label>Alleen voor specifieke site?</label>
                    <select id="exc-edit-site">
                        <option value="">Alle sites (globaal)</option>
                        ${App.sites.map(s => `<option value="${s.id}" ${exc.siteId === s.id ? 'selected' : ''}>${s.name || s.url}</option>`).join('')}
                    </select>
                </div>
                <div class="form-group">
                    <label>Reden</label>
                    <input type="text" id="exc-edit-reason" value="${escHtml(exc.reason || '')}" placeholder="bijv. Eerst handmatig testen">
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-danger" onclick="App.deleteException(${id})" style="margin-right:auto">Verwijderen</button>
                <button class="btn btn-secondary" onclick="App.closeModal()">Annuleren</button>
                <button class="btn btn-primary" onclick="App.updateException(${id})">Opslaan</button>
            </div>
        `);
    },

    async updateException(id) {
        const siteId = document.getElementById('exc-edit-site').value || null;
        const reason = document.getElementById('exc-edit-reason').value;

        await App.api('exception-update', {}, 'POST', { id, siteId, reason });
        App.closeModal();
        App.loadExceptions();
    },

    async deleteException(id) {
        if (!confirm('Uitzondering verwijderen?')) return;
        await App.api('exception-delete', { id });
        App.loadExceptions();
    },

    // --- History -----------------------------------------------------
    async loadHistory() {
        const el = document.getElementById('history-list');
        el.innerHTML = '<div class="loading"><div class="spinner"></div> Laden...</div>';

        const history = await App.api('history');

        if (!history.length) {
            el.innerHTML = '<div class="empty-state"><p>Nog geen update geschiedenis</p></div>';
            return;
        }

        el.innerHTML = `
            <table>
                <thead>
                    <tr>
                        <th>Datum</th>
                        <th>Schema</th>
                        <th>Site</th>
                        <th>Type</th>
                        <th>Item</th>
                        <th>Versie</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    ${history.map(h => `
                        <tr>
                            <td>${formatDate(h.executedAt)}</td>
                            <td>${h.scheduleName || '-'}</td>
                            <td>${h.siteName || '-'}</td>
                            <td><span class="badge badge-${h.type === 'plugin' ? 'plugin' : h.type === 'theme' ? 'theme' : h.type === 'core' ? 'core' : 'translation'}">${h.type}</span></td>
                            <td>${h.itemName || h.itemSlug || '-'}</td>
                            <td>${h.oldVersion && h.newVersion ? `${h.oldVersion} &rarr; ${h.newVersion}` : '-'}</td>
                            <td><span class="status-dot ${h.status}"></span>${statusLabel(h.status)}</td>
                        </tr>
                    `).join('')}
                </tbody>
            </table>
        `;
    },

    // --- Modal -------------------------------------------------------
    openModal(html) {
        document.getElementById('modal-content').innerHTML = html;
        document.getElementById('modal-overlay').style.display = 'flex';
    },

    closeModal() {
        document.getElementById('modal-overlay').style.display = 'none';
    }
};

// --- Helpers ---------------------------------------------------------
function formatDate(dateStr) {
    if (!dateStr) return '-';
    const d = new Date(dateStr);
    return d.toLocaleDateString('nl-NL', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}

function statusLabel(status) {
    const labels = {
        queued: 'In wachtrij',
        running: 'Bezig',
        success: 'Geslaagd',
        failed: 'Mislukt',
        skipped: 'Overgeslagen'
    };
    return labels[status] || status;
}

function escHtml(str) {
    const div = document.createElement('div');
    div.textContent = str || '';
    return div.innerHTML;
}

// Boot
document.addEventListener('DOMContentLoaded', () => App.init());

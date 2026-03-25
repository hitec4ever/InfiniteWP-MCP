<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>IWP Update Scheduler</title>
    <link rel="stylesheet" href="css/style.css?v=2">
</head>
<body>
    <div id="app">
        <!-- Login Screen -->
        <div id="login-screen" class="login-container">
            <div class="login-box">
                <div class="login-logo">
                    <svg width="40" height="40" viewBox="0 0 40 40" fill="none"><circle cx="20" cy="20" r="20" fill="#4F46E5"/><path d="M12 20l6 6 10-12" stroke="#fff" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    <h1>Update Scheduler</h1>
                    <p>IWP Geplande Updates</p>
                </div>
                <form id="login-form">
                    <div class="form-group">
                        <label for="login-email">Email</label>
                        <input type="email" id="login-email" required placeholder="admin@example.com">
                    </div>
                    <div class="form-group">
                        <label for="login-password">Wachtwoord</label>
                        <input type="password" id="login-password" required>
                    </div>
                    <button type="submit" class="btn btn-primary btn-full">Inloggen</button>
                    <div id="login-error" class="error-msg" style="display:none"></div>
                </form>
            </div>
        </div>

        <!-- Main App -->
        <div id="main-app" style="display:none">
            <nav class="sidebar">
                <div class="sidebar-header">
                    <svg width="28" height="28" viewBox="0 0 40 40" fill="none"><circle cx="20" cy="20" r="20" fill="#4F46E5"/><path d="M12 20l6 6 10-12" stroke="#fff" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    <span>Update Scheduler</span>
                </div>
                <ul class="nav-items">
                    <li><a href="#" class="nav-link active" data-view="dashboard">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
                        Dashboard
                    </a></li>
                    <li><a href="#" class="nav-link" data-view="sites">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 014 10 15.3 15.3 0 01-4 10 15.3 15.3 0 01-4-10 15.3 15.3 0 014-10z"/></svg>
                        Sites & Updates
                    </a></li>
                    <li><a href="#" class="nav-link" data-view="schedules">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                        Schema's
                    </a></li>
                    <li><a href="#" class="nav-link" data-view="exceptions">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg>
                        Uitzonderingen
                    </a></li>
                    <li><a href="#" class="nav-link" data-view="history">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 102.13-9.36L1 10"/></svg>
                        Geschiedenis
                    </a></li>
                </ul>
                <div class="sidebar-footer">
                    <a href="../" class="nav-link" target="_blank">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                        Open IWP Panel
                    </a>
                </div>
            </nav>

            <main class="content">
                <!-- Dashboard View -->
                <div id="view-dashboard" class="view active">
                    <div class="view-header">
                        <h2>Dashboard</h2>
                    </div>
                    <div class="stats-grid" id="dashboard-stats"></div>
                </div>

                <!-- Sites View -->
                <div id="view-sites" class="view">
                    <div class="view-header">
                        <h2>Sites & Beschikbare Updates</h2>
                        <button class="btn btn-secondary" onclick="App.loadSites()">Vernieuwen</button>
                    </div>
                    <div id="sites-list" class="card-list"></div>
                </div>

                <!-- Schedules View -->
                <div id="view-schedules" class="view">
                    <div class="view-header">
                        <h2>Update Schema's</h2>
                        <button class="btn btn-primary" onclick="App.showScheduleForm()">+ Nieuw Schema</button>
                    </div>
                    <div id="schedules-list" class="card-list"></div>
                </div>

                <!-- Exceptions View -->
                <div id="view-exceptions" class="view">
                    <div class="view-header">
                        <h2>Uitzonderingen</h2>
                        <button class="btn btn-primary" onclick="App.showExceptionForm()">+ Uitzondering</button>
                    </div>
                    <p class="hint">Plugins of thema's die NIET automatisch geupdate mogen worden.</p>
                    <div id="exceptions-list" class="card-list"></div>
                </div>

                <!-- History View -->
                <div id="view-history" class="view">
                    <div class="view-header">
                        <h2>Update Geschiedenis</h2>
                    </div>
                    <div id="history-list" class="table-wrap"></div>
                </div>
            </main>
        </div>

        <!-- Modal Overlay -->
        <div id="modal-overlay" class="modal-overlay" style="display:none">
            <div class="modal" id="modal-content"></div>
        </div>
    </div>

    <script src="js/app.js?v=2"></script>
</body>
</html>

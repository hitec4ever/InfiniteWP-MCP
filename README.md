# InfiniteWP MCP Server

Manage all your WordPress sites through AI chat. This project adds an **MCP (Model Context Protocol) server** on top of [InfiniteWP](https://infinitewp.com), giving AI assistants like Claude, Cursor, VS Code Copilot, and others the ability to view sites, trigger updates, query history, and more — all through natural conversation.

## What Can You Do With It?

Talk to your AI assistant in plain language:

- *"Which sites have pending updates?"*
- *"Update all plugins on example.com"*
- *"When was WooCommerce last updated on mysite.nl?"*
- *"Exclude WooCommerce from automatic updates"*
- *"Show me the latest client report for mysite.nl"*
- *"Give me a dashboard overview"*

## Features

**Read from IWP:**
- Dashboard overview of all sites, pending updates, and exceptions
- List and search sites by name or URL
- View available plugin, theme, and core updates per site
- Query IWP's native update history (e.g. "when was WooCommerce updated on site X?")
- Retrieve IWP-generated client reports (Phoenix template)

**Write to IWP:**
- Trigger plugin, theme, core, or translation updates on any site
- Updates are executed through IWP's own pipeline (signed requests, execute.php)
- Add or remove update exceptions (globally or per site)

**Scheduled updates** (optional server-side feature):
- The server component includes a cron-based scheduler that can run updates automatically
- Configurable per-schedule: which sites, which update types, which days/times
- Minimum update age: skip updates released less than N hours ago (avoid day-one bugs)
- Exception support: excluded plugins/themes are never auto-updated
- Execution log with status tracking per update

> Note: The scheduler is a server-side addition — IWP itself does not have built-in scheduled updates. It requires a cron job on your server (see Installation).

## Architecture

```
+------------------+         HTTPS/JSON         +---------------------------+
|  AI Assistant    |  <--------------------->   |  IWP Server               |
|  (Claude, etc.)  |                            |                           |
|                  |                            |  /scheduler/api.php       |
|  client/         |    Bearer Token Auth       |  /scheduler/cron-runner   |
|  server.mjs      |                            |  /scheduler/mcp/          |
|  (MCP over STDIO)|                            |    run-update.php         |
+------------------+                            |    get-updates.php        |
                                                |    generate-report.php    |
                                                |                           |
                                                |  IWP Database (MySQL)     |
                                                |  IWP execute.php          |
                                                +---------------------------+
                                                           |
                                                    +------+------+
                                                    |  WordPress  |
                                                    |  Sites      |
                                                    |  (IWP       |
                                                    |   Client)   |
                                                    +-------------+
```

The **MCP client** runs on your machine and communicates with the **API server** (deployed alongside your IWP panel) over HTTPS with Bearer token authentication. The API reads from IWP's database and triggers updates through IWP's native signed request pipeline — the same mechanism IWP uses when you click "Update" in the panel.

No database credentials ever leave the server.

## Directory Structure

```
client/           MCP server (runs on your machine)
  server.mjs      MCP tool definitions, communicates via STDIO
  package.json    Node.js dependencies

server/           API + helpers (deploy on your IWP server)
  api.php         REST API with token authentication
  install.php     Creates additional database tables for scheduling
  cron-runner.php Executes scheduled updates (called by cron)
  .htaccess       Blocks web access to sensitive files
  mcp/
    run-update.php      Triggers updates through IWP's pipeline
    get-updates.php     Decodes IWP serialized stats into readable format
    generate-report.php Retrieves IWP client reports
```

## Installation

### Server Side (IWP Panel)

1. **Copy server files** into your InfiniteWP installation directory:

   ```bash
   cp -r server/ /path/to/your/iwp-installation/scheduler/
   ```

   The `scheduler/` directory should be at the same level as IWP's `config.php`.

2. **Run the installer** to create the required database tables:

   ```bash
   cd /path/to/your/iwp-installation/scheduler/
   php install.php
   ```

   This creates tables for scheduled updates, exceptions, execution logs, and update age tracking. It does not modify any existing IWP tables.

3. **Set the API token** as an environment variable on your server:

   ```bash
   # Generate a secure random token
   openssl rand -hex 32

   # Set it in your environment (method depends on your hosting)
   # For Apache, add to .htaccess or vhost config:
   SetEnv IWP_SCHEDULER_TOKEN your-generated-token-here

   # For Nginx + PHP-FPM, add to your server block:
   fastcgi_param IWP_SCHEDULER_TOKEN your-generated-token-here;
   ```

4. **(Optional) Set up the cron job** for scheduled updates:

   ```bash
   */5 * * * * php /path/to/your/iwp-installation/scheduler/cron-runner.php >> /dev/null 2>&1
   ```

   This is only needed if you want automatic scheduled updates. The MCP tools work without it.

### Client Side (Your Machine)

1. **Install dependencies**:

   ```bash
   cd client/
   npm install
   ```

2. **Add to your MCP client configuration**:

   **Claude Code** (`~/.claude/settings.json`):
   ```json
   {
     "mcpServers": {
       "iwp": {
         "command": "node",
         "args": ["/path/to/client/server.mjs"],
         "env": {
           "IWP_API_URL": "https://your-iwp-panel.example.com/scheduler/api.php",
           "IWP_API_TOKEN": "your-generated-token-here"
         }
       }
     }
   }
   ```

   **Claude Desktop** (`claude_desktop_config.json`):
   ```json
   {
     "mcpServers": {
       "iwp": {
         "command": "node",
         "args": ["/path/to/client/server.mjs"],
         "env": {
           "IWP_API_URL": "https://your-iwp-panel.example.com/scheduler/api.php",
           "IWP_API_TOKEN": "your-generated-token-here"
         }
       }
     }
   }
   ```

   **Cursor / VS Code**: Add the same configuration to your MCP settings.

## MCP Tools

| Tool | Description |
|------|-------------|
| `dashboard` | Overview of all sites, pending updates, active schedules, and exceptions |
| `list_sites` | List managed WordPress sites with update counts; filter by status or search by name/URL |
| `site_details` | View a specific site's details and all available updates (plugins, themes, core) |
| `update_site` | Trigger updates on a site — choose plugins, themes, core, translations, or all |
| `site_history` | Search IWP's native update history for a site; filter by plugin/theme name |
| `list_schedules` | Show all configured automatic update schedules |
| `list_exceptions` | Show plugins/themes excluded from automatic updates |
| `add_exception` | Exclude a plugin or theme from automatic updates |
| `remove_exception` | Re-enable automatic updates for a previously excluded plugin/theme |
| `update_history` | View the scheduled update execution log (what ran, when, success/failure) |
| `generate_report` | Retrieve the most recent IWP client report for a site |

## Configuration

### Environment Variables

| Variable | Where | Required | Description |
|----------|-------|----------|-------------|
| `IWP_SCHEDULER_TOKEN` | Server | Yes | API authentication token |
| `IWP_API_URL` | Client | Yes | Full URL to the server's `api.php` |
| `IWP_API_TOKEN` | Client | Yes | Must match `IWP_SCHEDULER_TOKEN` on the server |

### Timezone

The server uses `Europe/Amsterdam` by default. To change this, search for `Europe/Amsterdam` in `cron-runner.php` and `api.php` and replace with your timezone.

## Security

- **Token authentication** — the API requires a Bearer token for all requests. Generate a strong token with `openssl rand -hex 32`.
- **No credentials in the client** — the MCP server on your machine only knows the API URL and token. Database credentials stay on your IWP server.
- **HTTPS required** — always run the API behind HTTPS.
- **`.htaccess` protection** — blocks web access to `install.php` and log files.
- **IWP's own signing** — updates are sent to WordPress sites using IWP's OpenSSL-signed request pipeline.

## Compatibility

### IWP
- InfiniteWP Admin Panel v2.x (self-hosted)

### Server Requirements
- PHP 7.4+ (8.0+ recommended)
- MySQL 5.7+ / MariaDB 10.3+

### Client Requirements
- Node.js 18+ (uses native `fetch`)
- `@modelcontextprotocol/sdk` v1.x

### Supported AI Tools
- Claude Code / Claude Desktop
- Cursor
- VS Code (GitHub Copilot)
- Windsurf
- Cline
- Continue.dev
- Any MCP-compatible client

## License

MIT — see [LICENSE](LICENSE).

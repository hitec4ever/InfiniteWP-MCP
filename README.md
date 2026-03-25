# InfiniteWP MCP Server

Manage all your WordPress sites through AI chat. This project adds an **MCP (Model Context Protocol) server** on top of [InfiniteWP](https://infinitewp.com), giving AI assistants like Claude the ability to view sites, run updates, manage schedules, and generate reports -- all through natural conversation.

## Features

- **Dashboard** -- overview of all sites, pending updates, active schedules
- **Site management** -- list sites, view details, search by name or URL
- **Update execution** -- update plugins, themes, core, or translations on any site
- **Scheduled updates** -- create cron-based schedules with day/time selection
- **Minimum update age** -- skip brand-new releases, only install updates that have been available for N hours
- **Exception management** -- exclude specific plugins/themes from auto-updates (globally or per-site)
- **Update history** -- full log of every update with status tracking
- **IWP history search** -- query the native IWP update history (e.g. "when was WooCommerce last updated on site X?")
- **Client reports** -- retrieve IWP-generated Phoenix template reports

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

The **MCP client** (runs on your laptop) communicates with the **API server** (runs alongside your IWP installation) over HTTPS with Bearer token authentication. The API reads from IWP's database and triggers updates through IWP's native execution pipeline.

## Directory Structure

```
client/           MCP server (runs on your machine)
  server.mjs      MCP tool definitions, connects via STDIO
  package.json    Node.js dependencies

server/           API + helpers (deploy on your IWP server)
  api.php         REST API backend
  install.php     Database table creation
  cron-runner.php Scheduled update executor
  .htaccess       Security rules
  mcp/
    run-update.php      Trigger updates for a site
    get-updates.php     Decode IWP stats into readable format
    generate-report.php Retrieve IWP client reports
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

4. **Set up the cron job** (recommended: every 5 minutes):

   ```bash
   */5 * * * * php /path/to/your/iwp-installation/scheduler/cron-runner.php >> /dev/null 2>&1
   ```

### Client Side (Your Machine)

1. **Install dependencies**:

   ```bash
   cd client/
   npm install
   ```

2. **Configure environment variables**:

   ```bash
   export IWP_API_URL="https://your-iwp-panel.example.com/scheduler/api.php"
   export IWP_API_TOKEN="your-generated-token-here"
   ```

3. **Add to your MCP client configuration** (e.g. Claude Desktop `claude_desktop_config.json`):

   ```json
   {
     "mcpServers": {
       "iwp-manager": {
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

## MCP Tools

| Tool | Description |
|------|-------------|
| `dashboard` | Overview of all sites, updates, schedules, and exceptions |
| `list_sites` | List managed WordPress sites with update status; supports filtering and search |
| `site_details` | View details and available updates for a specific site |
| `update_site` | Run updates on a site (plugins, themes, core, translations, or all) |
| `list_schedules` | Show all scheduled update plans |
| `list_exceptions` | Show all update exceptions |
| `add_exception` | Exclude a plugin or theme from automatic updates |
| `remove_exception` | Remove an update exception |
| `update_history` | View the scheduler's update execution log |
| `site_history` | Search IWP's native update history for a site (filter by plugin/theme name) |
| `generate_report` | Retrieve the most recent IWP client report for a site |

## Configuration

### Environment Variables

| Variable | Where | Required | Description |
|----------|-------|----------|-------------|
| `IWP_SCHEDULER_TOKEN` | Server | Yes | API authentication token (generate with `openssl rand -hex 32`) |
| `IWP_API_URL` | Client | Yes | Full URL to the server's `api.php` |
| `IWP_API_TOKEN` | Client | Yes | Must match `IWP_SCHEDULER_TOKEN` on the server |

### Timezone

The scheduler uses `Europe/Amsterdam` by default. To change this, search for `Europe/Amsterdam` in `cron-runner.php` and `api.php` and replace with your timezone.

## Security Notes

- The API token is the sole authentication mechanism for MCP/external access. Keep it secret.
- The `.htaccess` file blocks web access to `install.php` and `cron.log`.
- All API communication should be over HTTPS.
- The server files require access to IWP's `config.php` and database -- deploy them only on your IWP server.
- Never commit `.env` files or tokens to version control.

## Compatibility

- **InfiniteWP Admin Panel**: v2.x (self-hosted)
- **PHP**: 7.4+ (8.0+ recommended)
- **Node.js**: 18+ (for the MCP client, uses native `fetch`)
- **MySQL**: 5.7+ / MariaDB 10.3+
- **MCP SDK**: `@modelcontextprotocol/sdk` v1.x

## License

MIT -- see [LICENSE](LICENSE).

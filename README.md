# InfiniteWP MCP Server

Manage your WordPress sites through AI chat. This project adds an **MCP (Model Context Protocol) server** on top of [InfiniteWP](https://infinitewp.com), giving AI assistants the ability to view sites, trigger updates, query update history, and retrieve client reports — all through natural conversation.

## What Can You Do With It?

- *"Which sites have pending updates?"*
- *"Show me the details for example.com"*
- *"Update all plugins on example.com"*
- *"Update plugins on example.com but skip woocommerce"*
- *"When was WooCommerce last updated on example.com?"*
- *"Show me the client report for example.com"*

## Architecture

```
+------------------+         HTTPS/JSON         +---------------------------+
|  AI Assistant    |  <--------------------->   |  IWP Server               |
|  (Claude, etc.)  |                            |                           |
|                  |    Bearer Token Auth       |  /mcp/api.php         |
|  client/         |                            |  /mcp/helpers/            |
|  server.mjs      |                            |    run-update.php         |
|  (MCP over STDIO)|                            |    get-updates.php        |
+------------------+                            |    generate-report.php    |
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

The **MCP client** runs on your machine and communicates with the **API** (deployed alongside your IWP panel) over HTTPS with Bearer token auth. The API reads from IWP's database and triggers updates through IWP's native signed request pipeline — the same mechanism used when you click "Update" in the panel.

No database credentials ever leave your server.

## MCP Tools

| Tool | Description |
|------|-------------|
| `dashboard` | Overview of all sites and pending update counts |
| `list_sites` | List managed WordPress sites with update status; filter or search by name/URL |
| `site_details` | View a specific site's details and all available plugin/theme/core updates |
| `update_site` | Trigger updates on a site — choose plugins, themes, core, translations, or all; optionally exclude specific slugs |
| `site_history` | Search IWP's update history for a site; filter by plugin/theme name (e.g. "when was WooCommerce updated?") |
| `generate_report` | Retrieve the most recent IWP client report (Phoenix template) for a site |

## Installation

### Server Side (IWP Panel)

1. **Copy server files** into your InfiniteWP installation:

   ```bash
   cp -r server/ /path/to/your/iwp-installation/mcp/
   ```

   The `mcp/` directory should sit next to IWP's `config.php`.

2. **Set the API token** as an environment variable:

   ```bash
   # Generate a secure token
   openssl rand -hex 32

   # Apache — add to .htaccess or vhost:
   SetEnv IWP_MCP_TOKEN your-generated-token-here

   # Nginx + PHP-FPM — add to server block:
   fastcgi_param IWP_MCP_TOKEN your-generated-token-here;
   ```

### Client Side (Your Machine)

1. **Install dependencies**:

   ```bash
   cd client/
   npm install
   ```

2. **Add to your MCP client config**:

   **Claude Code** (`~/.claude/settings.json`):
   ```json
   {
     "mcpServers": {
       "iwp": {
         "command": "node",
         "args": ["/path/to/client/server.mjs"],
         "env": {
           "IWP_API_URL": "https://your-iwp-panel.example.com/mcp/api.php",
           "IWP_API_TOKEN": "your-generated-token-here"
         }
       }
     }
   }
   ```

   **Claude Desktop** (`claude_desktop_config.json`): same format.

   **Cursor / VS Code / Windsurf**: add the same to your MCP settings.

## Configuration

| Variable | Where | Required | Description |
|----------|-------|----------|-------------|
| `IWP_MCP_TOKEN` | Server | Yes | API authentication token |
| `IWP_API_URL` | Client | Yes | Full URL to `api.php` on your server |
| `IWP_API_TOKEN` | Client | Yes | Must match `IWP_MCP_TOKEN` |

## Security

- The API token is the sole auth mechanism for MCP access. Keep it secret.
- All communication should be over HTTPS.
- Database credentials stay on your IWP server — the MCP client never sees them.
- Updates use IWP's OpenSSL-signed request pipeline.

## Compatibility

**IWP:** v2.x (self-hosted)
**Server:** PHP 7.4+, MySQL 5.7+ / MariaDB 10.3+
**Client:** Node.js 18+

**Supported AI tools:**
Claude Code, Claude Desktop, Cursor, VS Code (Copilot), Windsurf, Cline, Continue.dev, and any MCP-compatible client.

## License

MIT — see [LICENSE](LICENSE).

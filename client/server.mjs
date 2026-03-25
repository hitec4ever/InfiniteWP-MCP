#!/usr/bin/env node

/**
 * InfiniteWP MCP Server
 *
 * Gives AI assistants (Claude, Cursor, VS Code, etc.) the ability to
 * read site data, trigger updates, and query history from an InfiniteWP
 * panel — all through natural conversation.
 *
 * Required env vars:
 *   IWP_API_URL   - Full URL to api.php on your IWP server
 *   IWP_API_TOKEN - Bearer token (must match server-side IWP_MCP_TOKEN)
 */

import { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import { StdioServerTransport } from "@modelcontextprotocol/sdk/server/stdio.js";
import { z } from "zod";

const API_URL = process.env.IWP_API_URL;
const API_TOKEN = process.env.IWP_API_TOKEN;

if (!API_URL) {
  console.error("IWP_API_URL is required (e.g. https://your-iwp-panel.example.com/mcp/api.php)");
  process.exit(1);
}
if (!API_TOKEN) {
  console.error("IWP_API_TOKEN is required");
  process.exit(1);
}

// --- API helper --------------------------------------------------------
async function api(action, params = {}, method = "GET", body = null) {
  const url = new URL(API_URL);
  url.searchParams.set("action", action);
  for (const [k, v] of Object.entries(params)) {
    url.searchParams.set(k, v);
  }

  const opts = {
    method,
    headers: {
      Authorization: `Bearer ${API_TOKEN}`,
      Accept: "application/json",
    },
  };

  if (body) {
    opts.headers["Content-Type"] = "application/json";
    opts.body = JSON.stringify(body);
  }

  const res = await fetch(url, opts);
  if (!res.ok) {
    throw new Error(`API error: ${res.status} ${await res.text()}`);
  }
  return res.json();
}

// Helper: find a site by name, URL, or ID
async function findSite(site) {
  const sites = await api("sites");
  const q = site.toLowerCase();
  return sites.find(
    (s) =>
      String(s.id) === site ||
      (s.name || "").toLowerCase().includes(q) ||
      (s.url || "").toLowerCase().includes(q)
  );
}

// --- MCP Server --------------------------------------------------------
const server = new McpServer({
  name: "iwp-manager",
  version: "1.0.0",
});

// === TOOL: Dashboard ===================================================
server.tool(
  "dashboard",
  "Overview of all managed sites and pending updates",
  {},
  async () => {
    const data = await api("dashboard");
    const text = `## IWP Dashboard

| Metric | Value |
|--------|-------|
| Total sites | ${data.totalSites} |
| Sites with updates | ${data.sitesWithUpdates} |
| Plugin updates | ${data.totalPluginUpdates} |
| Theme updates | ${data.totalThemeUpdates} |`;

    return { content: [{ type: "text", text }] };
  }
);

// === TOOL: List sites ==================================================
server.tool(
  "list_sites",
  "List all managed WordPress sites with their update status",
  {
    filter: z.enum(["all", "with-updates", "no-updates"]).optional().describe("Filter sites"),
    search: z.string().optional().describe("Search by name or URL"),
  },
  async ({ filter, search }) => {
    const sites = await api("sites");

    let filtered = sites;
    if (filter === "with-updates") filtered = sites.filter((s) => s.totalUpdates > 0);
    else if (filter === "no-updates") filtered = sites.filter((s) => s.totalUpdates === 0);
    if (search) {
      const q = search.toLowerCase();
      filtered = filtered.filter(
        (s) => (s.name || "").toLowerCase().includes(q) || (s.url || "").toLowerCase().includes(q)
      );
    }

    const lines = filtered.map((s) => {
      const updates = [];
      if (s.pluginUpdates > 0) updates.push(`${s.pluginUpdates} plugins`);
      if (s.themeUpdates > 0) updates.push(`${s.themeUpdates} themes`);
      if (s.coreUpdate) updates.push("WP core");
      const status = updates.length ? updates.join(", ") : "up-to-date";
      return `| ${s.name} | ${s.url} | WP ${s.wpVersion} | ${status} | ${s.id} |`;
    });

    return {
      content: [
        {
          type: "text",
          text: `${filtered.length} sites found:\n\n| Name | URL | WP | Updates | ID |\n|------|-----|----|---------|----||\n${lines.join("\n")}`,
        },
      ],
    };
  }
);

// === TOOL: Site details ================================================
server.tool(
  "site_details",
  "View details and available updates for a specific site",
  { site: z.string().describe("Site name, URL, or ID") },
  async ({ site }) => {
    const s = await findSite(site);
    if (!s) return { content: [{ type: "text", text: `Site "${site}" not found.` }] };

    const updates = await api("updates", { site_id: s.id });
    const siteUpdates = updates.find((u) => u.siteId === s.id) || {};

    let updateLines = [];
    if (siteUpdates.plugins?.length) {
      updateLines.push("**Plugin updates:**");
      siteUpdates.plugins.forEach((p) => updateLines.push(`  - ${p.name} (${p.slug}): ${p.oldVersion} -> ${p.newVersion}`));
    }
    if (siteUpdates.themes?.length) {
      updateLines.push("**Theme updates:**");
      siteUpdates.themes.forEach((t) => updateLines.push(`  - ${t.name}: ${t.oldVersion} -> ${t.newVersion}`));
    }
    if (siteUpdates.core) updateLines.push(`**Core update:** ${siteUpdates.core.current} -> ${siteUpdates.core.new}`);
    if (!updateLines.length) updateLines.push("No updates available.");

    const text = `## ${s.name}
- **URL**: ${s.url}
- **WordPress**: ${s.wpVersion}
- **Plugin updates**: ${s.pluginUpdates}
- **Theme updates**: ${s.themeUpdates}
- **Core update**: ${s.coreUpdate ? "Yes" : "No"}

### Available updates
${updateLines.join("\n")}`;

    return { content: [{ type: "text", text }] };
  }
);

// === TOOL: Update site =================================================
server.tool(
  "update_site",
  "Run updates on a site. Can update plugins, themes, core, translations, or all at once.",
  {
    site: z.string().describe("Site name, URL, or ID"),
    type: z.enum(["plugins", "themes", "core", "translations", "all"]).describe("What to update"),
    slugs: z.array(z.string()).optional().describe("Only update these specific plugin/theme slugs"),
    exclude: z.array(z.string()).optional().describe("Skip these plugin/theme slugs"),
  },
  async ({ site, type, slugs, exclude }) => {
    const s = await findSite(site);
    if (!s) return { content: [{ type: "text", text: `Site "${site}" not found.` }] };

    const result = await api("run-update", {}, "POST", {
      siteId: s.id,
      type,
      slugs: slugs || [],
      exclude: exclude || [],
    });

    return { content: [{ type: "text", text: `**Update ${s.name}:**\n\n\`\`\`\n${result.output || "Started"}\n\`\`\`` }] };
  }
);

// === TOOL: Search plugin/theme across all sites ========================
server.tool(
  "search_plugin",
  "Find which sites have a specific plugin or theme installed. Shows version, active status, and whether an update is available.",
  {
    search: z.string().describe("Plugin or theme name/slug to search for (e.g. 'woocommerce', 'yoast')"),
    type: z.enum(["plugin", "theme"]).optional().describe("Search for plugins or themes (default: plugin)"),
  },
  async ({ search, type }) => {
    const matches = await api("search-plugin", { search, type: type || "plugin" });

    if (!matches.length) {
      return { content: [{ type: "text", text: `No ${type || "plugin"} found matching "${search}" on any site.` }] };
    }

    const lines = matches.map((m) => {
      const status = m.isActive ? "active" : "inactive";
      const update = m.hasUpdate ? ` -> **${m.newVersion}** available` : "";
      return `| ${m.siteName} | ${m.name} | ${m.version}${update} | ${status} |`;
    });

    const summary = `Found "${search}" on **${matches.length}** site(s). Updates available: **${matches.filter((m) => m.hasUpdate).length}**`;

    return {
      content: [
        {
          type: "text",
          text: `## ${type || "Plugin"} search: "${search}"\n\n${summary}\n\n| Site | Name | Version | Status |\n|------|------|---------|--------|\n${lines.join("\n")}`,
        },
      ],
    };
  }
);

// === TOOL: Bulk update =================================================
server.tool(
  "bulk_update",
  "Update plugins/themes/core across multiple sites at once. Can target specific slugs or exclude certain ones.",
  {
    sites: z.array(z.string()).describe("Site names, URLs, or IDs to update"),
    type: z.enum(["plugins", "themes", "core", "translations", "all"]).describe("What to update"),
    slugs: z.array(z.string()).optional().describe("Only update these specific plugin/theme slugs"),
    exclude: z.array(z.string()).optional().describe("Skip these plugin/theme slugs"),
  },
  async ({ sites: siteQueries, type, slugs, exclude }) => {
    // Resolve site names/URLs to IDs
    const allSites = await api("sites");
    const resolvedIds = [];
    const notFound = [];

    for (const q of siteQueries) {
      const ql = q.toLowerCase();
      const match = allSites.find(
        (s) =>
          String(s.id) === q ||
          (s.name || "").toLowerCase().includes(ql) ||
          (s.url || "").toLowerCase().includes(ql)
      );
      if (match) resolvedIds.push(match.id);
      else notFound.push(q);
    }

    if (!resolvedIds.length) {
      return { content: [{ type: "text", text: `No matching sites found for: ${siteQueries.join(", ")}` }] };
    }

    const result = await api("bulk-update", {}, "POST", {
      siteIds: resolvedIds,
      type,
      slugs: slugs || [],
      exclude: exclude || [],
    });

    const lines = (result.results || []).map((r) => `### ${r.siteName}\n\`\`\`\n${r.output || "Started"}\n\`\`\``);
    let text = `## Bulk update (${type}) — ${resolvedIds.length} site(s)\n\n${lines.join("\n\n")}`;
    if (notFound.length) {
      text += `\n\n**Not found:** ${notFound.join(", ")}`;
    }

    return { content: [{ type: "text", text }] };
  }
);

// === TOOL: Site history ================================================
server.tool(
  "site_history",
  "Search the IWP update history for a site. Filter by plugin/theme name to answer questions like 'when was WooCommerce last updated?'",
  {
    site: z.string().describe("Site name, URL, or ID"),
    search: z.string().optional().describe("Filter by plugin/theme name or slug (e.g. 'woocommerce')"),
    type: z.enum(["all", "plugin", "theme", "core", "backup", "clientPlugin"]).optional().describe("Filter by type"),
    limit: z.number().optional().describe("Max results (default 30)"),
  },
  async ({ site, search, type, limit }) => {
    const s = await findSite(site);
    if (!s) return { content: [{ type: "text", text: `Site "${site}" not found.` }] };

    const params = { site_id: s.id, limit: limit || 30 };
    if (search) params.search = search;
    if (type && type !== "all") params.type = type;

    const history = await api("site-history", params);

    if (!history.length) {
      const note = search ? ` for "${search}"` : "";
      return { content: [{ type: "text", text: `No results found${note} on ${s.name}.` }] };
    }

    const lines = history.map((h) => {
      const status = { completed: "OK", success: "OK", error: "FAIL", netError: "FAIL", pending: "WAIT" }[h.status] || h.status;
      return `${status} **${h.date}** -- ${h.detailedAction}: **${h.uniqueName}**${h.itemStatus ? ` (${h.itemStatus})` : ""}`;
    });

    const label = search ? ` (search: "${search}")` : "";
    return { content: [{ type: "text", text: `## Update history: ${s.name}${label}\n\n${lines.join("\n")}` }] };
  }
);

// === TOOL: Generate report =============================================
server.tool(
  "generate_report",
  "Generate a client report for a site using IWP's professional template with your branding. Returns a link to the HTML report.",
  { site: z.string().describe("Site name, URL, or ID") },
  async ({ site }) => {
    const s = await findSite(site);
    if (!s) return { content: [{ type: "text", text: `Site "${site}" not found.` }] };

    const result = await api("generate-report", {}, "POST", { siteId: s.id });

    if (result.success && result.url) {
      return {
        content: [
          {
            type: "text",
            text: `## Report for ${result.site}\n\n**Link**: ${result.url}\n\n- Template: ${result.template}\n- Plugin updates: ${result.pluginUpdates}\n- Theme updates: ${result.themeUpdates}\n- Core updates: ${result.coreUpdates}\n- Generated: ${result.generatedAt}`,
          },
        ],
      };
    }

    return { content: [{ type: "text", text: `Failed to generate report: ${result.error || "unknown error"}` }] };
  }
);

// === Start =============================================================
const transport = new StdioServerTransport();
await server.connect(transport);

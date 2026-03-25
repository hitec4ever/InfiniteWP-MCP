#!/usr/bin/env node

/**
 * IWP MCP Server
 * Provides tools to manage WordPress sites via InfiniteWP through chat.
 * Communicates with the scheduler API over HTTPS — no direct DB access.
 *
 * Required env vars:
 *   IWP_API_URL   - e.g. https://your-iwp-panel.example.com/scheduler/api.php
 *   IWP_API_TOKEN - the API token (must match the server-side env var)
 */

import { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import { StdioServerTransport } from "@modelcontextprotocol/sdk/server/stdio.js";
import { z } from "zod";

const API_URL = process.env.IWP_API_URL;
const API_TOKEN = process.env.IWP_API_TOKEN;

if (!API_URL) {
  console.error("IWP_API_URL is required (e.g. https://your-iwp-panel.example.com/scheduler/api.php)");
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
      "Authorization": `Bearer ${API_TOKEN}`,
      "Accept": "application/json",
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

// --- MCP Server --------------------------------------------------------
const server = new McpServer({
  name: "iwp-manager",
  version: "1.0.0",
});

// === TOOL: Dashboard ===================================================
server.tool(
  "dashboard",
  "Show an overview of all sites, updates, schedules, and exceptions",
  {},
  async () => {
    const data = await api("dashboard");
    const text = `
## IWP Dashboard

| Metric | Value |
|--------|-------|
| Total sites | ${data.totalSites} |
| Sites with updates | ${data.sitesWithUpdates} |
| Plugin updates | ${data.totalPluginUpdates} |
| Theme updates | ${data.totalThemeUpdates} |
| Active schedules | ${data.activeSchedules} |
| Exceptions | ${data.totalExceptions} |
`.trim();

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
    if (filter === "with-updates") {
      filtered = sites.filter(s => s.totalUpdates > 0);
    } else if (filter === "no-updates") {
      filtered = sites.filter(s => s.totalUpdates === 0);
    }
    if (search) {
      const q = search.toLowerCase();
      filtered = filtered.filter(s =>
        (s.name || "").toLowerCase().includes(q) || (s.url || "").toLowerCase().includes(q)
      );
    }

    const lines = filtered.map(s => {
      const updates = [];
      if (s.pluginUpdates > 0) updates.push(`${s.pluginUpdates} plugins`);
      if (s.themeUpdates > 0) updates.push(`${s.themeUpdates} themes`);
      if (s.coreUpdate) updates.push("WP core");
      const status = updates.length ? updates.join(", ") : "up-to-date";
      return `| ${s.name} | ${s.url} | WP ${s.wpVersion} | ${status} | ${s.id} |`;
    });

    const text = `${filtered.length} sites found:\n\n| Name | URL | WP | Updates | ID |\n|------|-----|----|---------|----||\n${lines.join("\n")}`;
    return { content: [{ type: "text", text }] };
  }
);

// === TOOL: Site details ================================================
server.tool(
  "site_details",
  "View details and available updates for a specific site",
  {
    site: z.string().describe("Site name, URL, or ID"),
  },
  async ({ site }) => {
    const sites = await api("sites");
    const q = site.toLowerCase();
    const s = sites.find(s =>
      String(s.id) === site || (s.name || "").toLowerCase().includes(q) || (s.url || "").toLowerCase().includes(q)
    );

    if (!s) return { content: [{ type: "text", text: `Site "${site}" not found.` }] };

    const updates = await api("updates", { site_id: s.id });
    const siteUpdates = updates.find(u => u.siteId === s.id) || {};

    let updateLines = [];
    if (siteUpdates.plugins?.length) {
      updateLines.push("**Plugin updates:**");
      siteUpdates.plugins.forEach(p => {
        updateLines.push(`  - ${p.name} (${p.slug}): ${p.oldVersion} -> ${p.newVersion}`);
      });
    }
    if (siteUpdates.themes?.length) {
      updateLines.push("**Theme updates:**");
      siteUpdates.themes.forEach(t => {
        updateLines.push(`  - ${t.name}: ${t.oldVersion} -> ${t.newVersion}`);
      });
    }
    if (siteUpdates.core) {
      updateLines.push(`**Core update:** ${siteUpdates.core.current} -> ${siteUpdates.core.new}`);
    }
    if (!updateLines.length) updateLines.push("No updates available.");

    const text = `
## ${s.name}
- **URL**: ${s.url}
- **WordPress**: ${s.wpVersion}
- **Plugin updates**: ${s.pluginUpdates}
- **Theme updates**: ${s.themeUpdates}
- **Core update**: ${s.coreUpdate ? "Yes" : "No"}

### Available updates
${updateLines.join("\n")}
`.trim();

    return { content: [{ type: "text", text }] };
  }
);

// === TOOL: Update site =================================================
server.tool(
  "update_site",
  "Run updates on a site. Can update plugins, themes, core, or all.",
  {
    site: z.string().describe("Site name, URL, or ID"),
    type: z.enum(["plugins", "themes", "core", "translations", "all"]).describe("What to update"),
    slugs: z.array(z.string()).optional().describe("Specific plugin/theme slugs (optional, defaults to all)"),
    exclude: z.array(z.string()).optional().describe("Plugin/theme slugs to skip"),
  },
  async ({ site, type, slugs, exclude }) => {
    // Find site
    const sites = await api("sites");
    const q = site.toLowerCase();
    const s = sites.find(s =>
      String(s.id) === site || (s.name || "").toLowerCase().includes(q) || (s.url || "").toLowerCase().includes(q)
    );
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

// === TOOL: List schedules ==============================================
server.tool(
  "list_schedules",
  "Show all scheduled update plans",
  {},
  async () => {
    const schedules = await api("schedules");
    const dayNames = ["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"];

    if (!schedules.length) {
      return { content: [{ type: "text", text: "No schedules found." }] };
    }

    const lines = schedules.map(s => {
      const days = s.daysOfWeek.split(",").map(d => dayNames[parseInt(d)]).join(", ");
      const types = [];
      if (s.updatePlugins) types.push("plugins");
      if (s.updateThemes) types.push("themes");
      if (s.updateCore) types.push("core");
      if (s.updateTranslations) types.push("translations");
      const active = s.isActive ? "Active" : "Inactive";
      return `- **${s.name}** [${active}] -- ${s.scheduleTime.substring(0, 5)} on ${days} -- ${types.join(", ")}${s.minUpdateAgeHours > 0 ? ` -- min ${s.minUpdateAgeHours}h old` : ""}\n  Next: ${s.nextRun || "n/a"} | Last: ${s.lastRun || "never"}`;
    });

    return { content: [{ type: "text", text: `${schedules.length} schedule(s):\n\n${lines.join("\n\n")}` }] };
  }
);

// === TOOL: List exceptions =============================================
server.tool(
  "list_exceptions",
  "Show all update exceptions",
  {},
  async () => {
    const exceptions = await api("exceptions");

    if (!exceptions.length) {
      return { content: [{ type: "text", text: "No exceptions configured." }] };
    }

    const lines = exceptions.map(e => {
      const scope = e.siteName ? `only ${e.siteName}` : "all sites";
      return `- **${e.name}** (${e.type}: \`${e.slug}\`) -- ${scope}${e.reason ? ` -- "${e.reason}"` : ""}`;
    });

    return { content: [{ type: "text", text: `${exceptions.length} exception(s):\n\n${lines.join("\n")}` }] };
  }
);

// === TOOL: Add exception ===============================================
server.tool(
  "add_exception",
  "Add an exception so a plugin or theme is not automatically updated",
  {
    type: z.enum(["plugin", "theme"]).describe("Type: plugin or theme"),
    slug: z.string().describe("Plugin/theme slug (e.g. 'woocommerce')"),
    name: z.string().optional().describe("Display name"),
    reason: z.string().optional().describe("Reason for the exception"),
  },
  async ({ type, slug, name, reason }) => {
    await api("exception-save", {}, "POST", {
      type, slug, name: name || slug, reason: reason || null,
    });
    return { content: [{ type: "text", text: `Exception added: **${name || slug}** (${type}) will no longer be auto-updated.` }] };
  }
);

// === TOOL: Remove exception ============================================
server.tool(
  "remove_exception",
  "Remove an update exception",
  {
    slug: z.string().describe("Plugin/theme slug to remove from exceptions"),
  },
  async ({ slug }) => {
    const exceptions = await api("exceptions");
    const match = exceptions.find(e => e.slug === slug);
    if (!match) return { content: [{ type: "text", text: `No exception found for "${slug}".` }] };

    await api("exception-delete", { id: match.id });
    return { content: [{ type: "text", text: `Exception for "${match.name}" removed.` }] };
  }
);

// === TOOL: Update history ==============================================
server.tool(
  "update_history",
  "View update history",
  {
    limit: z.number().optional().describe("Number of results (default 20)"),
  },
  async ({ limit }) => {
    const history = await api("history", { limit: limit || 20 });

    if (!history.length) {
      return { content: [{ type: "text", text: "No update history found." }] };
    }

    const lines = history.map(h => {
      const status = { success: "OK", failed: "FAIL", skipped: "SKIP", queued: "WAIT", running: "RUN" }[h.status] || "?";
      const ver = h.oldVersion && h.newVersion ? ` ${h.oldVersion} -> ${h.newVersion}` : "";
      return `${status} ${h.executedAt} | ${h.siteName} | ${h.type}: ${h.itemName || h.itemSlug}${ver}`;
    });

    return { content: [{ type: "text", text: `Last ${history.length} updates:\n\n${lines.join("\n")}` }] };
  }
);

// === TOOL: Site history (IWP) ==========================================
server.tool(
  "site_history",
  "Search the full IWP update history for a site. Can filter by plugin/theme name. Answers questions like 'when was WooCommerce last updated on site X?'",
  {
    site: z.string().describe("Site name, URL, or ID"),
    search: z.string().optional().describe("Search by plugin/theme name or slug (e.g. 'woocommerce', 'ninja-forms')"),
    type: z.enum(["all", "plugin", "theme", "core", "backup", "clientPlugin"]).optional().describe("Filter by type"),
    limit: z.number().optional().describe("Max results (default 30)"),
  },
  async ({ site, search, type, limit }) => {
    const sites = await api("sites");
    const q = site.toLowerCase();
    const s = sites.find(s =>
      String(s.id) === site || (s.name || "").toLowerCase().includes(q) || (s.url || "").toLowerCase().includes(q)
    );
    if (!s) return { content: [{ type: "text", text: `Site "${site}" not found.` }] };

    const params = { site_id: s.id, limit: limit || 30 };
    if (search) params.search = search;
    if (type && type !== "all") params.type = type;

    const history = await api("site-history", params);

    if (!history.length) {
      const searchNote = search ? ` for "${search}"` : "";
      return { content: [{ type: "text", text: `No results found${searchNote} on ${s.name}.` }] };
    }

    const lines = history.map(h => {
      const status = { completed: "OK", success: "OK", error: "FAIL", netError: "FAIL", pending: "WAIT" }[h.status] || h.status;
      return `${status} **${h.date}** -- ${h.detailedAction}: **${h.uniqueName}**${h.itemStatus ? ` (${h.itemStatus})` : ""}`;
    });

    const searchLabel = search ? ` (search: "${search}")` : "";
    return { content: [{ type: "text", text: `## Update history: ${s.name}${searchLabel}\n\n${lines.join("\n")}` }] };
  }
);

// === TOOL: Generate report =============================================
server.tool(
  "generate_report",
  "Retrieve the most recent client report for a site (generated by IWP's Phoenix template). Returns a link to the HTML report.",
  {
    site: z.string().describe("Site name, URL, or ID"),
  },
  async ({ site }) => {
    const sites = await api("sites");
    const q = site.toLowerCase();
    const s = sites.find(s =>
      String(s.id) === site || (s.name || "").toLowerCase().includes(q) || (s.url || "").toLowerCase().includes(q)
    );
    if (!s) return { content: [{ type: "text", text: `Site "${site}" not found.` }] };

    const result = await api("generate-report", {}, "POST", { siteId: s.id });

    if (result.success && result.url) {
      return { content: [{ type: "text", text: `## Report for ${result.site}\n\n**Link**: ${result.url}\n\n- Generated: ${result.generatedAt} (${result.age} ago)\n- Schedule: ${result.schedule}\n\nThis report was generated by IWP's professional Phoenix template.` }] };
    }

    // No report available
    let text = `No recent report found for **${s.name}**.`;
    if (result.schedules?.length > 0) {
      text += `\n\nThere are ${result.schedules.length} schedule(s), but no report has been generated yet. Use "Run Now" in the IWP panel (Client Reporting).`;
    } else {
      text += `\n\nNo report schedule exists for this site yet. You can create one in the IWP panel under **Addons > Client Reporting > Schedule Report**.`;
    }
    text += `\n\n**Tip**: To generate a report via chat, first create a schedule for this site in IWP.`;

    return { content: [{ type: "text", text }] };
  }
);

// === Start =============================================================
const transport = new StdioServerTransport();
await server.connect(transport);

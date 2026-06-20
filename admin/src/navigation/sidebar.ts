import { ref } from "vue";
import type { NavigationMenuItem } from "@nuxt/ui";

export const open = ref(false);

/**
 * Admin sidebar navigation, modelled on `admin/ADMIN_IA.md`.
 *
 * Two groups, consumed by `layouts/default.vue`:
 *   - `items[0]` — the main product nav (top, scrollable)
 *   - `items[1]` — ops "Utilities" (pinned to the bottom via `mt-auto`)
 *
 * Sections with `children` render as collapsible accordions in the vertical menu.
 */
export const items = [
  // ── items[0]: main product nav ───────────────────────────────────────────
  [
    {
      label: "Home",
      icon: "i-lucide-house",
      to: "/",
    },
    {
      // Content types are fetched live from `GET /v1/admin/content-types`.
      // Left empty until that API is integrated; children are populated at runtime
      // (a fresh install ships with the seeded "Pages" type as the day-one entry).
      label: "Content",
      icon: "i-lucide-layers",
      defaultOpen: true,
      children: [],
    },
    {
      label: "Media",
      icon: "i-lucide-image",
      to: "/media",
    },
    {
      label: "Extensions",
      icon: "i-lucide-blocks",
      to: "/extensions",
    },
    {
      label: "Users & Access",
      icon: "i-lucide-users",
      children: [
        {
          label: "Users",
          icon: "i-lucide-user",
          to: "/users",
        },
        {
          label: "Roles & Permissions",
          icon: "i-lucide-shield-check",
          to: "/roles",
        },
        {
          label: "Audit Log",
          icon: "i-lucide-scroll-text",
          to: "/audit-log",
        },
      ],
    },
    {
      label: "Developers",
      icon: "i-lucide-code-xml",
      children: [
        {
          label: "API Reference",
          icon: "i-lucide-book-open",
          to: "/developers/api-reference",
        },
        {
          label: "Documentation",
          icon: "i-lucide-library",
          to: "/developers/documentation",
        },
        {
          label: "API Keys",
          icon: "i-lucide-key-round",
          to: "/developers/api-keys",
        },
        {
          label: "Webhooks",
          icon: "i-lucide-webhook",
          to: "/developers/webhooks",
        },
      ],
    },
    {
      label: "Settings",
      icon: "i-lucide-settings",
      children: [
        {
          label: "Content Types",
          icon: "i-lucide-shapes",
          to: "/settings/content-types",
        },
        {
          label: "General",
          icon: "i-lucide-sliders-horizontal",
          to: "/settings/general",
        },
        {
          label: "Languages",
          icon: "i-lucide-languages",
          to: "/settings/languages",
        },
        {
          label: "Redirects",
          icon: "i-lucide-signpost",
          to: "/settings/redirects",
        },
        {
          label: "Email",
          icon: "i-lucide-mail",
          to: "/settings/email",
        },
        {
          label: "Import / Export",
          icon: "i-lucide-arrow-down-up",
          to: "/settings/import-export",
        },
      ],
    },
  ],
  // ── items[1]: ops, pinned to the bottom ──────────────────────────────────
  [
    {
      label: "Utilities",
      icon: "i-lucide-wrench",
      children: [
        {
          label: "Scheduled Tasks",
          icon: "i-lucide-calendar-clock",
          to: "/utilities/scheduled-tasks",
        },
        {
          label: "Health",
          icon: "i-lucide-heart-pulse",
          to: "/utilities/health",
        },
        {
          label: "Cache",
          icon: "i-lucide-database-zap",
          to: "/utilities/cache",
        },
      ],
    },
  ],
] satisfies NavigationMenuItem[][];

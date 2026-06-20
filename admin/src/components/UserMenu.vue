<script setup lang="ts">
import { computed, ref } from "vue";
import type { DropdownMenuItem } from "@nuxt/ui";
import { useColorMode } from '@vueuse/core'

const colorMode = useColorMode({initialValue: 'system'})

defineProps<{
  collapsed?: boolean;
}>();

const showLogoutConfirm = ref(false);


const items = computed<DropdownMenuItem[][]>(() => [
  [
    {
      type: "label",
      label: "Account",
    },
  ],
  [{
    label: 'Appearance',
    icon: 'i-lucide-monitor',
    children: [{
      label: 'System',
      icon: 'i-lucide-sun',
      type: 'checkbox',
      checked: colorMode.value === 'system',
      onSelect(e: Event) {
        e.preventDefault()

        colorMode.value = 'system'
      }
    },
    {
      label: 'Light',
      icon: 'i-lucide-sun',
      type: 'checkbox',
      checked: colorMode.value === 'light',
      onSelect(e: Event) {
        e.preventDefault()

        colorMode.value = 'light'
      }
    },
    {
      label: 'Dark',
      icon: 'i-lucide-moon',
      type: 'checkbox',
      checked: colorMode.value === 'dark',
      onUpdateChecked(checked: boolean) {
        if (checked) {
          colorMode.value = 'dark'
        }
      },
      onSelect(e: Event) {
        e.preventDefault()
      }
    }]
  }],
  [
    {
      label: 'Profile',
      icon: 'i-lucide-user'
    },
    {
      label: 'Security',
      icon: 'i-lucide-lock'
    },
    {
      label: "Log out",
      icon: "i-lucide-log-out",
      onSelect: () => { showLogoutConfirm.value = true; },
    },
  ],
]);
</script>

<template>
  <UDropdownMenu
    :items="items"
    :content="{ align: 'center', collisionPadding: 12 }"
    :ui="{ content: collapsed ? 'w-48' : 'w-(--reka-dropdown-menu-trigger-width)' }"
  >
    <UButton
      color="neutral"
      variant="ghost"
      block
      :square="collapsed"
      :label="collapsed ? undefined : 'Account'"
      :trailing-icon="collapsed ? undefined : 'i-lucide-chevrons-up-down'"
      class="data-[state=open]:bg-elevated"
      :ui="{
        trailingIcon: 'text-dimmed',
      }"
    >
      <template #leading>
        <UAvatar text="A" size="2xs" />
      </template>
    </UButton>

    <template #chip-leading="{ item }">
      <div class="inline-flex items-center justify-center shrink-0 size-5">
        <span
          class="rounded-full ring ring-bg bg-(--chip-light) dark:bg-(--chip-dark) size-2"
          :style="{
            '--chip-light': `var(--color-${(item as any).chip}-500)`,
            '--chip-dark': `var(--color-${(item as any).chip}-400)`,
          }"
        />
      </div>
    </template>
  </UDropdownMenu>
</template>

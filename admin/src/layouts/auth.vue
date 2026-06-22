<template>
  <div class="min-h-screen flex items-center justify-center px-4 relative overlay">
    <div class="gradient" />

    <UPageCard
      class="max-w-md w-full backdrop-sepia-0 bg-white/35! dark:bg-gray-900/60! border-0 ring-0 rounded-2xl dark:shadow-black/20 p-5 relative z-10"
    >
      <div class="flex w-full">
        <AppLogo class="w-auto h-18 shrink-0 " :show-text="true" />
      </div>
      <RouterView />
    </UPageCard>
  </div>
</template>

<style scoped>
.gradient {
  position: absolute;
  inset: 0;
  pointer-events: none;
  background: radial-gradient(
    50% 50% at 50% 50%,
    rgb(9 87 251 / 0.12) 0%,
    rgb(255 255 255) 100%
  );
}

:root.dark .gradient {
  background: radial-gradient(
    50% 50% at 50% 50%,
    rgb(9 87 251 / 0.08) 0%,
    rgb(17 24 39) 100%
  );
}

.overlay {
  background-size: 100px 100px;
  background-image:
    linear-gradient(to right, rgb(9 87 251 / 0.05) 0.5px, transparent 0.5px),
    linear-gradient(to bottom, rgb(9 87 251 / 0.05) 0.5px, transparent 0.5px);
  background-color: rgb(255 255 255);
}

:root.dark .overlay {
  background-image:
    linear-gradient(to right, rgb(9 87 251 / 0.08) 0.5px, transparent 0.5px),
    linear-gradient(to bottom, rgb(9 87 251 / 0.08) 0.5px, transparent 0.5px);
  background-color: rgb(17 24 39);
}
</style>


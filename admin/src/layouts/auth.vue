<script lang="ts" setup></script>

<template>
  <div
    class="relative flex min-h-screen items-center justify-center overflow-hidden bg-linear-to-r from-[#f7f7f7] to-white px-4 py-8"
  >
    <div
      class="pointer-events-none absolute inset-y-0 left-0 w-[18%] bg-linear-to-r from-white/75 via-white/20 to-transparent"
    />
    <div
      class="pointer-events-none absolute inset-y-0 right-0 w-[18%] bg-linear-to-l from-white/75 via-white/20 to-transparent"
    />
    <div
      class="pointer-events-none absolute left-1/2 top-1/2 h-136 w-136 -translate-x-1/2 translate-y-[-58%] rounded-full bg-[radial-gradient(circle,rgba(255,255,255,0.96)_0%,rgba(255,255,255,0.82)_34%,rgba(255,255,255,0.35)_62%,rgba(255,255,255,0)_80%)] blur-2xl"
    />

    <section class="relative w-full max-w-110">
      <div
        class="pointer-events-none absolute inset-[-12px_-13px_-13px_10px] z-10 rounded-[28px] border border-[#e0e5eb]/80"
      />

      <div
        class="relative rounded-[18px] border border-[#dae1ea]/90 bg-white/60 px-7 pb-9 pt-8.5 backdrop-blur-[6px]"
      >
        <!-- <img :src="logoMain" alt="Keed E-Ticketing" /> -->
        <RouterView />
      </div>
    </section>
  </div>
</template>

<script setup lang="ts">
definePage({ meta: { requiresAuth: true } });
</script>

<template>{{ $route.params.type }}/{{ $route.params.uuid }}/versions</template>

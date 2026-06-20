<script setup lang="ts">
definePage({ meta: { requiresAuth: true } });
</script>

<template>{{ $route.params.type }}</template>

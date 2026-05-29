<template>
  <router-view></router-view>
</template>

<script setup>
import { onMounted } from 'vue';

onMounted(() => {
  // Read theme from localStorage and apply
  const isDark = localStorage.getItem('admin_theme') === 'dark';
  if (isDark) {
    document.documentElement.classList.add('dark');
  } else {
    document.documentElement.classList.remove('dark');
  }
});
</script>

<style>
html, body, #app {
  margin: 0;
  padding: 0;
  height: 100%;
  width: 100%;
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
  background-color: var(--el-bg-color);
  color: var(--el-text-color-primary);
}
</style>

import { ref, onMounted, onBeforeUnmount } from 'vue';

export function useMobile() {
  // Initialize on creation (works in setup block before mount)
  const isMobile = ref(typeof window !== 'undefined' ? window.innerWidth <= 768 : false);

  const checkMobile = () => {
    isMobile.value = window.innerWidth <= 768;
  };

  onMounted(() => {
    window.addEventListener('resize', checkMobile);
  });

  onBeforeUnmount(() => {
    window.removeEventListener('resize', checkMobile);
  });

  return {
    isMobile
  };
}

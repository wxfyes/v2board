<template>
  <el-container class="layout-container">
    <!-- Sidebar -->
    <el-aside v-if="!isMobile" :width="isCollapse ? '64px' : '220px'" class="aside">
      <div class="brand-logo flex-center" :class="{ 'logo-collapsed': isCollapse }">
        <el-icon class="brand-icon"><Platform /></el-icon>
        <span v-show="!isCollapse" class="brand-name">{{ systemName }}</span>
      </div>
      
      <el-menu
        :default-active="activeMenu"
        class="el-menu-vertical"
        :collapse="isCollapse"
        :default-openeds="['server-group', 'finance-group', 'user-group']"
        router
      >
        <el-menu-item index="/dashboard">
          <el-icon><Odometer /></el-icon>
          <template #title>仪表盘</template>
        </el-menu-item>
        
        <el-sub-menu index="server-group">
          <template #title>
            <el-icon><Connection /></el-icon>
            <span>节点网络</span>
          </template>
          <el-menu-item index="/servers">
            <el-icon><Link /></el-icon>
            <span>节点管理</span>
          </el-menu-item>
          <el-menu-item index="/groups">
            <el-icon><HelpFilled /></el-icon>
            <span>权限组管理</span>
          </el-menu-item>
          <el-menu-item index="/routes">
            <el-icon><Share /></el-icon>
            <span>路由管理</span>
          </el-menu-item>
        </el-sub-menu>
        
        <el-sub-menu index="finance-group">
          <template #title>
            <el-icon><Goods /></el-icon>
            <span>财务销售</span>
          </template>
          <el-menu-item index="/plans">
            <el-icon><Tickets /></el-icon>
            <span>订阅管理</span>
          </el-menu-item>
          <el-menu-item index="/cards">
            <el-icon><Goods /></el-icon>
            <span>发卡管理</span>
          </el-menu-item>
          <el-menu-item index="/orders">
            <el-icon><Document /></el-icon>
            <span>订单管理</span>
          </el-menu-item>
          <el-menu-item index="/coupons">
            <el-icon><PriceTag /></el-icon>
            <span>优惠券管理</span>
          </el-menu-item>
          <el-menu-item index="/giftcards">
            <el-icon><CreditCard /></el-icon>
            <span>礼品卡管理</span>
          </el-menu-item>
        </el-sub-menu>
        
        <el-sub-menu index="user-group">
          <template #title>
            <el-icon><User /></el-icon>
            <span>用户与支持</span>
          </template>
          <el-menu-item index="/users">
            <el-icon><Avatar /></el-icon>
            <span>用户管理</span>
          </el-menu-item>
          <el-menu-item index="/notices">
            <el-icon><Notification /></el-icon>
            <span>公告管理</span>
          </el-menu-item>
          <el-menu-item index="/tickets">
            <el-icon><ChatLineSquare /></el-icon>
            <span>工单管理</span>
          </el-menu-item>
          <el-menu-item index="/knowledges">
            <el-icon><Notebook /></el-icon>
            <span>知识库管理</span>
          </el-menu-item>
          <el-menu-item index="/security-audit">
            <el-icon><Lock /></el-icon>
            <span>安全审计</span>
          </el-menu-item>
        </el-sub-menu>
        
        <el-sub-menu index="settings-group">
          <template #title>
            <el-icon><Setting /></el-icon>
            <span>设置</span>
          </template>
          <el-menu-item index="/settings">
            <el-icon><Operation /></el-icon>
            <span>系统配置</span>
          </el-menu-item>
          <el-menu-item index="/payments">
            <el-icon><CreditCard /></el-icon>
            <span>支付配置</span>
          </el-menu-item>
          <el-menu-item index="/themes">
            <el-icon><Brush /></el-icon>
            <span>主题配置</span>
          </el-menu-item>
        </el-sub-menu>
        <el-menu-item index="/queues">
          <el-icon><Cpu /></el-icon>
          <template #title>队列监控</template>
        </el-menu-item>
      </el-menu>
    </el-aside>
    
    <!-- Main Area -->
    <el-container class="main-container">
      <!-- Header -->
      <el-header class="header flex-between">
        <div class="header-left flex-center">
          <el-icon class="toggle-icon" @click="isMobile ? (drawerVisible = true) : (isCollapse = !isCollapse)">
            <Menu v-if="isMobile" />
            <Fold v-else-if="!isCollapse" />
            <Expand v-else />
          </el-icon>
          <span class="page-title">{{ route.meta.title || '管理后台' }}</span>
        </div>
        
        <div class="header-right flex-center">
          <!-- Theme Toggle -->
          <div class="header-btn" @click="toggleTheme">
            <el-icon v-if="isDark"><Sunny /></el-icon>
            <el-icon v-else><Moon /></el-icon>
          </div>
          
          <!-- Dropdown -->
          <el-dropdown trigger="click" @command="handleCommand">
            <span class="user-profile flex-center">
              <el-avatar :size="30" class="user-avatar">AD</el-avatar>
              <span class="username" v-show="!isMobile">管理员</span>
              <el-icon class="el-icon--right"><arrow-down /></el-icon>
            </span>
            <template #dropdown>
              <el-dropdown-menu>
                <el-dropdown-item command="logout">退出登录</el-dropdown-item>
              </el-dropdown-menu>
            </template>
          </el-dropdown>
        </div>
      </el-header>
      
      <!-- Content -->
      <el-main class="main-content">
        <el-scrollbar class="scrollbar-wrapper">
          <div class="view-wrapper">
            <router-view></router-view>
          </div>
        </el-scrollbar>
      </el-main>
    </el-container>

    <!-- Mobile Drawer Menu -->
    <el-drawer
      v-if="isMobile"
      v-model="drawerVisible"
      direction="ltr"
      size="240px"
      :with-header="false"
      class="mobile-menu-drawer"
    >
      <div class="brand-logo flex-center" style="height: 60px; border-bottom: 1px solid var(--el-border-color-extra-light);">
        <el-icon class="brand-icon"><Platform /></el-icon>
        <span class="brand-name">{{ systemName }}</span>
      </div>
      
      <el-menu
        :default-active="activeMenu"
        class="el-menu-vertical"
        :default-openeds="['server-group', 'finance-group', 'user-group']"
        router
        @select="drawerVisible = false"
      >
        <el-menu-item index="/dashboard">
          <el-icon><Odometer /></el-icon>
          <template #title>仪表盘</template>
        </el-menu-item>
        
        <el-sub-menu index="server-group">
          <template #title>
            <el-icon><Connection /></el-icon>
            <span>节点网络</span>
          </template>
          <el-menu-item index="/servers">
            <el-icon><Link /></el-icon>
            <span>节点管理</span>
          </el-menu-item>
          <el-menu-item index="/groups">
            <el-icon><HelpFilled /></el-icon>
            <span>权限组管理</span>
          </el-menu-item>
          <el-menu-item index="/routes">
            <el-icon><Share /></el-icon>
            <span>路由管理</span>
          </el-menu-item>
        </el-sub-menu>
        
        <el-sub-menu index="finance-group">
          <template #title>
            <el-icon><Goods /></el-icon>
            <span>财务销售</span>
          </template>
          <el-menu-item index="/plans">
            <el-icon><Tickets /></el-icon>
            <span>订阅管理</span>
          </el-menu-item>
          <el-menu-item index="/cards">
            <el-icon><Goods /></el-icon>
            <span>发卡管理</span>
          </el-menu-item>
          <el-menu-item index="/orders">
            <el-icon><Document /></el-icon>
            <span>订单管理</span>
          </el-menu-item>
          <el-menu-item index="/coupons">
            <el-icon><PriceTag /></el-icon>
            <span>优惠券管理</span>
          </el-menu-item>
          <el-menu-item index="/giftcards">
            <el-icon><CreditCard /></el-icon>
            <span>礼品卡管理</span>
          </el-menu-item>
        </el-sub-menu>
        
        <el-sub-menu index="user-group">
          <template #title>
            <el-icon><User /></el-icon>
            <span>用户与支持</span>
          </template>
          <el-menu-item index="/users">
            <el-icon><Avatar /></el-icon>
            <span>用户管理</span>
          </el-menu-item>
          <el-menu-item index="/notices">
            <el-icon><Notification /></el-icon>
            <span>公告管理</span>
          </el-menu-item>
          <el-menu-item index="/tickets">
            <el-icon><ChatLineSquare /></el-icon>
            <span>工单管理</span>
          </el-menu-item>
          <el-menu-item index="/knowledges">
            <el-icon><Notebook /></el-icon>
            <span>知识库管理</span>
          </el-menu-item>
          <el-menu-item index="/security-audit">
            <el-icon><Lock /></el-icon>
            <span>安全审计</span>
          </el-menu-item>
        </el-sub-menu>
        
        <el-sub-menu index="settings-group">
          <template #title>
            <el-icon><Setting /></el-icon>
            <span>设置</span>
          </template>
          <el-menu-item index="/settings">
            <el-icon><Operation /></el-icon>
            <span>系统配置</span>
          </el-menu-item>
          <el-menu-item index="/payments">
            <el-icon><CreditCard /></el-icon>
            <span>支付配置</span>
          </el-menu-item>
          <el-menu-item index="/themes">
            <el-icon><Brush /></el-icon>
            <span>主题配置</span>
          </el-menu-item>
        </el-sub-menu>
        <el-menu-item index="/queues">
          <el-icon><Cpu /></el-icon>
          <template #title>队列监控</template>
        </el-menu-item>
      </el-menu>
    </el-drawer>
  </el-container>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ElMessageBox, ElMessage } from 'element-plus';

const route = useRoute();
const router = useRouter();
const isCollapse = ref(false);
const isDark = ref(false);
const isMobile = ref(false);
const drawerVisible = ref(false);

const activeMenu = computed(() => route.path);
const systemName = computed(() => {
  return window.settings?.title || '管理系统';
});

const checkMobile = () => {
  isMobile.value = window.innerWidth <= 768;
};

const toggleTheme = () => {
  isDark.value = !isDark.value;
  if (isDark.value) {
    document.documentElement.classList.add('dark');
    localStorage.setItem('admin_theme', 'dark');
  } else {
    document.documentElement.classList.remove('dark');
    localStorage.setItem('admin_theme', 'light');
  }
};

const handleCommand = (command) => {
  if (command === 'logout') {
    ElMessageBox.confirm('确定退出登录吗？', '提示', {
      confirmButtonText: '确定',
      cancelButtonText: '取消',
      type: 'warning',
    }).then(() => {
      localStorage.removeItem('authorization');
      ElMessage.success('已退出登录');
      router.push('/login');
    }).catch(() => {});
  }
};

onMounted(() => {
  checkMobile();
  window.addEventListener('resize', checkMobile);
  isDark.value = document.documentElement.classList.contains('dark');
});

onBeforeUnmount(() => {
  window.removeEventListener('resize', checkMobile);
});
</script>

<style scoped>
.layout-container {
  height: 100vh;
  width: 100vw;
  overflow: hidden;
}

.aside {
  height: 100%;
  background-color: var(--el-bg-color);
  border-right: 1px solid var(--el-border-color-light);
  display: flex;
  flex-direction: column;
  transition: width 0.3s ease;
}

.brand-logo {
  height: 60px;
  gap: 10px;
  border-bottom: 1px solid var(--el-border-color-extra-light);
  font-weight: 700;
  font-size: 16px;
  overflow: hidden;
  transition: all 0.3s;
}

.logo-collapsed {
  font-size: 20px;
}

.brand-icon {
  font-size: 22px;
  color: var(--el-color-primary);
}

.brand-name {
  color: var(--el-text-color-primary);
  letter-spacing: 0.5px;
}

.el-menu-vertical {
  border-right: none;
  flex: 1;
  overflow-y: auto;
  overflow-x: hidden;
}

.el-menu-vertical:not(.el-menu--collapse) {
  width: 100%;
}

.main-container {
  height: 100%;
  overflow: hidden;
}

.header {
  height: 60px;
  background-color: var(--el-bg-color);
  border-bottom: 1px solid var(--el-border-color-light);
  padding: 0 20px;
  box-shadow: 0 4px 15px rgba(0, 0, 0, 0.01);
  z-index: 10;
}

.toggle-icon {
  font-size: 20px;
  cursor: pointer;
  margin-right: 15px;
  color: var(--el-text-color-regular);
  transition: color 0.2s;
}

.toggle-icon:hover {
  color: var(--el-color-primary);
}

.page-title {
  font-size: 16px;
  font-weight: 600;
}

.header-right {
  gap: 20px;
}

.header-btn {
  width: 34px;
  height: 34px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  border: 1px solid var(--el-border-color-light);
  transition: all 0.2s;
}

.header-btn:hover {
  background-color: var(--el-fill-color-light);
  border-color: var(--el-color-primary);
  color: var(--el-color-primary);
}

.user-profile {
  cursor: pointer;
  gap: 8px;
  padding: 4px 8px;
  border-radius: 8px;
  transition: background-color 0.2s;
}

.user-profile:hover {
  background-color: var(--el-fill-color-light);
}

.user-avatar {
  background-color: var(--el-color-primary);
  color: white;
  font-weight: 600;
  font-size: 12px;
}

.username {
  font-size: 14px;
  font-weight: 500;
  color: var(--el-text-color-regular);
}

.main-content {
  background-color: var(--el-bg-color-page);
  padding: 0;
  overflow: hidden;
}

.scrollbar-wrapper {
  height: 100%;
}

.view-wrapper {
  padding: 24px;
  width: 100%;
  box-sizing: border-box;
}

/* Mobile responsive fixes */
.mobile-menu-drawer :deep(.el-drawer__body) {
  padding: 0 !important;
  display: flex;
  flex-direction: column;
}

@media (max-width: 768px) {
  .view-wrapper {
    padding: 12px;
  }
  .header {
    padding: 0 12px;
  }
}
</style>

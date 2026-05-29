<template>
  <div class="login-container flex-center">
    <div class="theme-toggle" @click="toggleTheme">
      <el-icon v-if="isDark"><Sunny /></el-icon>
      <el-icon v-else><Moon /></el-icon>
    </div>
    
    <div class="login-card">
      <div class="logo flex-center">
        <el-icon class="brand-icon"><Platform /></el-icon>
        <h2>天阙后台管理系统</h2>
      </div>
      
      <p class="subtitle">Tianque Management System</p>
      
      <el-form :model="loginForm" :rules="rules" ref="formRef" @keyup.enter="handleLogin">
        <el-form-item prop="email">
          <el-input 
            v-model="loginForm.email" 
            placeholder="管理员邮箱" 
            prefix-icon="User"
            size="large"
          />
        </el-form-item>
        
        <el-form-item prop="password">
          <el-input 
            v-model="loginForm.password" 
            type="password" 
            placeholder="密码" 
            prefix-icon="Lock" 
            show-password
            size="large"
          />
        </el-form-item>
        
        <el-button 
          type="primary" 
          size="large" 
          :loading="loading" 
          @click="handleLogin" 
          class="login-btn"
        >
          立即登录
        </el-button>
      </el-form>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { ElMessage } from 'element-plus';
import api from '../api';

const router = useRouter();
const formRef = ref(null);
const loading = ref(false);
const isDark = ref(false);

const loginForm = reactive({
  email: '',
  password: '',
});

const rules = {
  email: [
    { required: true, message: '请输入邮箱地址', trigger: 'blur' },
    { type: 'email', message: '请输入正确的邮箱格式', trigger: 'blur' }
  ],
  password: [
    { required: true, message: '请输入密码', trigger: 'blur' }
  ]
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

const handleLogin = async () => {
  if (!formRef.value) return;
  
  await formRef.value.validate(async (valid) => {
    if (!valid) return;
    
    loading.value = true;
    try {
      const res = await api.post('/passport/auth/login', {
        email: loginForm.email,
        password: loginForm.password
      });
      
      if (res.data && res.data.is_admin) {
        localStorage.setItem('admin_token', res.data.auth_data);
        ElMessage.success('登录成功');
        router.push('/dashboard');
      } else {
        ElMessage.error('该账户无管理员权限');
      }
    } catch (err) {
      console.error(err);
    } finally {
      loading.value = false;
    }
  });
};

onMounted(() => {
  isDark.value = document.documentElement.classList.contains('dark');
});
</script>

<style scoped>
.login-container {
  height: 100vh;
  width: 100vw;
  background: radial-gradient(circle at 10% 20%, rgba(92, 147, 255, 0.08) 0%, transparent 40%),
              radial-gradient(circle at 90% 80%, rgba(92, 147, 255, 0.05) 0%, transparent 40%);
  background-color: var(--el-bg-color-page);
  position: relative;
  overflow: hidden;
}

.theme-toggle {
  position: absolute;
  top: 25px;
  right: 25px;
  width: 40px;
  height: 40px;
  border-radius: 50%;
  background: var(--el-bg-color);
  border: 1px solid var(--el-border-color);
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
  transition: all 0.3s ease;
}

.theme-toggle:hover {
  transform: scale(1.05);
  border-color: var(--el-color-primary);
}

.login-card {
  width: 400px;
  padding: 45px 35px;
  background: var(--el-bg-color);
  border: 1px solid var(--el-border-color-light);
  border-radius: 20px;
  box-shadow: 0 15px 35px rgba(0, 0, 0, 0.03);
  text-align: center;
  transition: all 0.3s ease;
}

.dark .login-card {
  box-shadow: 0 20px 50px rgba(0, 0, 0, 0.2);
}

.logo {
  gap: 12px;
  margin-bottom: 8px;
}

.logo h2 {
  font-size: 24px;
  font-weight: 700;
  margin: 0;
  background: linear-gradient(135deg, var(--el-color-primary), var(--el-color-primary-light-3));
  -webkit-background-clip: text;
  -webkit-text-fill-color: transparent;
}

.brand-icon {
  font-size: 28px;
  color: var(--el-color-primary);
}

.subtitle {
  font-size: 13px;
  color: var(--el-text-color-secondary);
  margin-top: 0;
  margin-bottom: 40px;
  letter-spacing: 1px;
}

.login-btn {
  width: 100%;
  margin-top: 15px;
  font-weight: 600;
  border-radius: 12px;
  height: 48px;
  box-shadow: 0 6px 20px rgba(var(--el-color-primary-rgb), 0.2);
}
</style>

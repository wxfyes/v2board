import axios from 'axios';
import { ElMessage } from 'element-plus';

// Read secure path from laravel template settings or localStorage
export const getSecurePath = () => {
  return window.settings?.secure_path || localStorage.getItem('secure_path') || 'admin';
};

if (window.settings?.secure_path) {
  localStorage.setItem('secure_path', window.settings.secure_path);
}

const api = axios.create({
  baseURL: '/api/v1',
  timeout: 15000,
});

// Request interceptor
api.interceptors.request.use(
  (config) => {
    const token = localStorage.getItem('admin_token');
    if (token) {
      config.headers['Authorization'] = token;
    }
    return config;
  },
  (error) => {
    return Promise.reject(error);
  }
);

// Response interceptor
api.interceptors.response.use(
  (response) => {
    return response.data;
  },
  (error) => {
    const status = error.response?.status;
    const message = error.response?.data?.message || '请求失败，请稍后重试';
    
    if (status === 403) {
      localStorage.removeItem('admin_token');
      ElMessage.error('登录已过期，请重新登录');
      if (!window.location.hash.includes('/login')) {
        window.location.hash = '#/login';
      }
    } else {
      ElMessage.error(message);
    }
    return Promise.reject(error);
  }
);

export default api;

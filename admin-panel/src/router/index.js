import { createRouter, createWebHashHistory } from 'vue-router';

// Lazy load views
const Layout = () => import('../views/Layout.vue');
const Login = () => import('../views/Login.vue');
const Dashboard = () => import('../views/Dashboard.vue');
const Users = () => import('../views/Users.vue');
const Plans = () => import('../views/Plans.vue');
const Servers = () => import('../views/Servers.vue');
const Orders = () => import('../views/Orders.vue');
const Notices = () => import('../views/Notices.vue');
const Settings = () => import('../views/Settings.vue');

const routes = [
  {
    path: '/login',
    name: 'Login',
    component: Login,
    meta: { requiresAuth: false }
  },
  {
    path: '/',
    component: Layout,
    redirect: '/dashboard',
    children: [
      {
        path: 'dashboard',
        name: 'Dashboard',
        component: Dashboard,
        meta: { title: '仪表盘', requiresAuth: true }
      },
      {
        path: 'users',
        name: 'Users',
        component: Users,
        meta: { title: '用户管理', requiresAuth: true }
      },
      {
        path: 'plans',
        name: 'Plans',
        component: Plans,
        meta: { title: '订阅管理', requiresAuth: true }
      },
      {
        path: 'servers',
        name: 'Servers',
        component: Servers,
        meta: { title: '节点管理', requiresAuth: true }
      },
      {
        path: 'orders',
        name: 'Orders',
        component: Orders,
        meta: { title: '订单管理', requiresAuth: true }
      },
      {
        path: 'notices',
        name: 'Notices',
        component: Notices,
        meta: { title: '公告管理', requiresAuth: true }
      },
      {
        path: 'settings',
        name: 'Settings',
        component: Settings,
        meta: { title: '系统设置', requiresAuth: true }
      }
    ]
  }
];

const router = createRouter({
  history: createWebHashHistory(),
  routes
});

// Navigation guard
router.beforeEach((to, from, next) => {
  const token = localStorage.getItem('admin_token');
  
  if (to.meta.requiresAuth && !token) {
    next('/login');
  } else if (to.path === '/login' && token) {
    next('/dashboard');
  } else {
    next();
  }
});

export default router;

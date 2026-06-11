import { createRouter, createWebHashHistory } from 'vue-router';

// Lazy load views
const Layout = () => import('../views/Layout.vue');
const Login = () => import('../views/Login.vue');
const Dashboard = () => import('../views/Dashboard.vue');
const Users = () => import('../views/Users.vue');
const Plans = () => import('../views/Plans.vue');
const Servers = () => import('../views/Servers.vue');
const Groups = () => import('../views/Groups.vue');
const Routes = () => import('../views/Routes.vue');
const Orders = () => import('../views/Orders.vue');
const Coupons = () => import('../views/Coupons.vue');
const Giftcards = () => import('../views/Giftcards.vue');
const Notices = () => import('../views/Notices.vue');
const Tickets = () => import('../views/Tickets.vue');
const Knowledges = () => import('../views/Knowledges.vue');
const Settings = () => import('../views/Settings.vue');
const Queues = () => import('../views/Queues.vue');
const Payments = () => import('../views/Payments.vue');
const Themes = () => import('../views/Themes.vue');
const SecurityAudit = () => import('../views/SecurityAudit.vue');
const Cards = () => import('../views/Cards.vue');

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
        path: 'cards',
        name: 'Cards',
        component: Cards,
        meta: { title: '发卡管理', requiresAuth: true }
      },
      {
        path: 'servers',
        name: 'Servers',
        component: Servers,
        meta: { title: '节点管理', requiresAuth: true }
      },
      {
        path: 'groups',
        name: 'Groups',
        component: Groups,
        meta: { title: '权限组管理', requiresAuth: true }
      },
      {
        path: 'routes',
        name: 'Routes',
        component: Routes,
        meta: { title: '路由管理', requiresAuth: true }
      },
      {
        path: 'orders',
        name: 'Orders',
        component: Orders,
        meta: { title: '订单管理', requiresAuth: true }
      },
      {
        path: 'coupons',
        name: 'Coupons',
        component: Coupons,
        meta: { title: '优惠券管理', requiresAuth: true }
      },
      {
        path: 'giftcards',
        name: 'Giftcards',
        component: Giftcards,
        meta: { title: '礼品卡管理', requiresAuth: true }
      },
      {
        path: 'notices',
        name: 'Notices',
        component: Notices,
        meta: { title: '公告管理', requiresAuth: true }
      },
      {
        path: 'tickets',
        name: 'Tickets',
        component: Tickets,
        meta: { title: '工单管理', requiresAuth: true }
      },
      {
        path: 'knowledges',
        name: 'Knowledges',
        component: Knowledges,
        meta: { title: '知识库管理', requiresAuth: true }
      },
      {
        path: 'settings',
        name: 'Settings',
        component: Settings,
        meta: { title: '系统配置', requiresAuth: true }
      },
      {
        path: 'queues',
        name: 'Queues',
        component: Queues,
        meta: { title: '队列监控', requiresAuth: true }
      },
      {
        path: 'payments',
        name: 'Payments',
        component: Payments,
        meta: { title: '支付配置', requiresAuth: true }
      },
      {
        path: 'themes',
        name: 'Themes',
        component: Themes,
        meta: { title: '主题配置', requiresAuth: true }
      },
      {
        path: 'security-audit',
        name: 'SecurityAudit',
        component: SecurityAudit,
        meta: { title: '安全审计', requiresAuth: true }
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
  const token = localStorage.getItem('authorization');
  
  if (to.meta.requiresAuth && !token) {
    next('/login');
  } else if (to.path === '/login' && token) {
    next('/dashboard');
  } else {
    next();
  }
});

export default router;

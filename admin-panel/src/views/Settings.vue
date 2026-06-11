<template>
  <div class="settings-container">
    <el-card class="settings-card" shadow="hover" v-loading="loading">
      <template #header>
        <div class="flex-between flex-wrap gap-10">
          <span class="settings-title">系统配置中心</span>
          <el-button type="primary" :loading="submitLoading" @click="handleSave">保存配置</el-button>
        </div>
      </template>
      
      <el-tabs :tab-position="isMobile ? 'top' : 'left'" v-model="activeTab" style="min-height: 600px">
        <!-- 站点设置 -->
        <el-tab-pane label="站点" name="site">
          <div class="pane-title">站点基础信息设置</div>
          <el-form :model="configData.site" :label-position="isMobile ? 'top' : 'right'" :label-width="isMobile ? undefined : '160px'">
            <el-form-item label="站点名称">
              <el-input v-model="configData.site.app_name" />
            </el-form-item>
            <el-form-item label="站点描述">
              <el-input v-model="configData.site.app_description" type="textarea" :rows="2" />
            </el-form-item>
            <el-form-item label="站点 URL">
              <el-input v-model="configData.site.app_url" placeholder="例如 https://example.com" />
            </el-form-item>
            <el-form-item label="订阅路径">
              <el-input v-model="configData.site.subscribe_path" placeholder="必须以斜杠开头，如 /subscribe" />
            </el-form-item>
            <el-form-item label="自定义订阅地址">
              <el-input v-model="configData.site.subscribe_url" placeholder="留空默认使用站点URL，多域名用英文逗号分隔" />
            </el-form-item>
            <el-form-item label="小火箭订阅地址">
              <el-input v-model="configData.site.subscribe_url_shadowrocket" placeholder="留空默认使用上述自定义订阅地址，专门提供给 iOS/小火箭等客户端" />
            </el-form-item>
            <el-form-item label="LOGO 图片 URL">
              <el-input v-model="configData.site.logo" placeholder="站点 Logo 的网络地址" />
            </el-form-item>
            <el-form-item label="服务条款 URL">
              <el-input v-model="configData.site.tos_url" placeholder="用户注册协议服务条款链接地址" />
            </el-form-item>
            <el-row :gutter="20">
              <el-col :xs="24" :sm="12">
                <el-form-item label="货币代码">
                  <el-input v-model="configData.site.currency" placeholder="如 CNY" />
                </el-form-item>
              </el-col>
              <el-col :xs="24" :sm="12">
                <el-form-item label="货币符号">
                  <el-input v-model="configData.site.currency_symbol" placeholder="如 ¥" />
                </el-form-item>
              </el-col>
            </el-row>
            <el-form-item label="强制 HTTPS">
              <el-switch v-model="configData.site.force_https" :active-value="1" :inactive-value="0" />
            </el-form-item>
            <el-form-item label="停止新用户注册">
              <el-switch v-model="configData.site.stop_register" :active-value="1" :inactive-value="0" />
            </el-form-item>
            <el-row :gutter="20">
              <el-col :xs="24" :sm="12">
                <el-form-item label="试用订阅">
                  <el-select v-model="configData.site.try_out_plan_id" style="width: 100%" placeholder="请选择试用订阅计划">
                    <el-option label="关闭试用 / 不赠送" :value="0" />
                    <el-option 
                      v-for="plan in plans" 
                      :key="plan.id" 
                      :label="plan.name" 
                      :value="plan.id" 
                    />
                  </el-select>
                </el-form-item>
              </el-col>
              <el-col :xs="24" :sm="12">
                <el-form-item label="试用时长 (小时)">
                  <el-input-number v-model="configData.site.try_out_hour" :min="1" style="width: 100%" />
                </el-form-item>
              </el-col>
            </el-row>
          </el-form>
        </el-tab-pane>

        <!-- 安全设置 -->
        <el-tab-pane label="安全" name="safe">
          <div class="pane-title">系统防御与注册规则</div>
          <el-form :model="configData.safe" :label-position="isMobile ? 'top' : 'right'" :label-width="isMobile ? undefined : '160px'">
            <el-form-item label="邮箱验证">
              <el-switch v-model="configData.safe.email_verify" :active-value="1" :inactive-value="0" />
              <div class="form-tip">开启后将强制用户进行邮箱收码验证</div>
            </el-form-item>
            <el-form-item label="禁止使用 Gmail 别名">
              <el-switch v-model="configData.safe.email_gmail_limit_enable" :active-value="1" :inactive-value="0" />
              <div class="form-tip">开启后用户将无法使用带有 + 号的 Gmail 邮箱后缀进行多账号注册</div>
            </el-form-item>
            <el-form-item label="安全模式">
              <el-switch v-model="configData.safe.safe_mode_enable" :active-value="1" :inactive-value="0" />
              <div class="form-tip">开启后除了绑定的站点域名外，其它泛解析或恶意解析的域名访问都会直接报 403</div>
            </el-form-item>
            <el-form-item label="后台路径">
              <el-input v-model="configData.safe.secure_path" placeholder="默认为随机后台路径（只允许字母和数字）" />
              <div class="form-tip">更改此路径后，当前的管理员后台入口 URL 也会随之改变，务必牢记！</div>
            </el-form-item>
            <el-form-item label="邮箱后缀白名单">
              <el-switch v-model="configData.safe.email_whitelist_enable" :active-value="1" :inactive-value="0" />
            </el-form-item>
            <el-form-item v-if="configData.safe.email_whitelist_enable" label="允许的邮箱后缀">
              <el-input v-model="emailWhitelistRaw" type="textarea" :rows="3" placeholder="每行一个，例如 qq.com 或 outlook.com" />
            </el-form-item>

            <!-- Google reCAPTCHA -->
            <el-form-item label="谷歌 reCAPTCHA">
              <el-switch v-model="configData.safe.recaptcha_enable" :active-value="1" :inactive-value="0" />
            </el-form-item>
            <template v-if="configData.safe.recaptcha_enable">
              <el-form-item label="reCAPTCHA 密钥">
                <el-input v-model="configData.safe.recaptcha_key" placeholder="Google reCAPTCHA Secret Key" />
              </el-form-item>
              <el-form-item label="reCAPTCHA 站点密钥">
                <el-input v-model="configData.safe.recaptcha_site_key" placeholder="Google reCAPTCHA Site Key" />
              </el-form-item>
            </template>

            <!-- IP 注册限制 -->
            <el-form-item label="单 IP 注册频率限制">
              <el-switch v-model="configData.safe.register_limit_by_ip_enable" :active-value="1" :inactive-value="0" />
            </el-form-item>
            <el-row v-if="configData.safe.register_limit_by_ip_enable" :gutter="20">
              <el-col :xs="24" :sm="12">
                <el-form-item label="限制注册次数">
                  <el-input-number v-model="configData.safe.register_limit_count" :min="1" style="width: 100%" />
                </el-form-item>
              </el-col>
              <el-col :xs="24" :sm="12">
                <el-form-item label="频率检测周期 (秒)">
                  <el-input-number v-model="configData.safe.register_limit_expire" :min="10" style="width: 100%" />
                </el-form-item>
              </el-col>
            </el-row>

            <!-- 密码错误限制 -->
            <el-form-item label="登录密码错误频率限制">
              <el-switch v-model="configData.safe.password_limit_enable" :active-value="1" :inactive-value="0" />
            </el-form-item>
            <el-row v-if="configData.safe.password_limit_enable" :gutter="20">
              <el-col :xs="24" :sm="12">
                <el-form-item label="错误限制次数">
                  <el-input-number v-model="configData.safe.password_limit_count" :min="1" style="width: 100%" />
                </el-form-item>
              </el-col>
              <el-col :xs="24" :sm="12">
                <el-form-item label="锁定周期 (秒)">
                  <el-input-number v-model="configData.safe.password_limit_expire" :min="10" style="width: 100%" />
                </el-form-item>
              </el-col>
            </el-row>
          </el-form>
        </el-tab-pane>

        <!-- 订阅设置 -->
        <el-tab-pane label="订阅" name="subscribe">
          <div class="pane-title">订阅与分流控制</div>
          <el-form :model="configData.subscribe" :label-position="isMobile ? 'top' : 'right'" :label-width="isMobile ? undefined : '180px'">
            <el-form-item label="允许用户更改订阅">
              <el-switch v-model="configData.subscribe.plan_change_enable" :active-value="1" :inactive-value="0" />
              <div class="form-tip">开启后用户将会可以对订阅计划进行变更。</div>
            </el-form-item>
            <el-form-item label="月流量重置方式">
              <el-select v-model="configData.subscribe.reset_traffic_method" style="width: 100%">
                <el-option label="按月重置" :value="0" />
                <el-option label="按注册日重置" :value="1" />
                <el-option label="不重置" :value="2" />
                <el-option label="按年重置" :value="3" />
                <el-option label="按注册日年重置" :value="4" />
              </el-select>
              <div class="form-tip">全局流量重置方式，默认每月1号。可以在订阅管理中对订单单独设置。</div>
            </el-form-item>
            <el-form-item label="开启折抵方案">
              <el-switch v-model="configData.subscribe.surplus_enable" :active-value="1" :inactive-value="0" />
              <div class="form-tip">开启后用户更换订阅将会由系统对原有订阅进行折抵，方案参考文档。</div>
            </el-form-item>
            <el-form-item label="允许提前开启流量周期">
              <el-switch v-model="configData.subscribe.allow_new_period" :active-value="1" :inactive-value="0" />
              <div class="form-tip">开启后用户流量用尽时可以选择扣除订阅时长为代价重置流量，按月重置扣除当周期剩余订阅时长，每月1号重置扣除完整月时间30天。</div>
            </el-form-item>
            <el-form-item label="当订阅新购时触发事件">
              <el-select v-model="configData.subscribe.new_order_event_id" style="width: 100%">
                <el-option label="无任何动作" :value="0" />
                <el-option label="重置用户流量" :value="1" />
              </el-select>
              <div class="form-tip">新购订阅完成时将触发该任务。</div>
            </el-form-item>
            <el-form-item label="当订阅续费时触发事件">
              <el-select v-model="configData.subscribe.renew_order_event_id" style="width: 100%">
                <el-option label="无任何动作" :value="0" />
                <el-option label="重置用户流量" :value="1" />
              </el-select>
              <div class="form-tip">续费订阅完成时将触发该任务。</div>
            </el-form-item>
            <el-form-item label="当订阅变更时触发事件">
              <el-select v-model="configData.subscribe.change_order_event_id" style="width: 100%">
                <el-option label="无任何动作" :value="0" />
                <el-option label="重置用户流量" :value="1" />
              </el-select>
              <div class="form-tip">变更订阅完成时将触发该任务。</div>
            </el-form-item>
            <el-form-item label="在订阅中展示订阅信息">
              <el-switch v-model="configData.subscribe.show_info_to_server_enable" :active-value="1" :inactive-value="0" />
              <div class="form-tip">开启后将会在用户订阅节点时输出订阅信息。</div>
            </el-form-item>
            <el-form-item label="订阅链接生效模式">
              <el-select v-model="configData.subscribe.show_subscribe_method" style="width: 100%">
                <el-option label="永久有效" :value="0" />
                <el-option label="一次性有效" :value="1" />
                <el-option label="限时有效" :value="2" />
              </el-select>
              <div class="form-tip">用户获取订阅链接后的有效期。</div>
            </el-form-item>
            <el-form-item v-if="configData.subscribe.show_subscribe_method === 2" label="限时有效时间 (分钟)">
              <el-input-number v-model="configData.subscribe.show_subscribe_expire" :min="1" style="width: 150px" />
              <div class="form-tip">订阅链接的限时有效时长，默认5分钟。</div>
            </el-form-item>
          </el-form>
        </el-tab-pane>

        <!-- 充值设置 -->
        <el-tab-pane label="充值" name="deposit">
          <div class="pane-title">充值金额优惠与返现奖励配置</div>
          <el-form :label-position="isMobile ? 'top' : 'right'" :label-width="isMobile ? undefined : '160px'">
            <el-form-item label="充值奖励规则">
              <div class="bonus-list">
                <div v-for="(rule, index) in bonusRules" :key="index" class="bonus-item flex-center-y gap-10 mt-10">
                  <span>充值满</span>
                  <el-input-number v-model="rule.amount" :min="1" :controls="false" style="width: 120px">
                    <template #suffix>元</template>
                  </el-input-number>
                  <span>送</span>
                  <el-input-number v-model="rule.bonus" :min="1" :controls="false" style="width: 120px">
                    <template #suffix>元</template>
                  </el-input-number>
                  <el-button type="danger" icon="Delete" circle @click="removeBonusRule(index)" />
                </div>
                
                <el-button type="primary" plain class="mt-15" icon="Plus" @click="addBonusRule">
                  添加规则
                </el-button>
              </div>
              <div class="form-tip" style="margin-top: 10px;">设置规则后，当用户充值达到设定金额时，系统将自动赠送对应奖励余额。</div>
            </el-form-item>
          </el-form>
        </el-tab-pane>

        <!-- 工单设置 -->
        <el-tab-pane label="工单" name="ticket">
          <div class="pane-title">工单与客户支持配置</div>
          <el-form :model="configData.ticket" :label-position="isMobile ? 'top' : 'right'" :label-width="isMobile ? undefined : '160px'">
            <el-form-item label="工单设置">
              <el-select v-model="configData.ticket.ticket_status" style="width: 100%">
                <el-option label="完全开放工单" :value="0" />
                <el-option label="只允许购买过服务的用户提交工单" :value="1" />
                <el-option label="关闭工单提交服务" :value="2" />
              </el-select>
              <div class="form-tip">请选择工单的状态。</div>
            </el-form-item>
          </el-form>
        </el-tab-pane>

        <!-- 邀请推广设置 -->
        <el-tab-pane label="邀请&佣金" name="invite">
          <div class="pane-title">邀请返利与提现配置</div>
          <el-form :model="configData.invite" :label-position="isMobile ? 'top' : 'right'" :label-width="isMobile ? undefined : '160px'">
            <el-row :gutter="20">
              <el-col :xs="24" :sm="12">
                <el-form-item label="强制使用邀请码">
                  <el-switch v-model="configData.invite.invite_force" :active-value="1" :inactive-value="0" />
                </el-form-item>
              </el-col>
              <el-col :xs="24" :sm="12">
                <el-form-item label="邀请码不过期">
                  <el-switch v-model="configData.invite.invite_never_expire" :active-value="1" :inactive-value="0" />
                </el-form-item>
              </el-col>
            </el-row>
            <el-row :gutter="20">
              <el-col :xs="24" :sm="12">
                <el-form-item label="每个用户邀请码上限">
                  <el-input-number v-model="configData.invite.invite_gen_limit" :min="1" style="width: 100%" />
                </el-form-item>
              </el-col>
              <el-col :xs="24" :sm="12">
                <el-form-item label="默认返利比例 (%)">
                  <el-input-number v-model="configData.invite.invite_commission" :min="0" :max="100" style="width: 100%" />
                </el-form-item>
              </el-col>
            </el-row>
            <el-row :gutter="20">
              <el-col :xs="24" :sm="12">
                <el-form-item label="仅首次购买返利">
                  <el-switch v-model="configData.invite.commission_first_time_enable" :active-value="1" :inactive-value="0" />
                </el-form-item>
              </el-col>
              <el-col :xs="24" :sm="12">
                <el-form-item label="自动审核佣金返利">
                  <el-switch v-model="configData.invite.commission_auto_check_enable" :active-value="1" :inactive-value="0" />
                </el-form-item>
              </el-col>
            </el-row>

            <el-row :gutter="20">
              <el-col :xs="24" :sm="12">
                <el-form-item label="提现门槛 (元)">
                  <el-input-number v-model="configData.invite.commission_withdraw_limit" :min="1" style="width: 100%" />
                </el-form-item>
              </el-col>
              <el-col :xs="24" :sm="12">
                <el-form-item label="关闭提现申请">
                  <el-switch v-model="configData.invite.withdraw_close_enable" :active-value="1" :inactive-value="0" />
                </el-form-item>
              </el-col>
            </el-row>
            <el-form-item label="提现渠道 (换行或英文逗号隔开)">
              <el-input v-model="withdrawMethodsRaw" placeholder="如 微信, 支付宝, USDT" />
            </el-form-item>

            <!-- 三级分销 -->
            <el-form-item label="启用三级分销返利">
              <el-switch v-model="configData.invite.commission_distribution_enable" :active-value="1" :inactive-value="0" />
            </el-form-item>
            <template v-if="configData.invite.commission_distribution_enable">
              <el-row :gutter="20">
                <el-col :span="8" :xs="24">
                  <el-form-item label="一级返利 (%)">
                    <el-input-number v-model="configData.invite.commission_distribution_l1" :min="0" :max="100" style="width: 100%" />
                  </el-form-item>
                </el-col>
                <el-col :span="8" :xs="24">
                  <el-form-item label="二级返利 (%)">
                    <el-input-number v-model="configData.invite.commission_distribution_l2" :min="0" :max="100" style="width: 100%" />
                  </el-form-item>
                </el-col>
                <el-col :span="8" :xs="24">
                  <el-form-item label="三级返利 (%)">
                    <el-input-number v-model="configData.invite.commission_distribution_l3" :min="0" :max="100" style="width: 100%" />
                  </el-form-item>
                </el-col>
              </el-row>
            </template>
          </el-form>
        </el-tab-pane>

        <!-- 个性化设置 -->
        <el-tab-pane label="个性化" name="frontend">
          <div class="pane-title">前端风格与界面美化个性化设置</div>
          <el-form :model="configData.frontend" :label-position="isMobile ? 'top' : 'right'" :label-width="isMobile ? undefined : '160px'">
            <el-form-item label="前台主题">
              <el-select v-model="configData.frontend.frontend_theme" style="width: 100%">
                <el-option 
                  v-for="theme in themeTemplates" 
                  :key="theme" 
                  :label="theme" 
                  :value="theme" 
                />
              </el-select>
            </el-form-item>
            <el-row :gutter="20">
              <el-col :xs="24" :sm="12">
                <el-form-item label="侧边栏样式配色">
                  <el-select v-model="configData.frontend.frontend_theme_sidebar" style="width: 100%">
                    <el-option label="明亮风格" value="light" />
                    <el-option label="暗色风格" value="dark" />
                  </el-select>
                </el-form-item>
              </el-col>
              <el-col :xs="24" :sm="12">
                <el-form-item label="顶栏样式配色">
                  <el-select v-model="configData.frontend.frontend_theme_header" style="width: 100%">
                    <el-option label="明亮风格" value="light" />
                    <el-option label="暗色风格" value="dark" />
                  </el-select>
                </el-form-item>
              </el-col>
            </el-row>
            <el-form-item label="全局主题颜色色调">
              <el-select v-model="configData.frontend.frontend_theme_color" style="width: 100%">
                <el-option label="默认蓝色" value="default" />
                <el-option label="深蓝" value="darkblue" />
                <el-option label="炫酷黑色" value="black" />
                <el-option label="翡翠绿色" value="green" />
              </el-select>
            </el-form-item>
            <el-form-item label="登录背景图片 URL">
              <el-input v-model="configData.frontend.frontend_background_url" placeholder="留空则使用默认主题背景图片" />
            </el-form-item>
          </el-form>
        </el-tab-pane>

        <!-- 节点通信配置 -->
        <el-tab-pane label="节点" name="server">
          <div class="pane-title">节点控制器与 API 配置</div>
          <el-form :model="configData.server" :label-position="isMobile ? 'top' : 'right'" :label-width="isMobile ? undefined : '160px'">
            <el-form-item label="核心 API URL">
              <el-input v-model="configData.server.server_api_url" placeholder="主要给节点后端调用，多域名用逗号分隔" />
            </el-form-item>
            <el-form-item label="通信密钥 (Token)">
              <el-input v-model="configData.server.server_token" placeholder="通信验证密钥，用于节点同步" />
            </el-form-item>
            <el-row :gutter="20">
              <el-col :xs="24" :sm="12">
                <el-form-item label="下发拉取间隔 (秒)">
                  <el-input-number v-model="configData.server.server_pull_interval" :min="10" style="width: 100%" />
                </el-form-item>
              </el-col>
              <el-col :xs="24" :sm="12">
                <el-form-item label="上传数据间隔 (秒)">
                  <el-input-number v-model="configData.server.server_push_interval" :min="10" style="width: 100%" />
                </el-form-item>
              </el-col>
            </el-row>
            <el-row :gutter="20">
              <el-col :xs="24" :sm="12">
                <el-form-item label="上报最低流量限制 (B)">
                  <el-input-number v-model="configData.server.server_node_report_min_traffic" :min="0" style="width: 100%" />
                </el-form-item>
              </el-col>
              <el-col :xs="24" :sm="12">
                <el-form-item label="在线设备最低流量限制 (B)">
                  <el-input-number v-model="configData.server.server_device_online_min_traffic" :min="0" style="width: 100%" />
                </el-form-item>
              </el-col>
            </el-row>
            <el-form-item label="全局设备数限制采用宽松模式">
              <el-switch v-model="configData.server.device_limit_mode" :active-value="1" :inactive-value="0" />
              <div class="form-tip">开启后同一IP地址使用多个节点只统计为一个设备。</div>
            </el-form-item>
          </el-form>
        </el-tab-pane>

        <!-- 邮件发送设置 -->
        <el-tab-pane label="邮件" name="email">
          <div class="pane-title">SMTP 发信与模板配置</div>
          <el-form :model="configData.email" :label-position="isMobile ? 'top' : 'right'" :label-width="isMobile ? undefined : '160px'">
            <el-form-item label="发信地址 (From)">
              <el-input v-model="configData.email.email_from_address" placeholder="例如 service@example.com" />
            </el-form-item>
            <el-form-item label="邮件发信模板">
              <el-select v-model="configData.email.email_template" style="width: 100%">
                <el-option 
                  v-for="tpl in emailTemplates" 
                  :key="tpl" 
                  :label="tpl" 
                  :value="tpl" 
                />
              </el-select>
            </el-form-item>
            <el-form-item label="SMTP 主机">
              <el-input v-model="configData.email.email_host" placeholder="smtp.qq.com" />
            </el-form-item>
            <el-form-item label="SMTP 端口">
              <el-input-number v-model="configData.email.email_port" :controls="false" style="width: 150px" />
            </el-form-item>
            <el-form-item label="SMTP 用户名">
              <el-input v-model="configData.email.email_username" autocomplete="new-username" />
            </el-form-item>
            <el-form-item label="SMTP 密码">
              <el-input v-model="configData.email.email_password" type="password" show-password autocomplete="new-password" />
            </el-form-item>
            <el-form-item label="加密协议">
              <el-select v-model="configData.email.email_encryption" style="width: 150px">
                <el-option label="SSL" value="ssl" />
                <el-option label="TLS" value="tls" />
                <el-option label="无加密" value="" />
              </el-select>
            </el-form-item>
            <el-form-item>
              <el-button type="warning" :loading="testMailLoading" @click="handleTestMail">发送测试邮件</el-button>
            </el-form-item>
          </el-form>
        </el-tab-pane>

        <!-- Telegram 机器人 -->
        <el-tab-pane label="Telegram" name="telegram">
          <div class="pane-title">Telegram 消息推送机器人</div>
          <el-form :model="configData.telegram" :label-position="isMobile ? 'top' : 'right'" :label-width="isMobile ? undefined : '160px'">
            <el-form-item label="机器人 Token">
              <el-input v-model="configData.telegram.telegram_bot_token" placeholder="请输入由Botfather提供的token" />
            </el-form-item>
            
            <el-form-item label="设置 Webhook">
              <div class="flex-between w-100 align-center">
                <span class="form-tip-inline">对机器人进行Webhook设置，不设置将无法收到Telegram通知。</span>
                <el-button type="primary" :loading="webhookLoading" @click="handleSetWebhook">一键设置</el-button>
              </div>
            </el-form-item>
 
            <el-form-item label="开启机器人通知">
              <div class="flex-between w-100 align-center">
                <span class="form-tip-inline">开启后bot将会对绑定了telegram的管理员和用户进行基础通知。</span>
                <el-switch v-model="configData.telegram.telegram_bot_enable" :active-value="1" :inactive-value="0" />
              </div>
            </el-form-item>

            <el-form-item label="群组地址">
              <el-input v-model="configData.telegram.telegram_discuss_link" placeholder="填写后网会在用户端展示，或者被用于需要的地方。" />
            </el-form-item>
          </el-form>
        </el-tab-pane>

        <!-- 客户端下载 -->
        <el-tab-pane label="APP" name="app">
          <div class="pane-title">客户端下载与版本管理</div>
          <el-form :model="configData.app" :label-position="isMobile ? 'top' : 'right'" :label-width="isMobile ? undefined : '160px'">
            <el-row :gutter="20">
              <el-col :xs="24" :sm="12">
                <el-form-item label="Windows 版本">
                  <el-input v-model="configData.app.windows_version" placeholder="1.0.0" />
                </el-form-item>
              </el-col>
              <el-col :xs="24" :sm="12">
                <el-form-item label="Windows 链接">
                  <el-input v-model="configData.app.windows_download_url" />
                </el-form-item>
              </el-col>
            </el-row>
            <el-row :gutter="20">
              <el-col :xs="24" :sm="12">
                <el-form-item label="Android 版本">
                  <el-input v-model="configData.app.android_version" placeholder="1.0.0" />
                </el-form-item>
              </el-col>
              <el-col :xs="24" :sm="12">
                <el-form-item label="Android 链接">
                  <el-input v-model="configData.app.android_download_url" />
                </el-form-item>
              </el-col>
            </el-row>
            <el-row :gutter="20">
              <el-col :xs="24" :sm="12">
                <el-form-item label="macOS 版本">
                  <el-input v-model="configData.app.macos_version" placeholder="1.0.0" />
                </el-form-item>
              </el-col>
              <el-col :xs="24" :sm="12">
                <el-form-item label="macOS 链接">
                  <el-input v-model="configData.app.macos_download_url" />
                </el-form-item>
              </el-col>
            </el-row>
          </el-form>
        </el-tab-pane>
      </el-tabs>
    </el-card>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue';
import { getSecurePath } from '../api';
import api from '../api';
import { ElMessage } from 'element-plus';
import { useMobile } from '../utils/useMobile';

const { isMobile } = useMobile();

const loading = ref(false);
const submitLoading = ref(false);
const testMailLoading = ref(false);
const webhookLoading = ref(false);
const activeTab = ref('site');

const plans = ref([]);
const emailTemplates = ref([]);
const themeTemplates = ref([]);

// Whitelist parsing reactive properties
const emailWhitelistRaw = ref('');
const withdrawMethodsRaw = ref('');

// Recharge Bonus Rules list
const bonusRules = ref([]);

const configData = reactive({
  site: {
    app_name: '',
    app_description: '',
    app_url: '',
    subscribe_path: '',
    subscribe_url: '',
    subscribe_url_shadowrocket: '',
    logo: '',
    tos_url: '',
    currency: 'CNY',
    currency_symbol: '¥',
    force_https: 0,
    stop_register: 0,
    try_out_plan_id: 0,
    try_out_hour: 1,
  },
  safe: {
    email_verify: 0,
    email_gmail_limit_enable: 0,
    safe_mode_enable: 0,
    secure_path: '',
    email_whitelist_enable: 0,
    email_whitelist_suffix: [],
    recaptcha_enable: 0,
    recaptcha_key: '',
    recaptcha_site_key: '',
    register_limit_by_ip_enable: 0,
    register_limit_count: 3,
    register_limit_expire: 60,
    password_limit_enable: 1,
    password_limit_count: 5,
    password_limit_expire: 60
  },
  subscribe: {
    plan_change_enable: 1,
    reset_traffic_method: 0,
    surplus_enable: 1,
    allow_new_period: 0,
    show_info_to_server_enable: 0,
    new_order_event_id: 0,
    renew_order_event_id: 0,
    change_order_event_id: 0,
    show_subscribe_method: 0,
    show_subscribe_expire: 5
  },
  deposit: {
    deposit_bounus: []
  },
  ticket: {
    ticket_status: 0
  },
  invite: {
    invite_force: 0,
    invite_commission: 10,
    invite_gen_limit: 5,
    invite_never_expire: 0,
    commission_first_time_enable: 1,
    commission_auto_check_enable: 1,
    commission_withdraw_limit: 100,
    commission_withdraw_method: [],
    withdraw_close_enable: 0,
    commission_distribution_enable: 0,
    commission_distribution_l1: null,
    commission_distribution_l2: null,
    commission_distribution_l3: null,
  },
  frontend: {
    frontend_theme: 'default',
    frontend_theme_sidebar: 'light',
    frontend_theme_header: 'dark',
    frontend_theme_color: 'default',
    frontend_background_url: ''
  },
  server: {
    server_api_url: '',
    server_token: '',
    server_pull_interval: 60,
    server_push_interval: 60,
    server_node_report_min_traffic: 0,
    server_device_online_min_traffic: 0,
    device_limit_mode: 0
  },
  email: {
    email_template: 'default',
    email_host: '',
    email_port: 465,
    email_username: '',
    email_password: '',
    email_encryption: 'ssl',
    email_from_address: '',
  },
  telegram: {
    telegram_bot_enable: 0,
    telegram_bot_token: '',
    telegram_discuss_link: '',
  },
  app: {
    windows_version: '',
    windows_download_url: '',
    android_version: '',
    android_download_url: '',
    macos_version: '',
    macos_download_url: '',
  }
});

const addBonusRule = () => {
  bonusRules.value.push({ amount: 100, bonus: 10 });
};

const removeBonusRule = (index) => {
  bonusRules.value.splice(index, 1);
};

const fetchConfig = async () => {
  loading.value = true;
  try {
    const securePath = getSecurePath();
    const res = await api.get(`/${securePath}/config/fetch`);
    if (res.data) {
      // Map structures
      Object.keys(res.data).forEach(key => {
        if (configData[key] !== undefined) {
          Object.assign(configData[key], res.data[key]);
        }
      });
      
      // Auto-cast strings/values to numbers for switches to render properly
      configData.telegram.telegram_bot_enable = Number(configData.telegram.telegram_bot_enable);
      configData.site.force_https = Number(configData.site.force_https);
      configData.site.stop_register = Number(configData.site.stop_register);
      configData.site.try_out_plan_id = Number(configData.site.try_out_plan_id || 0);

      configData.safe.email_verify = Number(configData.safe.email_verify);
      configData.safe.email_gmail_limit_enable = Number(configData.safe.email_gmail_limit_enable);
      configData.safe.safe_mode_enable = Number(configData.safe.safe_mode_enable);
      configData.safe.email_whitelist_enable = Number(configData.safe.email_whitelist_enable);
      configData.safe.recaptcha_enable = Number(configData.safe.recaptcha_enable);
      configData.safe.register_limit_by_ip_enable = Number(configData.safe.register_limit_by_ip_enable);
      configData.safe.password_limit_enable = Number(configData.safe.password_limit_enable);

      configData.subscribe.plan_change_enable = Number(configData.subscribe.plan_change_enable);
      configData.subscribe.surplus_enable = Number(configData.subscribe.surplus_enable);
      configData.subscribe.allow_new_period = Number(configData.subscribe.allow_new_period);
      configData.subscribe.show_info_to_server_enable = Number(configData.subscribe.show_info_to_server_enable);
      configData.subscribe.new_order_event_id = Number(configData.subscribe.new_order_event_id || 0);
      configData.subscribe.renew_order_event_id = Number(configData.subscribe.renew_order_event_id || 0);
      configData.subscribe.change_order_event_id = Number(configData.subscribe.change_order_event_id || 0);
      configData.subscribe.show_subscribe_method = Number(configData.subscribe.show_subscribe_method || 0);

      configData.invite.invite_force = Number(configData.invite.invite_force);
      configData.invite.invite_never_expire = Number(configData.invite.invite_never_expire);
      configData.invite.withdraw_close_enable = Number(configData.invite.withdraw_close_enable);
      configData.invite.commission_first_time_enable = Number(configData.invite.commission_first_time_enable);
      configData.invite.commission_auto_check_enable = Number(configData.invite.commission_auto_check_enable);
      configData.invite.commission_distribution_enable = Number(configData.invite.commission_distribution_enable);

      configData.ticket.ticket_status = Number(configData.ticket.ticket_status);
      configData.server.device_limit_mode = Number(configData.server.device_limit_mode || 0);

      // Parse comma-separated strings/lists for email suffixes & withdraw channels
      if (Array.isArray(configData.safe.email_whitelist_suffix)) {
        emailWhitelistRaw.value = configData.safe.email_whitelist_suffix.join('\n');
      } else {
        emailWhitelistRaw.value = '';
      }

      if (Array.isArray(configData.invite.commission_withdraw_method)) {
        withdrawMethodsRaw.value = configData.invite.commission_withdraw_method.join(', ');
      } else {
        withdrawMethodsRaw.value = '';
      }

      // Parse deposit bonuses list ("100:10")
      bonusRules.value = [];
      const backendBonuses = configData.deposit.deposit_bounus || [];
      backendBonuses.forEach(tier => {
        if (tier && tier.includes(':')) {
          const parts = tier.split(':');
          bonusRules.value.push({
            amount: Number(parts[0]),
            bonus: Number(parts[1])
          });
        }
      });
    }
  } catch (err) {
    console.error(err);
  } finally {
    loading.value = false;
  }
};

const handleSave = async () => {
  submitLoading.value = true;
  try {
    const securePath = getSecurePath();
    
    // Set formatted parameters
    configData.safe.email_whitelist_suffix = emailWhitelistRaw.value
      ? emailWhitelistRaw.value.split('\n').map(s => s.trim()).filter(Boolean)
      : [];

    configData.invite.commission_withdraw_method = withdrawMethodsRaw.value
      ? withdrawMethodsRaw.value.split(',').map(s => s.trim()).filter(Boolean)
      : [];

    // Set recharge bonus rules format
    configData.deposit.deposit_bounus = bonusRules.value.map(rule => `${rule.amount}:${rule.bonus}`);

    // Construct single flat object for save (ConfigSave Laravel validation format)
    const payload = {};
    Object.keys(configData).forEach(section => {
      Object.keys(configData[section]).forEach(key => {
        payload[key] = configData[section][key];
      });
    });

    await api.post(`/${securePath}/config/save`, payload);
    ElMessage.success('配置更新成功，已写入系统！');
  } catch (err) {
    console.error(err);
    ElMessage.error(err.message || '配置保存失败');
  } finally {
    submitLoading.value = false;
  }
};

const handleTestMail = async () => {
  testMailLoading.value = true;
  try {
    const securePath = getSecurePath();
    await api.post(`/${securePath}/config/testSendMail`);
    ElMessage.success('测试邮件发送成功，请前往您的管理员邮箱查收！');
  } catch (err) {
    console.error(err);
  } finally {
    testMailLoading.value = false;
  }
};

const handleSetWebhook = async () => {
  webhookLoading.value = true;
  try {
    const securePath = getSecurePath();
    await api.post(`/${securePath}/config/setTelegramWebhook`, {
      telegram_bot_token: configData.telegram.telegram_bot_token
    });
    ElMessage.success('Telegram Webhook 设置成功！');
  } catch (err) {
    console.error(err);
  } finally {
    webhookLoading.value = false;
  }
};

const fetchPlans = async () => {
  try {
    const securePath = getSecurePath();
    const res = await api.get(`/${securePath}/plan/fetch`);
    if (res.data) {
      plans.value = res.data;
    }
  } catch (err) {
    console.error(err);
  }
};

const fetchEmailTemplates = async () => {
  try {
    const securePath = getSecurePath();
    const res = await api.get(`/${securePath}/config/getEmailTemplate`);
    if (res.data) {
      emailTemplates.value = res.data;
    }
  } catch (err) {
    console.error(err);
  }
};

const fetchThemeTemplates = async () => {
  try {
    const securePath = getSecurePath();
    const res = await api.get(`/${securePath}/config/getThemeTemplate`);
    if (res.data) {
      themeTemplates.value = res.data;
    }
  } catch (err) {
    console.error(err);
  }
};

onMounted(() => {
  fetchConfig();
  fetchPlans();
  fetchEmailTemplates();
  fetchThemeTemplates();
});
</script>

<style scoped>
.settings-card {
  border-radius: 16px;
  border: 1px solid var(--el-border-color-light);
}

.settings-title {
  font-size: 15px;
  font-weight: 600;
}

.pane-title {
  font-size: 15px;
  font-weight: 600;
  margin-bottom: 25px;
  padding-bottom: 10px;
  border-bottom: 1px solid var(--el-border-color-extra-light);
  color: var(--el-text-color-primary);
}

.form-tip {
  font-size: 11px;
  color: var(--el-text-color-secondary);
  line-height: 1.4;
  margin-top: 4px;
  width: 100%;
}

.form-tip-inline {
  font-size: 12px;
  color: var(--el-text-color-secondary);
}

.el-tab-pane {
  padding-right: 20px;
}

.gap-10 {
  gap: 10px;
}

.mt-10 {
  margin-top: 10px;
}

.mt-15 {
  margin-top: 15px;
}

.w-100 {
  width: 100%;
}

.bonus-list {
  background-color: var(--el-fill-color-light);
  padding: 15px;
  border-radius: 8px;
  width: 100%;
}

.bonus-item {
  background-color: var(--el-bg-color);
  padding: 8px 12px;
  border-radius: 6px;
  border: 1px solid var(--el-border-color-lighter);
}
</style>

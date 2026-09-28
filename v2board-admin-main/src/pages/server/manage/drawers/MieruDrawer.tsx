import { Input, Select } from 'antd'
import {
  DrawerActions,
  GroupField,
  NameRateRow,
  NodeDrawerFrame,
  ParentField,
  RouteField,
  TagsField,
  text,
  Trigger,
  useNodeDrawer,
  useSaveNode,
  type NodeDrawerProps,
} from './shared'

export function MieruDrawer({ record, children }: NodeDrawerProps) {
  const { server, setServer, visible, setVisible, change } = useNodeDrawer(record, () => ({
    rate: 1,
    transport: 'tcp',
    port_range: '',
  }))
  const { saving, save } = useSaveNode('mieru')
  const onShow = () => setVisible((v) => !v)

  return (
    <>
      <Trigger onClick={() => setVisible(true)}>{children}</Trigger>
      <NodeDrawerFrame server={server} visible={visible} onClose={onShow}>
        <div>
          <NameRateRow server={server} change={change} />
          <TagsField server={server} change={change} />
          <GroupField server={server} change={change} />
          <div className="row">
            <div className="form-group col-md-12 col-xs-12">
              <label>节点地址</label>
              <Input placeholder="域名或IP" value={text(server.host)} onChange={(e) => change('host', e.target.value)} />
            </div>
          </div>
          <div className="row">
            <div className="form-group col-md-6 col-xs-12">
              <label>连接端口</label>
              <Input placeholder="用户连接端口" value={text(server.port)} onChange={(e) => change('port', e.target.value)} />
            </div>
            <div className="form-group col-md-6 col-xs-12">
              <label>服务端口</label>
              <Input
                placeholder="节点监听端口"
                value={text(server.server_port)}
                onChange={(e) => change('server_port', e.target.value)}
              />
            </div>
          </div>
          <div className="row">
            <div className="form-group col-md-6 col-xs-12">
              <label>端口范围</label>
              <Input placeholder="例如: 10000-10050" value={text(server.port_range)} onChange={(e) => change('port_range', e.target.value)} />
            </div>
            <div className="form-group col-md-6 col-xs-12">
              <label>传输协议</label>
              <Select<string>
                value={text(server.transport) || 'tcp'}
                onChange={(v) => change('transport', v)}
                style={{ width: '100%' }}
                options={[
                  { value: 'tcp', label: 'TCP' },
                  { value: 'udp', label: 'UDP' },
                ]}
              />
            </div>
          </div>
          <ParentField type="mieru" server={server} change={change} />
          <RouteField server={server} change={change} />
        </div>
        <DrawerActions saving={saving} onCancel={onShow} onSave={() => void save(server, onShow)} />
      </NodeDrawerFrame>
    </>
  )
}

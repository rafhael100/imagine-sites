import importlib.util,json,pathlib,unittest
s=importlib.util.spec_from_file_location('inventory',pathlib.Path(__file__).parents[1]/'ops/staging/inventory.py');m=importlib.util.module_from_spec(s);s.loader.exec_module(m)
class InventoryTest(unittest.TestCase):
 def plan(self,**kw):
  args=dict(rows=[],listeners=set(),networks=['bridge'],volumes=[],containers=['crm-1'],memory_mib=8192,disk_gib=100,candidates=[18081,18090,18091],bind_probe=lambda p:True,cpu_count=8,load1=1.0);args.update(kw);return m.choose(**args)
 def test_crm_port_is_never_recommended_even_if_not_in_snapshot(self):
  r=self.plan();self.assertEqual(18090,r['recommended_loopback_port']);self.assertFalse(r['can_deploy']);self.assertFalse(r['port_reserved'])
 def test_kernel_and_stopped_docker_bind_both_exclude_candidates(self):
  rows=[{'8443/tcp':[{'HostIp':'127.0.0.1','HostPort':'18090'}]}]
  self.assertEqual(18091,self.plan(rows=rows)['recommended_loopback_port']);self.assertIsNone(self.plan(rows=rows,listeners={18091})['recommended_loopback_port'])
 def test_bind_race_skips_port(self):self.assertEqual(18091,self.plan(bind_probe=lambda p:p!=18090)['recommended_loopback_port'])
 def test_project_namespace_collision_blocks_existing_project(self):
  for key in ['networks','volumes','containers']:
   r=self.plan(**{key:[m.PROJECT+'_db_private']});self.assertIsNone(r['recommended_loopback_port']);self.assertTrue(r['namespace_collision'])
 def test_unknown_metadata_and_resource_shortage_fail_closed(self):
  for kw in [dict(rows=None),dict(listeners=None),dict(networks=None),dict(memory_mib=None),dict(disk_gib=1),dict(memory_mib=1024),dict(listeners={'secret'}),dict(load1=9.0),dict(cpu_count=None)]:self.assertIsNone(self.plan(**kw)['recommended_loopback_port'])
 def test_malformed_docker_bind_denied(self):
  for b in [{'HostIp':'bad','HostPort':'18090'},{'HostIp':'::','HostPort':'oops'},{'HostIp':'127.0.0.1','HostPort':'70000'}]:self.assertIsNone(self.plan(rows=[{'8443/tcp':[b]}])['recommended_loopback_port'])
 def test_names_and_private_extra_fields_never_printed(self):
  rows=[{'8443/tcp':[{'HostIp':'::','HostPort':'18090','private':'DO_NOT_EXPOSE'}]}]
  r=self.plan(rows=rows,containers=['private-customer-name']);serialized=json.dumps(r);self.assertNotIn('DO_NOT_EXPOSE',serialized);self.assertNotIn('private-customer-name',serialized);self.assertEqual(64,len(r['inventory_sha256']))
if __name__=='__main__':unittest.main()

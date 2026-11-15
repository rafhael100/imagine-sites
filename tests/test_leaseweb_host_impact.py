import importlib.util
from pathlib import Path
import tempfile
import unittest
import json
s=importlib.util.spec_from_file_location('impact',Path(__file__).resolve().parents[1]/'ops/homologation/leaseweb-host-impact.py');m=importlib.util.module_from_spec(s);s.loader.exec_module(m)
class ImpactTest(unittest.TestCase):
    def test_conflicting_existing_service_does_not_appear_deployable(self):
        rows=[{'8080/tcp':[{'HostIp':'127.0.0.1','HostPort':'18081'}]}]
        r=m.report(rows,{18081});self.assertFalse(r['candidate_port_available_observed']);self.assertFalse(r['can_deploy']);self.assertEqual('blocked',r['status'])
    def test_host_listener_outside_docker_also_blocks(self):
        self.assertFalse(m.report([], {18081})['candidate_port_available_observed'])
    def test_other_port_never_means_go(self):
        r=m.report([{'5432/tcp':None}],set());self.assertTrue(r['candidate_port_available_observed']);self.assertEqual('blocked',r['status']);self.assertFalse(r['can_publish'])
    def test_unknown_and_invalid_metadata_fail_closed(self):
        for rows in [None,{},[{} , {'5432/tcp':'bad'}],[{'8080/tcp':[{'HostIp':'0.0.0.0','HostPort':'oops'}]}]]:
            self.assertFalse(m.report(rows,set())['candidate_port_available_observed'])
        self.assertFalse(m.report([],None)['candidate_port_available_observed'])
    def test_ipv6_wildcard_and_public_db_reported(self):
        r=m.report([{'5432/tcp':[{'HostIp':'::','HostPort':'5432'}]}],set());self.assertIn('public_database_binding_detected',r['blockers'])
    def test_no_docker_names_or_secret_fields_serialized(self):
        r=m.report([{'8080/tcp':[{'HostIp':'127.0.0.1','HostPort':'18082','Secret':'CANARY_NEVER_PRINT'}]}],set());self.assertNotIn('CANARY',json.dumps(r))
    def test_proc_parser_and_invalid_input(self):
        with tempfile.TemporaryDirectory() as d:
            p=Path(d)/'tcp';p.write_text('header\n 0: 0100007F:46A1 00000000:0000 0A\n')
            self.assertIn(18081,m.listener_ports([p]));p.write_text('head\nmalformed\n');self.assertIsNone(m.listener_ports([p]))
    def test_backup_metadata_never_opens_content_or_follows_links(self):
        with tempfile.TemporaryDirectory() as d:
            root=Path(d);(root/'secret').write_text('CANARY_SECRET_CONTENT');(root/'loop').symlink_to(root,target_is_directory=True)
            r=m.backup_metadata(root);self.assertTrue(r['observed']);self.assertFalse(r['contents_read']);self.assertFalse(r['restoration_proven']);self.assertNotIn('CANARY',json.dumps(r))
            self.assertFalse(m.backup_metadata(root/'loop')['observed'])
if __name__=='__main__':unittest.main()

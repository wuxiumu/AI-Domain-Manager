"""HTTP + auth + DNS write regression. Every cloud request uses a local simulator."""
import json
import unittest
from dns_fixture import installation


class DnsApiTest(unittest.TestCase):
    def test_cloud_write_contract_and_authorization(self):
        site = installation()
        self.addCleanup(site.close)
        endpoint = '/api/domains/fixture/example.com/records'
        identity = site.login('legacy', 'legacy-password-test')[1]
        csrf = identity['csrf_token']
        snapshot = site.request('GET', endpoint)[1]
        version = snapshot['records'][0]['version']
        self.assertEqual(len(version), 64)
        self.assertEqual(len(snapshot['revision']), 64)
        self.assertEqual(site.request('PUT', endpoint+'/r1', {'value':'192.0.2.3','expected_version':version})[0],403)
        self.assertEqual(site.request('PUT', endpoint+'/r1', {'value':'192.0.2.3'}, csrf=csrf)[0],422)
        self.assertEqual(site.request('PUT', endpoint+'/r1', {'value':'192.0.2.3','expected_version':'a'*64},csrf=csrf)[0],409)
        self.assertEqual(json.loads((site.root/'data/fixture_dns.json').read_text())['writes'],0)
        status, result, headers = site.request('PUT',endpoint+'/r1',{'value':'192.0.2.3','expected_version':version},csrf=csrf)
        self.assertEqual(status,200); self.assertTrue(result['verified']); self.assertEqual(headers.get('Cache-Control'),'no-store')
        self.assertEqual(site.request('PUT',endpoint+'/r1',{'value':'192.0.2.4','expected_version':version},csrf=csrf)[0],409)
        snapshot = site.request('GET',endpoint)[1]
        self.assertEqual(snapshot['records'][0]['value'],'192.0.2.3')
        self.assertEqual(snapshot['records'][1]['value'],'192.0.2.2')
        version = snapshot['records'][0]['version']
        state_file = site.root/'data/fixture_dns.json'
        state = json.loads(state_file.read_text()); state['mode']='readback-fail'; state_file.write_text(json.dumps(state))
        status,result,_ = site.request('PUT',endpoint+'/r1',{'value':'192.0.2.4','expected_version':version},csrf=csrf)
        self.assertEqual(status,202); self.assertFalse(result['verified']); self.assertEqual(result['status'],'unconfirmed')
        self.assertNotIn('FIXTURE_PRIVATE_ERROR',json.dumps(result))
        state=json.loads(state_file.read_text()); state['mode']='';state['fail_read']=False;state_file.write_text(json.dumps(state))
        current=site.request('GET',endpoint+'?refresh=1')[1]
        self.assertEqual(current['records'][0]['value'],'192.0.2.4')
        history=site.request('GET','/api/dns/changes')[1]['changes']
        self.assertEqual(history[0]['outcome'],'unconfirmed')
        self.assertEqual(history[1]['before']['value'],'192.0.2.1')
        self.assertEqual(history[1]['after']['value'],'192.0.2.3')
        maintenance=site.request('GET','/api/maintenance')[1]
        self.assertEqual(maintenance['dns_changes'],history)
        self.assertTrue(any(row['outcome']=='unconfirmed' for row in maintenance['audit']))
        self.assertEqual(site.request('GET','/api/dns/changes',anonymous=True)[0],401)
        token=site.request('POST','/api/tokens',{'name':'fixture-writer','current_password':'legacy-password-test','scopes':['dns:read','dns:write']},csrf=csrf)[1]['token']
        self.assertEqual(site.request('GET','/api/dns/changes',token=token,anonymous=True)[0],403)
        record=current['records'][0]
        self.assertEqual(site.request('DELETE',endpoint+'/r1',{'expected_version':record['version']},token=token,anonymous=True)[0],200)
        remaining=site.request('GET',endpoint)[1]
        self.assertEqual([record['id'] for record in remaining['records']],['r2'])
        add={'type':'A','name':'api','value':'192.0.2.10','expected_revision':remaining['revision']}
        status,result,_=site.request('POST',endpoint,add,token=token,anonymous=True)
        self.assertEqual(status,200);self.assertTrue(result['verified'])
        self.assertTrue(result['record']['id'].startswith('new-'))
        self.assertEqual(len(result['record']['version']),64)
        self.assertEqual(site.request('POST',endpoint,add,token=token,anonymous=True)[0],409)
        history=site.request('GET','/api/dns/changes')[1]['changes']
        self.assertEqual(history[0]['via'],'api')
        spec=site.request('GET','/api/openapi.json',anonymous=True)[1]
        body=spec['paths']['/api/domains/{provider}/{domain}/records/{recordId}']['put']['requestBody']['content']['application/json']['schema']
        self.assertEqual(body['required'],['expected_version'])
        self.assertIn('202',spec['paths']['/api/domains/{provider}/{domain}/records/{recordId}']['put']['responses'])
        # Public responses stay JSON even when a private store is corrupt.
        (site.root/'data/dns_changes.json').write_text('broken private file')
        status,result,_=site.request('GET','/api/dns/changes')
        self.assertEqual(status,500);self.assertIsInstance(result,dict)
        self.assertNotIn(str(site.root),json.dumps(result))

    def test_php_suffix_inside_api_parameters_is_data(self):
        site=installation();self.addCleanup(site.close)
        identity=site.login('legacy','legacy-password-test')[1]
        file=site.root/'data/fixture_dns.json';state=json.loads(file.read_text())
        state['records'][0]['id']='r.php';file.write_text(json.dumps(state))
        path='/api/domains/fixture/example.com/records'
        version=site.request('GET',path)[1]['records'][0]['version']
        status,result,_=site.request('DELETE',path+'/r.php',{'expected_version':version},csrf=identity['csrf_token'])
        self.assertEqual(status,200);self.assertTrue(result['verified'])


if __name__ == '__main__': unittest.main()

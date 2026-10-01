"""HTTP integration checks. Requires PHP 8.2+; Python standard library only."""
import base64, json, os, socket, subprocess, tempfile, time, unittest
from urllib.request import Request, urlopen
from urllib.error import HTTPError
from pathlib import Path

PROJECT = Path(__file__).resolve().parents[1]
ORIGIN = 'https://client.example'
PNG = base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a0N8AAAAASUVORK5CYII=')

class ApiTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.temp = tempfile.TemporaryDirectory()
        with socket.socket() as sock:
            sock.bind(('127.0.0.1', 0)); port = sock.getsockname()[1]
        cls.url = f'http://127.0.0.1:{port}'
        env = dict(os.environ, UPLOAD_ALLOWED_ORIGIN=ORIGIN, UPLOAD_BASE_URL=cls.url, UPLOAD_STORAGE_DIR=cls.temp.name)
        cls.log = tempfile.TemporaryFile()
        cls.server = subprocess.Popen(['php', '-S', f'127.0.0.1:{port}', '-t', 'public', 'public/index.php'], cwd=PROJECT, env=env, stdout=cls.log, stderr=cls.log)
        for _ in range(100):
            try:
                with socket.create_connection(('127.0.0.1', port), timeout=.1): return
            except OSError: time.sleep(.05)
        raise RuntimeError('PHP server did not start')

    @classmethod
    def tearDownClass(cls):
        cls.server.terminate(); cls.server.wait(); cls.log.close(); cls.temp.cleanup()

    def request(self, method, route, body=None, content_type=None, origin=ORIGIN):
        headers = {}
        if origin is not None: headers['Origin'] = origin
        if content_type: headers['Content-Type'] = content_type
        try: response = urlopen(Request(self.url + route, data=body, headers=headers, method=method))
        except HTTPError as error: response = error
        with response: return response.status, response.headers, response.read()

    def upload(self, sets, files, origin=ORIGIN):
        boundary = 'upload-test-boundary'
        body = bytearray()
        def field(name, data, filename=None):
            body.extend(f'--{boundary}\r\nContent-Disposition: form-data; name="{name}"'.encode())
            if filename: body.extend(f'; filename="{filename}"\r\nContent-Type: application/octet-stream'.encode())
            body.extend(b'\r\n\r\n' + data + b'\r\n')
        field('metadata', json.dumps(sets).encode())
        for s, i, data in files: field(f'images[{s}][{i}]', data, 'image.png')
        body.extend(f'--{boundary}--\r\n'.encode())
        return self.request('POST', '/api/images', bytes(body), f'multipart/form-data; boundary={boundary}', origin)

    def test_crud_and_validation(self):
        sets = [{'root':'client-one', 'items':[{'path':'', 'name':'photo.png'}, {'path':'/covers/page/store', 'name':'cover.png'}]}, {'root':'client-two', 'items':[{'path':'gallery', 'name':'photo.png'}]}]
        status, _, body = self.upload(sets, [(0,0,PNG),(0,1,PNG),(1,0,PNG)])
        self.assertEqual(status, 201, body)
        self.assertEqual(len(json.loads(body)['images']), 3)
        route = '/api/images/client-one/covers/page/store/cover.png'
        self.assertEqual(self.request('GET', route, origin=None)[2], PNG)
        self.assertEqual(self.request('HEAD', route, origin=None)[0], 200)
        status, _, body = self.request('GET', '/api/images?root=client-one&path=covers/page/store')
        self.assertEqual(status, 200, body); self.assertEqual(len(json.loads(body)['images']), 1)
        self.assertEqual(self.upload(sets, [(0,0,PNG),(0,1,PNG),(1,0,PNG)])[0], 409)
        self.assertEqual(self.request('PUT', route, PNG, 'image/png')[0], 200)
        self.assertEqual(self.request('PUT', route, b'not an image', 'image/png')[0], 422)
        self.assertEqual(self.request('GET', route, origin=None)[2], PNG)
        move = json.dumps({'root':'client-one', 'path':'moved/deep', 'name':'new.png'}).encode()
        self.assertEqual(self.request('PATCH', route, move, 'application/json')[0], 200)
        self.assertEqual(self.request('GET', route, origin=None)[0], 404)
        moved = '/api/images/client-one/moved/deep/new.png'
        self.assertEqual(self.request('DELETE', moved)[0], 204)
        self.assertEqual(self.request('DELETE', moved)[0], 404)
        for origin in [None, 'https://evil.example', 'https://client.example.evil']:
            self.assertEqual(self.request('DELETE', '/api/images/client-one/photo.png', origin=origin)[0], 403)
        self.assertEqual(self.request('GET', '/api/images?root=client-one', origin=None)[0], 403)
        status, headers, _ = self.request('OPTIONS', '/api/images')
        self.assertEqual(status, 204); self.assertEqual(headers['Access-Control-Allow-Origin'], ORIGIN)
        self.assertEqual(self.request('POST', '/api/images', b'{}', 'application/json')[0], 400)
        self.assertEqual(self.request('POST', '/api/images/client-one/photo.png')[0], 405)
        for path in ['../outside', 'nested/../../outside', 'back\\slash', '.hidden']:
            batch = [{'root':'bad', 'items':[{'path':path, 'name':'image.png'}]}]
            self.assertEqual(self.upload(batch, [(0,0,PNG)])[0], 422)
        batch = [{'root':'bad', 'items':[{'path':'', 'name':'image.jpg'}]}]
        self.assertEqual(self.upload(batch, [(0,0,PNG)])[0], 422)
        batch = [{'root':'atomic', 'items':[{'name':'good.png'}, {'name':'bad.png'}]}]
        self.assertEqual(self.upload(batch, [(0,0,PNG),(0,1,b'invalid')])[0], 422)
        self.assertFalse(Path(self.temp.name, 'atomic/good.png').exists())
        Path(self.temp.name, 'linked').symlink_to(self.temp.name, target_is_directory=True)
        batch = [{'root':'linked', 'items':[{'name':'image.png'}]}]
        self.assertEqual(self.upload(batch, [(0,0,PNG)])[0], 422)
        self.assertEqual(self.request('PUT', '/api/images/missing/image.png', PNG, 'image/png')[0], 404)
        batch = [{'root':'duplicate', 'items':[{'name':'image.png'}, {'name':'image.png'}]}]
        self.assertEqual(self.upload(batch, [(0,0,PNG),(0,1,PNG)])[0], 409)
        batch = [{'root':'future', 'items':[{'name':'image.png','variants':{}}]}]
        self.assertEqual(self.upload(batch, [(0,0,PNG)])[0], 422)

if __name__ == '__main__': unittest.main()

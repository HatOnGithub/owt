/** Compile the mock once, then serve its bundles and isolated API on one port. */
import { spawn } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { listenMock } from './server.mjs';

const compiler = spawn(
  process.execPath,
  [
    fileURLToPath(new URL('../node_modules/@angular/cli/bin/ng.js', import.meta.url)),
    'build',
    '--configuration',
    'mock',
  ],
  { cwd: fileURLToPath(new URL('../', import.meta.url)), stdio: 'inherit' }
);
let server;
let stopping = false;
const stop = () => {
  stopping = true;
  compiler.kill('SIGTERM');
  server?.closeAllConnections();
  server?.close();
};
for (const signal of ['SIGINT', 'SIGTERM']) process.once(signal, stop);

try {
  const code = await new Promise((resolve, reject) => {
    compiler.once('error', reject);
    compiler.once('exit', resolve);
  });
  if (code !== 0) throw new Error(`Mock compilation exited with ${code}`);
  if (!stopping) {
    server = await listenMock(4300, {
      frontendRoot: fileURLToPath(new URL('../dist/mock/browser', import.meta.url)),
    });
    console.log('Prebuilt mock app and API: http://127.0.0.1:4300');
  }
} catch (error) {
  console.error(`Cannot start E2E mock: ${error.message}`);
  stop();
  process.exitCode = 1;
}

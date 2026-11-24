import http from 'node:http';
import { spawn } from 'node:child_process';
import { existsSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const rootDir = resolve(__dirname, '..');
const publicDir = resolve(rootDir, 'public');
const serverRouter = resolve(
  rootDir,
  'vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php',
);

const host = process.env.HOST || '127.0.0.1';
const listenPort = Number.parseInt(process.env.PORT || '8000', 10);
const workerCount = Math.max(2, Math.min(12, Number.parseInt(process.env.WORKERS || '4', 10)));
const baseWorkerPort = Number.parseInt(process.env.BASE_WORKER_PORT || String(listenPort + 1), 10);
const phpBin = process.env.PHP_BIN || 'php';

if (!existsSync(serverRouter)) {
  console.error(`[multiworker] Laravel server router not found: ${serverRouter}`);
  process.exit(1);
}

const workers = [];
let shuttingDown = false;

function spawnWorker(index) {
  const port = baseWorkerPort + index;
  const worker = {
    index,
    port,
    inFlight: 0,
    child: null,
  };

  const launch = () => {
    if (shuttingDown) return;
    const child = spawn(
      phpBin,
      ['-S', `${host}:${port}`, '-t', publicDir, serverRouter],
      {
        cwd: rootDir,
        env: {
          ...process.env,
          LARAVEL_SAIL: '0',
          PHP_CLI_SERVER_WORKERS: '1',
        },
        stdio: ['ignore', 'ignore', 'pipe'],
      },
    );

    worker.child = child;
    child.stderr.on('data', (chunk) => {
      const text = chunk.toString();
      if (text.includes('Fatal error') || text.includes('Parse error')) {
        process.stderr.write(`[worker:${port}] ${text}`);
      }
    });

    child.on('exit', (code) => {
      if (!shuttingDown) {
        console.warn(`[multiworker] Worker :${port} exited (code=${code}), restarting...`);
        setTimeout(launch, 300);
      }
    });
  };

  launch();
  return worker;
}

for (let i = 0; i < workerCount; i++) {
  workers.push(spawnWorker(i));
}

function pickLeastBusyWorker() {
  let best = workers[0];
  for (let i = 1; i < workers.length; i++) {
    if (workers[i].inFlight < best.inFlight) {
      best = workers[i];
    }
  }
  return best;
}

const proxyServer = http.createServer((clientReq, clientRes) => {
  const worker = pickLeastBusyWorker();
  worker.inFlight++;
  let released = false;
  const release = () => {
    if (!released) {
      released = true;
      worker.inFlight = Math.max(0, worker.inFlight - 1);
    }
  };

  const upstreamHeaders = {
    ...clientReq.headers,
    host: `${host}:${listenPort}`,
    connection: 'close',
  };

  const proxyReq = http.request(
    {
      hostname: host,
      port: worker.port,
      method: clientReq.method,
      path: clientReq.url,
      headers: upstreamHeaders,
    },
    (proxyRes) => {
      clientRes.writeHead(proxyRes.statusCode || 502, proxyRes.headers);
      proxyRes.pipe(clientRes);
      proxyRes.on('end', release);
      proxyRes.on('close', release);
      proxyRes.on('error', release);
    },
  );

  // Immediately abort upstream request if client cancels/disconnects mid-flight
  clientReq.on('aborted', () => {
    proxyReq.destroy();
    release();
  });
  clientRes.on('close', () => {
    if (!clientRes.writableEnded) {
      proxyReq.destroy();
      release();
    }
  });

  proxyReq.on('error', (err) => {
    release();
    if (!clientRes.headersSent && !clientRes.destroyed) {
      clientRes.writeHead(502, { 'Content-Type': 'application/json' });
      clientRes.end(
        JSON.stringify({
          status: 'error',
          message: `Local PHP worker :${worker.port} error: ${err.message}`,
        }),
      );
    }
  });

  clientReq.pipe(proxyReq);
});

proxyServer.listen(listenPort, host, () => {
  const ports = workers.map((w) => w.port).join(', ');
  console.log(
    `[multiworker] RetailPOS parallel dev server listening on http://${host}:${listenPort} (${workerCount} PHP workers: ${ports})`,
  );
  console.log('[multiworker] Press Ctrl+C to stop all workers cleanly.');
});

function shutdown() {
  if (shuttingDown) return;
  shuttingDown = true;
  console.log('\n[multiworker] Shutting down proxy and PHP workers...');
  proxyServer.close();
  for (const w of workers) {
    try {
      w.child?.kill();
    } catch (_) {}
  }
  setTimeout(() => process.exit(0), 250);
}

process.on('SIGINT', shutdown);
process.on('SIGTERM', shutdown);

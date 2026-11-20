import { loadConfig, ConfigError } from './config.js';
import { FileStore } from './store.js';
import { createMailer } from './mailer.js';
import { WichtelService } from './service.js';
import { createServer } from './server.js';
import { generateKey } from './crypto.js';

if (process.argv.includes('--generate-keys')) {
  console.log(`SECRET_KEY=${generateKey()}\nDATA_KEY=${generateKey()}\nTASKS_TOKEN=${generateKey()}`);
  process.exit(0);
}

let config;
try {
  config = loadConfig();
} catch (err) {
  if (err instanceof ConfigError) {
    console.error(err.message);
    process.exit(1);
  }
  throw err;
}

const mailer = createMailer(config);
const services = [];
for (const round of config.rounds) {
  const store = new FileStore({ dataDir: config.dataDir, key: config.dataKey, name: round.slug });
  try {
    await store.load();
  } catch (err) {
    console.error(`Datendatei der Runde "${round.slug}" konnte nicht geladen werden (falscher DATA_KEY oder beschädigte Datei?): ${err.message}`);
    process.exit(1);
  }
  services.push(new WichtelService({ config, round, store, mailer }));
}
const server = createServer({ config, services });

const tick = async () => {
  for (const s of services) {
    await s.runTasks().catch((err) => console.error(`Fehler bei zeitgesteuerten Aufgaben (${s.round.slug}):`, err));
  }
};
await tick();
const timer = config.schedulerIntervalSeconds > 0 ? setInterval(tick, config.schedulerIntervalSeconds * 1000) : null;

server.listen(config.port, config.host, () => {
  console.log(`Wichtelberg läuft auf ${config.host}:${config.port} (${config.baseUrl})`);
  for (const s of services) console.log(`  ${s.base}: ${s.round.name} (${s.round.countryName}) – Status ${s.data.status}`);
});

function shutdown() {
  if (timer) clearInterval(timer);
  server.close(() => process.exit(0));
  setTimeout(() => process.exit(0), 5000).unref();
}
process.on('SIGTERM', shutdown);
process.on('SIGINT', shutdown);

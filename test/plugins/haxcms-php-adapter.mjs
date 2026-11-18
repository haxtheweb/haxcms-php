// DRAFT (plugin platform, haxtheweb/issues#3356). Adapter for the plugin spec's runners (spec/13 section 9): every
// case gets its own PHP process running haxcms-php's native plugin engine (system/backend/php/lib/plugins), on a
// fresh, real HAXcms site, so the spec's conformance cases and every plugin's own tests run against haxcms-php the
// same way haxcms-nodejs runs them (test/plugins/haxcms-adapter.mjs there).
//   node node_modules/@haxtheweb/haxcms-plugin-spec/conformance/run.mjs --backend test/plugins/haxcms-php-adapter.mjs
//   node node_modules/@haxtheweb/haxcms-plugin-spec/harness/run-cases.mjs <plugin> --runtime php --backend test/plugins/haxcms-php-adapter.mjs
// HAXCMS_PLUGIN_ADAPTER_ENGINE=reference runs the in-memory engine instead of the HAXcms host.
import { spawn } from "node:child_process";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { createRequire } from "node:module";

const here = dirname(fileURLToPath(import.meta.url));
const require = createRequire(import.meta.url);
const specDir = process.env.HAXCMS_PLUGIN_SPEC_DIR || dirname(require.resolve("@haxtheweb/haxcms-plugin-spec/package.json", { paths: [join(here, "..", "..")] }));
const php = process.env.PHP_BINARY || "php";

class PhpBackend {
  constructor() {
    this.child = spawn(php, [join(here, "adapter-server.php")], { stdio: ["pipe", "pipe", "inherit"], env: { ...process.env, HAXCMS_PLUGIN_SPEC_DIR: specDir } });
    this.child.unref();
    this.child.stdout.setEncoding("utf8");
    this.buf = "";
    this.waiting = [];
    this.child.stdout.on("data", (d) => {
      this.buf += d;
      let i;
      while ((i = this.buf.indexOf("\n")) >= 0) {
        const line = this.buf.slice(0, i);
        this.buf = this.buf.slice(i + 1);
        const w = this.waiting.shift();
        if (w) w(line);
      }
    });
    this.queue = Promise.resolve();
    this.env = {};
    // mirrors of PHP state the step runner reads synchronously
    this.log = [];
    this.execCalls = [];
    this.fetchCalls = [];
    this.statuses = [];
    this.records = {};
    this.site = {};
    this.currentTx = null;
    this.managed = new Map();
    this.caseDir = ".";
    this.runtime = "php";
  }
  set now(v) { this.env.now = v; }
  set user(v) { this.env.user = v; }
  set execFakes(v) { this.env.execFakes = v; }
  set fetchFakes(v) { this.env.fetchFakes = v; }
  set urlAliases(v) { this.env.urlAliases = v; }
  rpc(m, ...a) {
    while (a.length && a[a.length - 1] === undefined) a.pop();
    const run = async () => {
      const env = this.env;
      this.env = {};
      const line = await new Promise((resolve) => {
        this.waiting.push(resolve);
        this.child.stdin.write(JSON.stringify({ m, a, env }) + "\n");
      });
      const msg = JSON.parse(line);
      if (msg.state) {
        const s = msg.state;
        this.log = s.log;
        this.execCalls = s.execCalls;
        this.fetchCalls = s.fetchCalls;
        this.statuses = s.status;
        this.records = s.records;
        this.site = s.site;
        this.managed = new Map(Object.entries(s.managed || {}).map(([k, v]) => [k, { content: v }]));
        this.currentTx = s.tx;
        if (this.openTx.length && s.tx) this.openTx[this.openTx.length - 1].marks = s.tx.marks;
        this.lastFireMarks = s.fireMarks;
      }
      if (msg.error !== undefined) throw new Error(msg.error + (msg.trace ? `\n${msg.trace}` : ""));
      return msg.r;
    };
    const p = this.queue.then(run);
    this.queue = p.catch(() => {});
    return p;
  }
  openTx = [];
  status() { return this.statuses; }
  async load(dirs, opts = {}) { await this.rpc("load", dirs, opts); return this; }
  async fire(hook, subject, opts = {}) {
    const r = await this.rpc("fire", hook, subject, opts);
    if (opts.transaction) opts.transaction.marks = this.lastFireMarks || {};
    return r;
  }
  call(op, req, opts) { return this.rpc("call", op, req, opts); }
  render(page, opts) { return this.rpc("render", page, { theme: opts && opts.theme, mode: opts && opts.mode }); }
  enable(id) { return this.rpc("enable", id); }
  disable(id) { return this.rpc("disable", id); }
  saveConfig(id, values) { return this.rpc("saveConfig", id, values); }
  upgrade(id, from) { return this.rpc("upgrade", id, from); }
  saveEntity(type, record) { return this.rpc("saveEntity", type, record); }
  deleteEntity(type, id) { return this.rpc("deleteEntity", type, id); }
  advertisedContracts() { return this.rpc("advertisedContracts"); }
  registry() { return this.rpc("registry"); }
  asset(plugin, path) { return this.rpc("asset", plugin, path); }
  unifiedContract(opts = {}) { return this.rpc("unifiedContract", opts); }
  rebuild(reason) { return this.rpc("rebuild", reason); }
  callContract(name, op, input, opts = {}) { return this.rpc("callContract", name, op, input, opts); }
  runSchedule(plugin, id) { return this.rpc("runSchedule", plugin, id); }
  importFile(input) { return this.rpc("importFile", input); }
  contractImplementers(name, opts = {}) { return this.rpc("contractImplementers", name, opts); }
  contractChoices(surface) { return this.rpc("contractChoices", surface); }
  exportUser(id) { return this.rpc("exportUser", id); }
  eraseUser(id) { return this.rpc("eraseUser", id); }
  flushLog() { return this.rpc("flushLog"); }
  reload() { return this.rpc("reload"); }
  async transaction(fn, opts = {}) {
    await this.rpc("txBegin", opts);
    const tx = { marks: (this.currentTx && this.currentTx.marks) || {} };
    this.openTx.push(tx);
    let result;
    try { result = await fn(tx); } catch (e) { this.openTx.pop(); await this.rpc("txEnd", { message: e.message, status: 500 }); throw e; }
    this.openTx.pop();
    await this.rpc("txEnd");
    return result;
  }
  close() { this.child.stdin.end(); }
}

export async function createBackend(opts = {}) {
  const b = new PhpBackend();
  await b.rpc("create", { haxcmsVersion: opts.haxcmsVersion || "26.10.1", ...(opts.user !== undefined ? { user: opts.user } : {}), ...(opts.hookTimeout ? { hookTimeout: opts.hookTimeout } : {}), ...(opts.site === null ? { site: null } : {}), ...(opts.target ? { target: opts.target } : {}) });
  return b;
}

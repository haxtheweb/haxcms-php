// DRAFT (plugin platform, haxtheweb/issues#3356; mirrors haxcms-nodejs#138 test/plugins/run-all.mjs).
//
// Runs the plugin spec's conformance cases, then every plugin's own executable cases (PHP runtime), against
// haxcms-php's native engine on real HAXcms storage through test/plugins/haxcms-php-adapter.mjs. Then, for every
// plugin, it runs the same cases through the spec's reference engine (Node, with the PHP code run by its bridge)
// and compares the two case by case: the same plugin code must give the same result on both engines, so a
// difference is a bug in one of them or a gap in the spec, never noise.
//   node test/plugins/run-all.mjs            all of it
//   node test/plugins/run-all.mjs feeds      one plugin (folder name under core-plugins/, contrib-plugins/, examples/)
// The spec comes from HAXCMS_PLUGIN_SPEC_DIR, else node_modules/@haxtheweb/haxcms-plugin-spec.
import { spawnSync } from "node:child_process";
import { readdirSync, existsSync, readFileSync, mkdtempSync } from "node:fs";
import { join, dirname } from "node:path";
import { tmpdir } from "node:os";
import { fileURLToPath } from "node:url";

const here = dirname(fileURLToPath(import.meta.url));
const spec = process.env.HAXCMS_PLUGIN_SPEC_DIR || join(here, "..", "..", "node_modules", "@haxtheweb", "haxcms-plugin-spec");
if (!existsSync(join(spec, "schema", "plugin.schema.json"))) {
  console.error(`plugin spec not found at ${spec} (set HAXCMS_PLUGIN_SPEC_DIR)`);
  process.exit(2);
}
const adapter = join(here, "haxcms-php-adapter.mjs");
const only = process.argv[2];
const env = { ...process.env, HAXCMS_PLUGIN_SPEC_DIR: spec, TMPDIR: process.env.TMPDIR || mkdtempSync(join(tmpdir(), "haxcms-php-plugins-")) };
const run = (args) => spawnSync(process.execPath, args, { stdio: "inherit", env }).status === 0;
const json = (args, extra = {}) => {
  const r = spawnSync(process.execPath, args, { encoding: "utf8", env: { ...env, ...extra }, maxBuffer: 256 * 1024 * 1024 });
  try { return JSON.parse(r.stdout); } catch { return { results: [{ id: "(runner)", ok: false, errors: [r.stderr || r.stdout] }] }; }
};
// PHP cannot tell [] from {}; compare with empty objects and arrays treated alike (as harness/expect.mjs does)
const canon = (v) => JSON.stringify(v, (k, x) => (x && typeof x === "object" && !Array.isArray(x) && !Object.keys(x).length ? [] : x));

const failed = [];
const differ = [];
if (!only && !run([join(spec, "conformance", "run.mjs"), "--backend", adapter])) failed.push("conformance");
for (const tier of ["core-plugins", "contrib-plugins", "examples"]) {
  if (!existsSync(join(spec, tier))) continue;
  for (const name of readdirSync(join(spec, tier)).sort()) {
    const dir = join(spec, tier, name);
    if (only && name !== only) continue;
    if (!existsSync(join(dir, "tests", "cases"))) continue;
    const manifest = readFileSync(join(dir, "plugin.yaml"), "utf8");
    if (!/^runtimes:.*\bphp\b/m.test(manifest)) continue;
    // pass/fail on real HAXcms storage
    const native = json([join(spec, "harness", "run-cases.mjs"), dir, "--runtime", "php", "--backend", adapter, "--json"]);
    // engine parity: haxcms-php's engine in memory (same site id, name and clock as the reference, so storage
    // differences such as a real site's uuid or outline fields do not count) against the reference engine
    const engine = json([join(spec, "harness", "run-cases.mjs"), dir, "--runtime", "php", "--backend", adapter, "--json", "--outputs"], { HAXCMS_PLUGIN_ADAPTER_ENGINE: "reference" });
    const reference = json([join(spec, "harness", "run-cases.mjs"), dir, "--runtime", "php", "--json", "--outputs"]);
    const ref = new Map((reference.results || []).map((r) => [r.id, r]));
    let bad = 0;
    for (const r of native.results || []) {
      if (!r.ok) { bad++; console.log(`FAIL ${name} ${r.id} [haxcms-php]\n      ${r.errors.join("\n      ")}`); }
    }
    for (const r of engine.results || []) {
      const other = ref.get(r.id);
      // parityIgnore keys (values that legitimately differ per run) are already left out by run-cases
      if (r.ok && other && other.ok && canon(r.out) !== canon(other.out)) {
        differ.push(`${name} ${r.id}`);
        console.log(`DIFF ${name} ${r.id}: haxcms-php and the reference engine disagree\n      haxcms-php ${canon(r.out).slice(0, 400)}\n      reference  ${canon(other.out).slice(0, 400)}`);
      }
    }
    const total = (native.results || []).length;
    console.log(`${bad ? "FAIL" : "ok  "} ${name}: ${total - bad}/${total} cases pass on haxcms-php`);
    if (bad) failed.push(name);
  }
}
console.log(differ.length ? `\n${differ.length} case(s) differ between haxcms-php and the reference engine: ${differ.join(", ")}` : "\nhaxcms-php and the reference engine agree on every case");
console.log(failed.length ? `failed: ${failed.join(", ")}` : "all plugin cases pass against haxcms-php");
process.exit(failed.length || differ.length ? 1 : 0);

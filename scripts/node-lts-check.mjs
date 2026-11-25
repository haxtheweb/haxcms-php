/**
 * Copyright 2026 The Pennsylvania State University
 * @license Apache-2.0, see LICENSE for full text.
 *
 * Node.js latest LTS (praw RULES.md 92dfb3b2-1c32-42dc-89c3-0f4ae66f7227):
 * the Node version this repo runs by default is the current LTS, NODE_LTS.
 * The same script runs in haxcms-nodejs, haxcms-php and create; keep the
 * copies identical.
 *
 * Fails (exit 1) when:
 * - package.json engines.node does not allow NODE_LTS (">=22" is fine: it
 *   is a floor; "22.x" or "<24" is not)
 * - .nvmrc or .node-version names another version
 * - a workflow node-version names one version and it is not NODE_LTS
 * - a workflow node-version matrix (the supported range, e.g. [22, 24, 26])
 *   leaves NODE_LTS out
 * Reports without failing:
 * - a Dockerfile FROM node:<n> image that is not NODE_LTS (moving a pinned
 *   image needs its new digest, so it is a follow-up, not a blocker)
 *
 *   node scripts/node-lts-check.mjs              check this repo
 *   node scripts/node-lts-check.mjs --self-test  the check catches what it should
 *
 * Raise NODE_LTS when a new LTS line starts, here and in every copy.
 * Standard library only.
 */
import console from "node:console";
import fs from "node:fs";
import path from "node:path";
import process from "node:process";
import { execFileSync } from "node:child_process";
import { fileURLToPath } from "node:url";

export const NODE_LTS = 24;

// a single version names the LTS when its major is NODE_LTS: 24, 24.x, ^24, v24.11.0, lts/*
export function isLts(value, lts = NODE_LTS) {
  const v = String(value)
    .trim()
    .replace(/^["']|["']$/g, "");
  if (/^lts\/[\w*-]+$/i.test(v)) return true;
  const m = v.match(/^(?:v|\^|~)?(\d+)(?:\.(?:\d+|x|\*))*$/i);
  return !!m && Number(m[1]) === lts;
}

// an engines range allows the LTS: ">=22", ">=22 <27", "^24", "24.x", "22 || 24"
export function allowsLts(range, lts = NODE_LTS) {
  return String(range)
    .split("||")
    .some((part) => {
      const p = part.trim();
      if (!p || p === "*") return true;
      if (isLts(p, lts)) return true;
      const parts = p.split(/\s+/);
      if (!parts.every((c) => /^(>=|>|<=|<)\s*v?\d+/.test(c))) return false;
      return parts.every((c) => {
        const m = c.match(/^(>=|>|<=|<)\s*v?(\d+)/);
        const n = Number(m[2]);
        if (m[1] === ">=") return lts >= n;
        if (m[1] === ">") return lts > n;
        if (m[1] === "<=") return lts <= n;
        return lts < n;
      });
    });
}

// node-version values in a workflow: { line, values, matrix }
export function workflowVersions(text) {
  const out = [];
  const lines = text.split("\n");
  lines.forEach((line, i) => {
    const m = line.match(/^(\s*)-?\s*node-version:\s*(.*?)\s*(#.*)?$/);
    if (!m) return;
    const raw = m[2];
    if (/\$\{\{/.test(raw)) return; // filled from a matrix, checked where the matrix lists it
    if (raw.startsWith("[")) {
      out.push({
        line: i + 1,
        values: raw
          .replace(/^\[|\]$/g, "")
          .split(",")
          .map((s) => s.trim())
          .filter(Boolean),
        matrix: true,
      });
    } else if (raw === "") {
      const values = [];
      for (let j = i + 1; j < lines.length; j++) {
        const item = lines[j].match(
          /^\s*-\s*(["']?[\w.*/^~-]+["']?)\s*(#.*)?$/,
        );
        if (!item) break;
        values.push(item[1]);
      }
      if (values.length) out.push({ line: i + 1, values, matrix: true });
    } else {
      out.push({ line: i + 1, values: [raw], matrix: false });
    }
  });
  return out;
}

export function findings(
  { pkg, versionFiles = {}, workflows = {} },
  lts = NODE_LTS,
) {
  const out = [];
  const engine = pkg && pkg.engines ? pkg.engines.node : undefined;
  if (engine && !allowsLts(engine, lts))
    out.push(
      `package.json: engines.node "${engine}" does not allow Node ${lts} (latest LTS)`,
    );
  for (const [file, text] of Object.entries(versionFiles)) {
    if (!isLts(text.trim(), lts))
      out.push(`${file}: "${text.trim()}" is not Node ${lts} (latest LTS)`);
  }
  for (const [file, text] of Object.entries(workflows)) {
    for (const w of workflowVersions(text)) {
      if (w.matrix) {
        if (!w.values.some((v) => isLts(v, lts)))
          out.push(
            `${file}:${w.line}: node-version matrix [${w.values.join(", ")}] leaves out Node ${lts} (latest LTS)`,
          );
      } else if (!isLts(w.values[0], lts)) {
        out.push(
          `${file}:${w.line}: node-version ${w.values[0]} is not Node ${lts} (latest LTS)`,
        );
      }
    }
  }
  return out;
}

export function dockerNotes(dockerfiles, lts = NODE_LTS) {
  const out = [];
  for (const [file, text] of Object.entries(dockerfiles)) {
    text.split("\n").forEach((line, i) => {
      const m = line.match(
        /^\s*FROM\s+(?:docker\.io\/)?(?:library\/)?node:v?(\d+)/i,
      );
      if (m && Number(m[1]) !== lts)
        out.push(
          `${file}:${i + 1}: base image node:${m[1]} is not Node ${lts} (latest LTS); move it with its new digest`,
        );
    });
  }
  return out;
}

function selfTest() {
  const cases = [
    ["isLts 24.x", isLts("24.x"), true],
    ["isLts 22", isLts("22"), false],
    ["isLts lts/*", isLts("lts/*"), true],
    ["engines >=22 allows 24", allowsLts(">=22"), true],
    ["engines >=22 <27 allows 24", allowsLts(">=22 <27"), true],
    ["engines 22.x refuses 24", allowsLts("22.x"), false],
    ["engines <24 refuses 24", allowsLts("<24"), false],
    ["engines 22 || 24 allows 24", allowsLts("22 || 24"), true],
    [
      "clean repo",
      findings({
        pkg: { engines: { node: ">=22" } },
        versionFiles: { ".nvmrc": "24\n" },
        workflows: {
          "a.yml":
            "  node-version: 24\n  node-version: [22, 24, 26]\n  node-version: ${{ matrix.node-version }}\n",
        },
      }).length,
      0,
    ],
    [
      "single old version",
      findings({
        workflows: { "a.yml": "x\n      node-version: 22\n" },
      }).join(),
      "a.yml:2: node-version 22 is not Node 24 (latest LTS)",
    ],
    [
      "matrix without the LTS",
      findings({
        workflows: { "a.yml": "        node-version: [20, 22]\n" },
      }).join(),
      "a.yml:1: node-version matrix [20, 22] leaves out Node 24 (latest LTS)",
    ],
    [
      "block matrix with the LTS",
      findings({
        workflows: {
          "a.yml": "        node-version:\n          - 22\n          - 24\n",
        },
      }).length,
      0,
    ],
    [
      "block matrix without the LTS",
      findings({
        workflows: {
          "a.yml": "        node-version:\n          - 20\n          - 22\n",
        },
      }).length,
      1,
    ],
    [".nvmrc", findings({ versionFiles: { ".nvmrc": "22\n" } }).length, 1],
    [
      "engines 22.x",
      findings({ pkg: { engines: { node: "22.x" } } }).length,
      1,
    ],
    [
      "docker node:22",
      dockerNotes({
        Dockerfile:
          "FROM node:22-slim@sha256:abc AS build\nFROM node:24-slim\nFROM php:8.3-apache\n",
      }).length,
      1,
    ],
  ];
  let failed = 0;
  for (const [name, got, want] of cases) {
    if (got !== want) {
      failed++;
      console.log(
        `self-test FAILED: ${name}: got ${JSON.stringify(got)}, wanted ${JSON.stringify(want)}`,
      );
    }
  }
  console.log(
    `node-lts self-test: ${cases.length - failed} of ${cases.length} cases pass`,
  );
  return failed ? 1 : 0;
}

function tracked(root) {
  return execFileSync("git", ["ls-files", "-z"], {
    cwd: root,
    encoding: "utf8",
    maxBuffer: 64 * 1024 * 1024,
  })
    .split("\0")
    .filter(Boolean);
}

const isMain =
  process.argv[1] &&
  path.resolve(process.argv[1]) === fileURLToPath(import.meta.url);
if (isMain) {
  if (process.argv.includes("--self-test")) {
    process.exitCode = selfTest();
  } else {
    const root = path.resolve(
      path.dirname(fileURLToPath(import.meta.url)),
      "..",
    );
    const files = tracked(root);
    const read = (f) => fs.readFileSync(path.join(root, f), "utf8");
    const pkg = files.includes("package.json")
      ? JSON.parse(read("package.json"))
      : null;
    const versionFiles = {};
    for (const f of [".nvmrc", ".node-version"])
      if (files.includes(f)) versionFiles[f] = read(f);
    const workflows = {};
    for (const f of files)
      if (/^\.github\/workflows\/[^/]+\.ya?ml$/.test(f)) workflows[f] = read(f);
    const dockerfiles = {};
    // test fixtures (old sites kept as they were made) are not this repo's images
    for (const f of files)
      if (/(^|\/)Dockerfile[^/]*$/.test(f) && !/^tests?\//.test(f))
        dockerfiles[f] = read(f);
    const out = findings({ pkg, versionFiles, workflows });
    const notes = dockerNotes(dockerfiles);
    out.forEach((f) => console.log(f));
    notes.forEach((n) => console.log(`note: ${n}`));
    console.log(
      `node-lts: ${out.length ? out.length + " finding(s)" : "OK"} (Node ${NODE_LTS})${notes.length ? `; ${notes.length} base image note(s)` : ""}`,
    );
    process.exitCode = out.length ? 1 : 0;
  }
}

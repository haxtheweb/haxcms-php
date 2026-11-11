#!/usr/bin/env node
/**
 * Report how this repo's agent skills copies differ from PRAW, where the
 * skills are written (https://github.com/haxtheweb/praw).
 *
 * Every .well-known/agent-skills/ folder here is a snapshot of one PRAW set
 * (made by praw's scripts/snapshot-agent-skills.js): the deployment root gets
 * the "system" set and the site boilerplate gets the "site" set. PRAW changes
 * often, so this compares each copy with PRAW's canonical index and set list:
 *   - skills whose SKILL.md digest differs from PRAW's (older or newer copy)
 *   - skills the set names that the copy is missing
 *   - skills the copy carries that the set no longer names
 *   - files whose bytes no longer match the copy's own index digest (an edit
 *     or a formatter touched them; agents that check digests will refuse them)
 *
 * Report-only: it prints Markdown (and appends it to $GITHUB_STEP_SUMMARY when
 * set) and exits 0 unless PRAW could not be read. Refresh a stale copy with
 * praw's snapshot script, e.g.
 *   node <praw>/scripts/snapshot-agent-skills.js system "$PWD/.well-known/agent-skills"
 *
 * Usage: node scripts/agent-skills-drift.js [--praw <path to a praw checkout>]
 * Without --praw it reads PRAW's main branch from GitHub.
 */
"use strict";

const fs = require("fs");
const path = require("path");
const crypto = require("crypto");

const ROOT = path.resolve(__dirname, "..");
// the copies in this repo and the PRAW set each one carries
const COPIES = [
  { dir: ".well-known/agent-skills", set: "system" },
  { dir: "system/boilerplate/site/.well-known/agent-skills", set: "site" },
];
const RAW = "https://raw.githubusercontent.com/haxtheweb/praw/main/";
const INDEX = ".well-known/agent-skills/index.json";
const SETS = ".well-known/agent-skills/agent-skills-sets.json";

const argPraw = process.argv.indexOf("--praw");
const prawDir = argPraw > -1 ? process.argv[argPraw + 1] : null;

async function readPraw(rel) {
  if (prawDir) {
    return JSON.parse(fs.readFileSync(path.join(prawDir, rel), "utf8"));
  }
  const res = await fetch(RAW + rel);
  if (!res.ok) {
    throw new Error(`${RAW + rel}: HTTP ${res.status}`);
  }
  return res.json();
}

function digest(file) {
  return (
    "sha256:" +
    crypto.createHash("sha256").update(fs.readFileSync(file)).digest("hex")
  );
}

function members(sets, name, all) {
  const want = sets[name] && sets[name].skills;
  if (!want) {
    return [];
  }
  return want === "*" || (want.length === 1 && want[0] === "*") ? all : want;
}

async function main() {
  let canon;
  let sets;
  try {
    canon = await readPraw(INDEX);
    sets = await readPraw(SETS);
  } catch (e) {
    console.error(`agent-skills-drift: could not read PRAW: ${e.message}`);
    process.exit(2);
  }
  const praw = new Map(canon.skills.map((s) => [s.name, s]));
  const out = ["# Agent skills: drift from PRAW", ""];
  let drift = 0;
  for (const copy of COPIES) {
    const dir = path.join(ROOT, copy.dir);
    const idxFile = path.join(dir, "index.json");
    if (!fs.existsSync(idxFile)) {
      out.push(`## \`${copy.dir}\``, "", "- no index.json here", "");
      drift++;
      continue;
    }
    const idx = JSON.parse(fs.readFileSync(idxFile, "utf8"));
    const have = new Map(idx.skills.map((s) => [s.name, s]));
    const want = members(sets, copy.set, [...praw.keys()]);
    const rows = [];
    for (const name of want) {
      const p = praw.get(name);
      const h = have.get(name);
      if (!h) {
        rows.push(`- missing \`${name}\` (in PRAW's ${copy.set} set)`);
      } else if (p && h.digest !== p.digest) {
        rows.push(`- \`${name}\` differs from PRAW`);
      }
    }
    for (const name of have.keys()) {
      if (!want.includes(name)) {
        rows.push(`- \`${name}\` is no longer in PRAW's ${copy.set} set`);
      }
    }
    for (const s of idx.skills) {
      const file = path.join(dir, s.url);
      if (fs.existsSync(file) && digest(file) !== s.digest) {
        rows.push(
          `- \`${s.url}\` does not match its digest in this copy's index.json`,
        );
      }
    }
    drift += rows.length;
    out.push(
      `## \`${copy.dir}\` (${copy.set} set, ${idx.skills.length} skills)`,
      "",
      ...(rows.length ? rows : ["- in step with PRAW"]),
      "",
    );
  }
  out.push(
    drift
      ? `${drift} difference(s). Refresh with praw's scripts/snapshot-agent-skills.js.`
      : "Every copy is in step with PRAW.",
  );
  const md = out.join("\n") + "\n";
  process.stdout.write(md);
  if (process.env.GITHUB_STEP_SUMMARY) {
    fs.appendFileSync(process.env.GITHUB_STEP_SUMMARY, md);
  }
}

main();

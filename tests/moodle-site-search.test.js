// Run with: node tests/moodle-site-search.test.js
// No browser or npm dependencies are required.
const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const vm = require("node:vm");

const root = path.join(__dirname, "..");
const template = fs.readFileSync(path.join(root, "public/app/templates/page/moodle.mustache"), "utf8");
const style = fs.readFileSync(path.join(root, "public/assets/style.css"), "utf8");
const scss = fs.readFileSync(path.join(root, "public/assets/style.scss"), "utf8");

assert.match(template, /data-search="{{domain}} {{site_fullname}} {{site_shortname}}"/);
for (const source of [style, scss]) {
    assert.match(source, /\.site-card\[hidden\]\s*\{\s*display:\s*none;/);
}

const script = template.match(/<script>([\s\S]*?)<\/script>/);
assert.ok(script, "Moodle site filter script must exist");

const cards = [
    {
        textContent: "Universidade de Educação Campus Ativo Detalhes",
        dataset: { search: "ensino.example.org Universidade de Educação CAMPUS" },
        hidden: false,
    },
    {
        textContent: "Treinamentos Internos Ativo Detalhes",
        dataset: { search: "training.example.org Treinamentos Internos TRAINING" },
        hidden: false,
    },
];
const events = {};
const filter = {
    value: "",
    addEventListener(type, callback) { events[type] = callback; },
};
const grid = {
    querySelectorAll(selector) {
        assert.equal(selector, ".site-card");
        return cards;
    },
};
const document = {
    getElementById(id) {
        return { "moodle-site-filter": filter, "moodle-site-grid": grid }[id] ?? null;
    },
    addEventListener(type, callback) {
        assert.equal(type, "DOMContentLoaded");
        callback();
    },
};
vm.runInNewContext(script[1], { document }, { timeout: 1000 });
assert.equal(typeof events.input, "function", "input listener must be registered");

function search(query) {
    filter.value = query;
    events.input();
    return cards.map(card => card.hidden);
}

assert.deepEqual(search("ensino.example.org"), [false, true], "searches domain even when not visible");
assert.deepEqual(search("EDUCACAO"), [false, true], "ignores accents and case in full name");
assert.deepEqual(search("campus"), [false, true], "searches short name");
assert.deepEqual(search("training"), [true, false], "matches second site");
assert.deepEqual(search("ativo"), [false, false], "retains search by visible text");
assert.deepEqual(search("does not exist"), [true, true], "hides nonmatches");
assert.deepEqual(search("   "), [false, false], "restores all sites on clearing search");
console.log("PASS: Moodle site search filters by domain, names and visible text.");

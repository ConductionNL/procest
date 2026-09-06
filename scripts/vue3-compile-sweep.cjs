/**
 * Vue 3 compile-readiness sweep (ADR-066, openspec vue-3-migration).
 *
 * Compiles every SFC template + script under src/ with @vue/compiler-sfc in
 *
 * @vue/compat MODE 2, and reports which components fail to COMPILE on Vue 3.
 * This is the fastest signal for the migration: compile failures are hard
 * blockers, and this needs no bundle/install of the runtime deps.
 *
 * Requires the Vue 3 toolchain (`npm i -D @vue/compiler-sfc@^3.5`). Run:
 *   node scripts/vue3-compile-sweep.cjs        (or: npm run check:vue3-compile)
 *
 * Exit code is the number of failing components (0 = all clean).
 *
 * NOTE: a clean sweep does NOT mean runtime-correct. Plain Vue 3 silently
 * mis-compiles `.sync` and `{{x|f}}` (see BUILD-VUE3.md); compat MODE 2 keeps
 * them correct here, but every such site must still be rewritten (tasks 2.2/2.6)
 * before the compat flags come off.
 */
const fs = require('fs')
const path = require('path')

let sfc
try {
	sfc = require('@vue/compiler-sfc')
} catch {
	console.error('[vue3-compile-sweep] @vue/compiler-sfc not found — install the Vue 3 toolchain first (npm i -D @vue/compiler-sfc@^3.5).')
	process.exit(2)
}

const root = path.resolve(__dirname, '..', 'src')
const compat = { compatConfig: { MODE: 2, COMPILER_FILTERS: true } }

/**
 * Collect every file path under a directory, recursively.
 *
 * @param {string} dir Directory to walk.
 * @param {Array<string>} out Accumulator the paths are appended to.
 *
 * @return {Array<string>} The same accumulator, for convenience.
 */
function walk(dir, out = []) {
	for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
		const p = path.join(dir, e.name)
		if (e.isDirectory()) walk(p, out)
		else if (e.name.endsWith('.vue')) out.push(p)
	}
	return out
}

const files = walk(root)
let clean = 0
const failed = []

for (const f of files) {
	const src = fs.readFileSync(f, 'utf8')
	const errs = []
	try {
		const { descriptor, errors } = sfc.parse(src, { filename: f })
		errs.push(...(errors || []).map((e) => 'parse: ' + (e.message || e)))
		if (descriptor.template) {
			const t = sfc.compileTemplate({ source: descriptor.template.content, filename: f, id: 'x', compilerOptions: compat })
			errs.push(...(t.errors || []).map((e) => 'tmpl: ' + (e.message || String(e)).slice(0, 100)))
		}
		if (descriptor.script || descriptor.scriptSetup) {
			try {
				sfc.compileScript(descriptor, { id: 'x', templateOptions: { compilerOptions: compat } })
			} catch (e) {
				errs.push('script: ' + (e.message || String(e)).slice(0, 100))
			}
		}
	} catch (e) {
		errs.push('fatal: ' + (e.message || String(e)).slice(0, 100))
	}
	if (errs.length === 0) clean++
	else failed.push({ f: path.relative(root, f), errs })
}

console.log(`Vue 3 compile sweep (compat MODE 2) — ${files.length} components`)
console.log(`  clean : ${clean}`)
console.log(`  failed: ${failed.length}`)
if (failed.length) {
	console.log('')
	for (const x of failed) console.log(`  ✗ ${x.f}\n      ${x.errs[0]}`)
}
process.exit(failed.length)

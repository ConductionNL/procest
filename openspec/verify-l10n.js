#!/usr/bin/env node
/**
 * Verify l10n: JSON valid, key sync, placeholders, code coverage.
 * Run from procest app root: node openspec/verify-l10n.js
 */
const fs = require('fs')
const path = require('path')

const L10N_DIR = path.join(__dirname, '..', 'l10n')
const SRC_DIR = path.join(__dirname, '..', 'src')

function loadJson(file) {
  try {
    const raw = fs.readFileSync(file, 'utf8')
    const data = JSON.parse(raw)
    return { data, error: null }
  } catch (e) {
    return { data: null, error: e.message }
  }
}

function main() {
  let failed = false

  // 1. Load and validate JSON
  const enPath = path.join(L10N_DIR, 'en.json')
  const nlPath = path.join(L10N_DIR, 'nl.json')

  const enResult = loadJson(enPath)
  const nlResult = loadJson(nlPath)

  if (enResult.error) {
    console.error('FAIL: en.json parsing error:', enResult.error)
    failed = true
  } else {
    console.log('OK: en.json valid JSON')
  }

  if (nlResult.error) {
    console.error('FAIL: nl.json parsing error:', nlResult.error)
    failed = true
  } else {
    console.log('OK: nl.json valid JSON')
  }

  if (failed) process.exit(1)

  const enKeys = new Set(Object.keys(enResult.data.translations))
  const nlKeys = new Set(Object.keys(nlResult.data.translations))

  // 2. REQ-L10N-001: Identical key sets
  const onlyInEn = [...enKeys].filter(k => !nlKeys.has(k))
  const onlyInNl = [...nlKeys].filter(k => !enKeys.has(k))

  if (onlyInEn.length > 0) {
    console.error('FAIL: Keys in en.json but not nl.json:', onlyInEn.slice(0, 5).join(', '), onlyInEn.length > 5 ? `... (+${onlyInEn.length - 5} more)` : '')
    failed = true
  }
  if (onlyInNl.length > 0) {
    console.error('FAIL: Keys in nl.json but not en.json:', onlyInNl.slice(0, 5).join(', '), onlyInNl.length > 5 ? `... (+${onlyInNl.length - 5} more)` : '')
    failed = true
  }
  if (onlyInEn.length === 0 && onlyInNl.length === 0) {
    console.log('OK: en.json and nl.json have identical keys (' + enKeys.size + ' total)')
  }

  // 3. Placeholder preservation
  const placeholderKeys = [...enKeys].filter(k => /\{[a-zA-Z]+\}/.test(enResult.data.translations[k]))
  let placeholderOk = true
  for (const key of placeholderKeys) {
    const enVal = enResult.data.translations[key]
    const nlVal = nlResult.data.translations[key]
    const enPlaceholders = (enVal.match(/\{[a-zA-Z]+\}/g) || []).sort().join(',')
    const nlPlaceholders = (nlVal.match(/\{[a-zA-Z]+\}/g) || []).sort().join(',')
    if (enPlaceholders !== nlPlaceholders) {
      console.error('FAIL: Placeholder mismatch for key:', key, '| en:', enPlaceholders, '| nl:', nlPlaceholders)
      placeholderOk = false
      failed = true
    }
  }
  if (placeholderOk && placeholderKeys.length > 0) {
    console.log('OK: Placeholders preserved in nl.json for', placeholderKeys.length, 'keys')
  }

  // 4. Extract keys from t('procest', ...) in src
  function walkDir(dir, files = []) {
    const entries = fs.readdirSync(dir, { withFileTypes: true })
    for (const e of entries) {
      const full = path.join(dir, e.name)
      if (e.isDirectory() && e.name !== 'node_modules') {
        walkDir(full, files)
      } else if (e.isFile() && (e.name.endsWith('.vue') || e.name.endsWith('.js'))) {
        files.push(full)
      }
    }
    return files
  }
  const srcFiles = walkDir(SRC_DIR)

  function unescapeKey(s) {
    return s.replace(/\\'/g, "'").replace(/\\"/g, '"').replace(/\\u([0-9a-fA-F]{4})/g, (_, hex) => String.fromCharCode(parseInt(hex, 16)))
  }

  const usedKeys = new Set()
  const tPatternSingle = /t\s*\(\s*['"]procest['"]\s*,\s*'((?:[^'\\]|\\.)*)'/g
  const tPatternDouble = /t\s*\(\s*['"]procest['"]\s*,\s*"((?:[^"\\]|\\.)*)"/g
  for (const file of srcFiles) {
    try {
      const content = fs.readFileSync(file, 'utf8')
      let m
      tPatternSingle.lastIndex = 0
      while ((m = tPatternSingle.exec(content)) !== null) {
        usedKeys.add(unescapeKey(m[1]))
      }
      tPatternDouble.lastIndex = 0
      while ((m = tPatternDouble.exec(content)) !== null) {
        usedKeys.add(unescapeKey(m[1]))
      }
    } catch (_) {}
  }

  const missingInL10n = [...usedKeys].filter(k => !enKeys.has(k))
  if (missingInL10n.length > 0) {
    console.error('FAIL: Keys used in code but missing from l10n:', missingInL10n.slice(0, 10).join(', '), missingInL10n.length > 10 ? `... (+${missingInL10n.length - 10} more)` : '')
    failed = true
  } else {
    console.log('OK: All', usedKeys.size, 'keys used in code exist in l10n')
  }

  // Orphan check: keys in l10n not used in code (original 55 are allowed)
  const originalKeys = new Set([
    'Procest', 'Dashboard', 'Cases', 'Case', 'Tasks', 'Task', 'New case', 'New task',
    'Title', 'Description', 'Status', 'Assignee', 'Priority', 'Due date', 'Created', 'Updated',
    'Closed', 'Save', 'Cancel', 'Delete', 'Edit', 'Search', 'Loading...', 'No cases found',
    'No tasks found', 'Are you sure you want to delete this?', 'Case created successfully',
    'Case updated successfully', 'Case deleted successfully', 'Task created successfully',
    'Task updated successfully', 'Settings', 'Register', 'Case schema', 'Task schema',
    'Status schema', 'Role schema', 'Result schema', 'Decision schema', 'Configuration saved',
    'Welcome to Procest', 'Manage your cases and tasks', 'open', 'in_progress', 'closed',
    'low', 'normal', 'high', 'urgent', 'Back to list', 'Previous', 'Next'
  ])
  const orphanKeys = [...enKeys].filter(k => !usedKeys.has(k) && !originalKeys.has(k))
  if (orphanKeys.length > 0) {
    console.warn('WARN: Keys in l10n not found in code (may be used dynamically):', orphanKeys.length)
  }

  console.log('')
  if (failed) {
    console.error('VERIFICATION FAILED')
    process.exit(1)
  }
  console.log('VERIFICATION PASSED')
}

main()

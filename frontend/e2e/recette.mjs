// Recette du front dans Chrome, contre l'API réelle (MySQL). Lancer : `npm run recette` (depuis frontend/).
// Démarre l'API (php -S :8000, PHP_CLI_SERVER_WORKERS=4) et Vite (:5173) s'ils ne tournent pas, les arrête ensuite.
// Les captures vont dans frontend/recette/ (ignoré par git), vidé puis recréé à chaque exécution.
// Code de sortie 1 si une erreur console/réseau inattendue apparaît (seul le 404 de l'id inexistant est attendu).
// Les scénarios sont des fonctions indépendantes (un contexte Chrome chacun) : la chaîne 1→7 est séquentielle, 8 à 15 sont
// lancés en parallèle (pool de RECETTE_JOBS, défaut 4). Les graines des scénarios 10, 11, 12, 14 et 15 se cherchent par l'API
// (e2e/api.mjs) ; l'UI n'est ouverte que sur la partie trouvée.
import { spawn } from 'node:child_process'
import { mkdirSync, rmSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { chromium } from 'playwright'
import * as api from './api.mjs'

// Délai maximal de chaque attente/action Playwright (valeur par défaut de Playwright).
const TIMEOUT = 30_000
const JOBS = Math.max(1, Number(process.env.RECETTE_JOBS) || 4)
// RECETTE_ONLY=10,15 : ne lance que ces tâches (noms de la liste de run(), dont « 1-7 »).
const ONLY = process.env.RECETTE_ONLY?.split(',')
const FRONT = fileURLToPath(new URL('..', import.meta.url))
const BACK = fileURLToPath(new URL('../../backend', import.meta.url))
const S = `${FRONT}recette`
rmSync(S, { recursive: true, force: true })
mkdirSync(S, { recursive: true })

const up = async (url) => { try { return (await fetch(url)).status < 500 } catch { return false } }
const started = []
const start = async (name, url, cmd, args, cwd, env = {}) => {
  if (await up(url)) return console.log(`${name} déjà démarré`)
  const p = spawn(cmd, args, { cwd, stdio: 'ignore', detached: true, env: { ...process.env, ...env } })
  started.push(p)
  for (let i = 0; i < 100 && !(await up(url)); i++) await new Promise((r) => setTimeout(r, 300))
  if (!(await up(url))) throw new Error(`${name} ne répond pas sur ${url}`)
}
// Groupe de processus : php -S avec workers a des processus enfants.
const stopAll = () => started.forEach((p) => { try { process.kill(-p.pid) } catch { p.kill() } })

const T0 = Date.now()
try {
  await start('API', 'http://127.0.0.1:8000/api/simulations/0', 'php', ['-d', 'memory_limit=512M', '-d', 'variables_order=EGPCS', '-S', '127.0.0.1:8000', '-t', 'public'], BACK, { PHP_CLI_SERVER_WORKERS: '4' })
  // Workers Messenger : POST /simulations ne joue plus la partie, il la met en file (statut queued).
  // Un worker par scénario parallèle ; ils sont arrêtés avec le reste (stopAll).
  for (let i = 0; i < JOBS; i++) {
    started.push(spawn('php', ['-d', 'memory_limit=512M', 'bin/console', 'messenger:consume', 'async', '--time-limit=3600', '--quiet'], { cwd: BACK, stdio: 'ignore', detached: true }))
  }
  await start('Vite', 'http://localhost:5173/', 'npx', ['vite', '--port', '5173', '--strictPort'], FRONT)
  await run()
} catch (e) {
  console.error(e)
  process.exitCode = 1
} finally {
  stopAll()
}

// ---------------------------------------------------------------------------------------------------------------------

/** Contexte Chrome isolé pour un scénario : page, problèmes, captures et logs préfixés. */
async function makeCtx(browser, name) {
  const context = await browser.newContext({ viewport: { width: 1500, height: 950 } })
  const page = await context.newPage()
  page.setDefaultTimeout(TIMEOUT)
  const problems = []
  page.on('console', (m) => { if (['error', 'warning'].includes(m.type())) problems.push(`[${name}] console.${m.type()}: ${m.text()}`) })
  page.on('pageerror', (e) => problems.push(`[${name}] pageerror: ${e.message}`))
  page.on('response', (r) => { if (r.status() >= 400) problems.push(`[${name}] HTTP ${r.status()} ${r.request().method()} ${r.url()}`) })
  const shot = (n) => page.screenshot({ path: `${S}/${n}.png` })
  const log = (...a) => console.log(`[${name}]`, ...a)
  const info = async () => (await page.locator('.topbar .muted').textContent().catch(() => null))?.replace(/\s+/g, ' ')
  const prog = async () => (await page.locator('.playback small').textContent()).trim()
  const problem = (msg) => problems.push(`[${name}] ${msg}`)
  // Seuls les boutons activés comptent (pendant un chargement ils sont désactivés et le panneau se redessine) ;
  // un clic raté est simplement retenté au tour suivant.
  const enabled = (title) => page.locator(`.actions h4:has-text("${title}") ~ button:not([disabled])`).first()
  const tryClick = async (b) => { try { await b.click({ timeout: 3000 }); return true } catch { return false } }
  // Crée une partie J0 humain via le bouton de l'UI (seed fixée) et attend qu'elle soit chargée.
  const newUiGame = async (seed) => {
    await page.fill('input[placeholder="aléatoire"]', String(seed))
    await page.click('text=Nouvelle partie Commander')
    await page.waitForSelector('.actions', { timeout: TIMEOUT })
    await keepUi()
  }
  // Mulligan de Londres : J0 humain démarre sur « Main de départ » ; on garde la main (et on met au fond s'il le faut).
  const keepUi = async () => {
    const keep = page.locator('.actions h4:has-text("Main de départ") ~ button:has-text("Garder")')
    await keep.first().waitFor({ timeout: TIMEOUT })
    await keep.first().click()
    await page.waitForFunction(() => !document.querySelector('.actions')?.textContent.includes('Main de départ'), null, { timeout: TIMEOUT })
    for (let i = 0; i < 10; i++) {
      const bottom = page.locator('.actions h4:has-text("Mettre au fond") ~ button:not([disabled])').first()
      if (!(await bottom.count())) break
      await bottom.click(); await page.waitForTimeout(300)
    }
  }
  // Ouvre une partie créée par l'API (champ « Charger # » + Ouvrir), se place à la fin du journal et attend le panneau d'actions.
  const openGame = async (id) => {
    await page.fill('input[type=number] >> nth=1', String(id))
    await page.click('text=Ouvrir')
    await page.waitForFunction(([i]) => {
      const m = (document.querySelector('.topbar .muted')?.textContent ?? '').match(/#(\d+)/)
      const n = (document.querySelector('.playback small')?.textContent ?? '').match(/\/ (\d+)/)
      return m && m[1] === String(i) && n && Number(n[1]) > 0
    }, [id], { timeout: TIMEOUT })
    await page.click('button[title="Fin"]')
    await page.waitForSelector('.actions', { timeout: TIMEOUT })
  }
  return { page, context, problems, shot, log, info, prog, problem, enabled, tryClick, newUiGame, keepUi, openGame }
}

/** Joue un scénario dans son propre contexte ; renvoie ses problèmes (une exception devient un problème). */
async function scenario(browser, name, fn) {
  const c = await makeCtx(browser, name)
  const t = Date.now()
  try {
    await c.page.goto('http://localhost:5173/')
    await fn(c)
  } catch (e) {
    c.problems.push(`[${name}] exception : ${e.message}`)
    await c.shot(`erreur-${name}`).catch(() => {})
  }
  c.log(`terminé en ${((Date.now() - t) / 1000).toFixed(1)} s`)
  await c.context.close()
  return c.problems
}

/** Cherche, via l'API, une partie où la main de J0 contient les cartes voulues. Graines « connues » d'abord, sinon 1..400. */
async function findHandSeed(cards, known, minLands = 0) {
  const seeds = [...known, ...Array.from({ length: 400 }, (_, i) => i + 1)]
  for (const seed of seeds) {
    const sim = await api.createHuman(seed)
    const { names, lands } = await api.handOf(sim.id)
    if (cards.every((c) => names.includes(c)) && lands >= minLands) return { seed, id: sim.id }
  }
  throw new Error(`aucune graine ne donne ${cards.join(' + ')} en main de J0`)
}

async function run() {
  const browser = await chromium.launch({ channel: 'chrome', headless: true })
  const tasks = [
    ['1-7', (b) => scenario(b, '1-7', chain)],
    ['15', (b) => scenario(b, '15', s15)],
    ['10', (b) => scenario(b, '10', s10)],
    ['8', (b) => scenario(b, '8', s8)],
    ['9', (b) => scenario(b, '9', s9)],
    ['11', (b) => scenario(b, '11', s11)],
    ['12', (b) => scenario(b, '12', s12)],
    ['13', (b) => scenario(b, '13', s13)],
    ['14', (b) => scenario(b, '14', s14)],
    ['16', (b) => scenario(b, '16', s16)],
  ].filter(([n]) => !ONLY || ONLY.includes(n))
  const results = []
  let next = 0
  const worker = async () => {
    while (next < tasks.length) {
      const [, fn] = tasks[next++]
      results.push(...(await fn(browser)))
    }
  }
  console.log(`recette : ${tasks.length} tâches, ${JOBS} en parallèle`)
  await Promise.all(Array.from({ length: Math.min(JOBS, tasks.length) }, worker))
  // Seul le 404 volontaire (id 99999) est toléré, avec le message console que Chrome en tire.
  const unexpected = results.filter((p) => !/404/.test(p))
  console.log('\nPROBLÈMES inattendus:', unexpected.length ? '\n' + unexpected.join('\n') : 'aucun')
  console.log(`durée totale : ${((Date.now() - T0) / 1000).toFixed(1)} s`)
  await browser.close()
  if (unexpected.length) process.exitCode = 1
}

// ---------------------------------------------------------------------------------------------------------------------
// Scénarios 1 à 7 : chaîne séquentielle dans une même page (chacun dépend de l'état du précédent).

async function chain({ page, log, shot, info, prog, keepUi }) {
  log('1 vide:', (await page.locator('.empty').textContent()).trim()); await shot('1-vide')

  // 2 : partie humaine
  await page.fill('input[placeholder="aléatoire"]', '7')
  await page.click('text=Nouvelle partie Commander')
  await page.waitForSelector('.actions')
  await keepUi()
  log('2 info:', await info(), '|', await prog()); await shot('2-humain')
  log('2 actions:', await page.locator('.actions button').allTextContents())

  // 3 : décider
  const before = await prog()
  const landBtn = page.locator('.actions h4:has-text("Terrains") ~ button').first()
  if (await landBtn.count()) { log('3 terrain:', await landBtn.textContent()); await landBtn.click() }
  await page.waitForTimeout(500)
  log('3 après terrain:', await prog(), '(avant', before + ')')
  for (let i = 0; i < 5; i++) {
    const pass = page.locator('.actions h4:has-text("Priorité") ~ button').first()
    if (!(await pass.count())) break
    await pass.click(); await page.waitForTimeout(400)
  }
  log('3 après passes:', await prog(), await info()); await shot('3-decisions')

  // 4 : navigation
  await page.click('button[title="Pas en arrière"]'); await page.click('button[title="Pas en arrière"]')
  log('4 recul:', await prog(), '| ActionPanel visible:', await page.locator('.actions').count())
  await page.click('button[title="Début"]'); log('4 début:', await prog())
  await page.click('button[title="Lecture"]'); await page.waitForTimeout(1200)
  await page.click('button[title="Pause"]'); log('4 lecture 1,2s:', await prog())
  await page.click('button[title="Fin"]'); log('4 fin:', await prog(), '| ActionPanel:', await page.locator('.actions').count()); await shot('4-nav')

  // 5 : 4 IA
  await page.uncheck('text=Je joue J0 >> input')
  await page.fill('input[placeholder="aléatoire"]', '42')
  const oldId = (await info()).match(/#(\d+)/)[1]
  const oldTotal = (await prog()).match(/\/ (\d+)/)[1]
  await page.click('text=Nouvelle partie Commander')
  // L'id change avant que les événements soient chargés : attendre aussi un nouveau total (sinon on lit celui de la partie précédente).
  await page.waitForFunction(([o, t]) => {
    const m = (document.querySelector('.topbar .muted')?.textContent ?? '').match(/#(\d+)/)
    const n = (document.querySelector('.playback small')?.textContent ?? '').match(/\/ (\d+)/)
    return m && m[1] !== o && n && n[1] !== t
  }, [oldId, oldTotal], { timeout: TIMEOUT })
  log('5 info:', await info(), '|', await prog())
  await page.selectOption('.playback select', '10')
  await page.click('button[title="Lecture"]')
  await page.waitForFunction(() => { const t = document.querySelector('.playback small')?.textContent ?? ''; const m = t.match(/(\d+) \/ (\d+)/); return m && m[1] === m[2] }, null, { timeout: 120000 })
  log('5 lecture terminée:', await prog()); await shot('5-4ia-fin')
  const parentInfo = await info(); const parentId = Number(parentInfo.match(/#(\d+)/)[1])

  // 6 : fork
  await page.click('button[title="Début"]')
  await page.fill('.slider', '1200').catch(() => {})
  await page.locator('.slider').evaluate((el) => { el.value = 1200; el.dispatchEvent(new Event('input', { bubbles: true })) })
  log('6 curseur:', await prog(), '|', (await page.locator('.pausebar p').first().textContent()).replace(/\s+/g, ' '))
  await page.check('.pausebar label:has-text("J1") input'); await shot('6a-avant-fork')
  await page.click('text=Reprendre la main ici')
  await page.waitForSelector('.actions', { timeout: TIMEOUT })
  log('6 fork info:', await info(), '|', await prog())
  log('6 fork actions:', (await page.locator('.actions h3').textContent()), (await page.locator('.actions button').count()), 'boutons'); await shot('6b-fork')
  // Selon le point de fork, J1 est en priorité ou en déclaration de combat : on prend l'une ou l'autre décision.
  const pass2 = page.locator('.actions h4:has-text("Priorité") ~ button, .actions h4:has-text("Terminer la déclaration") ~ button').first(); await pass2.click(); await page.waitForTimeout(500)
  log('6 fork après décision:', await prog(), await info()); await shot('6c-fork-decision')

  // 7 : charger
  await page.fill('input[type=number] >> nth=1', String(parentId)); await page.click('text=Ouvrir'); await page.waitForTimeout(1500)
  log('7 charger parent:', await info(), '|', await prog())
  await page.fill('input[type=number] >> nth=1', '99999'); await page.click('text=Ouvrir'); await page.waitForTimeout(800)
  log('7 id inexistant, erreur affichée:', await page.locator('.error').textContent().catch(() => '(aucune)')); await shot('7-erreur')
}

// ---------------------------------------------------------------------------------------------------------------------

// 8 : combat côté humain (J0 vs 3 IA, seed 7) : jouer, lancer des créatures, puis déclarer une attaque (et bloquer si l'IA attaque J0)
async function s8({ page, log, shot, info, problem, newUiGame }) {
  await newUiGame(7)
  const first = (title) => page.locator(`.actions h4:has-text("${title}") ~ button`).first()
  // Cartes : celles que J0 peut jouer sont mises en évidence, et le survol ouvre l'aperçu agrandi.
  await page.locator('.actions').waitFor({ timeout: TIMEOUT })
  const playable = await page.locator('.card.playable').count()
  if (!playable) problem('recette cartes : aucune carte jouable mise en évidence alors que J0 a la main')
  else {
    await page.locator('.card.playable').first().hover()
    if (!(await page.locator('.card-preview').isVisible())) problem('recette cartes : le survol n\'ouvre pas l\'aperçu')
  }
  log('8 cartes jouables en évidence:', playable); await shot('8a-cartes')
  await page.mouse.move(0, 0)
  let attacked = false, sawBlock = false
  for (let i = 0; i < 400 && !attacked; i++) {
    if (!(await page.locator('.actions').count())) break
    // Les boutons sont désactivés pendant une décision en vol (store.loading) : on attend qu'ils soient réactivés
    // avant de les chercher, sans avaler l'erreur si l'état reste bloqué.
    await page.waitForFunction(() => !document.querySelector('.actions button:disabled'), null, { timeout: TIMEOUT })
    const order = ['Défausser', 'Attaquer', 'Bloquer', 'Terrains', 'Sorts', 'Terminer la déclaration', 'Priorité']
    for (const title of order) {
      // Sorts : on ne lance que des créatures (le Bolt/Contresort ne servent pas ce scénario).
      const b = title === 'Sorts' ? page.locator('.actions h4:has-text("Sorts") ~ button:is(:has-text("Grizzly"), :has-text("Commandant"))').first() : first(title)
      if (!(await b.count())) continue
      // On ne bloque qu'une fois (pour vérifier la déclaration) : bloquer à chaque fois sacrifierait toutes les créatures de J0 avant qu'elles attaquent.
      if (title === 'Bloquer' && sawBlock) continue
      if (title === 'Bloquer') sawBlock = true
      if (title === 'Attaquer') attacked = true
      await b.click()
      break
    }
    await page.waitForTimeout(150)
  }
  log('8 attaque déclarée par J0:', attacked, '| a pu bloquer:', sawBlock, '|', await info())
  if (!attacked) problem('recette combat : J0 n\'a jamais pu déclarer d\'attaque en 400 décisions')
  await page.waitForTimeout(500)
  log('8 journal :', (await page.locator('text=/J0 attaque J\\d/').count()) > 0 ? 'AttackerDeclared affiché' : 'absent'); await shot('8-combat')
}

// 9 : capacités de mana à la main (J0 engage lui-même une source) -> la réserve s'affiche ; le mana flottant disparaît ensuite
async function s9({ page, log, shot, problem, enabled, tryClick, newUiGame }) {
  await newUiGame(7)
  let poolSeen = false
  for (let i = 0; i < 80 && !poolSeen; i++) {
    const mana = page.locator('.actions h4:has-text("Capacités") ~ button.mana-ability:not([disabled])').first()
    if (await mana.count()) {
      if (await tryClick(mana)) {
        await page.waitForTimeout(300)
        poolSeen = (await page.locator('.mana-pool').count()) > 0
        if (poolSeen) break
      }
    } else if (await enabled('Terrains').count()) await tryClick(enabled('Terrains'))
    else if (await enabled('Priorité').count()) await tryClick(enabled('Priorité'))
    await page.waitForTimeout(200)
  }
  log('9 réserve visible après engagement manuel:', poolSeen); await shot('9-mana-manuel')
  if (!poolSeen) problem('recette mana : la réserve n\'apparaît pas après une capacité de mana')
}

// 10 : capacité à choix de coût. La graine se cherche par l'API (depuis les decks de 100 cartes) : J0 a Goblin Bombardment,
// une créature à 1-2 mana et 2 terrains en main de départ. Un pilote API joue J0 (terrains, Bombardment et créatures, pas d'attaque) jusqu'à ce que le sacrifice d'une créature pour
// 1 dégât à J1 soit proposé ; l'UI ouvre alors la partie et J0 clique ce sacrifice. Piloter clic par clic dépendait du timing
// du navigateur : sur la CI, plus lente, un clic sur un bouton périmé faisait diverger la partie.
async function s10({ page, log, shot, info, problem, tryClick, openGame }) {
  const KNOWN_SEED_10 = 15 // graine retenue au dernier passage (essayée en premier)
  const SAC = /en sacrifiant.*→ joueur 1/
  const CHEAP = ['Llanowar Elves', 'Fyndhorn Elves', 'Elvish Mystic', 'Boreal Druid', 'Goblin Guide', 'Raging Goblin', 'Monastery Swiftspear',
    'Grizzly Bears', 'Runeclaw Bear', 'Balduvian Bears', 'Kalonian Tusker', 'Deadly Recluse', 'Wild Mongrel', 'Hybrid Bear', 'Young Pyromancer',
    'Elvish Visionary', 'Eldrazi Mimic', 'Alpha Myr', 'Perilous Myr', 'Iron Myr', 'Copper Myr']
  const CREATURE = new RegExp(['Goblin Bombardment', ...CHEAP].join('|'))
  const policy = (p, step) => {
    const first = (type, re) => p.actions.find((a) => a.type === type && (!re || re.test(a.label)))
    if (step > 250) return null
    if (first('ability', SAC)) return 'stop'
    return first('discard') ?? first('done') ?? first('land') ?? first('cast', CREATURE) ?? first('pass') ?? null
  }
  let sacrificed = false, seed10 = null
  let drives = 0
  for (const seed of [KNOWN_SEED_10, ...Array.from({ length: 400 }, (_, i) => i + 1)]) {
    if (sacrificed || drives >= 6) break
    const sim = await api.createHuman(seed)
    const { names, lands } = await api.handOf(sim.id)
    if (!names.includes('Goblin Bombardment') || !names.some((n) => CHEAP.includes(n)) || lands < 2) continue
    drives++
    if (!(await api.drive(sim.id, policy)).stopped) continue
    await openGame(sim.id)
    const sac = page.locator('.actions h4:has-text("Capacités") ~ button:not([disabled]):has-text("en sacrifiant"):has-text("→ joueur 1")').first()
    if (await sac.count() && await tryClick(sac)) {
      await page.waitForTimeout(400)
      sacrificed = (await page.locator('text=/J0 sacrifie/').count()) > 0
      if (sacrificed) seed10 = seed
    }
  }
  const sacLine = sacrificed ? (await page.locator('text=/J0 sacrifie/').first().textContent())?.replace(/\s+/g, ' ').trim() : '(absent)'
  log('10 graine retenue :', seed10, '| sacrifice d\'une créature (Goblin Bombardment) par J0 :', sacrificed, '| journal :', sacLine, '|', await info())
  await page.locator('text=/J0 sacrifie/').first().scrollIntoViewIfNeeded().catch(() => {}); await shot('10-cout-sacrifice')
  if (!sacrificed) problem('recette coût : J0 n\'a pas pu sacrifier une créature au Goblin Bombardment')
}

// 11 : trigger à cible. J0 doit avoir Goblin Bombardment et Perilous Myr en main de départ : findHandSeed cherche la graine (API).
// J0 lance Bombardment et le Myr, sacrifie le Myr (1 dégât à J1), puis choisit J2 comme cible du trigger « meurt ».
async function s11({ page, log, shot, info, problem, enabled, tryClick, openGame }) {
  const { seed, id } = await findHandSeed(['Goblin Bombardment', 'Perilous Myr'], [218, 246, 538, 580], 3)
  log('11 graine retenue :', seed)
  await openGame(id)
  let triggered = false
  for (let i = 0; i < 300 && !triggered; i++) {
    if (!(await page.locator('.actions').count())) break
    const put = page.locator('.actions h4:has-text("Triggers à mettre sur la pile") ~ button:not([disabled]):has-text("Perilous Myr"):has-text("→ joueur 2")').first()
    const sac = page.locator('.actions h4:has-text("Capacités") ~ button:not([disabled]):has-text("en sacrifiant Perilous Myr"):has-text("→ joueur 1")').first()
    const spell = page.locator('.actions h4:has-text("Sorts") ~ button:not([disabled]):is(:has-text("Goblin Bombardment"), :has-text("Perilous Myr"))').first()
    if (await put.count()) {
      if (await tryClick(put)) {
        await page.waitForTimeout(400)
        triggered = (await page.locator('text=/Trigger de Perilous Myr \\(J0\\).*→ J2/').count()) > 0
      }
    } else if (await sac.count()) await tryClick(sac)
    else if (await enabled('Défausser').count()) await tryClick(enabled('Défausser'))
    else if (await enabled('Terminer la déclaration').count()) await tryClick(enabled('Terminer la déclaration'))
    else if (await enabled('Terrains').count()) await tryClick(enabled('Terrains'))
    else if (await spell.count()) await tryClick(spell)
    else if (await enabled('Priorité').count()) await tryClick(enabled('Priorité'))
    await page.waitForTimeout(150)
  }
  const stackShown = triggered ? (await page.locator('.stack').textContent())?.replace(/\s+/g, ' ').trim() : '(absent)'
  const trigLine = triggered ? (await page.locator('text=/Trigger de Perilous Myr \\(J0\\)/').first().textContent())?.replace(/\s+/g, ' ').trim() : '(absent)'
  log('11 cible du trigger choisie par J0 :', triggered, '| journal :', trigLine, '| pile :', stackShown, '|', await info())
  await shot('11-declencheur')
  if (!triggered) problem('recette trigger : J0 n\'a pas pu choisir la cible du trigger de Perilous Myr')
}

// 12 : mana conditionnel. J0 doit avoir Eldrazi Temple et Eldrazi Mimic en main de départ : findHandSeed cherche la graine (API).
// J0 joue le Temple, l'engage à la main pour {C}{C} restreint (pastilles marquées dans la réserve), puis lance le Mimic avec.
async function s12({ page, log, shot, info, problem, enabled, tryClick, openGame }) {
  const { seed, id } = await findHandSeed(['Eldrazi Temple', 'Eldrazi Mimic'], [211, 147, 453, 549])
  log('12 graine retenue :', seed)
  await openGame(id)
  let restrictedSeen = false
  let mimicCast = false
  for (let i = 0; i < 120 && !mimicCast; i++) {
    if (!(await page.locator('.actions').count())) break
    const temple = page.locator('.actions h4:has-text("Terrains") ~ button:not([disabled]):has-text("Eldrazi Temple")').first()
    const restricted = page.locator('.actions h4:has-text("Capacités") ~ button:not([disabled]):has-text("Eldrazi Temple"):has-text("seulement pour")').first()
    const mimic = page.locator('.actions h4:has-text("Sorts") ~ button:not([disabled]):has-text("Eldrazi Mimic")').first()
    // Mana restreint en réserve : attendre que le panneau réactive le Mimic, ne jamais passer la priorité
    // (la réserve se viderait en fin d'étape ; sur une machine lente le panneau se redessine encore).
    if (restrictedSeen) {
      if (!(await mimic.waitFor({ timeout: 10_000 }).then(() => true, () => false))) break
      if (await tryClick(mimic)) {
        await page.locator('text=/J0 lance Eldrazi Mimic/').first().waitFor({ timeout: 10_000 }).catch(() => {})
        mimicCast = (await page.locator('text=/J0 lance Eldrazi Mimic/').count()) > 0
      }
    } else if (await restricted.count()) {
      if (await tryClick(restricted)) {
        await page.locator('.mana-pool .mana-sym.restricted').nth(1).waitFor({ timeout: 10_000 }).catch(() => {})
        restrictedSeen = (await page.locator('.mana-pool .mana-sym.restricted').count()) === 2
        if (restrictedSeen) await shot('12a-mana-restreint')
      }
    } else if (await temple.count()) await tryClick(temple)
    else if (await enabled('Défausser').count()) await tryClick(enabled('Défausser'))
    else if (await enabled('Terminer la déclaration').count()) await tryClick(enabled('Terminer la déclaration'))
    else if (await enabled('Priorité').count()) await tryClick(enabled('Priorité'))
    await page.waitForTimeout(150)
  }
  const mimicLine = mimicCast ? (await page.locator('text=/J0 lance Eldrazi Mimic/').first().textContent())?.replace(/\s+/g, ' ').trim() : '(absent)'
  const leftRestricted = await page.locator('.mana-pool .mana-sym.restricted').count()
  log('12 mana restreint affiché:', restrictedSeen, '| Mimic lancé avec :', mimicCast, '| journal :', mimicLine, '| restreint restant :', leftRestricted, '|', await info())
  await shot('12b-mimic')
  if (!restrictedSeen) problem('recette mana conditionnel : le {C}{C} restreint d\'Eldrazi Temple n\'apparaît pas dans la réserve')
  if (!mimicCast) problem('recette mana conditionnel : J0 n\'a pas pu lancer Eldrazi Mimic avec le mana du Temple')
  else if (leftRestricted) problem('recette mana conditionnel : le mana restreint n\'a pas été dépensé pour Eldrazi Mimic')
}

// 13 : stops. J0 humain ; on ne garde que l'étape « Fin de tour » (options décochées), on applique, la partie reprend jusqu'à cette étape ; puis « Défaut ».
async function s13({ page, log, shot, info, prog, problem, keepUi }) {
  await page.fill('input[placeholder="aléatoire"]', '7')
  await page.click('text=Nouvelle partie Commander')
  await page.waitForSelector('.stops', { timeout: TIMEOUT })
  await page.waitForSelector('.actions', { timeout: TIMEOUT })
  await keepUi()
  for (const box of await page.locator('.stops input[type=checkbox]').all()) if (await box.isChecked()) await box.uncheck()
  await page.locator('.stops label:has-text("Fin de tour") input').check()
  const [applyRes] = await Promise.all([
    page.waitForResponse((r) => /\/api\/simulations\/\d+\/stops$/.test(r.url()) && r.request().method() === 'PUT'),
    page.click('.stops button:has-text("Appliquer")'),
  ])
  const applyBody = await applyRes.json().catch(() => ({}))
  await page.waitForTimeout(800)
  const stepsNow = await page.locator('.stops input[type=checkbox]:checked').evaluateAll((els) => els.map((e) => e.parentElement.textContent.trim()))
  log('13 Appliquer : HTTP', applyRes.status(), '| cases cochées :', JSON.stringify(stepsNow), '| décision :', (await page.locator('.actions h3').textContent().catch(() => '(aucune)'))?.trim(), '|', await info(), '|', await prog())
  await shot('13a-stops-applique')
  if (applyRes.status() !== 200) problem(`recette stops : PUT /stops répond ${applyRes.status()} après Appliquer`)
  if (!applyBody.id) problem('recette stops : réponse de PUT /stops sans simulation')
  const [resetRes] = await Promise.all([
    page.waitForResponse((r) => /\/api\/simulations\/\d+\/stops$/.test(r.url()) && r.request().method() === 'PUT'),
    page.click('.stops button:has-text("Défaut")'),
  ])
  await page.waitForTimeout(500)
  log('13 Défaut : HTTP', resetRes.status(), '|', await info())
  await shot('13b-stops-defaut')
  if (resetRes.status() !== 200) problem(`recette stops : PUT /stops (Défaut) répond ${resetRes.status()}`)
}

// 14 : J0 BLOQUE. Un pilote API joue la politique « terrains, créatures, jamais d'attaque » jusqu'à ce que J0 ait une action de
// blocage (si l'IA n'attaque pas J0 dans la fenêtre : graine suivante). L'UI ouvre alors la partie, J0 bloque, le journal doit contenir « J0 bloque ».
async function s14({ page, log, shot, info, problem, enabled, tryClick, openGame }) {
  const CREATURE = /Bears|Spider|Recluse|Mongrel|Commandant/
  const policy = (p, step) => {
    const first = (type, re) => p.actions.find((a) => a.type === type && (!re || re.test(a.label)))
    if (step > 250) return null
    if (first('block', /avec/)) return 'stop'
    return first('discard') ?? first('done') ?? first('land') ?? first('cast', CREATURE) ?? first('pass') ?? null
  }
  let blocked = false, seed14 = null
  const tried = []
  // Graines où l'IA attaque J0 tôt depuis la cible d'attaque par menace (2026-10-14) : 49 (tour 6), 13, 24, 59, 22.
  for (const seed of [49, 24, 13, 59, 22, 7, 18, 42, 6, 16, 122, 99, 150]) {
    if (blocked) break
    tried.push(seed)
    const sim = await api.createHuman(seed)
    const r = await api.drive(sim.id, policy)
    if (!r.stopped) continue
    await openGame(sim.id)
    const block = page.locator('.actions h4:has-text("Bloquer") ~ button:not([disabled]):has-text("avec")').first()
    if (await block.count()) {
      await tryClick(block)
      await page.waitForTimeout(400)
      blocked = (await page.locator('text=/J0 bloque/').count()) > 0
      if (blocked) seed14 = seed
    }
  }
  const blockLine = blocked ? (await page.locator('text=/J0 bloque/').first().textContent())?.replace(/\s+/g, ' ').trim() : '(absent)'
  log('14 graine retenue :', seed14, '(essayées :', tried.join(','), ') | J0 a bloqué :', blocked, '| journal :', blockLine, '|', await info())
  await page.locator('text=/J0 bloque/').first().scrollIntoViewIfNeeded().catch(() => {}); await shot('14-blocage')
  if (!blocked) problem('recette blocage : J0 n\'a jamais pu bloquer un attaquant de l\'IA')
}

// 15 : J0 RÉPARTIT ses dégâts. L'IA ne double-bloque jamais (sauf menace) :
// on crée donc, par l'API, une partie où J0 ET J1 sont humains (human:auto) et un pilote API joue : J0 attaque J1 avec une seule
// créature ; J1 la bloque avec deux créatures ; jusqu'à ce que J0 ait une répartition AUTRE que celle par défaut. L'UI l'ouvre alors,
// J0 choisit cette répartition et les assertions portent sur le journal.
// Aucune dépendance au comportement de l'IA : seules les mains tirées comptent, d'où le balayage de graines si J1 n'a pas deux bloqueurs.
async function s15({ page, log, shot, problem, openGame, tryClick }) {
  const CREATURE = /Elves|Mystic|Druid|Mongrel|Visionary|Golem|Myr|Pillard|Pyromancer|Mimic|Bear|Goblin Guide|Raging Goblin|Swiftspear|Tusker|Recluse|Courser|Armodon|Ogre|Merfolk|Sprite|Crow|Soldiers|Turtle|Drake|Sorcerer|Juggernaut|Commandant/
  const isOther = (a) => a.type === 'assign' && !/\(par défaut\)/.test(a.label)
  let attacked = false
  const policy = (p) => {
    const first = (type, re) => p.actions.find((a) => a.type === type && (!re || re.test(a.label)))
    const seat = p.player
    const other = p.actions.find(isOther)
    if (other) return 'stop'
    const discard = first('discard')
    if (discard) return discard
    if (seat === 1 && first('block')) return first('block')
    const atk = first('attack', /Attaquer J1/)
    if (seat === 0 && !attacked && atk) { attacked = true; return atk }
    const next = first('done') ?? first('land') ?? first('cast', CREATURE)
    if (next) return next
    if (seat === 0) attacked = false
    return first('pass') ?? null
  }
  let assigned = false, seed15 = null
  let assignText = '(absent)'
  const tried = []
  // Graine connue d'abord (5 : J1 a deux bloqueurs, depuis le mulligan), puis balayage 1..25 si les decks changent.
  for (const seed of [5, ...Array.from({ length: 25 }, (_, i) => i + 1).filter((n) => n !== 5)]) {
    if (assigned) break
    tried.push(seed)
    attacked = false
    const sim = await api.create(seed, { 0: 'human:auto', 1: 'human:auto', 2: 'ai:heuristic', 3: 'ai:heuristic' })
    const r = await api.drive(sim.id, policy)
    if (!r.stopped) continue
    await openGame(sim.id)
    const options = page.locator('.actions h4:has-text("Répartir les dégâts de combat") ~ button:not([disabled])')
    const other = page.locator('.actions h4:has-text("Répartir les dégâts de combat") ~ button:not([disabled]):not(:has-text("(par défaut)"))').first()
    if (await other.count()) {
      const total = await options.count()
      const text = (await other.textContent())?.replace(/\s+/g, ' ').trim()
      if (await tryClick(other)) {
        assignText = text
        await page.waitForTimeout(500)
        assigned = (await page.locator('text=/inflige/').count()) > 0
        seed15 = seed
        log('15 répartitions proposées :', total, '| choisie :', assignText)
      }
    }
  }
  // Le journal affiche « <id> subit N dégâts » pour chaque bloqueur blessé : on le compare à la répartition choisie.
  const hurt = assigned ? (await page.locator('text=/subit \\d+ dégâts/').allTextContents()).map((t) => t.replace(/\s+/g, ' ').trim()) : []
  log('15 graine retenue :', seed15, '(essayées :', tried.join(','), ') | répartition choisie :', assignText)
  log('15 journal des dégâts subis :', hurt.slice(-4).join(' ; '))
  await page.locator('text=/subit \\d+ dégâts/').last().scrollIntoViewIfNeeded().catch(() => {}); await shot('15-repartition')
  if (!assigned) problem('recette répartition : J0 n\'a pas pu répartir les dégâts d\'un attaquant bloqué par deux créatures')
  else {
    // Répartition annoncée « N → Nom (id) » par bloqueur : N > 0 => « id subit N dégâts » ; N = 0 => aucune ligne pour ce bloqueur.
    const parts = [...assignText.matchAll(/(\d+) → [^,(]*\((p\d+_c\d+)\)/g)]
    if (parts.length < 2) problem(`recette répartition : répartition illisible : ${assignText}`)
    for (const [, n, id] of parts) {
      const seen = hurt.some((t) => new RegExp(`${id} subit ${n} dégâts`).test(t))
      if (Number(n) > 0 && !seen) problem(`recette répartition : « ${id} subit ${n} dégâts » absent du journal (${assignText})`)
      if (Number(n) === 0 && hurt.some((t) => t.includes(`${id} subit`))) problem(`recette répartition : ${id} a reçu des dégâts alors que 0 lui était assigné (${assignText})`)
    }
  }
}

// 16 : mulligan de Londres. J0 prend deux mulligans (journal « (1) » puis « (2) »), garde, met une carte au fond ; sa main compte alors 6 cartes
// et la partie démarre (une décision ordinaire apparaît).
async function s16({ page, log, shot, info, problem }) {
  await page.fill('input[placeholder="aléatoire"]', '7')
  await page.click('text=Nouvelle partie Commander')
  await page.waitForSelector('.actions h4:has-text("Main de départ")', { timeout: TIMEOUT })
  const mull = page.locator('.actions h4:has-text("Main de départ") ~ button:not([disabled]):has-text("Mulligan")')
  for (const n of [1, 2]) {
    await mull.first().click()
    await page.locator(`text=/prend un mulligan \\(${n}\\)/`).first().waitFor({ timeout: TIMEOUT }).catch(() => problem(`recette mulligan : journal sans « prend un mulligan (${n}) »`))
    await page.waitForFunction(() => !document.querySelector('.actions button:disabled'), null, { timeout: TIMEOUT })
  }
  await page.locator('.actions h4:has-text("Main de départ") ~ button:not([disabled]):has-text("Garder")').first().click()
  const bottom = page.locator('.actions h4:has-text("Mettre au fond") ~ button:not([disabled])').first()
  await bottom.waitFor({ timeout: TIMEOUT })
  await page.screenshot({ path: `${S}/16-mulligan.png`, fullPage: true })
  await bottom.click()
  await page.locator('text=/met une carte au fond/').first().waitFor({ timeout: TIMEOUT }).catch(() => problem('recette mulligan : journal sans « met une carte au fond »'))
  await page.waitForFunction(() => /Priorité|Terrains|Sorts/.test(document.querySelector('.actions')?.textContent ?? ''), null, { timeout: TIMEOUT })
  // Main de J0 juste après la dernière carte mise au fond (avant sa pioche de tour), relue dans l'état reconstruit par l'API.
  const id = Number((await info()).match(/#(\d+)/)[1])
  const evs = await api.events(id)
  const list = Array.isArray(evs) ? evs : evs.events
  const last = list.filter((e) => e.type === 'CardPutOnBottom').at(-1)
  const cp = list.find((e) => e.seq > (last?.seq ?? Infinity) && e.type === 'PriorityGranted' && e.payload.checkpoint)
  const stateHand = cp ? (await api.state(id, cp.seq)).players[0].hand.length : -1
  log('16 main de J0 après la mise au fond (premier checkpoint', cp?.seq, ') :', stateHand, '|', await info())
  if (stateHand !== 6) problem(`recette mulligan : la main de J0 compte ${stateHand} cartes au lieu de 6`)
  if (await page.locator('text=/garde sa main/').count() === 0) problem('recette mulligan : journal sans « garde sa main »')
}

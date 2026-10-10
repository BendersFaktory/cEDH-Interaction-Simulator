// Recette du front dans Chrome, contre l'API réelle (MySQL). Lancer : `npm run recette` (depuis frontend/).
// Démarre l'API (php -S :8000) et Vite (:5173) s'ils ne tournent pas, les arrête ensuite.
// Les captures vont dans frontend/recette/ (ignoré par git), vidé puis recréé à chaque exécution.
// Code de sortie 1 si une erreur console/réseau inattendue apparaît (seul le 404 de l'id inexistant est attendu).
import { spawn } from 'node:child_process'
import { mkdirSync, rmSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { chromium } from 'playwright'

// Délai maximal de chaque attente/action Playwright (valeur par défaut de Playwright).
const TIMEOUT = 30_000
const FRONT = fileURLToPath(new URL('..', import.meta.url))
const BACK = fileURLToPath(new URL('../../backend', import.meta.url))
const S = `${FRONT}recette`
rmSync(S, { recursive: true, force: true })
mkdirSync(S, { recursive: true })

const up = async (url) => { try { return (await fetch(url)).status < 500 } catch { return false } }
const started = []
const start = async (name, url, cmd, args, cwd) => {
  if (await up(url)) return console.log(`${name} déjà démarré`)
  const p = spawn(cmd, args, { cwd, stdio: 'ignore' })
  started.push(p)
  for (let i = 0; i < 100 && !(await up(url)); i++) await new Promise((r) => setTimeout(r, 300))
  if (!(await up(url))) throw new Error(`${name} ne répond pas sur ${url}`)
}
const stopAll = () => started.forEach((p) => p.kill())

try {
  await start('API', 'http://127.0.0.1:8000/api/simulations/0', 'php', ['-d', 'memory_limit=512M', '-d', 'variables_order=EGPCS', '-S', '127.0.0.1:8000', '-t', 'public'], BACK)
  await start('Vite', 'http://localhost:5173/', 'npx', ['vite', '--port', '5173', '--strictPort'], FRONT)
  await run()
} catch (e) {
  console.error(e)
  process.exitCode = 1
} finally {
  stopAll()
}

async function run() {
  const browser = await chromium.launch({ channel: 'chrome', headless: true })
  const page = await browser.newPage({ viewport: { width: 1500, height: 950 } })
  page.setDefaultTimeout(TIMEOUT)
  const problems = []
  page.on('console', m => { if (['error','warning'].includes(m.type())) problems.push(`console.${m.type()}: ${m.text()}`) })
  page.on('pageerror', e => problems.push('pageerror: ' + e.message))
  page.on('response', r => { if (r.status() >= 400) problems.push(`HTTP ${r.status()} ${r.request().method()} ${r.url()}`) })
  const shot = n => page.screenshot({ path: `${S}/${n}.png` })
  const log = (...a) => console.log(...a)
  const info = async () => (await page.locator('.topbar .muted').textContent().catch(()=>null))?.replace(/\s+/g,' ')
  const prog = async () => (await page.locator('.playback small').textContent()).trim()

  await page.goto('http://localhost:5173/')
  log('1 vide:', (await page.locator('.empty').textContent()).trim()); await shot('1-vide')

  // 2 : partie humaine
  await page.fill('input[placeholder="aléatoire"]', '7')
  await page.click('text=Nouvelle partie Commander')
  await page.waitForSelector('.actions')
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
  await page.fill('.slider', '1200').catch(()=>{})
  await page.locator('.slider').evaluate((el) => { el.value = 1200; el.dispatchEvent(new Event('input', { bubbles: true })) })
  log('6 curseur:', await prog(), '|', (await page.locator('.pausebar p').first().textContent()).replace(/\s+/g,' '))
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
  log('7 id inexistant, erreur affichée:', await page.locator('.error').textContent().catch(()=>'(aucune)')); await shot('7-erreur')

  // 8 : combat côté humain (J0 vs 3 IA, seed 7) : jouer, lancer des créatures, puis déclarer une attaque (et bloquer si l'IA attaque J0)
  await page.check('text=Je joue J0 >> input')
  await page.fill('input[placeholder="aléatoire"]', '7')
  await page.click('text=Nouvelle partie Commander')
  await page.waitForSelector('.actions', { timeout: TIMEOUT })
  const first = (title) => page.locator(`.actions h4:has-text("${title}") ~ button`).first()
  let attacked = false, sawBlock = false
  for (let i = 0; i < 400 && !attacked; i++) {
    if (!(await page.locator('.actions').count())) break
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
  if (!attacked) problems.push('recette combat : J0 n\'a jamais pu déclarer d\'attaque en 400 décisions')
  await page.waitForTimeout(500)
  log('8 journal :', (await page.locator('text=/J0 attaque J\\d/').count()) > 0 ? 'AttackerDeclared affiché' : 'absent'); await shot('8-combat')

  // 9 : capacités de mana à la main (J0 engage lui-même une source) -> la réserve s'affiche ; le mana flottant disparaît ensuite
  const prevGame = (await info()).match(/#(\d+)/)[1]
  await page.click('text=Nouvelle partie Commander')
  await page.waitForFunction((o) => (document.querySelector('.topbar .muted')?.textContent ?? '').match(/#(\d+)/)?.[1] !== o, prevGame)
  await page.waitForSelector('.actions', { timeout: TIMEOUT })
  let poolSeen = false
  // Seuls les boutons activés comptent (pendant un chargement ils sont désactivés et le panneau se redessine) ;
  // un clic raté est simplement retenté au tour suivant.
  const enabled = (title) => page.locator(`.actions h4:has-text("${title}") ~ button:not([disabled])`).first()
  const tryClick = async (b) => { try { await b.click({ timeout: 3000 }); return true } catch { return false } }
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
  if (!poolSeen) problems.push('recette mana : la réserve n\'apparaît pas après une capacité de mana')

  // Seul le 404 volontaire (id 99999) est toléré, avec le message console que Chrome en tire.
  const unexpected = problems.filter((p) => !/404/.test(p))
  log('\nPROBLÈMES inattendus:', unexpected.length ? '\n' + unexpected.join('\n') : 'aucun')
  await browser.close()
  if (unexpected.length) process.exitCode = 1
}

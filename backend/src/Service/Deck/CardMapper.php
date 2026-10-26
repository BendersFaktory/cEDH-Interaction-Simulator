<?php

declare(strict_types=1);

namespace App\Service\Deck;

use App\Engine\Ability\ActivatedAbility;
use App\Engine\Ability\TriggeredAbility;
use App\Engine\Card\CardDefinition;
use App\Engine\Card\SpellEffect;
use App\Engine\Game\CardInstance;
use App\Engine\Mana\ManaRestriction;

/**
 * Carte Scryfall -> `CardDefinition`. Pas d'interprétation libre du texte Oracle : seuls des motifs connus sont reconnus
 * (mots-clés du combat, mana `{T} : Add …`, Signets `{1}, {T}: Add …`, pioche activée (`[coût], [{T}], [Sacrifice ~]: Draw …`),
 * dégâts / contresort / rituel / pioche simples (un effet par ligne, plusieurs lignes = plusieurs effets), triggers « entre / meurt / entretien : piocher » et « meurt : N dégâts »). Tout texte restant
 * fait refuser la carte (`UnsupportedCard`) plutôt que de la jouer avec des effets silencieusement absents.
 */
final class CardMapper
{
    private const KEYWORDS = [
        'flying' => CardInstance::FLYING, 'reach' => CardInstance::REACH, 'haste' => CardInstance::HASTE,
        'vigilance' => CardInstance::VIGILANCE, 'defender' => CardInstance::DEFENDER, 'menace' => CardInstance::MENACE,
        'first strike' => CardInstance::FIRST_STRIKE, 'double strike' => CardInstance::DOUBLE_STRIKE,
        'trample' => CardInstance::TRAMPLE, 'deathtouch' => CardInstance::DEATHTOUCH, 'lifelink' => CardInstance::LIFELINK,
        'flash' => CardInstance::FLASH, 'partner' => CardInstance::PARTNER,
    ];
    private const TYPES = [
        'land' => CardInstance::LAND, 'instant' => CardInstance::INSTANT, 'sorcery' => CardInstance::SORCERY,
        'creature' => CardInstance::CREATURE, 'artifact' => CardInstance::ARTIFACT, 'enchantment' => CardInstance::ENCHANTMENT,
    ];
    private const BASIC_LAND_COLOR = ['Plains' => 'W', 'Island' => 'U', 'Swamp' => 'B', 'Mountain' => 'R', 'Forest' => 'G', 'Wastes' => 'C'];

    /**
     * @param array<string, mixed> $card objet carte Scryfall
     * @throws UnsupportedCard
     */
    public function map(array $card): CardDefinition
    {
        $name = (string) ($card['name'] ?? '');
        $fail = static fn (string $reason): UnsupportedCard => new UnsupportedCard($name, $reason);

        if (($card['layout'] ?? 'normal') === 'split') {
            throw $fail('carte scindée non modélisée (choix d\'une moitié au lancement)');
        }
        if (($card['layout'] ?? 'normal') !== 'normal') {
            throw $fail('carte à plusieurs faces ou mise en page spéciale (' . ($card['layout'] ?? '?') . ')');
        }

        [$types, $supertypes, $subtypeNames] = $this->parseTypeLine((string) ($card['type_line'] ?? ''), $fail);
        $type = count($types) === 1 ? $types[0] : $types;
        $isCreature = in_array(CardInstance::CREATURE, $types, true);
        $isLand = in_array(CardInstance::LAND, $types, true);
        $isSpell = in_array(CardInstance::INSTANT, $types, true) || in_array(CardInstance::SORCERY, $types, true);
        $subtypes = $subtypeNames === [] ? [] : [($isCreature ? CardInstance::CREATURE : $types[0]) => $subtypeNames];

        $manaCost = (string) ($card['mana_cost'] ?? '');
        if ($manaCost !== '' && !preg_match('/^(\{(?:\d+|X|[WUBRGC](?:\/[WUBRG])?|[WUBRG]\/P)\})+$/', $manaCost)) {
            throw $fail("coût de mana non modélisé ($manaCost)");
        }
        $power = $toughness = 0;
        if ($isCreature) {
            if (!ctype_digit((string) ($card['power'] ?? '')) || !ctype_digit((string) ($card['toughness'] ?? ''))) {
                throw $fail('force / endurance variable ou absente');
            }
            $power = (int) $card['power'];
            $toughness = (int) $card['toughness'];
        }

        $keywords = [];
        foreach ($card['keywords'] ?? [] as $keyword) {
            $key = strtolower((string) $keyword);
            if ($key === 'imprint' || $key === 'scry') {
                continue; // mot d'aptitude / action à mot-clé : le texte (« Imprint — … », « Scry N … ») est traité ligne par ligne
            }
            if (!isset(self::KEYWORDS[$key])) {
                throw $fail("mot-clé non modélisé ($keyword)");
            }
            $keywords[] = self::KEYWORDS[$key];
        }

        $effects = [];
        $untappedLifeCost = 0;
        $altCost = null; // coût alternatif de la carte (Force of Will, Fierce Guardianship)
        $produces = [];
        $abilities = [];
        $triggers = [];
        $noUntap = false; // « ~ doesn't untap during your untap step. » (Mana Vault, Grim Monolith)
        $upkeepUntap = null; // Mana Vault : « At the beginning of your upkeep, you may pay {N}. If you do, untap ~. »
        $tapPain = 0; // « Whenever ~ becomes tapped, it deals N damage to you. » (City of Brass)
        $hasX = str_contains($manaCost, '{X}');

        foreach ($this->oracleLines((string) ($card['oracle_text'] ?? ''), $name) as $line) {
            $line = $this->anyColor($line);
            if ($this->isKeywordLine($line)) {
                continue;
            }
            if ($isSpell && preg_match('/^~ deals (\d+|X) damage to any target\.$/', $line, $m)) {
                if ($m[1] === 'X' && !$hasX) {
                    throw $fail('dégâts X sans {X} dans le coût');
                }
                $effects[] = new SpellEffect('damage', $m[1] === 'X' ? 0 : (int) $m[1]);
            } elseif ($isSpell && $line === 'You may pay 1 life and exile a blue card from your hand rather than pay ~\'s mana cost.') {
                $altCost = CardInstance::ALT_PITCH_BLUE; // Force of Will
            } elseif ($isSpell && $line === 'If you control a commander, you may cast ~ without paying its mana cost.') {
                $altCost = CardInstance::ALT_COMMANDER; // Fierce Guardianship
            } elseif ($isSpell && $line === 'If it\'s not your turn, you may exile a blue card from your hand rather than pay ~\'s mana cost.') {
                $altCost = CardInstance::ALT_PITCH_BLUE_OFF_TURN; // Force of Negation
            } elseif ($isSpell && preg_match('/^You may return (an Island|two Islands) you control to (?:its|their) owner\'s hand rather than pay ~\'s mana cost\.$/', $line, $m)) {
                $altCost = CardInstance::ALT_RETURN_ISLANDS . ($m[1] === 'an Island' ? 1 : 2); // Daze, Gush
            } elseif ($isSpell && $line === 'Counter target noncreature spell.') {
                $effects[] = new SpellEffect('counter', 0, null, 'noncreature');
            } elseif ($isSpell && preg_match('/^Counter target (noncreature )?spell unless its controller pays ((?:\{\d+\})+)\.$/', $line, $m)) {
                $effects[] = new SpellEffect('counter', 0, null, $m[1] !== '' ? 'noncreature' : null, payCost: $m[2]); // Daze, Mana Leak, Spell Pierce
            } elseif ($isSpell && preg_match('/^Counter target (noncreature )?spell\. If that spell is countered this way, exile it instead of putting it into its owner\'s graveyard\.$/', $line, $m)) {
                $effects[] = new SpellEffect('counter', 0, null, $m[1] !== '' ? 'noncreature' : null, counterTo: SpellEffect::COUNTER_EXILE); // Force of Negation
            } elseif ($isSpell && $line === 'Counter target spell. If that spell is countered this way, put it on top of its owner\'s library instead of into that player\'s graveyard.') {
                $effects[] = new SpellEffect('counter', counterTo: SpellEffect::COUNTER_LIBRARY_TOP); // Memory Lapse
            } elseif ($isSpell && preg_match('/^Counter target spell with mana value (\d+)\.$/', $line, $m)) {
                $effects[] = new SpellEffect('counter', manaValue: (int) $m[1]); // Mental Misstep
            } elseif ($isSpell && $line === 'Counter target spell. At the beginning of your next main phase, add an amount of {C} equal to that spell\'s mana value.') {
                $effects[] = new SpellEffect('counter', drainMana: true); // Mana Drain
            } elseif ($isSpell && preg_match('/^At the beginning of your next upkeep, pay ((?:\{(?:\d+|[WUBRGC])\})+)\. If you don\'t, you lose the game\.$/', $line, $m)) {
                $effects[] = new SpellEffect('pact', payCost: $m[1]); // Pact of Negation
            } elseif ($isSpell && preg_match('/^Scry (\d+)\.$/', $line, $m)) {
                $effects[] = new SpellEffect('scry', (int) $m[1]);
            } elseif ($isSpell && preg_match('/^Scry (\d+), then draw a card\.$/', $line, $m)) {
                $effects[] = new SpellEffect('scry', (int) $m[1]); // Preordain : le rangement précède la pioche
                $effects[] = new SpellEffect('draw', 1);
            } elseif ($isSpell && preg_match('/^Draw (a card|one card|two cards|three cards|four cards), then put (one|two|three) cards? from your hand on top of your library in any order\.$/', $line, $m)) {
                $effects[] = new SpellEffect('draw', self::DRAW_COUNT[$m[1]]); // Brainstorm
                $effects[] = new SpellEffect('put_back', self::NUMBER[$m[2]]);
            } elseif ($isSpell && preg_match('/^Look at the top (two|three|four) cards of your library, then put them back in any order\. You may shuffle\.$/', $line, $m)) {
                $effects[] = new SpellEffect('arrange', self::NUMBER[$m[1]]); // Ponder (la pioche est sur la ligne suivante)
            } elseif ($isSpell && $line === 'Search your library for a card, put that card into your hand, then shuffle.') {
                $effects[] = new SpellEffect('tutor'); // Demonic Tutor
            } elseif ($isSpell && preg_match('/^Search your library for (?:a card|an? (creature|artifact or enchantment|instant or sorcery) card)(?:, reveal it)?, then shuffle and put (?:that card|the card) on top\.( You lose 2 life\.)?$/', $line, $m)) {
                // Vampiric / Imperial Seal (toute carte, 2 PV), Mystical / Worldly / Enlightened Tutor (par type)
                $filter = ['creature' => 'creature', 'artifact or enchantment' => 'artifact|enchantment', 'instant or sorcery' => 'instant|sorcery'][$m[1] ?? ''] ?? null;
                $effects[] = new SpellEffect('tutor', tutorTop: true, tutorFilter: $filter, lifeLoss: ($m[2] ?? '') !== '' ? 2 : 0);
            } elseif ($isSpell && $line === 'Create a Treasure token.') {
                $effects[] = new SpellEffect('treasure');
            } elseif ($isSpell && $line === 'Counter target spell.') {
                $effects[] = new SpellEffect('counter');
            } elseif ($isSpell && $line === 'Counter target instant spell.') {
                $effects[] = new SpellEffect('counter', 0, null, CardInstance::INSTANT); // Dispel
            } elseif ($isSpell && ($line === 'Counter target enchantment, instant, or sorcery spell. Its controller creates a 2/2 blue Bird creature token with flying.'
                || $line === 'Counter target enchantment, instant, or sorcery spell. If that spell is countered this way, its controller creates a 2/2 blue Bird creature token with flying.')) {
                $effects[] = new SpellEffect('counter', 0, null, implode('|', [CardInstance::ENCHANTMENT, CardInstance::INSTANT, CardInstance::SORCERY]), false, 'Bird:2/2', [CardInstance::FLYING]); // Swan Song
            } elseif ($isSpell && $line === 'Exile target creature. Its controller gains life equal to its power.') {
                $effects[] = new SpellEffect('exile', 0, null, 'creature', true); // Swords to Plowshares
            } elseif (!$isSpell && !$isLand && $line === "~ doesn't untap during your untap step.") {
                $noUntap = true;
            } elseif (!$isSpell && !$isLand && preg_match('/^At the beginning of your upkeep, you may pay ((?:\{\d+\})+)\. If you do, untap ~\.$/', $line, $m)) {
                $upkeepUntap = $m[1]; // le paiement à l'entretien est modélisé par une capacité `{N}: dégager`, activable à l'entretien seulement
            } elseif (!$isSpell && !$isLand && preg_match('/^(\{\d+\}): Untap ~\.$/', $line, $m)) {
                $abilities[] = ActivatedAbility::untap($m[1]); // Grim Monolith
            } elseif (!$isSpell && !$isLand && preg_match('/^At the beginning of your draw step, if ~ is tapped, it deals (\d+) damage to you\.$/', $line, $m)) {
                $triggers[] = new TriggeredAbility(TriggeredAbility::DRAW_STEP, 'self_damage', (int) $m[1], filter: TriggeredAbility::TAPPED); // Mana Vault
            } elseif (!$isSpell && !$isLand && preg_match('/^Imprint — When ~ enters, you may exile a nonartifact, nonland card from your hand\.$/u', $line)) {
                $triggers[] = new TriggeredAbility(TriggeredAbility::ETB, 'imprint'); // Chrome Mox
            } elseif (!$isSpell && $line === '{T}: Add one mana of any of the exiled card\'s colors.') {
                $abilities[] = ActivatedAbility::dynamicMana(ActivatedAbility::DYNAMIC_IMPRINT);
            } elseif (!$isSpell && $line === '{T}: Add one mana of any color that a land an opponent controls could produce.') {
                $abilities[] = ActivatedAbility::dynamicMana(ActivatedAbility::DYNAMIC_OPPONENT_LAND); // Fellwar Stone, Exotic Orchard
            } elseif ($isLand && preg_match('/^Whenever ~ becomes tapped, it deals (\d+) damage to you\.$/', $line, $m)) {
                $tapPain = (int) $m[1]; // City of Brass : équivaut à des dégâts à soi sur sa capacité de mana (elle seule l'engage)
            } elseif ($isSpell && preg_match('/^Add ((?:\{[WUBRG]\})+)\.$/', $line, $m)) {
                $effects[] = new SpellEffect('mana', 0, $m[1]);
            } elseif ($isSpell && preg_match('/^Draw (a card|one card|two cards|three cards|four cards)\.$/', $line, $m)) {
                $effects[] = new SpellEffect('draw', self::DRAW_COUNT[$m[1]]);
            } elseif (!$isSpell && preg_match('/^\{T\}: Add ((?:\{[WUBRGC]\})+(?: or (?:\{[WUBRGC]\})+)*)\. ~ deals (\d+) damage to you\.$/', $line, $m)) {
                $abilities[] = ActivatedAbility::mana(implode('|', explode(' or ', $m[1])), null, (int) $m[2]); // Ancient Tomb, Talismans, pain lands
            } elseif ($isLand && preg_match("/^As ~ enters, you may pay (\\d+) life\\. If you don't, it enters tapped\\.$/", $line, $m)) {
                $untappedLifeCost = (int) $m[1]; // shockland
            } elseif (!$isSpell && preg_match('/^\{T\}: Add (\{[WUBRGC]\})\. Spend this mana only to cast a legendary spell\.$/', $line, $m)) {
                $abilities[] = ActivatedAbility::mana($m[1] . '@' . ManaRestriction::LEGENDARY); // Delighted Halfling
            } elseif (!$isSpell && preg_match('/^\{T\}: Add ((?:\{[WUBRGC]\})+(?: or (?:\{[WUBRGC]\})+)*)\.$/', $line, $m)) {
                $options = explode(' or ', $m[1]);
                if ($isLand && count(array_filter($options, static fn (string $o) => strlen($o) > 3)) === 0) {
                    $produces = array_map(static fn (string $o) => trim($o, '{}'), $options); // un mana par alternative
                } else {
                    $abilities[] = ActivatedAbility::mana(implode('|', $options));
                }
            } elseif (!$isSpell && ($ability = $this->activated($line)) !== null) {
                $abilities[] = $ability;
            } elseif (!$isSpell && !$isLand && ($trigger = $this->trigger($line, $isCreature)) !== null) {
                $triggers[] = $trigger;
            } else {
                throw $fail('texte non modélisé : « ' . $line . ' »');
            }
        }

        if ($isSpell && $effects === []) {
            throw $fail('sort sans effet modélisé');
        }
        if ($upkeepUntap !== null) {
            $abilities[] = ActivatedAbility::untap($upkeepUntap, upkeepOnly: true);
        }
        if ($tapPain > 0) {
            // Le seul moyen d'engager le terrain est sa capacité de mana : le trigger devient un `selfDamage` (sans pile, CR 605.3b
            // le laisse en réalité sur la pile, mais rien ne peut y répondre entre l'engagement et les dégâts ici).
            if ($produces === [] || $abilities !== []) {
                throw $fail('trigger « devient engagé » sans production de mana simple');
            }
            $abilities[] = ActivatedAbility::mana(implode('|', array_map(static fn (string $c): string => '{' . $c . '}', $produces)), null, $tapPain);
            $produces = [];
        }
        if ($isLand && $produces === [] && $abilities === []) {
            // Types de terrain de base (CR 305.6) : `{T}: Add` implicite par type, basique ou non (Underground Sea : Island Swamp).
            $basic = array_values(array_intersect(array_keys(self::BASIC_LAND_COLOR), $subtypeNames));
            if ($basic === []) {
                throw $fail('terrain sans production de mana modélisée');
            }
            $produces = array_map(static fn (string $t): string => self::BASIC_LAND_COLOR[$t], $basic);
        }

        return new CardDefinition(
            $name, $type, (int) ($card['cmc'] ?? 0), $manaCost === '' ? null : $manaCost, $power, $toughness,
            null, 0, null, $produces, $abilities, $triggers, $subtypes, array_values(array_unique($keywords)),
            in_array('Legendary', $supertypes, true) ? [CardInstance::LEGENDARY] : [], $this->effectList($effects, $fail), $untappedLifeCost, $altCost, $noUntap,
        );
    }

    /** @return array{0: list<string>, 1: list<string>, 2: list<string>} types, supertypes, sous-types */
    private function parseTypeLine(string $typeLine, callable $fail): array
    {
        [$left, $right] = array_pad(explode('—', $typeLine, 2), 2, '');
        $types = [];
        $supertypes = [];
        foreach (preg_split('/\s+/', trim($left), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            if (in_array($word, ['Legendary', 'Basic', 'Snow'], true)) {
                $supertypes[] = $word;
            } elseif (isset(self::TYPES[strtolower($word)])) {
                $types[] = self::TYPES[strtolower($word)];
            } else {
                throw $fail("type non modélisé ($word)");
            }
        }
        if ($types === []) {
            throw $fail('type de carte absent');
        }

        return [$types, $supertypes, preg_split('/\s+/', trim($right), -1, PREG_SPLIT_NO_EMPTY) ?: []];
    }

    /**
     * Lignes du texte Oracle sans texte de rappel, le nom de la carte remplacé par `~`
     * (formulations « enters the battlefield » et « this creature » normalisées).
     *
     * @return list<string>
     */
    private function oracleLines(string $text, string $name): array
    {
        $text = (string) preg_replace('/\([^)]*\)/', '', $text);
        $text = str_replace($name, '~', $text);
        $text = (string) preg_replace('/\bthis (creature|artifact|enchantment|permanent|land|spell)\b/i', '~', $text);
        $text = str_replace('enters the battlefield', 'enters', $text);

        $lines = [];
        foreach (explode("\n", $text) as $line) {
            $line = trim((string) preg_replace('/\s+/', ' ', $line));
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    private const DRAW_COUNT = ['a card' => 1, 'one card' => 1, 'two cards' => 2, 'three cards' => 3, 'four cards' => 4];
    private const NUMBER = ['one' => 1, 'two' => 2, 'three' => 3, 'four' => 4];

    /**
     * Capacité activée à coût (hors `{T}: Add …` seul, traité avant) : coûts séparés par des virgules parmi mana générique /
     * coloré, `{T}` et `Sacrifice ~` ; effets « Draw … » ou « Add … » (Signet : mana + `{T}`, sans sacrifice). Sinon `null`.
     */
    private function activated(string $line): ?ActivatedAbility
    {
        if (!preg_match('/^((?:\{[^}]+\}|Sacrifice ~|Pay \d+ life)(?:, (?:\{[^}]+\}|Sacrifice ~|Pay \d+ life))*): (.+)$/', $line, $m)) {
            return null;
        }
        $tap = false;
        $sacrifice = false;
        $life = 0;
        $cost = '';
        foreach (explode(', ', $m[1]) as $part) {
            if ($part === '{T}') {
                $tap = true;
            } elseif ($part === 'Sacrifice ~') {
                $sacrifice = true;
            } elseif (preg_match('/^Pay (\d+) life$/', $part, $l)) {
                $life = (int) $l[1];
            } elseif (preg_match('/^(\{(?:\d+|[WUBRGC])\})+$/', $part)) {
                $cost .= $part;
            } else {
                return null;
            }
        }
        $cost = $cost === '' ? null : $cost;
        if ($tap && $sacrifice && $cost === null
            && preg_match('/^Search your library for (?:a (basic) land card|an? ([A-Z][a-z]+) or ([A-Z][a-z]+) card), put (?:it|that card) onto the battlefield( tapped)?, then shuffle\.$/', $m[2], $f)) {
            // Fetchland (Polluted Delta, Prismatic Vista) ou Evolving Wilds (terrain de base, engagé).
            return ActivatedAbility::fetch($f[1] === 'basic' ? 'basic' : $f[2] . '|' . $f[3], ($f[4] ?? '') !== '', $life);
        }
        if ($life > 0 && !($tap && !$sacrifice && $cost === null && str_starts_with($m[2], 'Add '))) {
            return null; // PV payés : seulement pour une fetchland ou `{T}, Pay N life: Add …` (Mana Confluence)
        }
        if (preg_match('/^Draw (a card|one card|two cards|three cards|four cards)\.$/', $m[2], $d)) {
            return ActivatedAbility::draw(self::DRAW_COUNT[$d[1]], $cost, $tap, $sacrifice);
        }
        if ($tap && $sacrifice && $cost === null && $m[2] === 'Add ' . self::ANY_COLOR . '.') {
            return ActivatedAbility::treasure(); // Lotus Petal : {T}, sacrifier : un mana de la couleur de son choix
        }
        if ($tap && !$sacrifice && ($cost !== null || $life > 0) && preg_match('/^Add ((?:\{[WUBRGC]\})+(?: or (?:\{[WUBRGC]\})+)*)\.$/', $m[2], $a)) {
            return ActivatedAbility::mana(implode('|', explode(' or ', $a[1])), $cost, 0, $life); // Signet ; Mana Confluence
        }

        return null;
    }

    /**
     * @param list<SpellEffect> $effects
     * @return list<SpellEffect>
     * @throws UnsupportedCard plus d'un effet ciblé (le moteur les gère, mais aucun motif multi-cibles n'est mappé)
     */
    private function effectList(array $effects, callable $fail): array
    {
        if (count(array_filter($effects, static fn (SpellEffect $e) => $e->isTargeted())) > 1) {
            throw $fail('plusieurs effets ciblés (motif multi-cibles non mappé)');
        }

        return $effects;
    }

    private const ANY_COLOR = '{W} or {U} or {B} or {R} or {G}';

    /**
     * « one mana of any color » → les cinq couleurs en alternatives. « … in your commander's color identity » (Command Tower,
     * Arcane Signet) est traité pareil : `Decks::validate()` garantit que toutes les cartes du deck sont dans l'identité, et
     * aucune carte ne permet de lancer celles d'un adversaire, donc une couleur hors identité ne paie jamais rien de plus.
     */
    private function anyColor(string $line): string
    {
        return (string) preg_replace("/\\bone mana of any color(?: in your commander's color identity)?(?=\\.)/", self::ANY_COLOR, $line);
    }

    /** Triggers reconnus : entrée / mort / entretien → piocher ; mort → N dégâts à n'importe quelle cible. Sinon `null`. */
    private function trigger(string $line, bool $isCreature): ?TriggeredAbility
    {
        $draw = '(?:, )?draw (a card|one card|two cards|three cards|four cards)\.$/';
        if (preg_match('/^When ~ enters' . $draw, $line, $m)) {
            return new TriggeredAbility(TriggeredAbility::ETB, 'draw', self::DRAW_COUNT[$m[1]]);
        }
        if (preg_match('/^At the beginning of your upkeep' . $draw, $line, $m)) {
            return new TriggeredAbility(TriggeredAbility::UPKEEP, 'draw', self::DRAW_COUNT[$m[1]]);
        }
        if (preg_match('/^When ~ enters, create (a|one|two|three) Treasure tokens?\.$/', $line, $m)) {
            return new TriggeredAbility(TriggeredAbility::ETB, 'treasure', ['a' => 1, 'one' => 1, 'two' => 2, 'three' => 3][$m[1]]);
        }
        if (preg_match('/^Whenever an opponent casts a spell, you may draw a card unless that player pays ((?:\{\d+\})+)\.$/', $line, $m)) {
            return new TriggeredAbility(TriggeredAbility::OPPONENT_CAST, 'draw_unless', 1, $m[1]); // Rhystic Study (« you may » : toujours)
        }
        if ($line === 'Whenever an opponent casts their second spell each turn, draw a card.') {
            return new TriggeredAbility(TriggeredAbility::OPPONENT_CAST, 'draw', 1, filter: TriggeredAbility::SECOND_SPELL); // Kraum
        }
        if ($line === 'At the beginning of each of your postcombat main phases, you may pay X life, where X is the number of opponents that were dealt combat damage this turn. If you do, draw X cards.') {
            return new TriggeredAbility(TriggeredAbility::POSTCOMBAT_MAIN, 'pay_life_draw'); // Tymna the Weaver
        }
        if ($isCreature && preg_match('/^When ~ dies' . $draw, $line, $m)) {
            return new TriggeredAbility(TriggeredAbility::DIES, 'draw', self::DRAW_COUNT[$m[1]]);
        }
        if ($isCreature && preg_match('/^When ~ dies, (?:it|~) deals (\d+) damage to any target\.$/', $line, $m)) {
            return new TriggeredAbility(TriggeredAbility::DIES, 'damage', (int) $m[1]);
        }

        return null;
    }

    /** « Flying, haste » : ligne composée uniquement de mots-clés connus (déjà pris en compte via `keywords`). */
    private function isKeywordLine(string $line): bool
    {
        foreach (preg_split('/\s*[,;]\s*/', rtrim($line, '.')) ?: [] as $part) {
            if (!isset(self::KEYWORDS[strtolower($part)])) {
                return false;
            }
        }

        return true;
    }
}

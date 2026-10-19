<?php

declare(strict_types=1);

namespace App\Service\Deck;

use App\Engine\Ability\ActivatedAbility;
use App\Engine\Ability\TriggeredAbility;
use App\Engine\Card\CardDefinition;
use App\Engine\Game\CardInstance;

/**
 * Carte Scryfall -> `CardDefinition`. Pas d'interprétation libre du texte Oracle : seuls des motifs connus sont reconnus
 * (mots-clés du combat, mana `{T} : Add …`, dégâts / contresort / rituel simples, « entre : piocher »). Tout texte restant
 * fait refuser la carte (`UnsupportedCard`) plutôt que de la jouer avec des effets silencieusement absents.
 */
final class CardMapper
{
    private const KEYWORDS = [
        'flying' => CardInstance::FLYING, 'reach' => CardInstance::REACH, 'haste' => CardInstance::HASTE,
        'vigilance' => CardInstance::VIGILANCE, 'defender' => CardInstance::DEFENDER, 'menace' => CardInstance::MENACE,
        'first strike' => CardInstance::FIRST_STRIKE, 'double strike' => CardInstance::DOUBLE_STRIKE,
        'trample' => CardInstance::TRAMPLE, 'deathtouch' => CardInstance::DEATHTOUCH, 'lifelink' => CardInstance::LIFELINK,
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
        if ($manaCost !== '' && !preg_match('/^(\{(?:\d+|X|[WUBRGC](?:\/[WUBRG])?)\})+$/', $manaCost)) {
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
            if (!isset(self::KEYWORDS[$key])) {
                throw $fail("mot-clé non modélisé ($keyword)");
            }
            $keywords[] = self::KEYWORDS[$key];
        }

        $effect = null;
        $amount = 0;
        $adds = null;
        $produces = [];
        $abilities = [];
        $triggers = [];
        $hasX = str_contains($manaCost, '{X}');

        foreach ($this->oracleLines((string) ($card['oracle_text'] ?? ''), $name) as $line) {
            if ($this->isKeywordLine($line)) {
                continue;
            }
            if ($isSpell && preg_match('/^~ deals (\d+|X) damage to any target\.$/', $line, $m)) {
                if ($m[1] === 'X' && !$hasX) {
                    throw $fail('dégâts X sans {X} dans le coût');
                }
                [$effect, $amount] = ['damage', $m[1] === 'X' ? 0 : (int) $m[1]];
            } elseif ($isSpell && $line === 'Counter target spell.') {
                $effect = 'counter';
            } elseif ($isSpell && preg_match('/^Add ((?:\{[WUBRG]\})+)\.$/', $line, $m)) {
                [$effect, $adds] = ['mana', $m[1]];
            } elseif (!$isSpell && preg_match('/^\{T\}: Add ((?:\{[WUBRGC]\})+(?: or (?:\{[WUBRGC]\})+)*)\.$/', $line, $m)) {
                $options = explode(' or ', $m[1]);
                if ($isLand && count(array_filter($options, static fn (string $o) => strlen($o) > 3)) === 0) {
                    $produces = array_map(static fn (string $o) => trim($o, '{}'), $options); // un mana par alternative
                } else {
                    $abilities[] = ActivatedAbility::mana(implode('|', $options));
                }
            } elseif (!$isSpell && !$isLand && $line === 'When ~ enters, draw a card.') {
                $triggers[] = new TriggeredAbility(TriggeredAbility::ETB, 'draw', 1);
            } else {
                throw $fail('texte non modélisé : « ' . $line . ' »');
            }
        }

        if ($isSpell && $effect === null) {
            throw $fail('sort sans effet modélisé');
        }
        if ($isLand && $produces === [] && $abilities === []) {
            $basic = array_values(array_intersect(array_keys(self::BASIC_LAND_COLOR), $subtypeNames));
            if (!in_array('Basic', $supertypes, true) || $basic === []) {
                throw $fail('terrain sans production de mana modélisée');
            }
            $produces = [self::BASIC_LAND_COLOR[$basic[0]]];
        }

        return new CardDefinition(
            $name, $type, (int) ($card['cmc'] ?? 0), $manaCost === '' ? null : $manaCost, $power, $toughness,
            $effect, $amount, $adds, $produces, $abilities, $triggers, $subtypes, array_values(array_unique($keywords)),
            in_array('Legendary', $supertypes, true) ? [CardInstance::LEGENDARY] : [],
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

<?php

declare(strict_types=1);

namespace App\Service\Deck;

/**
 * Lit une decklist texte (formats Moxfield / Archidekt / MTGO) : `1 Sol Ring`, `1x Sol Ring (C21) 263 *F*`, `Sol Ring`.
 * Commandant : section `Commander` / `Commandant`, ou ligne préfixée `Commander:` / `CMDR:` ; un second commandant (Partner)
 * est rendu sous `partner`, les suivants sont ignorés (`Decks::validate()` refuse un second commandant sans Partner).
 * Les commentaires (`//`, `#`) sont ignorés ; `Sideboard` / `Maybeboard` et ce qui suit sont ignorés.
 */
final class DecklistParser
{
    private const IGNORED_SECTIONS = ['sideboard', 'maybeboard', 'considering', 'companion'];
    private const COMMANDER_SECTIONS = ['commander', 'commandant', 'commanders'];
    private const MAIN_SECTIONS = ['deck', 'mainboard', 'main', 'maindeck'];

    /** @return array{commander: ?string, partner: ?string, entries: array<string, int>} entrées : nom => quantité (dans l'ordre d'apparition) */
    public static function parse(string $text): array
    {
        $commander = null;
        $partner = null;
        $entries = [];
        $section = 'main';

        foreach (preg_split('/\R/u', $text) ?: [] as $raw) {
            $line = trim($raw);
            if ($line === '' || str_starts_with($line, '//') || str_starts_with($line, '#')) {
                continue;
            }
            $header = strtolower(rtrim($line, ': '));
            if (in_array($header, self::COMMANDER_SECTIONS, true)) {
                $section = 'commander';
                continue;
            }
            if (in_array($header, self::MAIN_SECTIONS, true)) {
                $section = 'main';
                continue;
            }
            if (in_array($header, self::IGNORED_SECTIONS, true)) {
                $section = 'ignored';
                continue;
            }
            if ($section === 'ignored') {
                continue;
            }

            $isCommander = $section === 'commander';
            if (preg_match('/^(?:commander|commandant|cmdr)\s*:\s*(.+)$/iu', $line, $m)) {
                $isCommander = true;
                $line = trim($m[1]);
            }

            $quantity = 1;
            if (preg_match('/^(\d+)\s*x?\s+(.+)$/iu', $line, $m)) {
                $quantity = (int) $m[1];
                $line = trim($m[2]);
            }
            // « (SET) 123 », « *F* », « ^tag^ » : métadonnées d'impression, sans effet sur la carte.
            $name = trim((string) preg_replace('/\s+\([A-Za-z0-9]{2,6}\)(\s+[\w-]+)?(\s+\*[A-Za-z]+\*)?(\s+\^[^^]*\^)?\s*$/u', '', $line));
            if ($name === '' || $quantity < 1) {
                continue;
            }

            if ($isCommander) {
                if ($commander === null) {
                    $commander = $name;
                } elseif ($name !== $commander) {
                    $partner ??= $name;
                }
                continue;
            }
            $entries[$name] = ($entries[$name] ?? 0) + $quantity;
        }

        return ['commander' => $commander, 'partner' => $partner, 'entries' => $entries];
    }
}
